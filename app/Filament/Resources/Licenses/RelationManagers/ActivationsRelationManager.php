<?php

namespace App\Filament\Resources\Licenses\RelationManagers;

use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only — activations are only ever created by the license server's
 * own API (`LicenseController::activate()`) when an install checks in,
 * never by hand here. The one thing the vendor can do is free a slot for
 * a client to re-activate on a new/replacement installation.
 */
class ActivationsRelationManager extends RelationManager
{
    protected static string $relationship = 'activations';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('fingerprint')
            ->columns([
                TextColumn::make('fingerprint')
                    ->limit(20)
                    ->searchable(),
                TextColumn::make('domain'),
                TextColumn::make('ip_address')
                    ->label('IP'),
                TextColumn::make('app_version')
                    ->label('Version'),
                TextColumn::make('first_seen_at')
                    ->dateTime(),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([])
            ->recordActions([
                DeleteAction::make()
                    ->label('Deactivate'),
            ])
            ->toolbarActions([]);
    }
}
