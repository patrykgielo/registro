<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Seeds all email templates for the application (production reference data).
     * This migration replaces EmailTemplateSeeder for production deployments.
     *
     * Idempotency: Uses insertOrIgnore() with unique constraint on (key, language).
     */
    public function up(): void
    {
        $templates = [
            // 1. User Registered - Welcome Email
            [
                'key' => 'user-registered',
                'language' => 'pl',
                'subject' => 'Witamy w {{app_name}}!',
                'html_body' => '<h1>Witaj {{user_name}}!</h1><p>Dziękujemy za rejestrację w {{app_name}}.</p><p>Twój adres email: <strong>{{user_email}}</strong></p><p>Cieszymy się, że do nas dołączyłeś!</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Witaj {{user_name}}! Dziękujemy za rejestrację w {{app_name}}. Twój adres email: {{user_email}}. Cieszymy się, że do nas dołączyłeś! Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.user-registered-pl',
                'variables' => json_encode(['user_name', 'app_name', 'user_email']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'user-registered',
                'language' => 'en',
                'subject' => 'Welcome to {{app_name}}!',
                'html_body' => '<h1>Hello {{user_name}}!</h1><p>Thank you for registering with {{app_name}}.</p><p>Your email address: <strong>{{user_email}}</strong></p><p>We are excited to have you on board!</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{user_name}}! Thank you for registering with {{app_name}}. Your email address: {{user_email}}. We are excited to have you on board! Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.user-registered-en',
                'variables' => json_encode(['user_name', 'app_name', 'user_email']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 2. Password Reset
            [
                'key' => 'password-reset',
                'language' => 'pl',
                'subject' => 'Resetowanie hasła - {{app_name}}',
                'html_body' => '<h1>Witaj {{user_name}},</h1><p>Otrzymaliśmy prośbę o zresetowanie hasła do Twojego konta.</p><p><a href="{{reset_url}}" style="background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Zresetuj hasło</a></p><p>Jeśli nie prosiłeś o zmianę hasła, zignoruj tę wiadomość.</p><p>Link jest ważny przez {{expires_in}} minut.</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Witaj {{user_name}}, Otrzymaliśmy prośbę o zresetowanie hasła do Twojego konta. Kliknij link: {{reset_url}}. Jeśli nie prosiłeś o zmianę hasła, zignoruj tę wiadomość. Link jest ważny przez {{expires_in}} minut. Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.password-reset-pl',
                'variables' => json_encode(['user_name', 'app_name', 'reset_url', 'expires_in']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'password-reset',
                'language' => 'en',
                'subject' => 'Password Reset - {{app_name}}',
                'html_body' => '<h1>Hello {{user_name}},</h1><p>We received a request to reset your password.</p><p><a href="{{reset_url}}" style="background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Reset Password</a></p><p>If you did not request a password reset, please ignore this email.</p><p>This link is valid for {{expires_in}} minutes.</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{user_name}}, We received a request to reset your password. Click here: {{reset_url}}. If you did not request a password reset, please ignore this email. This link is valid for {{expires_in}} minutes. Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.password-reset-en',
                'variables' => json_encode(['user_name', 'app_name', 'reset_url', 'expires_in']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 3. Appointment Created - Confirmation
            [
                'key' => 'appointment-created',
                'language' => 'pl',
                'subject' => 'Potwierdzenie rezerwacji - {{service_name}}',
                'html_body' => '<h1>Cześć {{customer_name}}!</h1><p>Twoja rezerwacja na usługę <strong>{{service_name}}</strong> została potwierdzona.</p><p><strong>Data:</strong> {{appointment_date}}<br><strong>Godzina:</strong> {{appointment_time}}<br><strong>Lokalizacja:</strong> {{location_address}}</p><p>Do zobaczenia!</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}! Twoja rezerwacja na usługę {{service_name}} została potwierdzona. Data: {{appointment_date}}, Godzina: {{appointment_time}}, Lokalizacja: {{location_address}}. Do zobaczenia! Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.appointment-created-pl',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'location_address', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-created',
                'language' => 'en',
                'subject' => 'Appointment Confirmation - {{service_name}}',
                'html_body' => '<h1>Hello {{customer_name}}!</h1><p>Your appointment for <strong>{{service_name}}</strong> is confirmed.</p><p><strong>Date:</strong> {{appointment_date}}<br><strong>Time:</strong> {{appointment_time}}<br><strong>Location:</strong> {{location_address}}</p><p>See you soon!</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}! Your appointment for {{service_name}} is confirmed. Date: {{appointment_date}}, Time: {{appointment_time}}, Location: {{location_address}}. See you soon! Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.appointment-created-en',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'location_address', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 4. Appointment Rescheduled
            [
                'key' => 'appointment-rescheduled',
                'language' => 'pl',
                'subject' => 'Zmiana terminu rezerwacji - {{service_name}}',
                'html_body' => '<h1>Cześć {{customer_name}},</h1><p>Informujemy, że termin Twojej rezerwacji uległ zmianie.</p><p><strong>Nowy termin:</strong><br>Data: {{appointment_date}}<br>Godzina: {{appointment_time}}<br>Lokalizacja: {{location_address}}</p><p>Jeśli masz pytania, skontaktuj się z nami.</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}, Informujemy, że termin Twojej rezerwacji uległ zmianie. Nowy termin: Data: {{appointment_date}}, Godzina: {{appointment_time}}, Lokalizacja: {{location_address}}. Jeśli masz pytania, skontaktuj się z nami. Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.appointment-rescheduled-pl',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'location_address', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-rescheduled',
                'language' => 'en',
                'subject' => 'Appointment Rescheduled - {{service_name}}',
                'html_body' => '<h1>Hello {{customer_name}},</h1><p>We would like to inform you that your appointment has been rescheduled.</p><p><strong>New schedule:</strong><br>Date: {{appointment_date}}<br>Time: {{appointment_time}}<br>Location: {{location_address}}</p><p>If you have any questions, please contact us.</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}, We would like to inform you that your appointment has been rescheduled. New schedule: Date: {{appointment_date}}, Time: {{appointment_time}}, Location: {{location_address}}. If you have any questions, please contact us. Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.appointment-rescheduled-en',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'location_address', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 5. Appointment Cancelled
            [
                'key' => 'appointment-cancelled',
                'language' => 'pl',
                'subject' => 'Anulowanie rezerwacji - {{service_name}}',
                'html_body' => '<h1>Cześć {{customer_name}},</h1><p>Twoja rezerwacja na usługę <strong>{{service_name}}</strong> została anulowana.</p><p><strong>Szczegóły anulowanej rezerwacji:</strong><br>Data: {{appointment_date}}<br>Godzina: {{appointment_time}}</p><p>Mamy nadzieję, że skorzystasz z naszych usług w przyszłości.</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}, Twoja rezerwacja na usługę {{service_name}} została anulowana. Szczegóły: Data: {{appointment_date}}, Godzina: {{appointment_time}}. Mamy nadzieję, że skorzystasz z naszych usług w przyszłości. Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.appointment-cancelled-pl',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-cancelled',
                'language' => 'en',
                'subject' => 'Appointment Cancelled - {{service_name}}',
                'html_body' => '<h1>Hello {{customer_name}},</h1><p>Your appointment for <strong>{{service_name}}</strong> has been cancelled.</p><p><strong>Cancelled appointment details:</strong><br>Date: {{appointment_date}}<br>Time: {{appointment_time}}</p><p>We hope to see you again in the future.</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}, Your appointment for {{service_name}} has been cancelled. Details: Date: {{appointment_date}}, Time: {{appointment_time}}. We hope to see you again in the future. Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.appointment-cancelled-en',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 6. Appointment Reminder - 24 Hours
            [
                'key' => 'appointment-reminder-24h',
                'language' => 'pl',
                'subject' => 'Przypomnienie: Jutro Twoja rezerwacja - {{service_name}}',
                'html_body' => '<h1>Cześć {{customer_name}}!</h1><p>Przypominamy, że jutro masz zaplanowaną wizytę.</p><p><strong>Szczegóły:</strong><br>Usługa: {{service_name}}<br>Data: {{appointment_date}}<br>Godzina: {{appointment_time}}<br>Lokalizacja: {{location_address}}</p><p>Do zobaczenia!</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}! Przypominamy, że jutro masz zaplanowaną wizytę. Szczegóły: Usługa: {{service_name}}, Data: {{appointment_date}}, Godzina: {{appointment_time}}, Lokalizacja: {{location_address}}. Do zobaczenia! Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.appointment-reminder-24h-pl',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'location_address', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-reminder-24h',
                'language' => 'en',
                'subject' => 'Reminder: Tomorrow is Your Appointment - {{service_name}}',
                'html_body' => '<h1>Hello {{customer_name}}!</h1><p>This is a reminder that you have an appointment scheduled for tomorrow.</p><p><strong>Details:</strong><br>Service: {{service_name}}<br>Date: {{appointment_date}}<br>Time: {{appointment_time}}<br>Location: {{location_address}}</p><p>See you soon!</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}! This is a reminder that you have an appointment scheduled for tomorrow. Details: Service: {{service_name}}, Date: {{appointment_date}}, Time: {{appointment_time}}, Location: {{location_address}}. See you soon! Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.appointment-reminder-24h-en',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'location_address', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 7. Appointment Reminder - 2 Hours
            [
                'key' => 'appointment-reminder-2h',
                'language' => 'pl',
                'subject' => 'Przypomnienie: Za 2 godziny Twoja rezerwacja - {{service_name}}',
                'html_body' => '<h1>Cześć {{customer_name}}!</h1><p>Przypominamy, że za 2 godziny masz zaplanowaną wizytę.</p><p><strong>Szczegóły:</strong><br>Usługa: {{service_name}}<br>Godzina: {{appointment_time}}<br>Lokalizacja: {{location_address}}</p><p>Do zobaczenia wkrótce!</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}! Przypominamy, że za 2 godziny masz zaplanowaną wizytę. Szczegóły: Usługa: {{service_name}}, Godzina: {{appointment_time}}, Lokalizacja: {{location_address}}. Do zobaczenia wkrótce! Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.appointment-reminder-2h-pl',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_time', 'location_address', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-reminder-2h',
                'language' => 'en',
                'subject' => 'Reminder: Your Appointment in 2 Hours - {{service_name}}',
                'html_body' => '<h1>Hello {{customer_name}}!</h1><p>This is a reminder that you have an appointment in 2 hours.</p><p><strong>Details:</strong><br>Service: {{service_name}}<br>Time: {{appointment_time}}<br>Location: {{location_address}}</p><p>See you very soon!</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}! This is a reminder that you have an appointment in 2 hours. Details: Service: {{service_name}}, Time: {{appointment_time}}, Location: {{location_address}}. See you very soon! Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.appointment-reminder-2h-en',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_time', 'location_address', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 8. Appointment Follow-up
            [
                'key' => 'appointment-followup',
                'language' => 'pl',
                'subject' => 'Dziękujemy za skorzystanie z naszych usług!',
                'html_body' => '<h1>Cześć {{customer_name}}!</h1><p>Dziękujemy za skorzystanie z usługi <strong>{{service_name}}</strong>.</p><p>Mamy nadzieję, że jesteś zadowolony z naszej pracy. Jeśli masz chwilę, będziemy wdzięczni za zostawienie opinii.</p><p><a href="{{review_url}}" style="background-color: #2196F3; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Zostaw opinię</a></p><p>Do zobaczenia ponownie!</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}! Dziękujemy za skorzystanie z usługi {{service_name}}. Mamy nadzieję, że jesteś zadowolony z naszej pracy. Jeśli masz chwilę, będziemy wdzięczni za zostawienie opinii: {{review_url}}. Do zobaczenia ponownie! Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.appointment-followup-pl',
                'variables' => json_encode(['customer_name', 'service_name', 'review_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-followup',
                'language' => 'en',
                'subject' => 'Thank You for Choosing Our Services!',
                'html_body' => '<h1>Hello {{customer_name}}!</h1><p>Thank you for using our <strong>{{service_name}}</strong> service.</p><p>We hope you are satisfied with our work. If you have a moment, we would greatly appreciate your feedback.</p><p><a href="{{review_url}}" style="background-color: #2196F3; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Leave a Review</a></p><p>See you again!</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}! Thank you for using our {{service_name}} service. We hope you are satisfied with our work. If you have a moment, we would greatly appreciate your feedback: {{review_url}}. See you again! Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.appointment-followup-en',
                'variables' => json_encode(['customer_name', 'service_name', 'review_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 9. Admin Daily Digest
            [
                'key' => 'admin-daily-digest',
                'language' => 'pl',
                'subject' => 'Dzienny raport rezerwacji - {{date}}',
                'html_body' => '<h1>Raport dzienny</h1><p>Witaj {{admin_name}},</p><p>Oto podsumowanie nowych rezerwacji z dnia <strong>{{date}}</strong>:</p><p><strong>Liczba nowych rezerwacji:</strong> {{appointment_count}}</p><p>{{appointment_list}}</p><p>Zaloguj się do panelu administracyjnego, aby zobaczyć szczegóły.</p><p>Pozdrawiamy,<br>System {{app_name}}</p>',
                'text_body' => 'Raport dzienny. Witaj {{admin_name}}, Oto podsumowanie nowych rezerwacji z dnia {{date}}: Liczba nowych rezerwacji: {{appointment_count}}. {{appointment_list}}. Zaloguj się do panelu administracyjnego, aby zobaczyć szczegóły. Pozdrawiamy, System {{app_name}}',
                'blade_path' => 'emails.admin-daily-digest-pl',
                'variables' => json_encode(['admin_name', 'date', 'appointment_count', 'appointment_list', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'admin-daily-digest',
                'language' => 'en',
                'subject' => 'Daily Appointment Report - {{date}}',
                'html_body' => '<h1>Daily Report</h1><p>Hello {{admin_name}},</p><p>Here is a summary of new appointments for <strong>{{date}}</strong>:</p><p><strong>Number of new appointments:</strong> {{appointment_count}}</p><p>{{appointment_list}}</p><p>Log in to the admin panel to see details.</p><p>Best regards,<br>{{app_name}} System</p>',
                'text_body' => 'Daily Report. Hello {{admin_name}}, Here is a summary of new appointments for {{date}}: Number of new appointments: {{appointment_count}}. {{appointment_list}}. Log in to the admin panel to see details. Best regards, {{app_name}} System',
                'blade_path' => 'emails.admin-daily-digest-en',
                'variables' => json_encode(['admin_name', 'date', 'appointment_count', 'appointment_list', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 10. Email Change Requested
            [
                'key' => 'email-change-requested',
                'language' => 'pl',
                'subject' => 'Żądanie zmiany adresu email - {{app_name}}',
                'html_body' => '<h1>Cześć {{customer_name}},</h1><p>Otrzymaliśmy żądanie zmiany adresu email Twojego konta na: <strong>{{new_email}}</strong></p><p>Jeśli to nie Ty złożyłeś to żądanie, możesz je anulować w ustawieniach profilu:</p><p><a href="{{cancel_url}}" style="background-color: #EF4444; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Anuluj zmianę</a></p><p>Jeśli to Ty, poczekaj na email weryfikacyjny wysłany na nowy adres.</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}, Otrzymaliśmy żądanie zmiany adresu email Twojego konta na: {{new_email}}. Jeśli to nie Ty, anuluj zmianę: {{cancel_url}}. Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.email-change-requested-pl',
                'variables' => json_encode(['customer_name', 'new_email', 'cancel_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'email-change-requested',
                'language' => 'en',
                'subject' => 'Email Change Request - {{app_name}}',
                'html_body' => '<h1>Hello {{customer_name}},</h1><p>We received a request to change your email address to: <strong>{{new_email}}</strong></p><p>If you did not make this request, you can cancel it in your profile settings:</p><p><a href="{{cancel_url}}" style="background-color: #EF4444; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Cancel Change</a></p><p>If this was you, please wait for the verification email sent to the new address.</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}, We received a request to change your email address to: {{new_email}}. If you did not make this request, cancel it here: {{cancel_url}}. Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.email-change-requested-en',
                'variables' => json_encode(['customer_name', 'new_email', 'cancel_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 11. Email Change Verification
            [
                'key' => 'email-change-verification',
                'language' => 'pl',
                'subject' => 'Potwierdź nowy adres email - {{app_name}}',
                'html_body' => '<h1>Cześć {{customer_name}},</h1><p>Aby potwierdzić zmianę adresu email, kliknij poniższy przycisk:</p><p><a href="{{verification_url}}" style="background-color: #22C55E; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Potwierdź adres email</a></p><p>Link jest ważny przez {{expires_in}}.</p><p>Jeśli nie zainicjowałeś tej zmiany, zignoruj tę wiadomość.</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}, Aby potwierdzić zmianę adresu email, kliknij link: {{verification_url}}. Link jest ważny przez {{expires_in}}. Jeśli nie zainicjowałeś tej zmiany, zignoruj tę wiadomość. Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.email-change-verification-pl',
                'variables' => json_encode(['customer_name', 'verification_url', 'expires_in', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'email-change-verification',
                'language' => 'en',
                'subject' => 'Confirm Your New Email Address - {{app_name}}',
                'html_body' => '<h1>Hello {{customer_name}},</h1><p>To confirm your email address change, please click the button below:</p><p><a href="{{verification_url}}" style="background-color: #22C55E; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Confirm Email Address</a></p><p>This link is valid for {{expires_in}}.</p><p>If you did not initiate this change, please ignore this email.</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}, To confirm your email address change, click here: {{verification_url}}. This link is valid for {{expires_in}}. If you did not initiate this change, please ignore this email. Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.email-change-verification-en',
                'variables' => json_encode(['customer_name', 'verification_url', 'expires_in', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 12. Email Change Completed
            [
                'key' => 'email-change-completed',
                'language' => 'pl',
                'subject' => 'Adres email został zmieniony - {{app_name}}',
                'html_body' => '<h1>Cześć {{customer_name}},</h1><p>Twój adres email został pomyślnie zmieniony na: <strong>{{new_email}}</strong></p><p>Jeśli to nie Ty dokonałeś tej zmiany, natychmiast skontaktuj się z nami.</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}, Twój adres email został pomyślnie zmieniony na: {{new_email}}. Jeśli to nie Ty dokonałeś tej zmiany, natychmiast skontaktuj się z nami. Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.email-change-completed-pl',
                'variables' => json_encode(['customer_name', 'new_email', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'email-change-completed',
                'language' => 'en',
                'subject' => 'Email Address Changed - {{app_name}}',
                'html_body' => '<h1>Hello {{customer_name}},</h1><p>Your email address has been successfully changed to: <strong>{{new_email}}</strong></p><p>If you did not make this change, please contact us immediately.</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}, Your email address has been successfully changed to: {{new_email}}. If you did not make this change, please contact us immediately. Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.email-change-completed-en',
                'variables' => json_encode(['customer_name', 'new_email', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 13. Account Deletion Requested
            [
                'key' => 'account-deletion-requested',
                'language' => 'pl',
                'subject' => 'Potwierdzenie żądania usunięcia konta - {{app_name}}',
                'html_body' => '<h1>Cześć {{customer_name}},</h1><p>Otrzymaliśmy żądanie usunięcia Twojego konta.</p><p><strong>Ważne:</strong> Jeśli potwierdzisz usunięcie, Twoje dane zostaną zanonimizowane. Ta operacja jest nieodwracalna.</p><p>Aby potwierdzić usunięcie konta, kliknij poniższy przycisk:</p><p><a href="{{confirm_url}}" style="background-color: #EF4444; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Potwierdź usunięcie konta</a></p><p>Jeśli chcesz zachować swoje konto, możesz anulować żądanie:</p><p><a href="{{cancel_url}}" style="background-color: #6B7280; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Anuluj usunięcie</a></p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}, Otrzymaliśmy żądanie usunięcia Twojego konta. Aby potwierdzić: {{confirm_url}}. Aby anulować: {{cancel_url}}. Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.account-deletion-requested-pl',
                'variables' => json_encode(['customer_name', 'deletion_date', 'confirm_url', 'cancel_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'account-deletion-requested',
                'language' => 'en',
                'subject' => 'Account Deletion Request Confirmation - {{app_name}}',
                'html_body' => '<h1>Hello {{customer_name}},</h1><p>We received a request to delete your account.</p><p><strong>Important:</strong> If you confirm the deletion, your data will be anonymized. This action is irreversible.</p><p>To confirm account deletion, click the button below:</p><p><a href="{{confirm_url}}" style="background-color: #EF4444; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Confirm Account Deletion</a></p><p>If you want to keep your account, you can cancel the request:</p><p><a href="{{cancel_url}}" style="background-color: #6B7280; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Cancel Deletion</a></p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}, We received a request to delete your account. To confirm: {{confirm_url}}. To cancel: {{cancel_url}}. Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.account-deletion-requested-en',
                'variables' => json_encode(['customer_name', 'deletion_date', 'confirm_url', 'cancel_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 14. Account Deletion Completed
            [
                'key' => 'account-deletion-completed',
                'language' => 'pl',
                'subject' => 'Twoje konto zostało usunięte - {{app_name}}',
                'html_body' => '<h1>Do widzenia {{customer_name}},</h1><p>Twoje konto w {{app_name}} zostało usunięte zgodnie z Twoim żądaniem.</p><p>Wszystkie Twoje dane osobowe zostały zanonimizowane zgodnie z wymogami RODO.</p><p>Dziękujemy za korzystanie z naszych usług. Jeśli kiedykolwiek będziesz chciał wrócić, zawsze możesz założyć nowe konto.</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Do widzenia {{customer_name}}, Twoje konto w {{app_name}} zostało usunięte zgodnie z Twoim żądaniem. Wszystkie Twoje dane osobowe zostały zanonimizowane. Dziękujemy za korzystanie z naszych usług. Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => 'emails.account-deletion-completed-pl',
                'variables' => json_encode(['customer_name', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'account-deletion-completed',
                'language' => 'en',
                'subject' => 'Your Account Has Been Deleted - {{app_name}}',
                'html_body' => '<h1>Goodbye {{customer_name}},</h1><p>Your {{app_name}} account has been deleted as per your request.</p><p>All your personal data has been anonymized in accordance with GDPR requirements.</p><p>Thank you for using our services. If you ever want to return, you can always create a new account.</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Goodbye {{customer_name}}, Your {{app_name}} account has been deleted as per your request. All your personal data has been anonymized. Thank you for using our services. Best regards, The {{app_name}} Team',
                'blade_path' => 'emails.account-deletion-completed-en',
                'variables' => json_encode(['customer_name', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 15. Admin Created User - Password Setup
            [
                'key' => 'admin-user-created',
                'language' => 'pl',
                'subject' => 'Ustaw hasło do konta - {{app_name}}',
                'html_body' => '<h1>Witaj {{user_name}}!</h1><p>Administrator właśnie utworzył dla Ciebie konto w systemie {{app_name}}.</p><p>Aby aktywować konto i zalogować się, musisz ustawić swoje hasło.</p><p style="margin: 30px 0;"><a href="{{setup_url}}" style="background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">Ustaw hasło</a></p><p><strong>Dane dostępu:</strong><br>Email: <code>{{user_email}}</code><br>Link ważny do: {{expires_at}}</p><p style="color: #666; font-size: 14px;">Link jest ważny przez 24 godziny. Po tym czasie wygaśnie i będziesz musiał poprosić o nowy.</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Witaj {{user_name}}! Administrator właśnie utworzył dla Ciebie konto w systemie {{app_name}}. Aby aktywować konto, ustaw swoje hasło: {{setup_url}}. Email: {{user_email}}. Link ważny do: {{expires_at}}. Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => null,
                'variables' => json_encode(['user_name', 'app_name', 'user_email', 'setup_url', 'expires_at']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'admin-user-created',
                'language' => 'en',
                'subject' => 'Set Your Password - {{app_name}}',
                'html_body' => '<h1>Hello {{user_name}}!</h1><p>An administrator has created an account for you in {{app_name}}.</p><p>To activate your account and log in, you must set your password.</p><p style="margin: 30px 0;"><a href="{{setup_url}}" style="background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">Set Your Password</a></p><p><strong>Login details:</strong><br>Email: <code>{{user_email}}</code><br>Link valid until: {{expires_at}}</p><p style="color: #666; font-size: 14px;">This link is valid for 24 hours. After that, you will need to request a new one.</p><p>Best regards,<br>{{app_name}} Team</p>',
                'text_body' => 'Hello {{user_name}}! An administrator has created an account for you in {{app_name}}. Set your password here: {{setup_url}}. Email: {{user_email}}. Link valid until: {{expires_at}}. Best regards, {{app_name}} Team',
                'blade_path' => null,
                'variables' => json_encode(['user_name', 'app_name', 'user_email', 'setup_url', 'expires_at']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // Insert all templates (idempotent - skips duplicates)
        foreach ($templates as $template) {
            DB::table('email_templates')->insertOrIgnore($template);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Deletes all seeded email templates.
     */
    public function down(): void
    {
        $templateKeys = [
            'user-registered',
            'password-reset',
            'appointment-created',
            'appointment-rescheduled',
            'appointment-cancelled',
            'appointment-reminder-24h',
            'appointment-reminder-2h',
            'appointment-followup',
            'admin-daily-digest',
            'email-change-requested',
            'email-change-verification',
            'email-change-completed',
            'account-deletion-requested',
            'account-deletion-completed',
            'admin-user-created',
        ];

        DB::table('email_templates')
            ->whereIn('key', $templateKeys)
            ->delete();
    }
};
