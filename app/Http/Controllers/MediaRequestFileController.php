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
    public function download(Media $media): BinaryFileResponse
    {
        $request = $media->model;

        abort_unless($request instanceof MediaRequest && $media->collection_name === 'attachments', Response::HTTP_NOT_FOUND);
        Gate::authorize('view', $request);
        abort_unless(is_file($media->getPath()), Response::HTTP_NOT_FOUND);

        $response = response()->download($media->getPath(), $media->file_name, [
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
