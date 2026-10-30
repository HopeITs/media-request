<?php

namespace App\Http\Controllers;

use App\Models\MediaRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Files attached to media requests live on the private disk. They are read
 * back only through here, after MediaRequestPolicy::view on the request.
 */
class MediaRequestFileController extends Controller
{
    /** Shown in the page (previews) instead of downloaded: only these, never SVG or HTML. */
    private const VIEWABLE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    /** Pictures the Latest designs slideshow may show to anyone signed in. */
    public const SHOWCASE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * A design on the home screen slideshow, for anyone signed in: only a picture (never SVG or
     * an open file), only one the team sent as the result (not a draft), only of a delivered
     * request (approved by the media manager and the requester's side). Nothing else of the
     * request is given out; opening the request still needs MediaRequestPolicy::view.
     */
    public function showcase(Media $media): BinaryFileResponse
    {
        $request = $media->model;

        abort_unless($request instanceof MediaRequest && $request->status === \App\Enums\MediaRequestStatus::Delivered
            && $media->collection_name === 'results' && ($media->getCustomProperty('kind') ?? 'result') === 'result'
            && in_array($media->mime_type, self::SHOWCASE_TYPES, true) && is_file($media->getPath()), Response::HTTP_NOT_FOUND);

        $response = response()->file($media->getPath(), ['Content-Type' => $media->mime_type, 'Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff']);
        $response->headers->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }

    /**
     * A HC_PACK file kept in the app (added or replaced by the media manager). The media team
     * downloads it to send; the person who asked for it, once their HC_PACK request is delivered
     * (?r= that request, which must name this file and be theirs to see).
     */
    public function pack(\Illuminate\Http\Request $http, \App\Models\MediaPackFile $file): BinaryFileResponse
    {
        abort_if($file->removed || ! $file->stored || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($file->stored), Response::HTTP_NOT_FOUND);

        $team = app(\App\Support\MediaRequests\MediaTeam::class);
        $user = $http->user();
        if (! $team->isMember($user->profile) && ! $team->canActAsMediaManager($user)) {
            $request = MediaRequest::find($http->integer('r'));
            abort_unless($request && $request->isPack() && $request->status === \App\Enums\MediaRequestStatus::Delivered
                && in_array($file->path, $request->pack_files ?? [], true), Response::HTTP_FORBIDDEN);
            Gate::authorize('view', $request);
        }

        $response = response()->download(\Illuminate\Support\Facades\Storage::disk('local')->path($file->stored), basename($file->path), [
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    public function download(\Illuminate\Http\Request $http, Media $media): BinaryFileResponse
    {
        $request = $media->model;

        abort_unless($request instanceof MediaRequest && in_array($media->collection_name, ['attachments', 'results'], true), Response::HTTP_NOT_FOUND);
        Gate::authorize('view', $request);
        // Open files the team sent (.ai, .psd, …) stay with the media team; the requester gets the finished files.
        abort_if($media->collection_name === 'results' && \App\Support\MediaRequests\MediaTeam::isOpenFile($media->file_name)
            && ! app(\App\Support\MediaRequests\MediaTeam::class)->seesArchive($http->user()), Response::HTTP_FORBIDDEN);
        abort_unless(is_file($media->getPath()), Response::HTTP_NOT_FOUND);

        $inline = $http->boolean('view') && in_array($media->mime_type, self::VIEWABLE, true);
        $response = $inline
            ? response()->file($media->getPath(), ['Content-Type' => $media->mime_type, 'Content-Disposition' => 'inline; filename="'.addslashes($media->file_name).'"', 'X-Content-Type-Options' => 'nosniff'])
            : response()->download($media->getPath(), $media->file_name, [
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
