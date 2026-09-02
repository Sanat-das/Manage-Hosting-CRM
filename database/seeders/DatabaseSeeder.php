<?php

namespace Database\Seeders;

use Database\Seeders\DummyDataSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            InitialDataSeeder::class,
            AdminLteRbacSeeder::class,
            PaymentGatewaySeeder::class,
            DummyDataSeeder::class,
        ]);
    }
}
