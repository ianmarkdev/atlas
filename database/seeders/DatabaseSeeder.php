<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Production starts empty: run `php artisan app:install` instead.
     */
    public function run(): void
    {
        if (app()->environment('local')) {
            $this->call(LocalDemoSeeder::class);
        }
    }
}
