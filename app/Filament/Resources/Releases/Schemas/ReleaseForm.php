<?php

namespace App\Filament\Resources\Releases\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class ReleaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('version')
                    ->required()
                    ->maxLength(50)
                    ->regex('/^\d+\.\d+\.\d+$/')
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('product', $get('product')),
                    )
                    ->helperText('e.g. 1.2.0 — must match git tag v1.2.0, which installations check out when updating.'),
                TextInput::make('product')
                    ->required()
                    ->default('mie')
                    ->maxLength(255),
                MarkdownEditor::make('notes')
                    ->label('Release notes')
                    ->columnSpanFull(),
                Toggle::make('is_published')
                    ->label('Published')
                    ->helperText('Installations are offered only published releases. Publish once the git tag is pushed.'),
                DateTimePicker::make('released_at')
                    ->default(now()),
            ]);
    }
}
