<?php

namespace App\Livewire\MediaRequests;

use App\Models\Program;
use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\HcPack;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * HC_PACK: logos and ready templates. Tick the files you need and ask for
 * them; the request records who asked and when, and any team member sends
 * the files from the shared drive.
 */
class Pack extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'group')]
    public string $group = '';

    /** @var array<int, string> */
    public array $selected = [];

    public string $note = '';

    public function requestFiles(MediaRequestWorkflow $workflow)
    {
        $request = $workflow->requestPack(auth()->user(), $this->selected, $this->note);

        return $this->redirectRoute('media-requests.show', $request);
    }

    public function render()
    {
        $pack = app(HcPack::class);
        $programs = Program::query()->get(['id', 'code', 'slug']);
        $searching = trim($this->search) !== '' || $this->group !== '';

        return view('livewire.media-requests.pack', [
            'groups' => HcPack::GROUPS,
            'counts' => $pack->groupCounts(),
            'files' => $searching ? $pack->search($this->search, $this->group ?: null, $programs) : collect(),
            'searching' => $searching,
            'chosen' => $pack->find($this->selected),
        ]);
    }
}
