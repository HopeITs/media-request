<?php

namespace App\Support;

use Livewire\Component;

/**
 * Feedback while the app is being tried out (config media-requests.feedback). After an action
 * worth asking about, a component calls ask(); the browser then shows a small "how did that
 * go?" card (public/js/feedback.js) and sends the answer to /feedback.
 */
class BetaFeedback
{
    /** Ask the person in the browser to rate what they just did (an action name, e.g. approveMedia). */
    public static function ask(Component $component, string $action): void
    {
        if (config('media-requests.feedback')) {
            $component->dispatch('mr-rate', action: $action, label: self::actionLabel($action));
        }
    }

    /** The same, for an action that ends by going to another page: asked when that page opens. */
    public static function askNext(string $action): void
    {
        if (config('media-requests.feedback')) {
            session()->flash('mr-rate', ['action' => $action, 'label' => self::actionLabel($action)]);
        }
    }

    /** The action in words, for the card and the feedback page (the name itself when there are none). */
    public static function actionLabel(?string $action): string
    {
        if ($action === null || $action === '') {
            return '—';
        }
        $key = 'media.fb.act.'.$action;

        return __($key) !== $key ? __($key) : $action;
    }
}
