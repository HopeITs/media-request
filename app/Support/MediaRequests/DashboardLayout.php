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
 * 1x1 up to 4x4 (bigger, a card with its data). The person drags a tile's corner to change it cell by cell.
 *
 * A layout is an ordered list of [key, size, pinned, hidden]. Whatever is
 * stored is cleaned against this catalogue every time it is read, so a card
 * that was removed, a size out of bounds, or a card the person may not use
 * can never come back from a stale or edited layout.
 */
class DashboardLayout
{
    public const COLUMNS = 8;

    /** Widgets: key => [icon, min [cols, rows], max [cols, rows], default [cols, rows]] */
    private const WIDGETS = [
        'late' => ['clock', [3, 1], [12, 3], [12, 1]],      // requests past their needed-by date
        'decide' => ['alert', [3, 2], [12, 6], [4, 3]],     // approvals, checks and answers waiting on you
        'ask' => ['spark', [3, 1], [12, 3], [3, 1]],
        'flow' => ['layers', [4, 3], [12, 6], [8, 3]],   // where every request is, stage by stage (3 rows: icons, counts and names)
        'clock' => ['clock', [2, 1], [12, 6], [2, 2]],
        'waiting' => ['arrow', [2, 2], [12, 8], [4, 6]],
        'calendar' => ['cal', [3, 3], [12, 8], [4, 4]],
        'notes' => ['edit', [2, 1], [12, 8], [4, 2]],
        'team' => ['users', [2, 2], [12, 8], [4, 2]],
        'pack' => ['box', [2, 1], [12, 4], [2, 2]],
        'proof' => ['check', [3, 3], [12, 8], [4, 4]],   // the proofreader, for everyone
        'showcase' => ['image', [3, 2], [12, 8], [4, 3]],   // a slideshow of the latest designs delivered
        'mine' => ['inbox', [3, 2], [12, 8], [4, 4]],
        'bell' => ['bell', [3, 2], [12, 8], [4, 2]],
    ];

    /** App shortcuts: key => [icon, route name] */
    private const APPS = [
        'app.new' => ['plus', null],
        'app.my' => ['inbox', 'media-requests.index'],
        'app.pack' => ['box', 'media-requests.pack'],
        'app.generator' => ['wand', null],
        'app.board' => ['board', 'media-requests.board'],
        'app.approvals' => ['shield', 'media-requests.approvals'],
        'app.drive' => ['layers', 'media-requests.drive'],
        'app.stats' => ['chart', 'media-requests.stats'],
        'app.log' => ['list', 'media-requests.log'],
        'app.assistant' => ['spark', 'media-requests.assistant'],
        'app.bell' => ['bell', 'notifications.index'],
        'app.people' => ['users', 'people.index'],
    ];

    /**
     * Ready layouts, each laid out by hand to tile 8 columns with no gap (a wider screen
     * grows the cards into the extra columns). Anyone may pick one in Customise and then
     * change it as they like; a new screen, and "Reset", start from the one for what the
     * person does (KIND). A card the person may not use is left out. Only what is listed
     * shows; everything else waits in "Add a card". No shortcut to a page the top bar
     * already links to (HC Generator is another site, so it stays).
     */
    public const PRESETS = [
        // Asks for work: their requests and what came back, ways to ask, ready files.
        'simple' => [['ask', '6x1'], ['app.new', '1x1'], ['app.generator', '1x1'], ['decide', '4x3'], ['mine', '4x3'],
            ['bell', '4x2'], ['pack', '4x2'], ['showcase', '4x3'], ['proof', '4x3']],
        // Manages programs: what waits for their approval, and how the requests move.
        'programs' => [['late', '12x1'], ['ask', '6x1'], ['app.new', '1x1'], ['app.generator', '1x1'], ['decide', '4x3'], ['waiting', '4x3'],
            ['flow', '8x3'], ['showcase', '4x3'], ['pack', '4x3']],
        // On the media team: their work, the team, the days ahead, the tools they use
        // (the ask bar too: the team files requests for people who phone or write).
        'team' => [['late', '12x1'], ['ask', '6x1'], ['app.new', '1x1'], ['app.generator', '1x1'], ['waiting', '4x6'], ['decide', '4x3'], ['team', '4x3'],
            ['clock', '4x2'], ['pack', '4x2'], ['calendar', '4x4'], ['proof', '4x4']],
        // Runs the team: the whole flow, who has what, what is late or waits on them.
        'manager' => [['late', '12x1'], ['ask', '6x1'], ['app.new', '1x1'], ['app.generator', '1x1'], ['flow', '8x3'], ['decide', '4x3'], ['team', '4x3'],
            ['waiting', '4x4'], ['calendar', '4x4'], ['clock', '4x2'], ['notes', '4x2']],
    ];

