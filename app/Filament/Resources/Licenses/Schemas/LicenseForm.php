<?php

namespace App\Filament\Resources\Licenses\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LicenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('client_id')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('plan')
                    ->maxLength(255)
                    ->placeholder('Standard'),
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'suspended' => 'Suspended',
                        'revoked' => 'Revoked',
                    ])
                    ->default('active')
                    ->required(),
                TextInput::make('max_activations')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->required(),
                DateTimePicker::make('expires_at')
                    ->helperText('Leave blank for a perpetual license.'),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
