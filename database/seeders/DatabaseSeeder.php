<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Call the AccessControl module's seeder
        $this->call(\Modules\AccessControl\Database\Seeders\AccessControlDatabaseSeeder::class);
        
        // Call the Billing module's plan seeder
        $this->call(\Modules\Billing\Database\Seeders\PlanSeeder::class);
    }
}
