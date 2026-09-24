<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\Branding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * Branding for this admin panel: its name, and a logo for light and for
 * dark mode (the dark one falls back to the light one).
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = 99;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            Branding::NAME => Setting::get(Branding::NAME),
            Branding::LOGO => Setting::get(Branding::LOGO),
            Branding::LOGO_DARK => Setting::get(Branding::LOGO_DARK),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Branding')
                    ->description('Shown in the admin panel header and on the sign-in page.')
                    ->columns(2)
                    ->schema([
                        TextInput::make(Branding::NAME)
                            ->label('Brand name')
                            ->placeholder(config('app.name'))
                            ->helperText('Shown when no logo is set, and in the browser tab. Leave empty to use the app name.')
                            ->maxLength(100)
                            ->columnSpanFull(),
                        FileUpload::make(Branding::LOGO)
                            ->label('Logo (light mode)')
                            ->image()
                            ->disk('public')
                            ->directory(Branding::DIRECTORY)
                            ->maxSize(2048),
                        FileUpload::make(Branding::LOGO_DARK)
                            ->label('Logo (dark mode)')
                            ->helperText('Optional — the light logo is used when empty.')
                            ->image()
                            ->disk('public')
                            ->directory(Branding::DIRECTORY)
                            ->maxSize(2048),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach ([Branding::NAME, Branding::LOGO, Branding::LOGO_DARK] as $key) {
            $new = filled($state[$key] ?? null) ? (string) $state[$key] : null;
            $old = Setting::get($key);

            // A replaced or removed logo's file isn't needed any more.
            if ($key !== Branding::NAME && filled($old) && $old !== $new) {
                Storage::disk('public')->delete($old);
            }

            Setting::set($key, $new);
        }

        Notification::make()->title('Settings saved')->success()->send();

        // The panel header reads the new branding on the next page load.
        $this->redirect(static::getUrl(), navigate: false);
    }
}
