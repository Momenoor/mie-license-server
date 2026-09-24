<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Services\ReleaseIntake;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * New releases announced by the app repository — pushed by its GitHub
 * Action, or pulled with "Sync from GitHub" — always arrive unpublished.
 */
class ReleaseIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['releases.api_token' => 'secret-token', 'releases.github_repo' => 'Momenoor/wakeel']);
        Http::preventStrayRequests();
    }

    public function test_a_pushed_tag_creates_an_unpublished_release_with_its_notes(): void
    {
        $this->withToken('secret-token')
            ->postJson('/api/v1/releases', ['version' => 'v1.2.0', 'notes' => "- Added dashboard filters\n- Fixed updater"])
            ->assertCreated()
            ->assertJson(['version' => '1.2.0', 'is_published' => false, 'created' => true]);

        $release = Release::query()->sole();
        $this->assertSame('1.2.0', $release->version);
        $this->assertSame('mie', $release->product);
        $this->assertFalse($release->is_published);
        $this->assertStringContainsString('Added dashboard filters', $release->notes);
    }

    public function test_the_endpoint_requires_the_shared_token(): void
    {
        $this->postJson('/api/v1/releases', ['version' => '1.2.0'])->assertUnauthorized();
        $this->withToken('wrong')->postJson('/api/v1/releases', ['version' => '1.2.0'])->assertUnauthorized();

        config(['releases.api_token' => null]);
        $this->withToken('')->postJson('/api/v1/releases', ['version' => '1.2.0'])->assertUnauthorized();

        $this->assertSame(0, Release::query()->count());
    }

    public function test_a_non_version_tag_is_rejected(): void
    {
        $this->withToken('secret-token')
            ->postJson('/api/v1/releases', ['version' => 'nightly'])
            ->assertUnprocessable();
    }

    public function test_a_repeated_push_never_unpublishes_or_overwrites_hand_written_notes(): void
    {
        Release::factory()->create(['version' => '1.2.0', 'is_published' => true, 'notes' => 'Written by hand']);

        $this->withToken('secret-token')
            ->postJson('/api/v1/releases', ['version' => 'v1.2.0', 'notes' => 'From commits'])
            ->assertOk()
            ->assertJson(['created' => false, 'is_published' => true]);

        $this->assertSame('Written by hand', Release::query()->sole()->notes);
    }

    public function test_sync_pulls_github_releases_and_bare_tags_with_githubs_dates(): void
    {
        Http::fake([
            'api.github.com/repos/Momenoor/wakeel/releases*' => Http::response([
                ['tag_name' => 'v1.0.13', 'body' => '- Keep PHP handler', 'draft' => false, 'published_at' => '2026-09-25T10:00:00Z'],
                ['tag_name' => 'v1.0.14', 'body' => 'draft', 'draft' => true, 'published_at' => null],
            ]),
            'api.github.com/repos/Momenoor/wakeel/tags*' => Http::response([
                ['name' => 'v1.0.13', 'commit' => ['url' => 'https://api.github.com/repos/Momenoor/wakeel/commits/c13']],
                ['name' => 'v1.0.12', 'commit' => ['url' => 'https://api.github.com/repos/Momenoor/wakeel/commits/c12']],
                ['name' => 'v1.0.11', 'commit' => ['url' => 'https://api.github.com/repos/Momenoor/wakeel/commits/c11']],
                ['name' => 'not-a-release', 'commit' => ['url' => 'https://api.github.com/repos/Momenoor/wakeel/commits/x']],
            ]),
            'api.github.com/repos/Momenoor/wakeel/commits/c11' => Http::response(['commit' => ['committer' => ['date' => '2026-09-24T08:30:00Z']]]),
        ]);

        Release::factory()->create(['version' => '1.0.12', 'is_published' => true]);

        $this->assertSame(2, app(ReleaseIntake::class)->syncFromGitHub());

        // A GitHub release: its notes and GitHub's publish date.
        $fromRelease = Release::query()->where('version', '1.0.13')->sole();
        $this->assertFalse($fromRelease->is_published);
        $this->assertSame('- Keep PHP handler', $fromRelease->notes);
        $this->assertSame('2026-09-25 10:00:00', $fromRelease->released_at->utc()->toDateTimeString());

        // A bare tag: dated by its commit.
        $fromTag = Release::query()->where('version', '1.0.11')->sole();
        $this->assertSame('2026-09-24 08:30:00', $fromTag->released_at->utc()->toDateTimeString());

        // Already-recorded tags cost no extra request; drafts and
        // non-version tags are skipped; existing releases stay published.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'commits/c12'));
        $this->assertSame(3, Release::query()->count());
        $this->assertTrue(Release::query()->where('version', '1.0.12')->sole()->is_published);
    }

    public function test_dates_are_stored_in_utc_and_shown_in_local_time(): void
    {
        // The same moment, sent with an offset instead of Z.
        $this->withToken('secret-token')
            ->postJson('/api/v1/releases', ['version' => '1.2.0', 'released_at' => '2026-09-25T00:25:53+04:00'])
            ->assertCreated();

        $release = Release::query()->sole();
        $this->assertSame('2026-09-24 20:25:53', $release->getRawOriginal('released_at'));

        // What the admin panel's date columns render.
        $this->assertSame('Asia/Dubai', FilamentTimezone::get());
        $this->assertSame('2026-09-25 00:25', $release->released_at->setTimezone(FilamentTimezone::get())->format('Y-m-d H:i'));
    }

    public function test_githubs_date_replaces_a_fallback_date_on_an_existing_release(): void
    {
        Release::factory()->create(['version' => '1.2.0', 'released_at' => '2026-01-01 00:00:00']);

        $this->withToken('secret-token')
            ->postJson('/api/v1/releases', ['version' => 'v1.2.0', 'released_at' => '2026-09-25T10:00:00Z'])
            ->assertOk();

        $this->assertSame('2026-09-25 10:00:00', Release::query()->sole()->released_at->utc()->toDateTimeString());
    }
}
