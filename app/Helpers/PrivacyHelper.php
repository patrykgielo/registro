<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Privacy Helper
 *
 * GDPR-compliant PII (Personally Identifiable Information) masking utilities.
 * Used to protect sensitive data in logs, error messages, and external systems.
 */
class PrivacyHelper
{
    /**
     * Mask phone number for GDPR compliance.
     *
     * Masks middle digits of phone number while keeping country code and last 2 digits visible.
     *
     * Examples:
     * - +48604555103 → +48***03
     * - +48501234567 → +48***67
     * - +1234567890 → +12***90
     *
     * @param  string|null  $phone  Phone number to mask
     * @return string Masked phone number or original if too short
     */
    public static function maskPhone(?string $phone): string
    {
        if (empty($phone)) {
            return '[empty]';
        }

        // Keep first 3 chars (e.g., +48) and last 2 digits
        $length = mb_strlen($phone);

        if ($length <= 5) {
            // Too short to mask meaningfully
            return substr($phone, 0, 2).'***';
        }

        $prefix = substr($phone, 0, 3);  // +48
        $suffix = substr($phone, -2);     // 03

        return $prefix.'***'.$suffix;
    }

    /**
     * Mask email address for GDPR compliance.
     *
     * Masks username part while keeping domain visible.
     *
     * Examples:
     * - john.doe@example.com → j****e@example.com
     * - admin@example.com → a****n@example.com
     *
     * @param  string|null  $email  Email address to mask
     * @return string Masked email address
     */
    public static function maskEmail(?string $email): string
    {
        if (empty($email)) {
            return '[empty]';
        }

        if (! str_contains($email, '@')) {
            // Invalid email, mask everything except first and last char
            return self::maskString($email);
        }

        [$username, $domain] = explode('@', $email, 2);

        if (strlen($username) <= 2) {
            return $username[0].'***@'.$domain;
        }

        $maskedUsername = $username[0].'****'.substr($username, -1);

        return $maskedUsername.'@'.$domain;
    }

    /**
     * Mask generic string (first char + *** + last char).
     *
     * Examples:
     * - sensitive_data → s***a
     * - token123 → t***3
     *
     * @param  string|null  $string  String to mask
     * @return string Masked string
     */
    public static function maskString(?string $string): string
    {
        if (empty($string)) {
            return '[empty]';
        }

        $length = mb_strlen($string);

        if ($length <= 2) {
            return $string[0].'***';
        }

        return $string[0].'***'.substr($string, -1);
    }

    /**
     * Mask credit card number (show only last 4 digits).
     *
     * Examples:
     * - 4111111111111111 → **** **** **** 1111
     * - 5500000000000004 → **** **** **** 0004
     *
     * @param  string|null  $cardNumber  Card number to mask
     * @return string Masked card number
     */
    public static function maskCardNumber(?string $cardNumber): string
    {
        if (empty($cardNumber)) {
            return '[empty]';
        }

        $cleaned = preg_replace('/\s+/', '', $cardNumber);
        $length = strlen($cleaned);

        if ($length < 4) {
            return '****';
        }

        $lastFour = substr($cleaned, -4);

        return '**** **** **** '.$lastFour;
    }
}
