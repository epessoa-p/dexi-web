<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Setting;
use App\Support\SettingDefinitions;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedScope(SettingDefinitions::global(), null);

        foreach (Company::pluck('id') as $companyId) {
            $this->seedScope(SettingDefinitions::company(), $companyId);
        }
    }

    /**
     * Crea las configuraciones que falten con su valor por defecto,
     * sin pisar las que ya fueron ajustadas.
     */
    protected function seedScope(array $definitions, ?int $companyId): void
    {
        foreach ($definitions as $key => $definition) {
            if ($definition['default'] === null) {
                continue;
            }

            Setting::firstOrCreate(
                ['company_id' => $companyId, 'key' => $key],
                [
                    'group' => $definition['group'],
                    'type' => $definition['type'],
                    'value' => Setting::encode($definition['default'], $definition['type']),
                ],
            );
        }
    }
}
