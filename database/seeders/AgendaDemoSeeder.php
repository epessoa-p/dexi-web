<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Cargo;
use App\Models\Company;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleBlock;
use App\Models\Specialty;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Tarifario con precios, dos odontólogos con horario y citas de esta semana
 * (algunas ya en sala de espera) para Clínica Dental Demo.
 */
class AgendaDemoSeeder extends Seeder
{
    /** código => precio en la moneda de la clínica (referencia, editable). */
    private const PRICES = [
        'GEN-01' => 100, 'GEN-02' => 150, 'GEN-03' => 200, 'GEN-04' => 250, 'GEN-05' => 80, 'GEN-06' => 50,
        'OPE-01' => 180, 'OPE-02' => 280, 'OPE-03' => 90, 'OPE-04' => 1200, 'OPE-05' => 450, 'OPE-06' => 1800,
        'END-01' => 700, 'END-02' => 900, 'END-03' => 1200, 'END-04' => 1400, 'END-05' => 350,
        'PER-01' => 400, 'CIR-01' => 200, 'CIR-02' => 400, 'CIR-03' => 900,
        'IMP-01' => 6500, 'PRO-01' => 2500, 'PRO-02' => 1500, 'ORT-01' => 300, 'ORT-02' => 4500, 'ORT-03' => 200,
        'PED-01' => 100, 'RAD-01' => 40, 'RAD-02' => 150,
    ];

    public function run(): void
    {
        $company = Company::where('ruc', '1000000001')->first();
        if (! $company || app()->runningUnitTests()) {
            return;
        }

        // Por consola no corre SetTenant: las horas «de pared» deben ser las de la clínica.
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set(settings()->get('regional.timezone', 'America/La_Paz', $company->id) ?: 'America/La_Paz');

        try {
            app(Tenancy::class)->runFor($company->id, fn () => $this->seed($company));
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    private function seed(Company $company): void
    {
        if (Appointment::exists()) {
            return;
        }

        // Tarifario sugerido, con precios de referencia.
        Procedure::loadSuggested();
        foreach (self::PRICES as $code => $price) {
            Procedure::where('code', $code)->update(['price' => $price]);
        }

        // Dos odontólogos con su horario.
        $cargo = Cargo::firstOrCreate(['company_id' => $company->id, 'name' => 'Odontólogo'], ['role_id' => Role::where('slug', 'odontologo')->value('id'), 'active' => true]);
        $specialty = fn (string $name) => Specialty::where('name', $name)->value('id');
        $ana = Personal::create(['cargo_id' => $cargo->id, 'full_name' => 'Ana Ruiz Salazar', 'phone' => '70100200', 'active' => true,
            'attends_patients' => true, 'specialty_id' => $specialty('Odontología general'), 'agenda_color' => '#0e9f9a']);
        $carlos = Personal::create(['cargo_id' => $cargo->id, 'full_name' => 'Carlos Méndez Lora', 'phone' => '70300400', 'active' => true,
            'attends_patients' => true, 'specialty_id' => $specialty('Endodoncia'), 'agenda_color' => '#7c3aed']);

        foreach ([1, 2, 3, 4, 5] as $day) {
            Schedule::create(['personal_id' => $ana->id, 'weekday' => $day, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
            Schedule::create(['personal_id' => $ana->id, 'weekday' => $day, 'starts_at' => '14:00:00', 'ends_at' => '19:00:00']);
        }
        foreach ([1, 3, 5, 6] as $day) {
            Schedule::create(['personal_id' => $carlos->id, 'weekday' => $day, 'starts_at' => '09:00:00', 'ends_at' => '13:00:00']);
        }
        ScheduleBlock::create(['personal_id' => $carlos->id, 'reason' => 'Congreso de endodoncia',
            'starts_at' => now()->addWeeks(2)->startOfWeek()->setTime(0, 0), 'ends_at' => now()->addWeeks(2)->startOfWeek()->addDays(2)->setTime(23, 59, 59)]);

        // Citas de hoy y de la semana.
        $patients = Patient::orderBy('number')->get()->values();
        $proc = fn (string $code) => Procedure::where('code', $code)->first();
        $today = now()->startOfDay();
        $plan = [
            // [paciente, profesional, procedimiento, día (+n), hora, estado]
            [0, $ana, 'GEN-03', 0, '08:30', 'atendida'],
            [1, $ana, 'GEN-01', 0, '09:30', 'en_espera'],
            [3, $ana, 'OPE-01', 0, '10:30', 'confirmada'],
            [6, $ana, 'GEN-06', 0, '15:00', 'agendada'],
            [4, $carlos, 'END-01', 0, '09:00', 'en_atencion'],
            [5, $carlos, 'RAD-02', 0, '11:00', 'agendada'],
            [2, $ana, 'PED-01', 1, '16:00', 'agendada'],
            [7, $ana, 'GEN-05', 1, '17:00', 'confirmada'],
            [1, $carlos, 'END-02', 2, '09:00', 'agendada'],
            [0, $ana, 'OPE-04', 3, '14:30', 'agendada'],
            [6, $ana, 'GEN-01', -1, '10:00', 'no_asistio'],
        ];

        foreach ($plan as [$patientIndex, $pro, $code, $offset, $time, $status]) {
            $procedure = $proc($code);
            $starts = $today->copy()->addDays($offset)->setTimeFromTimeString($time);
            while ($starts->isWeekend() && $pro->id === $ana->id) {
                $starts->addDay();
            }
            $appointment = Appointment::create([
                'patient_id' => $patients[$patientIndex]->id, 'personal_id' => $pro->id, 'procedure_id' => $procedure?->id,
                'starts_at' => $starts, 'ends_at' => $starts->copy()->addMinutes($procedure?->duration_minutes ?? 30),
                'status' => $status,
                'confirmed_at' => $status === 'confirmada' ? now()->subDay() : null,
                'arrived_at' => in_array($status, ['en_espera', 'en_atencion', 'atendida'], true) ? now()->subMinutes($status === 'en_espera' ? 12 : 40) : null,
                'started_at' => in_array($status, ['en_atencion', 'atendida'], true) ? now()->subMinutes(25) : null,
                'finished_at' => $status === 'atendida' ? now()->subMinutes(5) : null,
            ]);
            $appointment->logs()->create(['action' => 'creada']);
        }
    }
}
