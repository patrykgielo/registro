<?php

declare(strict_types=1);

namespace App\Filament\Traits;

use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Trait for Filament EditRecord pages that should stay on page after save.
 *
 * Instead of redirecting to list after save, the user stays on the edit page
 * and sees a success notification. This provides WordPress-like "Update" behavior.
 *
 * Features:
 * - Prevents redirect after save (stays on edit page)
 * - Shows Polish success notification
 * - Adds "Save and close" button to return to list
 *
 * Usage:
 *   class EditPage extends EditRecord
 *   {
 *       use StaysOnPageAfterSave;
 *   }
 */
trait StaysOnPageAfterSave
{
    /**
     * Prevent redirect after save - stay on current page.
     */
    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    /**
     * Custom save notification with Polish translation.
     */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Zapisano pomyślnie')
            ->body('Zmiany zostały zapisane. Możesz kontynuować edycję.')
            ->duration(3000);
    }

    /**
     * Add "Save and close" button alongside default save.
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->label('Zapisz'),
            $this->getSaveAndCloseFormAction(),
            $this->getCancelFormAction()
                ->label('Anuluj'),
        ];
    }

    /**
     * Create "Save and close" action that saves and redirects to list.
     */
    protected function getSaveAndCloseFormAction(): Action
    {
        return Action::make('saveAndClose')
            ->label('Zapisz i zamknij')
            ->color('gray')
            ->action(function (): void {
                $this->save();

                $this->redirect($this->getResource()::getUrl('index'));
            });
    }
}
