<?php

namespace App\Filament\Resources\Licenses\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LicensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key_last_four')
                    ->label('Key')
                    ->formatStateUsing(fn (string $state): string => "MIE-…-{$state}"),
                TextColumn::make('plan'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        'revoked' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('activations_count')
                    ->counts('activations')
                    ->label('Activations')
                    ->formatStateUsing(fn (mixed $state, $record): string => "{$state} / {$record->max_activations}"),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->placeholder('Perpetual')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'suspended' => 'Suspended',
                        'revoked' => 'Revoked',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('revoke')
                    ->label('Revoke')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => $record->status !== 'revoked')
                    ->action(function ($record): void {
                        $record->update(['status' => 'revoked']);

                        Notification::make()
                            ->title('License revoked')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
