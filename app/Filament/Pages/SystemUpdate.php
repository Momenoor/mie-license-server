<?php

namespace App\Filament\Pages;

use App\Services\SelfUpdater;
use App\Support\AppVersion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Renderless;
use Throwable;

/**
 * Updates this license server to its repository's newest vX.Y.Z tag — one
 * step per Livewire call, chained from the view (see SelfUpdater).
 */
class SystemUpdate extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'System Update';

    protected static ?string $title = 'System Update';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.system-update';

    /**
     * The running (or failed) update, if any — public so the view's step
     * loop can read it.
     *
     * @var array{version: string, completed: list<string>, failed: bool, log: string}|null
     */
    public ?array $updateState = null;

    /**
     * @var array{version: string, notes: string|null, url: string|null}|null
     */
    public ?array $latest = null;

    public ?string $checkError = null;

    public function mount(): void
    {
        $this->updateState = app(SelfUpdater::class)->state();
        $this->check(fresh: false);
    }

    public function getCurrentVersion(): ?string
    {
        return AppVersion::current();
    }

    public function isUpdateAvailable(): bool
    {
        $current = AppVersion::current();

        return $this->latest !== null && ($current === null || version_compare($this->latest['version'], $current, '>'));
    }

    /**
     * Renderless: after the dependencies step replaces vendor/, this same
     * PHP process must not go on to render the page; refreshState() does,
     * in a fresh request.
     *
     * @return 'ran'|'stopped'|'busy'
     */
    #[Renderless]
    public function runNextStep(): string
    {
        return app(SelfUpdater::class)->runNextStepIfIdle();
    }

    public function refreshState(): void
    {
        $this->updateState = app(SelfUpdater::class)->state();

        if ($this->updateState === null) {
            $this->check(fresh: false);
        }
    }

    public function retryStep(): void
    {
        app(SelfUpdater::class)->retry();
        $this->updateState = app(SelfUpdater::class)->state();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label('Check for updates')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->disabled(fn (): bool => $this->updateState !== null)
                ->action(function (): void {
                    $this->check(fresh: true);

                    Notification::make()
                        ->title(match (true) {
                            $this->checkError !== null => 'Could not check: '.$this->checkError,
                            $this->isUpdateAvailable() => "Version {$this->latest['version']} is available.",
                            default => 'You are on the latest version.',
                        })
                        ->color($this->checkError === null ? 'success' : 'danger')
                        ->send();
                }),

            Action::make('update')
                ->label(fn (): string => 'Update to '.($this->latest['version'] ?? ''))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => $this->updateState === null && $this->isUpdateAvailable())
                ->requiresConfirmation()
                ->modalHeading('Update the license server?')
                ->modalDescription('The license API may miss a few check-ins while dependencies install; installations keep running on their grace period. Take a database backup first.')
                ->modalSubmitActionLabel('Update now')
                ->action(function (): void {
                    app(SelfUpdater::class)->start($this->latest['version']);
                    $this->updateState = app(SelfUpdater::class)->state();
                }),

            Action::make('abandon')
                ->label('Cancel update')
                ->color('danger')
                ->visible(fn (): bool => ($this->updateState['failed'] ?? false) === true)
                ->requiresConfirmation()
                ->modalDescription('If the update failed after downloading the new version, parts of it may not work until an update completes.')
                ->action(function (): void {
                    app(SelfUpdater::class)->abandon();
                    $this->updateState = null;
                }),
        ];
    }

    private function check(bool $fresh): void
    {
        try {
            $this->latest = app(SelfUpdater::class)->latestRelease($fresh);
            $this->checkError = null;
        } catch (Throwable $e) {
            $this->checkError = $e->getMessage();
        }
    }
}
