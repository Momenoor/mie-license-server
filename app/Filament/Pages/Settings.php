<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\Branding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\BaseFileUpload;
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
 * Branding for this admin panel: its name, a logo for light and for dark
 * mode (the dark one falls back to the light one), and the favicon.
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
            Branding::FAVICON => Setting::get(Branding::FAVICON),
        ]);
    }

    /**
     * A branding image upload: previews through the `branding.file` route
     * (Filament's default preview URL is /storage/…, which needs a
     * public/storage symlink this server may not have — the field then
     * kept loading), with the image editor for cropping.
     */
    private static function brandingUpload(string $key): FileUpload
    {
        return FileUpload::make($key)
            ->image()
            ->imageEditor()
            ->disk('public')
            ->directory(Branding::DIRECTORY)
            ->maxSize(2048)
            ->getUploadedFileUsing(function (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array {
                $uploaded = $component->getUploadedFile($file, $storedFileNames);

                return $uploaded === null ? null : [...$uploaded, 'url' => route('branding.file', ['path' => $file])];
            });
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
                        static::brandingUpload(Branding::LOGO)
                            ->label('Logo (light mode)')
                            ->imageEditorAspectRatios([null, '4:1', '3:1', '2:1', '1:1']),
                        static::brandingUpload(Branding::LOGO_DARK)
                            ->label('Logo (dark mode)')
                            ->helperText('Optional — the light logo is used when empty.')
                            ->imageEditorAspectRatios([null, '4:1', '3:1', '2:1', '1:1']),
                        static::brandingUpload(Branding::FAVICON)
                            ->label('Favicon')
                            ->helperText('The browser tab icon. A square image, e.g. 64×64 PNG.')
                            ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml'])
                            ->imageEditorAspectRatios(['1:1'])
                            ->maxSize(512),
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

        foreach ([Branding::NAME, Branding::LOGO, Branding::LOGO_DARK, Branding::FAVICON] as $key) {
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
