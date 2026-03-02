<?php

namespace Database\Seeders;

use App\Enums\TemplateKey;
use App\Models\ReminderConfig;
use Illuminate\Database\Seeder;

class ReminderConfigSeeder extends Seeder
{
    /**
     * Seed the default reminder configurations.
     *
     * This matches the current hardcoded system:
     * - 24h reminder (SMS + Email)
     * - 2h reminder (SMS + Email)
     * - Follow-up after appointment (SMS + Email)
     */
    public function run(): void
    {
        $configs = [
            // 24h Reminders
            [
                'name' => 'Przypomnienie SMS 24h przed wizytą',
                'channel' => 'sms',
                'trigger_type' => 'before',
                'trigger_hours' => 24,
                'trigger_minutes' => 0,
                'window_buffer_minutes' => 60,
                'template_key' => TemplateKey::APPOINTMENT_REMINDER_24H->value,
                'enabled' => true,
                'priority' => 10,
            ],
            [
                'name' => 'Przypomnienie Email 24h przed wizytą',
                'channel' => 'email',
                'trigger_type' => 'before',
                'trigger_hours' => 24,
                'trigger_minutes' => 0,
                'window_buffer_minutes' => 60,
                'template_key' => TemplateKey::APPOINTMENT_REMINDER_24H->value,
                'enabled' => true,
                'priority' => 11,
            ],

            // 2h Reminders
            [
                'name' => 'Przypomnienie SMS 2h przed wizytą',
                'channel' => 'sms',
                'trigger_type' => 'before',
                'trigger_hours' => 2,
                'trigger_minutes' => 0,
                'window_buffer_minutes' => 60,
                'template_key' => TemplateKey::APPOINTMENT_REMINDER_2H->value,
                'enabled' => true,
                'priority' => 20,
            ],
            [
                'name' => 'Przypomnienie Email 2h przed wizytą',
                'channel' => 'email',
                'trigger_type' => 'before',
                'trigger_hours' => 2,
                'trigger_minutes' => 0,
                'window_buffer_minutes' => 60,
                'template_key' => TemplateKey::APPOINTMENT_REMINDER_2H->value,
                'enabled' => true,
                'priority' => 21,
            ],

            // Follow-ups (24h after appointment)
            [
                'name' => 'Follow-up SMS 24h po wizycie',
                'channel' => 'sms',
                'trigger_type' => 'after',
                'trigger_hours' => 24,
                'trigger_minutes' => 0,
                'window_buffer_minutes' => 60,
                'template_key' => TemplateKey::APPOINTMENT_FOLLOWUP->value,
                'enabled' => true,
                'priority' => 30,
            ],
            [
                'name' => 'Follow-up Email 24h po wizycie',
                'channel' => 'email',
                'trigger_type' => 'after',
                'trigger_hours' => 24,
                'trigger_minutes' => 0,
                'window_buffer_minutes' => 60,
                'template_key' => TemplateKey::APPOINTMENT_FOLLOWUP->value,
                'enabled' => true,
                'priority' => 31,
            ],
        ];

        foreach ($configs as $config) {
            ReminderConfig::updateOrCreate(
                [
                    'channel' => $config['channel'],
                    'trigger_type' => $config['trigger_type'],
                    'trigger_hours' => $config['trigger_hours'],
                    'template_key' => $config['template_key'],
                ],
                $config
            );
        }

        $this->command->info('Created '.count($configs).' reminder configurations.');
    }
}
