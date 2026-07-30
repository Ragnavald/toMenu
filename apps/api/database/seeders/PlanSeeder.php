<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Plan::firstOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro',
                'price_cents' => 9900,
                'max_products' => 500,
                'allows_online_payment' => true,
            ]
        );
    }
}
