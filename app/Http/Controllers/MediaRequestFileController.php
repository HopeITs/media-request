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

    public function download(\Illuminate\Http\Request $http, Media $media): BinaryFileResponse
    {
        $request = $media->model;

        abort_unless($request instanceof MediaRequest && in_array($media->collection_name, ['attachments', 'results'], true), Response::HTTP_NOT_FOUND);
        Gate::authorize('view', $request);
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
