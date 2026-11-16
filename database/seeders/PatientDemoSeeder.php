<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Patient;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Pacientes de ejemplo para Clínica Dental Demo: con y sin alertas médicas,
 * un menor con tutor y uno que cumple años hoy, para lucir la lista y la ficha.
 */
class PatientDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('ruc', '1000000001')->first();
        // Los tests crean sus propios pacientes (numeración desde HC-000001).
        if (! $company || app()->runningUnitTests()) {
            return;
        }

        app(Tenancy::class)->runFor($company->id, function () {
            if (Patient::exists()) {
                return;
            }

            $patients = [
                ['first_name' => 'María Fernanda', 'last_name' => 'Rojas Vaca', 'document_number' => '6234517', 'birth_date' => '1990-03-14', 'gender' => 'F',
                    'phone' => '70012345', 'email' => 'mfrojas@example.com', 'occupation' => 'Contadora', 'referral_source' => 'recomendacion', 'referred_by' => 'Carla Suárez',
                    'allergies' => 'Penicilina', 'conditions' => [], 'blood_type' => 'O+'],
                ['first_name' => 'Jorge Luis', 'last_name' => 'Mendoza Ríos', 'document_number' => '4521876', 'birth_date' => '1958-11-02', 'gender' => 'M',
                    'phone' => '71234567', 'occupation' => 'Jubilado', 'referral_source' => 'paso',
                    'conditions' => ['hipertension', 'anticoagulantes', 'diabetes'], 'medications' => 'Losartán 50 mg, warfarina 5 mg, metformina 850 mg'],
                ['first_name' => 'Valentina', 'last_name' => 'Gutiérrez Paz', 'document_number' => null, 'birth_date' => now()->subYears(9)->subMonths(4)->toDateString(), 'gender' => 'F',
                    'guardian_name' => 'Patricia Paz', 'guardian_relationship' => 'Madre', 'guardian_phone' => '76543210', 'referral_source' => 'redes', 'conditions' => ['asma']],
                ['first_name' => 'Andrés', 'last_name' => 'Salvatierra Núñez', 'document_number' => '7890123', 'birth_date' => now()->subYears(31)->toDateString(), 'gender' => 'M',
                    'phone' => '69912345', 'whatsapp' => '59169912345', 'occupation' => 'Ingeniero', 'referral_source' => 'google', 'conditions' => ['fumador']],
                ['first_name' => 'Lucía', 'last_name' => 'Arteaga Soliz', 'document_number' => '5678901', 'birth_date' => '1995-07-21', 'gender' => 'F',
                    'phone' => '72233445', 'pregnant' => true, 'referral_source' => 'recomendacion', 'insurance' => 'Caja Nacional de Salud'],
                ['first_name' => 'Roberto', 'last_name' => 'Chávez Montaño', 'document_number' => '3344556', 'birth_date' => '1975-01-30', 'gender' => 'M',
                    'phone' => '78812345', 'occupation' => 'Comerciante', 'referral_source' => 'convenio', 'insurance' => 'Seguro Universal'],
                ['first_name' => 'Camila', 'last_name' => 'Ortiz Ribera', 'document_number' => '9988776', 'birth_date' => '2001-09-09', 'gender' => 'F',
                    'phone' => '75566778', 'occupation' => 'Estudiante', 'referral_source' => 'redes', 'notes' => 'Prefiere turnos por la tarde. Ansiedad dental.'],
                ['first_name' => 'Diego', 'last_name' => 'Flores Antelo', 'document_number' => null, 'birth_date' => now()->subYears(15)->toDateString(), 'gender' => 'M',
                    'guardian_name' => 'Ramiro Flores', 'guardian_relationship' => 'Padre', 'guardian_phone' => '70098765', 'referral_source' => 'recomendacion'],
            ];

            foreach ($patients as $i => $data) {
                $hasMedical = ! empty($data['allergies']) || ! empty($data['conditions']) || ! empty($data['pregnant']) || ! empty($data['medications']);
                Patient::create($data + [
                    'number' => $i + 1,
                    'document_type' => 'CI',
                    'active' => true,
                    'medical_updated_at' => $hasMedical ? now()->subDays(10 - $i) : null,
                ]);
            }
        });
    }
}
