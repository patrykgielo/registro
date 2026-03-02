<?php

namespace App\Services;

use App\Enums\TemplateKey;
use App\Models\User;
use App\Services\Email\EmailService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileService
{
    public function __construct(
        protected EmailService $emailService
    ) {}

    /**
     * Update user's personal information (name, phone).
     */
    public function updatePersonalInfo(User $user, array $data): User
    {
        $user->update([
            'first_name' => $data['first_name'] ?? $user->first_name,
            'last_name' => $data['last_name'] ?? $user->last_name,
            'phone_e164' => $data['phone_e164'] ?? $user->phone_e164,
        ]);

        return $user->fresh();
    }

    /**
     * Request email change - sends verification to NEW email.
     *
     * @return string Token for verification
     *
     * @throws ValidationException If email is already in use
     */
    public function requestEmailChange(User $user, string $newEmail): string
    {
        // Check if email is already in use
        $exists = User::where('email', $newEmail)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => [__('Ten adres email jest już używany.')],
            ]);
        }

        // Generate token and store pending email
        $token = $user->requestEmailChange($newEmail);

        // Send verification email to NEW email
        $this->emailService->send(
            to: $newEmail,
            templateKey: TemplateKey::EMAIL_CHANGE_VERIFICATION->value,
            variables: [
                'customer_name' => $user->name,
                'verification_url' => route('profile.email.confirm', ['token' => $token]),
                'expires_in' => '24 godziny',
            ],
            metadata: [
                'user_id' => $user->id,
                'type' => 'email_change',
            ],
            language: $user->preferred_language
        );

        // Send notification to OLD email
        $this->emailService->send(
            to: $user->email,
            templateKey: TemplateKey::EMAIL_CHANGE_REQUESTED->value,
            variables: [
                'customer_name' => $user->name,
                'new_email' => $this->maskEmail($newEmail),
                'cancel_url' => route('profile.index').'#security',
            ],
            metadata: [
                'user_id' => $user->id,
                'type' => 'email_change_notification',
            ],
            language: $user->preferred_language
        );

        return $token;
    }

    /**
     * Confirm email change with token.
     */
    public function confirmEmailChange(User $user, string $token): bool
    {
        $oldEmail = $user->email;
        $newEmail = $user->pending_email;

        $success = $user->confirmEmailChange($token);

        if ($success) {
            // Send confirmation to old email
            $this->emailService->send(
                to: $oldEmail,
                templateKey: TemplateKey::EMAIL_CHANGE_COMPLETED->value,
                variables: [
                    'customer_name' => $user->name,
                    'new_email' => $this->maskEmail($newEmail),
                ],
                metadata: [
                    'user_id' => $user->id,
                    'type' => 'email_change_completed',
                ],
                language: $user->preferred_language
            );
        }

        return $success;
    }

    /**
     * Change user's password.
     *
     * @throws ValidationException If current password is wrong
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('Obecne hasło jest nieprawidłowe.')],
            ]);
        }

        $user->update([
            'password' => $newPassword, // Will be hashed by cast
        ]);

        return true;
    }

    /**
     * Update communication preferences (consent toggles).
     */
    public function updateCommunicationPreferences(User $user, array $preferences, ?string $ip = null): User
    {
        // SMS general consent
        if (isset($preferences['sms_consent'])) {
            if ($preferences['sms_consent']) {
                $user->grantSmsConsent($ip);
            } else {
                $user->revokeSmsConsent('manual');
            }
        }

        return $user->fresh();
    }

    /**
     * Request account deletion - sends confirmation email.
     */
    public function requestAccountDeletion(User $user): string
    {
        $token = $user->requestAccountDeletion();

        // Calculate deletion date (7 days grace period)
        $deletionDate = now()->addDays(7)->format('d.m.Y');

        $this->emailService->send(
            to: $user->email,
            templateKey: TemplateKey::ACCOUNT_DELETION_REQUESTED->value,
            variables: [
                'customer_name' => $user->name,
                'deletion_date' => $deletionDate,
                'cancel_url' => route('profile.delete.cancel'),
                'confirm_url' => route('profile.delete.confirm', ['token' => $token]),
            ],
            metadata: [
                'user_id' => $user->id,
                'type' => 'account_deletion',
            ],
            language: $user->preferred_language
        );

        return $token;
    }

    /**
     * Confirm account deletion with token.
     */
    public function confirmAccountDeletion(User $user, string $token): bool
    {
        $email = $user->email;
        $name = $user->name;
        $language = $user->preferred_language;

        $success = $user->confirmAccountDeletion($token);

        if ($success) {
            // Send final confirmation (before anonymization happens)
            $this->emailService->send(
                to: $email,
                templateKey: TemplateKey::ACCOUNT_DELETION_COMPLETED->value,
                variables: [
                    'customer_name' => $name,
                ],
                metadata: [
                    'type' => 'account_deletion_completed',
                ],
                language: $language
            );
        }

        return $success;
    }

    /**
     * Mask email for privacy (show first 2 chars + domain).
     */
    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***@***.***';
        }

        $local = $parts[0];
        $domain = $parts[1];

        $maskedLocal = substr($local, 0, 2).str_repeat('*', max(3, strlen($local) - 2));

        return $maskedLocal.'@'.$domain;
    }
}
