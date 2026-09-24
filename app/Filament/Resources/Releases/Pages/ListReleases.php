<?php

namespace App\Filament\Resources\Releases\Pages;

use App\Filament\Resources\Releases\ReleaseResource;
use App\Services\ReleaseIntake;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListReleases extends ListRecords
{
    protected static string $resource = ReleaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Picks up any tag the repository's GitHub Action didn't
            // deliver. New ones arrive unpublished.
            Action::make('syncFromGitHub')
                ->label('Sync from GitHub')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (ReleaseIntake $intake): void {
                    try {
                        $created = $intake->syncFromGitHub();
                    } catch (Throwable $e) {
                        Notification::make()->title('Sync failed')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title($created === 0 ? 'Already up to date' : "{$created} new release(s) added as unpublished")
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
