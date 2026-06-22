<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'key'         => 'support_whatsapp',
                'value'       => '+5493764794897',
                'description' => 'Número de WhatsApp de soporte',
            ],
            [
                'key'         => 'support_email',
                'value'       => 'nailsmanagerpro.app@gmail.com',
                'description' => 'Email de soporte',
            ],
            [
                'key'         => 'subscription_warning_days',
                'value'       => '15',
                'description' => 'Días antes del vencimiento para mostrar el aviso en la app',
            ],
        ];

        foreach ($settings as $setting) {
            \App\Models\Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting,
            );
        }
    }
}
