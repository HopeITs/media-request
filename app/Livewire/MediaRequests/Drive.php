<?php

namespace App\Livewire\MediaRequests;

use App\Models\City;
use App\Models\MediaRequest;
use App\Models\Program;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The files, as folders, like a drive:
 *
 *   001 HC / 000 SYR / MR-001-26-0005 · 001HC_SYR_Poster_Exhibition / the files
 *
 * Program, then governorate, then the request, then what was attached to it and
 * what the team sent (each version), with the request's links. Everyone sees only
 * the requests they may see (MediaRequest::visibleTo).
 */
class Drive extends Component
{
    #[Url(as: 'p', except: null)]
    public ?int $program = null;

    #[Url(as: 'c', except: null)]
    public ?int $city = null;

    #[Url(as: 'r', except: null)]
    public ?int $request = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function open(string $level, int $id): void
    {
        match ($level) {
            'program' => [$this->program, $this->city, $this->request] = [$id, null, null],
            'city' => [$this->city, $this->request] = [$id, null],
            'request' => $this->request = $id,
            default => null,
        };
        $this->search = '';
    }

    public function up(string $level): void
    {
        match ($level) {
            'root' => [$this->program, $this->city, $this->request] = [null, null, null],
            'program' => [$this->city, $this->request] = [null, null],
            'city' => $this->request = null,
            default => null,
        };
    }

    public function render()
    {
        $user = auth()->user();
        $visible = fn () => MediaRequest::query()->visibleTo($user);

        $program = $this->program ? Program::find($this->program) : null;
        $city = $this->city ? City::find($this->city) : null;
        $request = $this->request ? $visible()->with(['media', 'program', 'city'])->find($this->request) : null;

        // Searching: requests across every folder whose number or name matches.
        $found = trim($this->search) !== ''
            ? $visible()->with(['program', 'city'])->where(fn ($q) => $q->where('reference', 'like', '%'.trim($this->search).'%')
                ->orWhere('title', 'like', '%'.trim($this->search).'%'))->latest()->limit(40)->get()
            : collect();

        return view('livewire.media-requests.drive', [
            'programObj' => $program,
            'cityObj' => $city,
            'requestObj' => $request,
            'found' => $found,
            'folders' => $request ? collect() : $this->folders($visible, $program, $city),
        ])->title(__('media.nav.drive'));
    }

    /** The folders at the current level, with how many requests and files are inside. */
    private function folders(callable $visible, ?Program $program, ?City $city): Collection
    {
        $rows = $visible()->withCount('media')
            ->when($program, fn ($q) => $q->where('program_id', $program->id))
            ->when($city, fn ($q) => $q->where('city_id', $city->id))
            ->get(['id', 'program_id', 'city_id', 'reference', 'title', 'item_code', 'service', 'type', 'status', 'created_at', 'draft_link', 'result_link', 'archive_link']);

        if (! $program) {
            $programs = Program::whereIn('id', $rows->pluck('program_id')->unique())->orderBy('code')->get();

            return $programs->map(fn (Program $p) => ['level' => 'program', 'id' => $p->id, 'name' => trim($p->code.' '.$p->slug),
                'requests' => $rows->where('program_id', $p->id)->count(), 'files' => $rows->where('program_id', $p->id)->sum('media_count')]);
        }

        if (! $city) {
            $cities = City::whereIn('id', $rows->pluck('city_id')->unique())->orderBy('code')->get();

            return $cities->map(fn (City $c) => ['level' => 'city', 'id' => $c->id, 'name' => trim($c->code.' '.$c->slug),
                'requests' => $rows->where('city_id', $c->id)->count(), 'files' => $rows->where('city_id', $c->id)->sum('media_count')]);
        }

        return $rows->sortByDesc('created_at')->values()->map(fn (MediaRequest $r) => ['level' => 'request', 'id' => $r->id,
            'name' => $r->reference, 'sub' => app(\App\Support\MediaRequests\MediaTeam::class)->seesArchive(auth()->user()) ? $r->displayName() : $r->shortName(), 'files' => $r->media_count
                + count(array_filter([$r->draft_link, $r->result_link, $r->archive_link]))]);
    }
}
