<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Every link on every page opens, for every kind of person, in both languages.
 * Starting from the home screen, each page's links are followed one level at a
 * time; a link may redirect (a requester is sent back to the home screen) but
 * must never fail or be forbidden to someone it was shown to.
 */
class AllLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    public function test_every_link_shown_to_a_person_opens(): void
    {
        $broken = [];
        $visited = 0;

        foreach (['rami', 'nour', 'lina', 'maya', 'fadi', 'sami', 'admin'] as $who) {
            $user = User::where('email', $who.'@example.test')->firstOrFail();

            foreach (['en', 'ar'] as $locale) {
                $seen = [];
                $queue = ['/media-requests/home', '/media-requests/home?bell=1'];

                // Two levels: the pages linked from the home screen, and the pages linked from those.
                for ($depth = 0; $depth < 2 && $queue; $depth++) {
                    $next = [];
                    foreach ($queue as $url) {
                        if (isset($seen[$url]) || count($seen) > 120) {
                            continue;
                        }
                        $seen[$url] = true;
                        $visited++;

                        $response = $this->actingAs($user)->withSession(['locale' => $locale])->get($url);
                        $status = $response->getStatusCode();
                        if ($status >= 400) {
                            $broken[] = "$who/$locale $url -> $status";

                            continue;
                        }
                        if ($status >= 300) {
                            continue;
                        }

                        foreach ($this->links((string) $response->getContent()) as $link) {
                            $next[] = $link;
                        }
                    }
                    $queue = array_values(array_unique($next));
                }
            }
        }

        $this->assertGreaterThan(100, $visited, 'The crawl should reach the pages behind the links');
        $this->assertSame([], $broken, "Links that do not open:\n".implode("\n", $broken));
    }

    /** Internal links on a page, without the ones that change state or leave the app. */
    private function links(string $html): array
    {
        preg_match_all('/href="([^"#][^"]*)"/', $html, $m);
        $base = rtrim(url('/'), '/');

        return collect($m[1])
            ->map(fn ($h) => html_entity_decode($h))
            ->map(fn ($h) => str_starts_with($h, $base) ? substr($h, strlen($base)) : $h)
            ->filter(fn ($h) => str_starts_with($h, '/') && ! str_starts_with($h, '//'))
            ->reject(fn ($h) => preg_match('#^/(logout|lang/|css/|js/|img/|fonts/|livewire|storage/|media-requests/files/)#', $h))
            ->unique()->values()->all();
    }
}
