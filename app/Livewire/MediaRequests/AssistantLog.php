<?php

namespace App\Livewire\MediaRequests;

use App\Models\MediaAssistantLog;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Teach the assistant": everything people typed to the request assistant.
 * The ones it did not understand, and the ones where the person picked
 * something else, show which words its keyword lists are missing
 * (App\Support\MediaRequests\MediaAssistant and config media-requests.aliases).
 */
class AssistantLog extends Component
{
    use WithPagination;

    #[Url(as: 'show')]
    public string $filter = 'missed';

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['missed', 'corrected', 'all'], true) ? $filter : 'missed';
        $this->resetPage();
    }

    public function render()
    {
        $base = MediaAssistantLog::query();

        return view('livewire.media-requests.assistant-log', [
            'rows' => MediaAssistantLog::query()
                ->with(['employee', 'program', 'city', 'mediaRequest'])
                ->when($this->filter === 'missed', fn (Builder $q) => $q->where('understood', false))
                ->when($this->filter === 'corrected', fn (Builder $q) => $q->where('corrected', true))
                ->latest('id')
                ->paginate(40),
            'counts' => [
                'missed' => (clone $base)->where('understood', false)->count(),
                'corrected' => (clone $base)->where('corrected', true)->count(),
                'all' => (clone $base)->count(),
            ],
        ]);
    }
}
