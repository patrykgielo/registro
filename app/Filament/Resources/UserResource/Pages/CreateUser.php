<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Events\AdminCreatedUser;
use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        // Check checkbox instead of empty password
        if ($this->data['send_setup_email'] ?? false) {
            try {
                // Generate password setup token
                $token = $this->record->initiatePasswordSetup();

                // Verify token was actually saved (defense against silent failures)
                $this->record->refresh();
                if (! $this->record->password_setup_token) {
                    throw new \RuntimeException('Token generation failed - token is NULL after save');
                }

                \Log::info('Password setup token generated in afterCreate', [
                    'user_id' => $this->record->id,
                    'email' => $this->record->email,
                    'token_preview' => substr($token, 0, 8).'...',
                    'expires_at' => $this->record->password_setup_expires_at->toIso8601String(),
                ]);

                // Dispatch event to send email
                event(new AdminCreatedUser($this->record));

            } catch (\Exception $e) {
                \Log::error('Failed to initiate password setup in afterCreate', [
                    'user_id' => $this->record->id,
                    'email' => $this->record->email,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Show error to admin (don't fail silently!)
                \Filament\Notifications\Notification::make()
                    ->danger()
                    ->title('Błąd wysyłki emaila')
                    ->body('Użytkownik został utworzony, ale nie udało się wysłać emaila z linkiem. Użyj przycisku "Wyślij email z hasłem".')
                    ->persistent()
                    ->send();
            }
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        if ($this->data['send_setup_email'] ?? false) {
            return 'Użytkownik utworzony - email z linkiem wysłany (ważny 30 minut)';
        }

        return 'Użytkownik utworzony pomyślnie';
    }
}
