<?php

namespace App\Http\Controllers;

use App\Models\BetaFeedback;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feedback while the app is being tried out (config media-requests.feedback): anyone signed in
 * may send a rating or a report about their own use; only administrators read them (/feedback)
 * and open their screenshots, which are kept on the private disk.
 */
class BetaFeedbackController extends Controller
{
    /** A screenshot larger than this (decoded) is refused, the note is still kept. */
    private const MAX_SHOT_BYTES = 4 * 1024 * 1024;

    public function store(Request $request): JsonResponse
    {
        abort_unless(config('media-requests.feedback'), 404);

        $data = $request->validate([
            'kind' => ['required', 'in:rating,report'],
            'rating' => ['nullable', 'required_if:kind,rating', 'integer', 'between:1,3'],
            'action' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'url' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:3000', 'required_if:kind,report'],
            'screenshot' => ['nullable', 'string', 'max:6000000'],
            'viewport' => ['nullable', 'string', 'max:20', 'regex:/^\d{2,5}x\d{2,5}$/'],
        ]);

        $feedback = BetaFeedback::create([
            'user_id' => $request->user()->id,
            'kind' => $data['kind'],
            'rating' => $data['kind'] === 'rating' ? (int) $data['rating'] : null,
            'action' => $data['action'] ?? null,
            'url' => $this->path($data['url']),
            'note' => isset($data['note']) ? trim($data['note']) : null,
            'viewport' => $data['viewport'] ?? null,
            'user_agent' => Str::limit((string) $request->userAgent(), 290, ''),
        ]);

        if ($data['kind'] === 'report' && ! empty($data['screenshot'])) {
            $feedback->update(['screenshot' => $this->keepShot($feedback, $data['screenshot'])]);
        }

        return response()->json(['ok' => true]);
    }

    /** A report's screenshot, for administrators only. */
    public function shot(Request $request, BetaFeedback $feedback): Response
    {
        abort_unless(app(MediaTeam::class)->isAdministrator($request->user()), 403);
        abort_unless($feedback->screenshot && Storage::disk('local')->exists($feedback->screenshot), 404);

        return Storage::disk('local')->response($feedback->screenshot, null, [
            'Content-Type' => str_ends_with($feedback->screenshot, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Only the path of a page of this app (the address bar's own site), never another address. */
    private function path(string $url): string
    {
        $parts = parse_url($url);
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');

        return Str::limit($path.(isset($parts['query']) ? '?'.$parts['query'] : ''), 490, '');
    }

    /** A JPEG or PNG data URL, checked to be a real image; stored as it came, else nothing. */
    private function keepShot(BetaFeedback $feedback, string $dataUrl): ?string
    {
        if (! preg_match('#^data:image/(jpeg|png);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            return null;
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || strlen($bytes) > self::MAX_SHOT_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }

        $path = 'feedback/'.$feedback->id.'-'.Str::random(12).($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }
}
