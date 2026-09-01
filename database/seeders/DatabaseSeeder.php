<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ichava icon seeder, only runs when the ichava package is installed.
        if (class_exists(IchavaSeeder::class)) {
            $this->call(IchavaSeeder::class);
        }
    }
}
