{{-- The event date came from the calendar: say so instead of asking again. --}}
@if ($field['key'] === 'eventDate' && ($presetDate ?? null) && ! ($changeEventDate ?? false) && ($answers['eventDate'] ?? null) === $presetDate)
    <div class="field full presetday" wire:key="fld-eventDate-preset">
        <span class="lbl"><x-media.bi k="media.f.eventDate" /></span>
        <p><x-ic n="cal" /><b>{{ \Illuminate\Support\Carbon::parse($presetDate)->translatedFormat('l') }} <span class="num">{{ \Illuminate\Support\Carbon::parse($presetDate)->format('d-m-Y') }}</span></b>
            <span class="sub">{{ __('media.wz.fromCalendar') }}</span>
            <button type="button" class="btn-q" wire:click="$set('changeEventDate', true)">{{ __('media.wz.change') }}</button></p>
    </div>
@else{{--
    One question from config media-requests.fields, bound to answers.<key>.
    Choices update live, because later questions depend on them; typed
    answers sync when the field loses focus.
--}}
@php
    $key = $field['key'];
    $type = $field['type'];
    $model = 'answers.'.$key;
    $id = 'fld-'.$key;
    $options = $field['options'] ?? [];
    $pics = $field['pics'] ?? null;
    $picMode = is_string($pics) ? $pics : null;
    $picFor = function (string $option) use ($pics) {
        if ($pics === true || is_string($pics)) {
            return $option;
        }

        return is_array($pics) ? ($pics[$option] ?? null) : null;
    };
    $other = app()->getLocale() === 'ar' ? 'en' : 'ar';
    $hint = $catalog->hint($key);
    $placeholder = $catalog->placeholder($key);
    $error = $errors->first($model);
    $full = ! empty($field['full']) || in_array($type, ['chips', 'checks', 'textarea', 'yesno'], true);
    $required = ! empty($field['required']);
@endphp

@if (in_array($type, ['chips', 'checks', 'yesno'], true))
    <fieldset @class(['field', 'full' => $full]) wire:key="field-{{ $key }}" @if ($error) aria-invalid="true" @endif>
        <legend><x-media.bi :k="'media.f.'.$key" />@if ($required)<span class="req" aria-hidden="true">*</span>@endif</legend>
        @if ($hint)<p class="hint">{{ $hint }}</p>@endif
        <div @class(['choices', 'pics' => $pics, 'wide' => ! empty($field['wide'])])>
            @foreach ($options as $option)
                @php
                    $pic = $picFor($option);
                    $label = $catalog->optionLabel($key, $option);
                    $label2 = $catalog->optionLabel($key, $option, $other);
                    $info = $catalog->optionInfo($key, $option);
                @endphp
                <label class="choice" wire:key="opt-{{ $key }}-{{ $option }}">
                    <input type="{{ $type === 'checks' ? 'checkbox' : 'radio' }}" value="{{ $option }}" wire:model.live="{{ $model }}" name="{{ $model }}">
                    <span>
                        <x-ic n="check" class="ck" />
                        @if ($pic)<x-media.pic :name="$pic" :mode="$picMode" class="pic" />@endif
                        <span class="bi"><span>{{ $label }}</span>@if ($label2 !== $label)<span class="bi2" lang="{{ $other }}">{{ $label2 }}</span>@endif</span>
                        @if ($info)<span class="pinfo">{{ $info }}</span>@endif
                    </span>
                </label>
            @endforeach
        </div>
        @if ($error)<p class="err" role="alert"><x-ic n="alert" />{{ $error }}</p>@endif
    </fieldset>
@else
    <div @class(['field', 'full' => $full]) wire:key="field-{{ $key }}">
        @if ($type === 'check')
            <label class="check">
                <input type="checkbox" wire:model.live="{{ $model }}">
                <x-media.bi :k="'media.f.'.$key" />@if ($required)<span class="req" aria-hidden="true">*</span>@endif
            </label>
            @if ($hint)<p class="hint">{{ $hint }}</p>@endif
        @else
            <label for="{{ $id }}"><x-media.bi :k="'media.f.'.$key" />@if ($required)<span class="req" aria-hidden="true">*</span>@endif</label>
            @if ($hint)<p class="hint" id="{{ $id }}-h">{{ $hint }}</p>@endif
            @if ($type === 'select')
                <select id="{{ $id }}" class="inp" wire:model.live="{{ $model }}">
                    <option value="">{{ __('media.o.select') }}</option>
                    @foreach ($options as $option)
                        <option value="{{ $option }}">{{ $catalog->optionLabel($key, $option) }}</option>
                    @endforeach
                </select>
            @elseif ($type === 'textarea')
                <textarea id="{{ $id }}" class="inp" rows="3" wire:model.blur="{{ $model }}" placeholder="{{ $placeholder }}" @if ($error) aria-invalid="true" @endif></textarea>
            @else
                <input id="{{ $id }}" class="inp" type="{{ ['number' => 'number', 'date' => 'date', 'time' => 'time'][$type] ?? 'text' }}"
                       @if ($type === 'number') min="1" inputmode="numeric" @endif
                       wire:model.blur="{{ $model }}" placeholder="{{ $placeholder }}" @if ($error) aria-invalid="true" @endif>
            @endif
        @endif
        @if ($error)<p class="err" role="alert"><x-ic n="alert" />{{ $error }}</p>@endif
    </div>
@endif
@endif
