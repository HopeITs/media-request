{{--
    The "your request is delivered" email: Arabic first, then English. Plain tables and
    inline styles, so it looks the same in Gmail, Outlook and on a phone.
--}}
@php
$rows = fn (string $l) => array_filter([
    __('media.mail.ref', [], $l) => $r->reference,
    __('media.mail.name', [], $l) => $r->shortName(),
    __('media.mail.what', [], $l) => __('media.svc.'.app(\App\Support\MediaRequests\MediaCatalog::class)->serviceKey($r->service), [], $l).($r->isPack() ? '' : ' · '.__('media.type.'.$r->type, [], $l)),
    __('media.mail.program', [], $l) => $r->program ? trim($r->program->code.' '.$r->program->slug) : null,
    __('media.mail.city', [], $l) => $r->city ? ($l === 'ar' ? $r->city->name_ar : $r->city->name_en) : null,
    __('media.mail.requester', [], $l) => $r->requester?->nameForLocale($l),
    __('media.mail.asked', [], $l) => $r->created_at?->format('d-m-Y'),
    __('media.mail.neededBy', [], $l) => $r->needed_by?->format('d-m-Y'),
    __('media.mail.delivered', [], $l) => $r->delivered_at?->format('d-m-Y'),
    __('media.mail.doneBy', [], $l) => $r->assignee?->nameForLocale($l),
    __('media.mail.revisions', [], $l) => $r->revisions ? (string) $r->revisions : null,
]);
@endphp
<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>{{ $r->reference }}</title></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:'Somar Sans',Tahoma,Arial,sans-serif;color:#18181b">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden">
    <tr><td style="background:#BE1F24;padding:18px 24px;color:#ffffff;font-size:18px;font-weight:bold">Hope Center · Media</td></tr>
    @foreach (['ar' => 'rtl', 'en' => 'ltr'] as $l => $dir)
        <tr><td dir="{{ $dir }}" style="padding:22px 24px 8px;text-align:{{ $dir === 'rtl' ? 'right' : 'left' }}">
            <p style="margin:0 0 6px;font-size:20px;font-weight:bold">{{ __('media.mail.title', [], $l) }}</p>
            <p style="margin:0 0 16px;font-size:15px;color:#52525b">{{ __('media.mail.lead', ['name' => $to->profile?->nameForLocale($l) ?? $to->name], $l) }}</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border-collapse:collapse">
                @foreach ($rows($l) as $label => $value)
                    <tr>
                        <td style="padding:7px 0;color:#71717a;border-bottom:1px solid #f0f0f0;width:42%">{{ $label }}</td>
                        <td style="padding:7px 0;font-weight:bold;border-bottom:1px solid #f0f0f0" dir="auto">{{ $value }}</td>
                    </tr>
                @endforeach
            </table>
        </td></tr>
    @endforeach
    <tr><td align="center" style="padding:18px 24px 10px">
        <a href="{{ $url }}" style="display:inline-block;background:#BE1F24;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 26px;border-radius:10px">{{ __('media.mail.open', [], 'ar') }} · {{ __('media.mail.open', [], 'en') }}</a>
    </td></tr>
    <tr><td style="padding:6px 24px 22px;font-size:12px;color:#a1a1aa;text-align:center">
        {{ __('media.mail.onlyApp', [], 'ar') }}<br>{{ __('media.mail.onlyApp', [], 'en') }}
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
