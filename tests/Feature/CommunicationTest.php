<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Patient;
use App\Models\PatientFollowup;
use App\Models\Personal;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Support\Communications;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comunicaciones por WhatsApp (wa.me): lista del día con el mensaje listo,
 * registro de lo enviado, plantillas propias, controles programados,
 * inasistencias y saldos vencidos; permisos, plan y aislamiento.
 */
class CommunicationTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Patient $maria;

    private Patient $jorge;

    private Appointment $tomorrow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();
        // Los datos se arman en la hora de la clínica (la web también trabaja así).
        config(['app.timezone' => 'America/La_Paz']);
        date_default_timezone_set('America/La_Paz');

        app(Tenancy::class)->runFor($this->demo->id, function () {
            $ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true]);
            $this->maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'whatsapp' => '591 70011223', 'active' => true,
                'birth_date' => now()->subYears(30)->toDateString()]);
            $this->jorge = Patient::create(['number' => 2, 'first_name' => 'Jorge', 'last_name' => 'Paz', 'active' => true]);   // sin celular
            $start = now()->addDay()->setTime(9, 30);
            $this->tomorrow = Appointment::create(['patient_id' => $this->maria->id, 'personal_id' => $ana->id, 'reason' => 'Limpieza',
                'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'status' => 'agendada']);
        });
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    private function as(?User $user = null)
    {
        $this->flushHeaders();

        return $this->actingAs($user ?? $this->admin)->withSession(['current_company_id' => $this->demo->id]);
    }

    public function test_recordatorio_de_mañana_con_mensaje_listo_y_registro_del_envio(): void
    {
        $this->as()->get(route('communications.index', ['tipo' => 'recordatorio']))->assertOk()
            ->assertSee('María Rojas')->assertSee('09:30')->assertSee('te recordamos tu cita')->assertSee('data-phone="59170011223"', false);

        $this->as()->postJson(route('communications.send'), [
            'kind' => 'recordatorio', 'patient_id' => $this->maria->id, 'appointment_id' => $this->tomorrow->id, 'message' => 'Hola María, mañana 09:30',
        ])->assertOk()->assertJson(['ok' => true]);

        $sent = Communication::allCompanies()->firstOrFail();
        $this->assertSame('59170011223', $sent->recipient);
        $this->assertSame($this->admin->id, $sent->user_id);
        $this->as()->get(route('communications.index', ['tipo' => 'recordatorio']))->assertSee('Enviado hoy');
        $this->as()->get(route('communications.history'))->assertOk()->assertSee('Hola María, mañana 09:30');
        $this->as()->get(route('patients.show', [$this->maria, 'tab' => 'recordatorios']))->assertOk()->assertSee('Hola María, mañana 09:30');
    }

    public function test_plantilla_propia_y_variables(): void
    {
        $this->as()->post(route('communications.templates.save'), ['templates' => [
            'recordatorio' => 'Hola {nombre}: {fecha} a las {hora} con {profesional}. {clinica}',
            'cumpleanos' => '',   // vacío = vuelve al sugerido
        ]])->assertSessionHasNoErrors();

        app(Tenancy::class)->runFor($this->demo->id, function () {
            $text = $this->tomorrow->fresh()->reminderMessage();
            $this->assertStringStartsWith('Hola María: ', $text);
            $this->assertStringContainsString('a las 09:30 con Ana Ruiz.', $text);
            $this->assertStringContainsString('Feliz cumpleaños, María', Communications::render('cumpleanos', $this->maria));
        });
        $this->as()->get(route('communications.templates'))->assertOk()->assertSee('Personalizado');
    }

    public function test_cumpleaños_y_paciente_sin_celular(): void
    {
        $this->as()->get(route('communications.index', ['tipo' => 'cumpleanos']))->assertOk()->assertSee('Cumple 30 años');

        app(Tenancy::class)->runFor($this->demo->id, fn () => $this->jorge->update(['birth_date' => now()->subYears(40)->toDateString()]));
        $this->as()->get(route('communications.index', ['tipo' => 'cumpleanos']))->assertSee('Jorge Paz')->assertSee('Sin celular');
    }

    public function test_control_programado_aparece_el_dia_que_vence_y_se_cierra(): void
    {
        $this->as()->post(route('communications.followups.store', $this->maria), ['reason' => 'Control de 6 meses', 'in' => '+6 months'])
            ->assertSessionHasNoErrors();
        $followup = PatientFollowup::allCompanies()->firstOrFail();
        $this->assertSame(today()->addMonths(6)->toDateString(), $followup->due_date->toDateString());

        // Hoy todavía no toca.
        $this->as()->get(route('communications.index', ['tipo' => 'seguimiento']))->assertDontSee('Control de 6 meses');
        // No se programa en el pasado.
        $this->as()->post(route('communications.followups.store', $this->maria), ['reason' => 'X', 'due_date' => today()->subDay()->toDateString()])
            ->assertSessionHasErrors('due_date');

        $followup->update(['due_date' => today()->subDays(2)]);
        $this->as()->get(route('communications.index', ['tipo' => 'seguimiento']))->assertSee('Control de 6 meses')->assertSee('control de 6 meses. ¿Te agendamos');

        $this->as()->post(route('communications.followups.close', $followup), ['status' => 'hecho'])->assertSessionHasNoErrors();
        $this->assertSame('hecho', $followup->fresh()->status);
        $this->as()->get(route('communications.index', ['tipo' => 'seguimiento']))->assertDontSee('Control de 6 meses');
        $this->as()->get(route('communications.followups', ['estado' => 'hecho']))->assertOk()->assertSee('Control de 6 meses');
    }

    public function test_inasistencia_sin_reagendar_y_saldo_vencido(): void
    {
        app(Tenancy::class)->runFor($this->demo->id, function () {
            $start = now()->subDays(3)->setTime(10, 0);
            Appointment::create(['patient_id' => $this->jorge->id, 'personal_id' => $this->tomorrow->personal_id, 'reason' => 'Control', 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'status' => 'no_asistio']);
            Sale::create(['patient_id' => $this->maria->id, 'number' => 1, 'status' => 'completada', 'subtotal' => 300, 'total' => 300, 'paid' => 100, 'balance' => 200,
                'due_date' => today()->subDays(5)->toDateString()]);

            $queue = Communications::queue();
            $this->assertSame([$this->jorge->id], $queue['inasistencia']->pluck('patient.id')->all());
            $this->assertStringContainsString('Bs 200.00', $queue['saldo']->first()['message']);

            // Si ya reagendó, no hace falta escribirle.
            $start = now()->addDays(2)->setTime(11, 0);
            Appointment::create(['patient_id' => $this->jorge->id, 'personal_id' => $this->tomorrow->personal_id, 'reason' => 'Control', 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'status' => 'agendada']);
            $this->assertTrue(Communications::queue()['inasistencia']->isEmpty());
        });
    }

    public function test_permisos_plan_y_aislamiento(): void
    {
        // El cajero no ve comunicaciones; la recepcionista envía pero no edita los mensajes.
        $cajero = User::create(['name' => 'caj', 'email' => 'caj@clinicademo.test', 'password' => 'Secreta123']);
        $cajero->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'cajero')->value('id'), 'active' => true]);
        $this->as($cajero)->get(route('communications.index'))->assertForbidden();

        $recep = User::create(['name' => 'rec', 'email' => 'rec@clinicademo.test', 'password' => 'Secreta123']);
        $recep->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'recepcionista')->value('id'), 'active' => true]);
        $this->as($recep)->get(route('communications.index'))->assertOk();
        $this->as($recep)->post(route('communications.templates.save'), ['templates' => ['saldo' => 'x']])->assertForbidden();

        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $this->actingAs($sonrisa)->get(route('communications.index'))->assertForbidden();   // plan Consultorio: sin recordatorios
        $sonrisa->companies()->first()->subscription->update(['features_override' => ['recordatorios']]);
        $this->actingAs($sonrisa)->get(route('communications.index'))->assertOk()->assertDontSee('María Rojas');
        // Un paciente de otra clínica no se registra (aunque se adivine el id).
        $this->actingAs($sonrisa)->postJson(route('communications.send'), ['kind' => 'cumpleanos', 'patient_id' => $this->maria->id, 'message' => 'x'])->assertNotFound();
        $this->actingAs($sonrisa)->post(route('communications.followups.store', $this->maria), ['reason' => 'x', 'in' => '+1 week'])->assertNotFound();

        $this->as()->get(route('dashboard'))->assertOk()->assertSee('WhatsApp por enviar');
    }
}
