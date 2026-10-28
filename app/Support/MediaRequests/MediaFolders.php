<?php

namespace App\Support\MediaRequests;

use App\Models\MediaRequest;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

/**
 * Where a request's files go on the disk, the same folders as the Files page:
 *
 *   storage/app/private/001_HC/001_ALP/MR-001-26-0005/<file id>/the-file.pdf
 *
 * The folder is decided when the file is added (custom property "folder", see folderFor),
 * so files added before this, or if a request later moves, are still found where they are.
 * Anything without a folder keeps the library's default (<file id>/).
 */
class MediaFolders extends DefaultPathGenerator
{
    /** program / governorate / request number, safe for any file system. */
    public static function folderFor(MediaRequest $r): string
    {
        $r->loadMissing(['program', 'city']);
        $part = fn (?string $code, ?string $slug) => self::safe(trim(($code ?? '').'_'.($slug ?? ''), '_')) ?: '000_NONE';

        return $part($r->program?->code, $r->program?->slug).'/'.$part($r->city?->code, $r->city?->slug).'/'.(self::safe((string) $r->reference) ?: 'request-'.$r->id);
    }

    protected function getBasePath(Media $media): string
    {
        $folder = (string) $media->getCustomProperty('folder', '');

        return $folder !== '' ? trim($folder, '/').'/'.$media->getKey() : parent::getBasePath($media);
    }

    private static function safe(string $name): string
    {
        return trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '', '._');
    }
}
