<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Setting::clearCache();
        $this->actingAs(User::factory()->create());
    }

    public function test_saving_branding_sets_the_panels_name_and_logos(): void
    {
        Livewire::test(Settings::class)
            ->fillForm([
                Branding::NAME => 'JPA License Server',
                Branding::LOGO => UploadedFile::fake()->image('light.png', 200, 60),
                Branding::LOGO_DARK => UploadedFile::fake()->image('dark.png', 200, 60),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $panel = Filament::getPanel('admin');

        $this->assertSame('JPA License Server', $panel->getBrandName());
        $this->assertStringContainsString('/branding/light', (string) $panel->getBrandLogo());
        $this->assertStringContainsString('/branding/dark', (string) $panel->getDarkModeBrandLogo());

        // Served without a public/storage symlink, to guests too (sign-in page).
        auth()->logout();
        $this->get(route('branding.logo', ['variant' => 'light']))->assertOk();
        $this->get(route('branding.logo', ['variant' => 'dark']))->assertOk();
    }

    public function test_without_settings_the_app_name_and_no_logo_are_used(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame(config('app.name'), $panel->getBrandName());
        $this->assertNull($panel->getBrandLogo());
        $this->get(route('branding.logo', ['variant' => 'light']))->assertNotFound();
    }

    public function test_the_dark_logo_falls_back_to_the_light_one(): void
    {
        Storage::disk('public')->put('branding/light.png', 'png');
        Setting::set(Branding::LOGO, 'branding/light.png');

        $this->assertStringContainsString('/branding/light', (string) Branding::darkLogoUrl());
    }

    public function test_replacing_a_logo_deletes_the_old_file(): void
    {
        Storage::disk('public')->put('branding/old.png', 'png');
        Setting::set(Branding::LOGO, 'branding/old.png');

        // As in the browser: remove the current logo, then upload another.
        Livewire::test(Settings::class)
            ->set('data.'.Branding::LOGO, [])
            ->fillForm([Branding::LOGO => UploadedFile::fake()->image('new.png')])
            ->call('save')
            ->assertHasNoFormErrors();

        Storage::disk('public')->assertMissing('branding/old.png');
        $this->assertNotSame('branding/old.png', Setting::get(Branding::LOGO));
        Storage::disk('public')->assertExists(Setting::get(Branding::LOGO));
    }
}
