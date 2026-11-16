<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Seeder;

/**
 * Dos clínicas de demostración, para poder probar el aislamiento entre tenants:
 *   - Clínica Dental Demo: plan Clínica en periodo de prueba.
 *   - Clínica Sonrisa:     plan Consultorio, al día.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $demo = $this->company('1000000001', [
            'name' => 'Clínica Dental Demo',
            'address' => 'Av. Principal 123',
            'phone' => '+591 700-00000',
            'email' => 'contacto@clinicademo.test',
            'description' => 'Clínica de demostración del sistema Dexi',
        ]);

        $this->subscribe($demo, 'clinica', [
            'status' => 'trial',
            'trial_ends_at' => now()->addDays(15)->endOfDay(),
        ]);

        $this->branch($demo, 'Consultorio Central', 'C1');
        $this->branch($demo, 'Consultorio Norte', 'C2');

        $sonrisa = $this->company('1000000002', [
            'name' => 'Clínica Sonrisa',
            'address' => 'Calle Los Pinos 45',
            'phone' => '+591 700-11111',
            'email' => 'hola@sonrisa.test',
            'description' => 'Segunda clínica de demostración (aislamiento de datos)',
        ]);

        $this->subscribe($sonrisa, 'consultorio', [
            'status' => 'active',
            'current_period_end' => now()->addMonth()->endOfDay(),
        ]);

        $this->branch($sonrisa, 'Consultorio Sonrisa', 'C1');
    }

    protected function company(string $ruc, array $attributes): Company
    {
        return Company::updateOrCreate(['ruc' => $ruc], $attributes + ['active' => true]);
    }

    protected function subscribe(Company $company, string $planSlug, array $attributes): void
    {
        $plan = Plan::where('slug', $planSlug)->firstOrFail();

        Subscription::updateOrCreate(['company_id' => $company->id], $attributes + [
            'plan_id' => $plan->id,
            'starts_at' => today(),
            'grace_days' => 3,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'notes' => 'Suscripción creada por el seeder.',
        ]);
    }

    protected function branch(Company $company, string $name, string $code): void
    {
        Branch::forCompany($company)->updateOrCreate(
            ['company_id' => $company->id, 'code' => $code],
            ['name' => $name, 'active' => true],
        );
    }
}
