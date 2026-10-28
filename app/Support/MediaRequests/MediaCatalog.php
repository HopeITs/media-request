<?php

namespace App\Support\MediaRequests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * The questions each kind of request asks (config media-requests.fields),
 * which of them are visible for the answers given so far, and how answers are
 * validated, cleaned and shown.
 *
 * Follow-up questions depend on earlier answers: a social post asks
 * post / story / carousel, never a size; a print poster asks a paper size.
 */
class MediaCatalog
{
    /** @return array<string, array{key: string, types: array<int, string>}> */
    public function services(): array
    {
        return config('media-requests.services', []);
    }

    /** Services a person can file a full request for (000 is the HC_PACK shortcut). */
    public function requestServices(): array
    {
        return array_values(array_filter(array_keys($this->services()), fn (string $s): bool => $s !== '000'));
    }

    /** @return array<int, string> */
    public function types(string $service): array
    {
        return $this->services()[$service]['types'] ?? [];
    }

    public function serviceKey(string $service): string
    {
        return $this->services()[$service]['key'] ?? 'design';
    }

    public function isValidType(?string $service, ?string $type): bool
    {
        return $service !== null && $type !== null && in_array($type, $this->types($service), true);
    }

    /** @return array<int, array<string, mixed>> */
    public function fields(?string $service, ?string $type): array
    {
        // The keys are "001.new" etc.; config() would read the dot as nesting.
        return config('media-requests.fields', [])[$service.'.'.$type] ?? [];
    }

    /**
     * Visible fields for the given answers.
     *
     * @param  'details'|'extra'|null  $group  details = the main questions, extra = optional last step
     * @return array<int, array<string, mixed>>
     */
    public function visibleFields(?string $service, ?string $type, array $answers, ?string $group = null): array
    {
        return array_values(array_filter(
            $this->fields($service, $type),
            function (array $field) use ($answers, $group): bool {
                if ($group === 'details' && ! empty($field['extra'])) {
                    return false;
                }
                if ($group === 'extra' && empty($field['extra'])) {
                    return false;
                }

                return $this->isVisible($field, $answers);
            },
        ));
    }

    public function isVisible(array $field, array $answers): bool
    {
        $rule = $field['show_if'] ?? null;

        return $rule === null || $this->holds($rule, $answers);
    }

    /** @return array<string, mixed> */
    public function defaults(?string $service, ?string $type): array
    {
        $out = [];

        foreach ($this->fields($service, $type) as $field) {
            if (array_key_exists('default', $field)) {
                $out[$field['key']] = $field['default'];
            } elseif (($field['type'] ?? '') === 'checks') {
                $out[$field['key']] = [];
            }
        }

        return $out;
    }

    /**
     * Keep only answers to visible questions, with the right shape and only
     * allowed options. Anything else the browser sent is dropped.
     *
     * @return array<string, mixed>
     */
    public function clean(?string $service, ?string $type, array $answers): array
    {
        $out = [];

        foreach ($this->fields($service, $type) as $field) {
            if (! $this->isVisible($field, $answers)) {
                continue;
            }

            $key = $field['key'];
            $value = $answers[$key] ?? null;
            $options = $field['options'] ?? null;

            $value = match ($field['type']) {
                'checks' => array_values(array_intersect($options ?? [], array_map('strval', (array) ($value ?? [])))),
                'chips', 'select', 'yesno' => in_array((string) $value, $options ?? [], true) ? (string) $value : null,
                'check' => (bool) $value,
                'number' => is_numeric($value) && (int) $value > 0 ? min((int) $value, 100000) : null,
                'date' => $this->isDate($value) ? (string) $value : null,
                'time' => is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value) ? $value : null,
                default => is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, 2000) : null,
            };

            if ($value === null || $value === [] || ($field['type'] === 'check' && $value === false)) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Required, visible questions without an answer.
     *
     * @return array<string, string> field key => message
     */
    public function missing(?string $service, ?string $type, array $answers, ?string $group = null): array
    {
        $errors = [];
        $clean = $this->clean($service, $type, $answers);

        foreach ($this->visibleFields($service, $type, $answers, $group) as $field) {
            if (empty($field['required'])) {
                continue;
            }

            if (! array_key_exists($field['key'], $clean)) {
                $errors[$field['key']] = __('media.err.required');
            }
        }

        return $errors;
    }

    // ------------------------------------------------------------------
    // Labels
    // ------------------------------------------------------------------

    public function fieldLabel(string $key, ?string $locale = null): string
    {
        return $this->trans("media.f.{$key}", $locale) ?? $key;
    }

    public function hint(string $key, ?string $locale = null): ?string
    {
        return $this->trans("media.h.{$key}", $locale);
    }

    public function placeholder(string $key, ?string $locale = null): ?string
    {
        return $this->trans("media.ph.{$key}", $locale);
    }

    public function optionLabel(string $field, string $option, ?string $locale = null): string
    {
        return $this->trans("media.o.{$field}.{$option}", $locale)
            ?? $this->trans("media.o.{$option}", $locale)
            ?? $option;
    }

    public function optionInfo(string $field, string $option, ?string $locale = null): ?string
    {
        return $this->trans("media.o.{$field}.{$option}.i", $locale);
    }

    /** Readable value of one answer, for summaries and the log. */
    public function valueText(array $field, mixed $value, ?string $locale = null): string
    {
        return match ($field['type']) {
            'checks' => collect((array) $value)->map(fn ($v) => $this->optionLabel($field['key'], (string) $v, $locale))->implode('، '),
            'chips', 'select', 'yesno' => $this->optionLabel($field['key'], (string) $value, $locale),
            'check' => $value ? ($this->trans('media.yes', $locale) ?? '✓') : '—',
            'date' => $this->isDate($value) ? Carbon::parse($value)->format('d-m-Y') : (string) $value,
            default => (string) $value,
        };
    }

    /**
     * The answered questions in display order: [label, value].
     *
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public function summary(?string $service, ?string $type, array $answers, ?string $locale = null): array
    {
        $rows = [];

        foreach ($this->fields($service, $type) as $field) {
            if (! array_key_exists($field['key'], $answers) || ! $this->isVisible($field, $answers)) {
                continue;
            }

            $rows[] = [
                'key' => $field['key'],
                'label' => $this->fieldLabel($field['key'], $locale),
                'value' => $this->valueText($field, $answers[$field['key']], $locale),
            ];
        }

        return $rows;
    }

    public function field(?string $service, ?string $type, string $key): ?array
    {
        foreach ($this->fields($service, $type) as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------

    private function holds(array $rule, array $answers): bool
    {
        if (array_key_exists('any', $rule)) {
            foreach ($rule['any'] as $group) {
                if ($this->holds($group, $answers)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($rule as [$key, $op, $value]) {
            $answer = $answers[$key] ?? null;

            $ok = match ($op) {
                'in' => is_scalar($answer) && in_array((string) $answer, (array) $value, true),
                'not' => ! (is_scalar($answer) && in_array((string) $answer, (array) $value, true)),
                'has' => is_array($answer) && in_array($value, $answer, true),
                'filled' => $answer !== null && $answer !== '' && $answer !== [],
                default => false,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    private function trans(string $key, ?string $locale): ?string
    {
        $locale ??= app()->getLocale();

        return Lang::has($key, $locale, false) ? (string) __($key, [], $locale) : null;
    }
}
