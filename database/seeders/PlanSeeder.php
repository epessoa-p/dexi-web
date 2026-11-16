<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Planes semilla por tamaño de clínica dental. Editables luego desde el panel
 * de Planes (y cada clínica puede tener overrides en su suscripción).
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Consultorio',
                'slug' => 'consultorio',
                'description' => 'Para el odontólogo independiente con un solo consultorio.',
                'price' => 19.00,
                'currency' => 'USD',
                'billing_period' => 'monthly',
                'trial_days' => 15,
                'max_users' => 3,
                'max_personal' => 5,
                'max_branches' => 1,
                'features' => ['agenda', 'historia_clinica', 'ventas', 'caja'],
                'active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Clínica',
                'slug' => 'clinica',
                'description' => 'Para clínicas con varios odontólogos y más de una sede.',
                'price' => 49.00,
                'currency' => 'USD',
                'billing_period' => 'monthly',
                'trial_days' => 15,
                'max_users' => 15,
                'max_personal' => 30,
                'max_branches' => 3,
                'features' => ['agenda', 'historia_clinica', 'tratamientos', 'ventas', 'caja', 'finanzas', 'inventario', 'compras', 'recordatorios'],
                'active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Red de clínicas',
                'slug' => 'red',
                'description' => 'Todos los módulos y sin límites de usuarios, personal ni consultorios.',
                'price' => 99.00,
                'currency' => 'USD',
                'billing_period' => 'monthly',
                'trial_days' => 15,
                'max_users' => null,
                'max_personal' => null,
                'max_branches' => null,
                'features' => array_keys(Plan::MODULES),
                'active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
