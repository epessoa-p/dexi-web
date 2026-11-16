<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. El orden importa.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            PlanSeeder::class,
            CompanySeeder::class,
            SuperAdminSeeder::class,
            SettingSeeder::class,
            FinanceDemoSeeder::class,
            PatientDemoSeeder::class,
            AgendaDemoSeeder::class,
        ]);
    }
}
