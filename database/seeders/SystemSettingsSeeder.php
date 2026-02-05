<?php

namespace Database\Seeders;

use App\Support\Contracts\Settings\SettingsRepositoryInterface;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(SettingsRepositoryInterface::class);

        $defaults = [
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
        ];

        foreach ($defaults as $key => $value) {
            if (! $settings->has($key)) {
                $settings->set($key, $value);
            }
        }
    }
}
