<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Release;
use App\Services\ReleasePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The newest release is always published; an older one stays published
 * only while an installation still runs it.
 */
class ReleasePublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_the_newest_and_unpublishes_old_releases_nobody_runs(): void
    {
        Release::factory()->create(['version' => '1.0.9', 'is_published' => true]);
        Release::factory()->create(['version' => '1.0.10', 'is_published' => true]);
        Release::factory()->create(['version' => '1.0.11', 'is_published' => true]);
        // Newest by semantic version, not by string order ("1.0.12" < "1.0.9").
        Release::factory()->create(['version' => '1.0.12', 'is_published' => false]);

        LicenseActivation::factory()->create(['app_version' => '1.0.10']);

        $changes = app(ReleasePublisher::class)->sync('mie');

        $this->assertSame(['published' => ['1.0.12'], 'unpublished' => ['1.0.11', '1.0.9']], $changes);
        $this->assertSame(['1.0.10', '1.0.12'], $this->publishedVersions());
    }

    public function test_an_old_unpublished_release_is_not_republished_for_its_installations(): void
    {
        Release::factory()->create(['version' => '1.0.1', 'is_published' => false]);
        Release::factory()->create(['version' => '1.0.2', 'is_published' => true]);
        LicenseActivation::factory()->create(['app_version' => '1.0.1']);

        app(ReleasePublisher::class)->sync('mie');

        $this->assertSame(['1.0.2'], $this->publishedVersions());
    }

    public function test_installations_of_another_product_do_not_keep_a_release_published(): void
    {
        Release::factory()->create(['version' => '1.0.1', 'is_published' => true]);
        Release::factory()->create(['version' => '1.0.2', 'is_published' => true]);
        LicenseActivation::factory()->create([
            'license_id' => License::factory()->create(['product' => 'other']),
            'app_version' => '1.0.1',
        ]);

        app(ReleasePublisher::class)->sync('mie');

        $this->assertSame(['1.0.2'], $this->publishedVersions());
    }

    public function test_the_last_installation_leaving_an_old_release_unpublishes_it(): void
    {
        Release::factory()->create(['version' => '1.0.1', 'is_published' => true]);
        Release::factory()->create(['version' => '1.0.2', 'is_published' => true]);
        $license = License::factory()->withKey('MIE-AAAAA-BBBBB-CCCCC-DDDDD')->create();
        LicenseActivation::factory()->create([
            'license_id' => $license->id,
            'fingerprint' => 'install-1',
            'app_version' => '1.0.1',
        ]);

        $this->postJson('/api/v1/license/verify', [
            'license_key' => 'MIE-AAAAA-BBBBB-CCCCC-DDDDD',
            'fingerprint' => 'install-1',
            'app_version' => '1.0.2',
        ])->assertOk()->assertJson(['valid' => true, 'latest_version' => '1.0.2']);

        $this->assertSame(['1.0.2'], $this->publishedVersions());
    }

    public function test_the_command_reports_what_changed(): void
    {
        Release::factory()->create(['version' => '1.0.1', 'is_published' => true]);
        Release::factory()->create(['version' => '1.0.2', 'is_published' => false]);

        $this->artisan('releases:sync-published')
            ->expectsOutput('Published: 1.0.2')
            ->expectsOutput('Unpublished: 1.0.1')
            ->assertSuccessful();
    }

    /**
     * @return list<string>
     */
    private function publishedVersions(): array
    {
        return Release::query()->where('is_published', true)->orderBy('id')->pluck('version')->all();
    }
}
