<?php

namespace App\Livewire\Layout;

use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Throwable;

class Breadcrumbs extends Component
{
    public string $title = 'Page Title';

    /** @var array<int, array{label: string, url?: string|null}> */
    public array $crumbs = [];

    public bool $showButton = false;

    public function render()
    {
        // Normalize crumbs & compute href
        $items = collect($this->crumbs)->map(function ($c) {
            $label = (string) ($c['label'] ?? '');
            $href = null;

            if (! empty($c['url'])) {
                $href = (string) $c['url'];
            } elseif (! empty($c['route']) && Route::has($c['route'])) {
                try {
                    $href = route($c['route'], $c['params'] ?? []);
                } catch (Throwable $e) {
                    $href = null;
                }
            }

            return ['label' => $label, 'href' => $href];
        })->toArray();

        return view('livewire.layout.breadcrumbs', [
            'items' => $items,
            'title' => $this->title,
            'showButton' => $this->showButton,
        ]);
    }
}
