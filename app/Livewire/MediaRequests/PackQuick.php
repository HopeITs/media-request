<?php

namespace App\Livewire\MediaRequests;

use App\Models\Program;
use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\HcPack;
use Livewire\Component;

/**
 * HC_PACK on the home screen (its card and its side panel): results appear while
 * typing, the groups show before anything is typed, and the ticked files are asked
 * for from here, as on the HC_PACK page. That page stays one link away for more.
 */
class PackQuick extends Component
{
    public string $place = 'card';   // keeps the field ids apart when it is on the screen twice

    public string $search = '';

    public string $group = '';

    /** @var array<int, string> */
    public array $selected = [];

    public function requestFiles(MediaRequestWorkflow $workflow)
    {
        $request = $workflow->requestPack(auth()->user(), $this->selected, '');

        return $this->redirectRoute('media-requests.show', $request);
    }

    public function render()
    {
        $pack = app(HcPack::class);
        $searching = trim($this->search) !== '' || $this->group !== '';
        $counts = $pack->groupCounts();

        return view('livewire.media-requests.pack-quick', [
            'groups' => array_values(array_filter(HcPack::GROUPS, fn ($g) => ($counts[$g[0]] ?? 0) > 0)),
            'counts' => $counts,
            'files' => $searching ? $pack->search($this->search, $this->group ?: null, Program::query()->get(['id', 'code', 'slug']), 30) : collect(),
            'searching' => $searching,
        ]);
    }
}
