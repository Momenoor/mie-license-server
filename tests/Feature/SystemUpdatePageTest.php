<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemUpdate;
use App\Models\User;
use App\Services\SelfUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SystemUpdatePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        config(['self_update.github_repo' => 'Momenoor/mie-license-server']);
        Http::preventStrayRequests();

        Http::fake([
            'api.github.com/repos/Momenoor/mie-license-server/tags*' => Http::response([
                ['name' => 'v1.0.9'], ['name' => 'v1.0.10'], ['name' => 'nightly'],
            ]),
            'api.github.com/repos/Momenoor/mie-license-server/releases/tags/v1.0.10' => Http::response([
                'body' => '- Self update from GitHub', 'html_url' => 'https://github.com/Momenoor/mie-license-server/releases/tag/v1.0.10',
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        app(SelfUpdater::class)->abandon();
        File::delete(storage_path('app/self-update/live.log'));

        parent::tearDown();
    }

    public function test_it_shows_the_newest_tag_and_its_release_notes(): void
    {
        // Rendered through Livewire: over HTTP, Filament only admits users
        // that implement FilamentUser outside the local environment.
        Livewire::test(SystemUpdate::class)
            ->assertSee('1.0.10')
            ->assertSee('Self update from GitHub')
            ->assertSee('Update available');
    }

    public function test_starting_an_update_records_resumable_state(): void
    {
        Livewire::test(SystemUpdate::class)
            ->callAction('update')
            ->assertSet('updateState.version', '1.0.10')
            ->assertSet('updateState.completed', []);

        $this->assertSame('1.0.10', app(SelfUpdater::class)->state()['version']);
    }

    public function test_a_step_is_never_started_while_another_is_running(): void
    {
        $updater = app(SelfUpdater::class);
        $updater->start('1.0.10');

        $running = Cache::lock('self-update:step', 1800);
        $this->assertTrue($running->get());

        $this->assertSame('busy', $updater->runNextStepIfIdle());
        $this->assertSame([], $updater->state()['completed']);

        $running->release();
    }

    public function test_the_live_output_route_needs_a_signed_in_user(): void
    {
        File::ensureDirectoryExists(storage_path('app/self-update'));
        File::put(storage_path('app/self-update/live.log'), "== Install PHP dependencies ==\n\e[32mInstalling\e[39m\n");

        $this->getJson(route('self-update.live-output'))
            ->assertOk()
            ->assertJson(['output' => "== Install PHP dependencies ==\nInstalling\n"]);

        auth()->logout();
        $this->getJson(route('self-update.live-output'))->assertUnauthorized();
    }
}
