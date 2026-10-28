<?php

namespace App\Support\MediaRequests;

use App\Models\City;
use App\Models\MediaRequest;
use App\Models\Program;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Names follow the archive convention, e.g. 002MP_ALP_Certificate_V3_03212026:
 * program code + slug, city slug, what was made, version, MMDDYYYY.
 *
 * The request name is that prefix plus a short name the requester types.
 * The archive name is set at delivery from the prefix and the version.
 */
class MediaNaming
{
    public function itemCode(string $service, string $type, array $answers = []): string
    {
        $a = fn (string $key) => $answers[$key] ?? null;

        if ($service === '000') {
            return 'HCPACK';
        }

        if ($service === '002') {
            return 'Photos';
        }

        if ($service === '003') {
            return 'Video';
        }

        return match ($type) {
            'new' => match ($a('format')) {
                'social' => 'SocialMedia',
                'poster' => 'Poster',
                'brochure' => $a('brochureType') === 'flyer' ? 'Flyer' : 'Brochure',
                'rollup' => $a('rollupSize') === 'custom' ? 'Banner' : 'Rollup',
                'id' => 'ID',
                'cert' => 'Certificate',
                'invite' => 'Invitation',
                'newsletter' => 'Newsletter',
                'cover' => 'Cover',
                'form' => 'Form',
                default => 'Design',
            },
            'edit' => 'Edit',
            'print' => match ($a('printItem')) {
                'flyer' => 'Flyer', 'poster' => 'Poster', 'cert' => 'Certificate', 'card' => 'Card',
                'brochure' => 'Brochure', 'rollup' => 'Rollup', default => 'Print',
            },
            'production' => match ($a('kind')) {
                'plexi' => 'Plexi', 'laser' => 'Laser', 'banner' => 'Banner', 'signage' => 'Signage',
                'fabric' => 'Fabrication', 'stickers' => 'Stickers', 'merch' => 'Merch', default => 'Production',
            },
            default => 'Design',
        };
    }

    public function programCode(?Program $program): string
    {
        if ($program === null) {
            return '___';
        }

        return preg_replace('/\s+/', '', trim((string) $program->code).trim((string) $program->slug)) ?: '___';
    }

    public function prefix(?Program $program, ?City $city, string $itemCode): string
    {
        return $this->programCode($program).'_'.($city?->slug ? trim($city->slug) : '___').'_'.$itemCode;
    }

    public function requestPrefix(MediaRequest $request): string
    {
        $request->loadMissing(['program', 'city']);

        return $this->prefix($request->program, $request->city, $request->item_code);
    }

    public function displayName(MediaRequest $request): string
    {
        if ($request->isPack()) {
            return 'HC_PACK · '.$request->title;
        }

        $title = trim((string) $request->title);

        return $this->requestPrefix($request).($title !== '' ? '_'.$title : '');
    }

    public function archiveName(MediaRequest $request, ?Carbon $at = null): string
    {
        $at ??= now();

        return $this->requestPrefix($request).'_V'.($request->revisions + 1).'_'.$at->format('mdY');
    }

    /** MR-001-26-0001, numbered per service and year. */
    public function nextReference(string $service, ?Carbon $at = null): string
    {
        $at ??= now();
        $stem = 'MR-'.$service.'-'.$at->format('y').'-';

        $last = DB::table('media_requests')
            ->where('reference', 'like', $stem.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $n = $last ? ((int) substr($last, strlen($stem))) + 1 : 1;

        return $stem.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
