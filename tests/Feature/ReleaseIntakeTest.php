<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Services\ReleaseIntake;
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

    public function test_sync_pulls_github_releases_and_bare_tags(): void
    {
        Http::fake([
            'api.github.com/repos/Momenoor/wakeel/releases*' => Http::response([
                ['tag_name' => 'v1.0.13', 'body' => '- Keep PHP handler', 'draft' => false, 'published_at' => '2026-09-25T10:00:00Z'],
                ['tag_name' => 'v1.0.14', 'body' => 'draft', 'draft' => true, 'published_at' => null],
            ]),
            'api.github.com/repos/Momenoor/wakeel/tags*' => Http::response([
                ['name' => 'v1.0.13'],
                ['name' => 'v1.0.12'],
                ['name' => 'not-a-release'],
            ]),
        ]);

        Release::factory()->create(['version' => '1.0.12', 'is_published' => true]);

        $this->assertSame(1, app(ReleaseIntake::class)->syncFromGitHub());

        $new = Release::query()->where('version', '1.0.13')->sole();
        $this->assertFalse($new->is_published);
        $this->assertSame('- Keep PHP handler', $new->notes);
        $this->assertSame(2, Release::query()->count(), 'Drafts and non-version tags are skipped; existing releases untouched.');
        $this->assertTrue(Release::query()->where('version', '1.0.12')->sole()->is_published);
    }
}
