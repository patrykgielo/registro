<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the two templates behind TenantRegistered.
 *
 * Business registration used to send nothing at all -- the machinery existed but
 * was wired only to the end-CUSTOMER flow. These rows are what the new
 * notifications render; without them EmailService::sendFromTemplate() throws and
 * the queued notification lands in failed_jobs with nothing user-visible.
 *
 * A data migration rather than a seeder, matching every other template in this
 * project: seeders are dev-only here, and `db:seed` is forbidden in deploy.
 *
 * Idempotent via insertOrIgnore() on the (key, language) unique constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $templates = [
            [
                'key' => 'tenant-welcome',
                'language' => 'pl',
                'subject' => 'Witamy w {{app_name}} — {{organization_name}} jest gotowa',
                'html_body' => '<h1>Witaj, {{owner_name}}!</h1><p>Firma <strong>{{organization_name}}</strong> została założona i możesz już z niej korzystać.</p><p><strong>Zapisz te adresy — będą Ci potrzebne przy każdym logowaniu:</strong><br>Panel zarządzania: <a href="{{admin_url}}">{{admin_url}}</a><br>Twoja strona: <a href="{{site_url}}">{{site_url}}</a></p><p style="margin: 30px 0;"><a href="{{admin_url}}" style="background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">Przejdź do panelu</a></p><p>Pierwsze kroki: dodaj swoje usługi, ustaw godziny pracy i uzupełnij dane kontaktowe.</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Witaj, {{owner_name}}! Firma {{organization_name}} została założona. Panel zarządzania: {{admin_url}} — Twoja strona: {{site_url}}. Pierwsze kroki: dodaj usługi, ustaw godziny pracy, uzupełnij dane kontaktowe. Zespół {{app_name}}',
                'blade_path' => null,
                'variables' => json_encode(['owner_name', 'organization_name', 'admin_url', 'site_url', 'app_name']),
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'tenant-welcome',
                'language' => 'en',
                'subject' => 'Welcome to {{app_name}} — {{organization_name}} is ready',
                'html_body' => '<h1>Welcome, {{owner_name}}!</h1><p><strong>{{organization_name}}</strong> has been created and is ready to use.</p><p><strong>Save these addresses — you will need them every time you sign in:</strong><br>Admin panel: <a href="{{admin_url}}">{{admin_url}}</a><br>Your site: <a href="{{site_url}}">{{site_url}}</a></p><p style="margin: 30px 0;"><a href="{{admin_url}}" style="background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">Go to the panel</a></p><p>First steps: add your services, set your opening hours and fill in your contact details.</p><p>Best regards,<br>The {{app_name}} team</p>',
                'text_body' => 'Welcome, {{owner_name}}! {{organization_name}} has been created. Admin panel: {{admin_url}} — Your site: {{site_url}}. First steps: add services, set opening hours, fill in contact details. The {{app_name}} team',
                'blade_path' => null,
                'variables' => json_encode(['owner_name', 'organization_name', 'admin_url', 'site_url', 'app_name']),
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'tenant-registered-operator',
                'language' => 'pl',
                'subject' => 'Nowy tenant: {{organization_name}}',
                'html_body' => '<h1>Nowa firma na platformie</h1><p><strong>{{organization_name}}</strong> zarejestrowała się {{registered_at}}.</p><p><strong>Szczegóły:</strong><br>Slug: {{organization_slug}}<br>Właściciel: {{owner_name}}<br>E-mail: {{owner_email}}<br>Strona: <a href="{{site_url}}">{{site_url}}</a></p><p>Wiadomość wygenerowana automatycznie przez {{app_name}}.</p>',
                'text_body' => 'Nowa firma na platformie: {{organization_name}} zarejestrowała się {{registered_at}}. Slug: {{organization_slug}}, Właściciel: {{owner_name}} ({{owner_email}}), Strona: {{site_url}}. Wiadomość automatyczna z {{app_name}}.',
                'blade_path' => null,
                'variables' => json_encode(['organization_name', 'organization_slug', 'owner_name', 'owner_email', 'site_url', 'registered_at', 'app_name']),
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'tenant-registered-operator',
                'language' => 'en',
                'subject' => 'New tenant: {{organization_name}}',
                'html_body' => '<h1>New business on the platform</h1><p><strong>{{organization_name}}</strong> registered on {{registered_at}}.</p><p><strong>Details:</strong><br>Slug: {{organization_slug}}<br>Owner: {{owner_name}}<br>E-mail: {{owner_email}}<br>Site: <a href="{{site_url}}">{{site_url}}</a></p><p>Generated automatically by {{app_name}}.</p>',
                'text_body' => 'New business on the platform: {{organization_name}} registered on {{registered_at}}. Slug: {{organization_slug}}, Owner: {{owner_name}} ({{owner_email}}), Site: {{site_url}}. Generated automatically by {{app_name}}.',
                'blade_path' => null,
                'variables' => json_encode(['organization_name', 'organization_slug', 'owner_name', 'owner_email', 'site_url', 'registered_at', 'app_name']),
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('email_templates')->insertOrIgnore($templates);
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->whereIn('key', ['tenant-welcome', 'tenant-registered-operator'])
            ->delete();
    }
};
