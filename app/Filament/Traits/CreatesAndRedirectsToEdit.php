<?php

declare(strict_types=1);

namespace App\Filament\Traits;

use Filament\Notifications\Notification;

/**
 * Trait for Filament CreateRecord pages that redirect to edit after creation.
 *
 * After creating a new record, the user is redirected to the edit page
 * of the newly created record instead of the list page.
 *
 * Features:
 * - Redirects to edit page after successful creation
 * - Shows Polish success notification
 *
 * Usage:
 *   class CreatePage extends CreateRecord
 *   {
 *       use CreatesAndRedirectsToEdit;
 *   }
 */
trait CreatesAndRedirectsToEdit
{
    /**
     * Redirect to edit page of newly created record.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    /**
     * Override form actions with Polish labels.
     *
     * @return array<\Filament\Actions\Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->label('Utwórz'),
            $this->getCreateAnotherFormAction()
                ->label('Utwórz i dodaj kolejny'),
            $this->getCancelFormAction()
                ->label('Anuluj'),
        ];
    }

    /**
     * Custom created notification with Polish translation.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Utworzono pomyślnie')
            ->body('Możesz teraz edytować zawartość.')
            ->duration(3000);
    }
}
