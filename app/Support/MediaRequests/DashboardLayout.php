<?php

namespace App\Support\MediaRequests;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The home screen, phone style: a grid of cells holding widgets (clock,
 * calendar, notes…) and app shortcuts (one-cell icons that open a page).
 *
 * A size is "CxR": columns × rows of grid cells (12 columns on a wide screen,
 * 8 on a computer, 4 on a phone; a tile wider than the grid takes its full width). Each widget may be any size between its own minimum and
 * maximum, so the calendar never gets too small to read; app shortcuts are
 * 1x1 up to 3x3 (a bigger icon). The person drags a tile's corner to change it cell by cell.
 *
 * A layout is an ordered list of [key, size, pinned, hidden]. Whatever is
 * stored is cleaned against this catalogue every time it is read, so a card
 * that was removed, a size out of bounds, or a card the person may not use
 * can never come back from a stale or edited layout.
 */
class DashboardLayout
{
    public const COLUMNS = 8;

    /** Widgets: key => [icon, min [cols, rows], max [cols, rows], default [cols, rows], shown by default] */
    private const WIDGETS = [
        'late' => ['clock', [3, 1], [12, 3], [12, 1], true],      // requests past their needed-by date
        'decide' => ['alert', [3, 2], [12, 6], [4, 3], true],     // approvals, checks and answers waiting on you
        'ask' => ['spark', [3, 1], [12, 3], [3, 1], true],
        'flow' => ['layers', [4, 3], [12, 6], [8, 3], true],   // where every request is, stage by stage (3 rows: icons, counts and names)
        'clock' => ['clock', [2, 1], [12, 6], [2, 2], true],
        'waiting' => ['arrow', [2, 2], [12, 8], [4, 6], true],
        'calendar' => ['cal', [3, 3], [12, 8], [4, 4], true],
        'notes' => ['edit', [2, 1], [12, 8], [4, 2], true],
        'team' => ['users', [2, 2], [12, 8], [4, 2], true],
        'pack' => ['box', [2, 1], [12, 4], [2, 2], true],
        'proof' => ['check', [3, 3], [12, 8], [4, 4], true],   // the proofreader, for everyone
        'showcase' => ['image', [3, 2], [12, 8], [4, 3], true],   // a slideshow of the latest designs delivered
        // Shown by default only to people who just ask for work (they have no other pages).
        'mine' => ['inbox', [3, 2], [12, 8], [4, 4], false],
        'bell' => ['bell', [3, 2], [12, 8], [4, 2], false],
    ];

    /** App shortcuts: key => [icon, route name, shown by default] */
    private const APPS = [
        'app.new' => ['plus', null, true],
        'app.my' => ['inbox', 'media-requests.index', true],
        'app.pack' => ['box', 'media-requests.pack', true],
        'app.generator' => ['wand', null, true],
        'app.board' => ['board', 'media-requests.board', true],
        'app.approvals' => ['shield', 'media-requests.approvals', true],
        'app.drive' => ['layers', 'media-requests.drive', true],
        'app.stats' => ['chart', 'media-requests.stats', false],
        'app.log' => ['list', 'media-requests.log', false],
        'app.assistant' => ['spark', 'media-requests.assistant', false],
        'app.bell' => ['bell', 'notifications.index', false],
        'app.people' => ['users', 'people.index', false],
    ];

    /** Earlier named sizes, read from layouts saved before free sizes. */
    private const LEGACY = ['1' => '1x1', 's' => '2x2', 'm' => '4x2', 'l' => '4x4', 'w' => '8x2', 'bar' => '8x1'];

    /**
     * Default order, chosen to tile the grid: the ask bar with the shortcuts beside it
     * (on screen the bar stretches over whatever the shortcuts leave); clock, HC_PACK and
     * waiting; then calendar beside notes and team. Cards grow into any cells left empty.
     */
    private const ORDER = [
        'late', 'decide', 'ask', 'app.new', 'app.my', 'app.pack', 'app.drive', 'app.generator', 'app.board', 'app.approvals', 'app.stats', 'app.log', 'app.assistant',
        'app.bell', 'app.people', 'flow', 'clock', 'pack', 'showcase', 'proof', 'mine', 'bell', 'waiting', 'calendar', 'notes', 'team',
    ];

    /** Cards that were fixed bars over the grid until people asked to move and resize them. */
    private const ALERTS = ['decide', 'late'];

    public function __construct(private MediaTeam $team) {}

    /** @return array<int, string> keys this person may use, in default order */
    public function available(User $user): array
    {
        return array_values(array_filter(self::ORDER, fn (string $key): bool => $this->allowed($user, $key)));
    }

