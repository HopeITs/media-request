<?php

namespace App\Livewire\MediaRequests;

use App\Models\Program;
use App\Services\HcPackManager;
use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\HcPack;
use App\Support\MediaRequests\MediaTeam;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * HC_PACK: logos and ready templates. Tick the files you need and ask for
 * them; the request records who asked and when, and any team member sends
 * the files from the shared drive. The media manager also keeps the pack
 * here: adds a file, replaces one with a newer version, or takes one out.
 */
class Pack extends Component
{
    use WithFileUploads;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'group')]
    public string $group = '';

    /** @var array<int, string> */
    public array $selected = [];

    public string $note = '';

    // The media manager's tools.
    public bool $adding = false;

    public $newFile = null;

    public string $newGroup = '';

    public string $newFolder = '';

    public string $replacing = '';   // the path being replaced

    public $replacement = null;

    public function requestFiles(MediaRequestWorkflow $workflow)
    {
        $request = $workflow->requestPack(auth()->user(), $this->selected, $this->note);

        return $this->redirectRoute('media-requests.show', $request);
    }

    public function addFile(HcPackManager $manager): void
    {
        $this->validate([
            'newFile' => ['required', 'file', 'max:'.HcPackManager::MAX_KB, 'extensions:'.implode(',', HcPackManager::EXTENSIONS)],
            'newGroup' => ['required', 'string'],
            'newFolder' => ['nullable', 'string', 'max:120'],
        ], [], ['newFile' => __('media.hp.m.file'), 'newGroup' => __('media.hp.group'), 'newFolder' => __('media.hp.m.folder')]);

        $row = $manager->add(auth()->user(), $this->newFile, $this->newGroup, $this->newFolder);
        $this->reset(['adding', 'newFile', 'newFolder']);
        $this->search = pathinfo($row->path, PATHINFO_FILENAME);   // show where it went
        session()->flash('packDone', __('media.hp.m.added'));
    }

    public function startReplace(string $path): void
    {
        $this->authorizeManager();
        [$this->replacing, $this->replacement] = [$path, null];
        $this->resetValidation();
    }

    public function cancelReplace(): void
    {
        $this->reset(['replacing', 'replacement']);
    }

    public function replaceFile(HcPackManager $manager): void
    {
        $this->validate(['replacement' => ['required', 'file', 'max:'.HcPackManager::MAX_KB, 'extensions:'.implode(',', HcPackManager::EXTENSIONS)]],
            [], ['replacement' => __('media.hp.m.file')]);

        $manager->replace(auth()->user(), $this->replacing, $this->replacement);
        $this->selected = array_values(array_diff($this->selected, [$this->replacing]));
        $this->reset(['replacing', 'replacement']);
        session()->flash('packDone', __('media.hp.m.replaced'));
    }

    public function removeFile(HcPackManager $manager, string $path): void
    {
        $manager->remove(auth()->user(), $path);
        $this->selected = array_values(array_diff($this->selected, [$path]));
        session()->flash('packDone', __('media.hp.m.removed'));
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
            'chosen' => $pack->find($this->selected)->reject(fn ($f) => $f['removed']),
            'manages' => app(MediaTeam::class)->canActAsMediaManager(auth()->user()),
            'extensions' => HcPackManager::EXTENSIONS,
        ]);
    }

    private function authorizeManager(): void
    {
        abort_unless(app(MediaTeam::class)->canActAsMediaManager(auth()->user()), 403);
    }
}
