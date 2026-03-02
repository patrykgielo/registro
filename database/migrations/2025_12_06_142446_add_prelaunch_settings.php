<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $settings = [
            // Contact logo
            ['group' => 'contact', 'key' => 'logo_path', 'value' => ['/images/logo.svg']],
            ['group' => 'contact', 'key' => 'logo_alt', 'value' => ['Registro - Mobilne Myjnie Parowe']],

            // Prelaunch defaults
            ['group' => 'prelaunch', 'key' => 'page_title', 'value' => ['Wkrótce startujemy - Registro']],
            ['group' => 'prelaunch', 'key' => 'heading', 'value' => ['Wkrótce Ruszamy!']],
            ['group' => 'prelaunch', 'key' => 'tagline', 'value' => ['Registro polega na tym, że to my przyjeżdżamy do Ciebie, a nie Ty do Nas!']],
            ['group' => 'prelaunch', 'key' => 'date_label', 'value' => ['Data startu']],
            ['group' => 'prelaunch', 'key' => 'description_1', 'value' => ['Wprowadzamy autorski system rezerwacji mobilnych usług mycia pojazdów oraz detailingu.']],
            ['group' => 'prelaunch', 'key' => 'description_2', 'value' => ['Świadczymy usługi we wskazanej przez Ciebie lokalizacji.']],
            ['group' => 'prelaunch', 'key' => 'contact_heading', 'value' => ['Masz pytania?']],
            ['group' => 'prelaunch', 'key' => 'copyright_text', 'value' => ['Registro. Wszelkie prawa zastrzeżone.']],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['group' => $setting['group'], 'key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove contact logo settings
        Setting::where('group', 'contact')
            ->whereIn('key', ['logo_path', 'logo_alt'])
            ->delete();

        // Remove all prelaunch settings
        Setting::where('group', 'prelaunch')->delete();
    }
};