    public function allowed(User $user, string $key): bool
    {
        $isTeam = $this->team->isMember($user->profile) || $this->team->canActAsMediaManager($user);
        $requesterOnly = $this->team->isRequesterOnly($user);
        $managesPrograms = $this->team->managedProgramIds($user->profile) !== [] || $user->myRole() === 'super-admin';

        if (isset(self::APPS[$key]) && self::APPS[$key][1] !== null && ! Route::has(self::APPS[$key][1])) {
            return false;
        }

        return match ($key) {
            'ask', 'pack', 'app.new', 'app.pack', 'app.generator' => $user->hasActivePermission('media-requests.create'),
            'team' => $isTeam,   // the media manager and the team see who is working on what
            'app.board', 'app.stats' => $isTeam && $user->hasActivePermission('media-requests.work'),
            'app.approvals' => $managesPrograms && $user->hasActivePermission('media-requests.approve'),
            'app.log', 'app.assistant' => $isTeam && $user->hasActivePermission('media-requests.view-log'),
            'app.people' => $user->hasActivePermission('people.manage'),
            // Their requests and notifications live in cards, not on other pages.
            'app.my', 'app.bell' => ! $requesterOnly,
            default => isset(self::WIDGETS[$key]) || isset(self::APPS[$key]),
        };
    }

    public function isApp(string $key): bool
    {
        return isset(self::APPS[$key]);
    }

    public function icon(string $key): string
    {
        return self::WIDGETS[$key][0] ?? self::APPS[$key][0] ?? 'file';
    }

    /** Route the shortcut opens; null for "new request" (opens the form) and HC Generator (another site). */
    public function route(string $key): ?string
    {
        return self::APPS[$key][1] ?? null;
    }

    /** @return array{min: array{0: int, 1: int}, max: array{0: int, 1: int}} */
    public function bounds(string $key): array
    {
        if (isset(self::APPS[$key])) {
            return ['min' => [1, 1], 'max' => [3, 3]];   // a shortcut can be a bigger icon, to fill a gap
        }

        return ['min' => self::WIDGETS[$key][1] ?? [1, 1], 'max' => self::WIDGETS[$key][2] ?? [1, 1]];
    }

    public function canResize(string $key): bool
    {
        return $this->bounds($key)['min'] !== $this->bounds($key)['max'];
    }

    /** "CxR" kept inside the card's bounds; anything unreadable becomes the default. */
    public function size(string $key, mixed $size): string
    {
        $size = is_string($size) ? (self::LEGACY[$size] ?? $size) : null;
        $b = $this->bounds($key);

        if ($size === null || ! preg_match('/^(\d{1,2})x(\d{1,2})$/', $size, $m)) {
            return $this->defaultSize($key);
        }

        $c = max($b['min'][0], min($b['max'][0], (int) $m[1]));
        $r = max($b['min'][1], min($b['max'][1], (int) $m[2]));

        return $c.'x'.$r;
    }

    /** @return array<int, array{key: string, size: string, pinned: bool, hidden: bool}> */
    public function defaults(User $user): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'size' => $this->defaultSize($key),
            'pinned' => false,
            'hidden' => ($key === 'flow' && $this->team->isRequesterOnly($user)) || ! ($key === 'bell' || ($key === 'mine' && $this->team->isRequesterOnly($user))) && ! (self::WIDGETS[$key][4] ?? self::APPS[$key][2] ?? true),
        ] + $this->look($key, null), $this->available($user));
    }

    /**
     * Keep only known, allowed items with sizes inside their bounds; add any
     * new item at the end (hidden if it is not shown by default); pinned items
     * first, keeping their relative order.
     *
     * @return array<int, array{key: string, size: string, pinned: bool, hidden: bool}>
     */
    public function clean(User $user, ?array $stored): array
    {
        if ($stored === null) {
            return $this->defaults($user);
        }

        $available = $this->available($user);
        $out = [];

        foreach ($stored as $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : null;

            if (! is_string($key) || ! in_array($key, $available, true) || isset($out[$key])) {
                continue;
            }

            $out[$key] = [
                'key' => $key,
                'size' => $this->size($key, $item['size'] ?? null),
                'pinned' => (bool) ($item['pinned'] ?? false),
                'hidden' => (bool) ($item['hidden'] ?? false),
            ] + $this->look($key, $item['style'] ?? null);
        }

        $first = [];
        foreach ($this->defaults($user) as $item) {
            // The alerts were above the grid before they were cards: someone who never had them gets them first.
            if (! isset($out[$item['key']]) && in_array($item['key'], self::ALERTS, true)) {
                $first[$item['key']] = $item;

                continue;
            }
            $out[$item['key']] ??= $item;
        }
        $out = $first + $out;

        $out = array_values($out);
        usort($out, fn (array $a, array $b): int => (int) $b['pinned'] <=> (int) $a['pinned']);

        return $out;
    }

    /** Cards that come in more than one look: key => allowed looks, the first is the default. */
    public const LOOKS = ['clock' => ['digital', 'analog']];

    /** @return array{style?: string} */
    private function look(string $key, mixed $style): array
    {
        if (! isset(self::LOOKS[$key])) {
            return [];
        }

        return ['style' => in_array($style, self::LOOKS[$key], true) ? $style : self::LOOKS[$key][0]];
    }

    private function defaultSize(string $key): string
    {
        $d = isset(self::APPS[$key]) ? [1, 1] : (self::WIDGETS[$key][3] ?? [2, 2]);

        return $d[0].'x'.$d[1];
    }
}