    /** The ready layout a new screen starts from, by what the person does (kind()). */
    private const KIND = ['requester' => 'simple', 'programs' => 'programs', 'team' => 'team', 'manager' => 'manager'];

    /** Earlier named sizes, read from layouts saved before free sizes. */
    private const LEGACY = ['1' => '1x1', 's' => '2x2', 'm' => '4x2', 'l' => '4x4', 'w' => '8x2', 'bar' => '8x1'];

    /**
     * Default order, chosen to tile the grid: what is late on one line; the ask bar with the
     * shortcuts beside it (on screen the bar stretches over whatever the shortcuts leave);
     * then the cards, most needed first. Cards grow into any cells left empty.
     */
    private const ORDER = [
        'late', 'ask', 'app.new', 'app.generator', 'app.my', 'app.pack', 'app.drive', 'app.board', 'app.approvals', 'app.stats', 'app.log', 'app.assistant',
        'app.bell', 'app.people', 'decide', 'flow', 'waiting', 'team', 'mine', 'bell', 'clock', 'calendar', 'pack', 'proof', 'showcase', 'notes',
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

    /**
     * The page a card opens on double-click, when it has one this person may see; null otherwise.
     * Shortcuts open with a single click, so they are not here.
     */
    public function page(User $user, string $key): ?string
    {
        $route = match ($key) {
            'late', 'waiting', 'mine' => 'media-requests.index',
            'decide' => $this->allowed($user, 'app.approvals') ? 'media-requests.approvals' : 'media-requests.index',
            'flow', 'team' => $this->allowed($user, 'app.board') ? 'media-requests.board' : null,
            'bell' => 'notifications.index',
            'pack' => 'media-requests.pack',
            default => null,
        };

        return $route && Route::has($route) ? route($route) : null;
    }

    /** Route the shortcut opens; null for "new request" (opens the form) and HC Generator (another site). */
    public function route(string $key): ?string
    {
        return self::APPS[$key][1] ?? null;
    }

    /** @return array{min: array{0: int, 1: int}, max: array{0: int, 1: int}, card?: array{0: int, 1: int}, full?: array{0: int, 1: int}} */
    public function bounds(string $key): array
    {
        if (isset(self::APPS[$key])) {
            return ['min' => [1, 1], 'max' => [4, 4]];   // bigger, a shortcut becomes a small card with its data
        }

        // Any card can go down to one cell. Below `card` (the smallest it can show itself at) it is an
        // icon that opens the card at `full` (its default size) above the others.
        return ['min' => [1, 1], 'max' => self::WIDGETS[$key][2] ?? [1, 1],
            'card' => self::WIDGETS[$key][1] ?? [1, 1], 'full' => self::WIDGETS[$key][3] ?? [1, 1]];
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
        return $this->preset($user, $this->suggested($user));
    }

    /** The ready layout that fits what this person does. */
    public function suggested(User $user): string
    {
        return self::KIND[$this->kind($user)];
    }

    /**
     * A ready layout for this person: its cards in its order and sizes, then everything else
     * they may use, hidden, so "Add a card" still offers it.
     *
     * @return array<int, array{key: string, size: string, pinned: bool, hidden: bool}>
     */
    public function preset(User $user, string $name): array
    {
        $available = $this->available($user);
        $out = [];

        foreach (self::PRESETS[$name] ?? self::PRESETS['simple'] as [$key, $size]) {
            if (in_array($key, $available, true)) {
                $out[$key] = ['key' => $key, 'size' => $this->size($key, $size), 'pinned' => false, 'hidden' => false] + $this->look($key, null);
            }
        }
        foreach ($available as $key) {
            $out[$key] ??= ['key' => $key, 'size' => $this->defaultSize($key), 'pinned' => false, 'hidden' => true] + $this->look($key, null);
        }

        return array_values($out);
    }

    /** Which default screen fits this person: requester, programs, team or manager. */
    public function kind(User $user): string
    {
        return match (true) {
            $this->team->canActAsMediaManager($user) => 'manager',
            $this->team->isMember($user->profile) => 'team',
            $this->team->managedProgramIds($user->profile) !== [] => 'programs',
            default => 'requester',
        };
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
            ] + (! empty($item['fixed']) ? ['fixed' => true] : [])   // sized by the person: shown at exactly that size, never grown
              + $this->look($key, $item['style'] ?? null);
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
