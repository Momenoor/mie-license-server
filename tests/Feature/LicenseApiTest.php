<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\LicenseActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicenseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_succeeds_with_a_valid_key_and_creates_an_activation(): void
    {
        $license = License::factory()->withKey('MIE-AAAAA-BBBBB-CCCCC-DDDDD')->create();

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => 'MIE-AAAAA-BBBBB-CCCCC-DDDDD',
            'fingerprint' => 'install-1',
            'domain' => 'https://client.example',
            'app_version' => '1.0.0',
        ]);

        $response->assertOk()->assertJson(['valid' => true]);

        $this->assertDatabaseHas('license_activations', [
            'license_id' => $license->id,
            'fingerprint' => 'install-1',
            'domain' => 'https://client.example',
        ]);
    }

    public function test_activate_fails_with_the_wrong_key(): void
    {
        License::factory()->withKey('MIE-AAAAA-BBBBB-CCCCC-DDDDD')->create();

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => 'MIE-WRONG-WRONG-WRONG-WRONG',
            'fingerprint' => 'install-1',
        ]);

        $response->assertOk()->assertJson(['valid' => false, 'reason' => 'not_found']);
    }

    public function test_activate_fails_once_max_activations_is_reached(): void
    {
        $license = License::factory()->withKey('MIE-AAAAA-BBBBB-CCCCC-DDDDD')->create(['max_activations' => 1]);
        LicenseActivation::factory()->create(['license_id' => $license->id, 'fingerprint' => 'existing-install']);

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => 'MIE-AAAAA-BBBBB-CCCCC-DDDDD',
            'fingerprint' => 'a-second-install',
        ]);

        $response->assertOk()->assertJson(['valid' => false, 'reason' => 'activation_limit_reached']);
    }

    public function test_activate_re_running_for_the_same_fingerprint_does_not_consume_a_second_slot(): void
    {
        $license = License::factory()->withKey('MIE-AAAAA-BBBBB-CCCCC-DDDDD')->create(['max_activations' => 1]);

        $this->postJson('/api/v1/license/activate', [
            'license_key' => 'MIE-AAAAA-BBBBB-CCCCC-DDDDD',
            'fingerprint' => 'install-1',
        ])->assertOk()->assertJson(['valid' => true]);

        // Same fingerprint again — still only one activation row, and
        // still succeeds despite max_activations being 1.
        $this->postJson('/api/v1/license/activate', [
            'license_key' => 'MIE-AAAAA-BBBBB-CCCCC-DDDDD',
            'fingerprint' => 'install-1',
        ])->assertOk()->assertJson(['valid' => true]);

        $this->assertSame(1, $license->activations()->count());
    }

    public function test_activate_fails_for_a_revoked_license(): void
    {
        License::factory()->withKey('MIE-AAAAA-BBBBB-CCCCC-DDDDD')->create(['status' => 'revoked']);

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => 'MIE-AAAAA-BBBBB-CCCCC-DDDDD',
            'fingerprint' => 'install-1',
        ]);

        $response->assertOk()->assertJson(['valid' => false, 'reason' => 'revoked']);
    }

    public function test_activate_fails_for_an_expired_license(): void
    {
        License::factory()->withKey('MIE-AAAAA-BBBBB-CCCCC-DDDDD')->create(['expires_at' => now()->subDay()]);

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => 'MIE-AAAAA-BBBBB-CCCCC-DDDDD',
            'fingerprint' => 'install-1',
        ]);

        $response->assertOk()->assertJson(['valid' => false, 'reason' => 'expired']);
    }

    public function test_verify_succeeds_for_an_already_activated_install_and_touches_last_seen(): void
    {
        $license = License::factory()->withKey('MIE-AAAAA-BBBBB-CCCCC-DDDDD')->create();
        $activation = LicenseActivation::factory()->create([
            'license_id' => $license->id,
            'fingerprint' => 'install-1',
            'last_seen_at' => now()->subDays(3),
        ]);

        $response = $this->postJson('/api/v1/license/verify', [
            'license_key' => 'MIE-AAAAA-BBBBB-CCCCC-DDDDD',
            'fingerprint' => 'install-1',
        ]);

        $response->assertOk()->assertJson(['valid' => true]);
        $this->assertTrue($activation->fresh()->last_seen_at->isAfter(now()->subMinute()));
    }

    public function test_verify_fails_for_a_fingerprint_that_was_never_activated(): void
    {
        License::factory()->withKey('MIE-AAAAA-BBBBB-CCCCC-DDDDD')->create();

        $response = $this->postJson('/api/v1/license/verify', [
            'license_key' => 'MIE-AAAAA-BBBBB-CCCCC-DDDDD',
            'fingerprint' => 'never-activated',
        ]);

        $response->assertOk()->assertJson(['valid' => false, 'reason' => 'not_activated']);
    }

    public function test_verify_fails_once_the_license_is_suspended(): void
    {
        $license = License::factory()->withKey('MIE-AAAAA-BBBBB-CCCCC-DDDDD')->create();
        LicenseActivation::factory()->create(['license_id' => $license->id, 'fingerprint' => 'install-1']);

        $license->update(['status' => 'suspended']);

        $response = $this->postJson('/api/v1/license/verify', [
            'license_key' => 'MIE-AAAAA-BBBBB-CCCCC-DDDDD',
            'fingerprint' => 'install-1',
        ]);

        $response->assertOk()->assertJson(['valid' => false, 'reason' => 'suspended']);
    }
}
