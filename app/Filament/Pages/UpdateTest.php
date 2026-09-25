<?php

namespace App\Filament\Pages;

use App\Support\AppVersion;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Added in 1.0.7 only to prove the self-updater delivers new code: after
 * updating, this page appearing in the sidebar is the proof. Remove it
 * once confirmed.
 */
class UpdateTest extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Update Test';

    protected static ?string $title = 'Update Test';

    protected static ?int $navigationSort = 101;

    protected string $view = 'filament.pages.update-test';

    public function getVersion(): string
    {
        return AppVersion::current() ?? 'untagged';
    }
}
