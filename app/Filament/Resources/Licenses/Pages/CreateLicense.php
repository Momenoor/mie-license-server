<?php

namespace App\Filament\Resources\Licenses\Pages;

use App\Filament\Resources\Licenses\LicenseResource;
use App\Models\License;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * The plaintext key only ever exists for the duration of this one
 * request — generated here, hashed into the record being saved, and
 * shown back to the vendor exactly once via a persistent notification
 * that never auto-dismisses. It is never written to the database or
 * logged anywhere.
 */
class CreateLicense extends CreateRecord
{
    protected static string $resource = LicenseResource::class;

    private string $plaintextKey = '';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->plaintextKey = License::generateKey();

        $data['key_hash'] = License::hashKey($this->plaintextKey);
        $data['key_last_four'] = substr($this->plaintextKey, -4);
        $data['issued_at'] = now();

        return $data;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('License created')
            ->body("Key (shown once, copy it now): {$this->plaintextKey}")
            ->persistent();
    }
}
