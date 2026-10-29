{{--
    Files the team sent with a draft or a result, newest version first. Images and PDFs
    open in the page (?view=1); everything else downloads. With "latest", only the newest.
--}}
@props(['r', 'latest' => false])
@php($archive = app(\App\Support\MediaRequests\MediaTeam::class)->seesArchive(auth()->user()))
@php($groups = $r->getMedia('results')->reject(fn ($m) => ! $archive && \App\Support\MediaRequests\MediaTeam::isOpenFile($m->file_name))->sortByDesc('id')->groupBy(fn ($m) => ($m->getCustomProperty('kind') ?? 'result').':'.($m->getCustomProperty('version') ?? 1)))
@php($groups = $latest ? $groups->take(1) : $groups)

@foreach ($groups as $key => $files)
    @php([$kind, $version] = explode(':', $key))
    <div {{ $attributes->class(['resgal']) }}>
        <p class="resgal-h">{{ $kind === 'draft' ? __('media.d.draftV') : __('media.d.version', ['n' => $version]) }} <span class="sub">· {{ $files->first()->created_at->diffForHumans() }}</span></p>
        <ul>
            @foreach ($files as $m)
                @php($viewable = in_array($m->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'], true))
                <li>
                    <a href="{{ route('media-requests.files', $viewable ? ['media' => $m, 'view' => 1] : $m) }}" @if ($viewable) target="_blank" rel="noopener" @endif title="{{ $m->file_name }}">
                        @if (str_starts_with((string) $m->mime_type, 'image/') && $m->mime_type !== 'image/svg+xml')
                            <img src="{{ route('media-requests.files', ['media' => $m, 'view' => 1]) }}" alt="{{ $m->file_name }}" loading="lazy">
                        @else
                            <span class="rpi"><x-ic :n="$m->mime_type === 'application/pdf' ? 'file' : 'download'" /><small class="ltr">{{ strtoupper(pathinfo($m->file_name, PATHINFO_EXTENSION)) }}</small></span>
                        @endif
                        <span class="rpn ltr">{{ $m->file_name }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endforeach
