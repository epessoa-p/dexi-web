<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleBlock;
use App\Models\User;
use App\Support\Tenancy;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agenda: alta con choques (profesional, paciente, bloqueo, fuera de horario
 * y sobreturno), horas libres, reprogramar con historial, flujo de estados,
 * disponibilidad, plan, aislamiento y API.
 */
class AgendaTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Personal $ana;

    private Personal $luis;

    private Patient $maria;

    private Patient $jorge;

    private Carbon $monday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();
        Carbon::setTestNow(Carbon::parse('next monday 07:00'));
        $this->monday = now()->startOfDay();

        app(Tenancy::class)->runFor($this->demo->id, function () {
            $this->ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true]);
            $this->luis = Personal::create(['full_name' => 'Luis Vaca', 'active' => true, 'attends_patients' => true]);
            foreach ([1, 2, 3, 4, 5] as $day) {
                Schedule::create(['personal_id' => $this->ana->id, 'weekday' => $day, 'starts_at' => '08:00', 'ends_at' => '12:00']);
            }
            $this->maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'active' => true, 'allergies' => 'Penicilina']);
            $this->jorge = Patient::create(['number' => 2, 'first_name' => 'Jorge', 'last_name' => 'Paz', 'active' => true]);
            Procedure::create(['code' => 'END-01', 'name' => 'Endodoncia', 'price' => 700, 'duration_minutes' => 60, 'applies_to' => 'pieza']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(?User $user = null)
    {
        $this->flushHeaders();

        return $this->actingAs($user ?? $this->admin)->withSession(['current_company_id' => $this->demo->id]);
    }

    private function book(array $overrides = [])
    {
        return $this->as()->post(route('appointments.store'), $overrides + [
            'patient_id' => $this->maria->id, 'personal_id' => $this->ana->id,
            'date' => $this->monday->toDateString(), 'time' => '09:00', 'duration' => 30,
        ]);
    }

    private function appointment(): Appointment
    {
        return Appointment::allCompanies()->latest('id')->firstOrFail();
    }

    public function test_agendar_usa_la_duracion_del_procedimiento_y_deja_historial(): void
    {
        $procedure = Procedure::allCompanies()->where('code', 'END-01')->firstOrFail();
        $this->book(['procedure_id' => $procedure->id, 'duration' => null])->assertSessionHasNoErrors()
            ->assertRedirect(route('appointments.index', ['date' => $this->monday->toDateString()]));

        $a = $this->appointment();
        $this->assertSame('09:00', $a->starts_at->format('H:i'));
        $this->assertSame('10:00', $a->ends_at->format('H:i'));
        $this->assertSame('agendada', $a->status);
        $this->assertFalse($a->overbooked);
        $this->assertSame('creada', $a->logs()->value('action'));

        $this->as()->get(route('appointments.show', $a))->assertOk()->assertSee('María Rojas')->assertSee('Antes de atender')->assertSee('Cita creada');
    }

    public function test_choques_bloqueos_horario_y_sobreturno(): void
    {
        $this->book()->assertSessionHasNoErrors();

        // Mismo profesional a la misma hora: pide sobreturno; con «force» se agenda marcado.
        $this->book(['patient_id' => $this->jorge->id, 'time' => '09:15'])->assertSessionHasErrors('force');
        $this->book(['patient_id' => $this->jorge->id, 'time' => '09:15', 'force' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($this->appointment()->overbooked);

        // El mismo paciente con otro profesional a la vez: nunca.
        $this->book(['personal_id' => $this->luis->id, 'time' => '09:10', 'force' => 1])->assertSessionHasErrors('time');

        // Fuera del horario de Ana (12:00–): sobreturno.
        $this->book(['patient_id' => $this->jorge->id, 'time' => '15:00'])->assertSessionHasErrors('force');
        // Luis no tiene horario cargado: se puede a cualquier hora.
        $this->book(['patient_id' => $this->jorge->id, 'personal_id' => $this->luis->id, 'time' => '15:00'])->assertSessionHasNoErrors();

        // Bloqueo de la clínica: ni con sobreturno.
        app(Tenancy::class)->runFor($this->demo->id, fn () => ScheduleBlock::create(['reason' => 'Feriado',
            'starts_at' => $this->monday->copy()->addDay(), 'ends_at' => $this->monday->copy()->addDay()->endOfDay()]));
        $this->book(['date' => $this->monday->copy()->addDay()->toDateString(), 'force' => 1])->assertSessionHasErrors('time');

        // Hora pasada: no.
        $this->book(['date' => $this->monday->copy()->subDay()->toDateString()])->assertSessionHasErrors('time');

        // Un profesional que no atiende pacientes no se puede elegir.
        $nurse = app(Tenancy::class)->runFor($this->demo->id, fn () => Personal::create(['full_name' => 'Asistente', 'active' => true]));
        $this->book(['personal_id' => $nurse->id])->assertSessionHasErrors('personal_id');
    }

    public function test_horas_libres_respetan_horario_citas_y_duracion(): void
    {
        $this->book(['time' => '09:00', 'duration' => 60])->assertSessionHasNoErrors();

        $slots = $this->as()->getJson(route('appointments.slots', ['personal_id' => $this->ana->id, 'date' => $this->monday->toDateString(), 'duration' => 60]))
            ->assertOk()->assertJsonPath('schedule', ['08:00–12:00'])->json('slots');

        $this->assertSame('08:00', $slots[0]);
        $this->assertNotContains('08:15', $slots); // 08:15–09:15 pisa la cita de las 09:00
        $this->assertContains('10:00', $slots);
        $this->assertSame('11:00', end($slots)); // la última que entra antes de las 12:00

        // Domingo: no atiende.
        $this->as()->getJson(route('appointments.slots', ['personal_id' => $this->ana->id, 'date' => $this->monday->copy()->addDays(6)->toDateString()]))
            ->assertJsonPath('slots', [])->assertJsonPath('has_schedule', true);
    }

    public function test_reprogramar_arrastrando_y_editando_queda_en_el_historial(): void
    {
        $this->book()->assertSessionHasNoErrors();
        $a = $this->appointment();

        $this->as()->patchJson(route('appointments.move', $a), ['start' => $this->monday->toDateString() . 'T10:30:00Z', 'end' => $this->monday->toDateString() . 'T11:30:00Z'])
            ->assertOk();
        $a->refresh();
        $this->assertSame('10:30', $a->starts_at->format('H:i'));
        $this->assertSame(60, $a->duration_minutes);
        $this->assertSame(1, $a->reschedule_count);

        // Arrastrar fuera de horario: 422 pidiendo sobreturno; con force pasa.
        $this->as()->patchJson(route('appointments.move', $a), ['start' => $this->monday->toDateString() . 'T16:00:00'])->assertUnprocessable()->assertJsonValidationErrors('force');
        $this->as()->patchJson(route('appointments.move', $a), ['start' => $this->monday->toDateString() . 'T16:00:00', 'force' => 1])->assertOk();

        $this->as()->put(route('appointments.update', $a), [
            'patient_id' => $this->maria->id, 'personal_id' => $this->luis->id, 'date' => $this->monday->copy()->addDays(2)->toDateString(), 'time' => '10:00', 'duration' => 45,
        ])->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertSame(3, $a->reschedule_count);
        $this->assertSame($this->luis->id, $a->personal_id);
        $this->assertSame(['reprogramada', 'profesional'], $a->logs()->whereIn('action', ['reprogramada', 'profesional'])->orderByDesc('id')->limit(2)->pluck('action')->reverse()->values()->all());
    }

    public function test_flujo_de_estados_sala_de_espera_y_cancelacion(): void
    {
        $this->book()->assertSessionHasNoErrors();
        $a = $this->appointment();

        $this->as()->post(route('appointments.status', $a), ['status' => 'atendida'])->assertSessionHasErrors('status');
        $this->as()->post(route('appointments.status', $a), ['status' => 'en_espera'])->assertSessionHasNoErrors();
        $this->assertNotNull($a->fresh()->arrived_at);

        $this->as()->get(route('appointments.today', ['date' => $this->monday->toDateString()]))->assertOk()->assertSee('En sala de espera')->assertSee('María Rojas');

        $this->as()->postJson(route('appointments.status', $a), ['status' => 'en_atencion'])->assertOk()->assertJsonPath('status', 'en_atencion');
        $this->as()->post(route('appointments.status', $a), ['status' => 'atendida']);
        $a->refresh();
        $this->assertSame('atendida', $a->status);
        $this->assertNotNull($a->finished_at);

        // Cerrada: no se edita ni se arrastra.
        $this->as()->get(route('appointments.edit', $a))->assertForbidden();
        $this->as()->patchJson(route('appointments.move', $a), ['start' => $this->monday->toDateString() . 'T11:00:00'])->assertUnprocessable();

        // Cancelar pide motivo y libera el horario.
        $this->book(['patient_id' => $this->jorge->id, 'time' => '10:00'])->assertSessionHasNoErrors();
        $b = $this->appointment();
        $this->as()->post(route('appointments.status', $b), ['status' => 'cancelada'])->assertSessionHasErrors('cancel_reason');
        $this->as()->post(route('appointments.status', $b), ['status' => 'cancelada', 'cancel_reason' => 'Viaje'])->assertSessionHasNoErrors();
        $this->book(['time' => '10:00'])->assertSessionHasNoErrors();
    }

    public function test_calendario_eventos_y_pantallas(): void
    {
        $this->book()->assertSessionHasNoErrors();
        $a = $this->appointment();

        $this->as()->get(route('appointments.index'))->assertOk()->assertSee('fullcalendar')->assertSee('Ana Ruiz');
        $events = $this->as()->getJson(route('appointments.events', [
            'start' => $this->monday->toDateString() . 'T00:00:00Z', 'end' => $this->monday->copy()->addWeek()->toDateString() . 'T00:00:00Z',
        ]))->assertOk()->json();
        $this->assertSame($this->monday->toDateString() . 'T09:00:00', $events[0]['start']);
        $this->assertTrue($events[0]['extendedProps']['alert']);

        // Filtrar por otro profesional: no aparece.
        $this->as()->getJson(route('appointments.events', ['start' => $this->monday->toDateString(), 'end' => $this->monday->copy()->addWeek()->toDateString(), 'professionals' => [$this->luis->id]]))
            ->assertOk()->assertJsonCount(0);

        $this->as()->get(route('appointments.create', ['start' => $this->monday->toDateString() . 'T10:00', 'patient_id' => $this->maria->id]))->assertOk()->assertSee('value="10:00"', false)->assertSee('María Rojas');
        $this->as()->get(route('appointments.edit', $a))->assertOk();
        $this->as()->get(route('availability.index', ['personal' => $this->ana->id]))->assertOk()->assertSee('Horario semanal');
        $this->as()->get(route('patients.show', [$this->maria, 'tab' => 'citas']))->assertOk()->assertSee('Próximas');
        $this->as()->get(route('dashboard'))->assertOk()->assertSee('Agenda de hoy')->assertSee('María Rojas');
    }

    public function test_disponibilidad_guarda_horario_y_valida_bloques(): void
    {
        $this->as()->put(route('availability.update', $this->luis), [
            'attends_patients' => 1, 'agenda_color' => '#123456',
            'schedule' => [['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '13:00'], ['weekday' => 2, 'starts_at' => '15:00', 'ends_at' => '18:00']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Schedule::allCompanies()->where('personal_id', $this->luis->id)->count());
        $this->assertSame('#123456', $this->luis->fresh()->color);

        $this->as()->put(route('availability.update', $this->luis), ['attends_patients' => 1,
            'schedule' => [['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '13:00'], ['weekday' => 2, 'starts_at' => '12:00', 'ends_at' => '14:00']]])
            ->assertSessionHasErrors('schedule');

        $this->as()->post(route('availability.blocks.store'), ['reason' => 'Vacaciones', 'personal_id' => $this->ana->id,
            'from' => $this->monday->toDateString(), 'to' => $this->monday->copy()->addDays(4)->toDateString(), 'all_day' => 1])->assertSessionHasNoErrors();
        $this->book()->assertSessionHasErrors('time');
    }

    public function test_plan_permisos_aislamiento_y_api(): void
    {
        $this->book()->assertSessionHasNoErrors();
        $a = $this->appointment();

        // Sin el módulo agenda: 403.
        $sonrisaAdmin = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $sonrisaAdmin->companies()->first()->subscription->update(['features_override' => ['caja']]);
        $this->actingAs($sonrisaAdmin)->get(route('appointments.index'))->assertForbidden();
        // Con agenda, pero de otra clínica: 404.
        $sonrisaAdmin->companies()->first()->subscription->update(['features_override' => ['agenda']]);
        $this->actingAs($sonrisaAdmin)->get(route('appointments.show', $a))->assertNotFound();

        // El cajero ve la agenda pero no agenda.
        $cajero = User::create(['name' => 'caj', 'email' => 'caj@clinicademo.test', 'password' => 'Secreta123']);
        $cajero->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'cajero')->value('id'), 'active' => true]);
        $this->as($cajero)->get(route('appointments.today'))->assertOk();
        $this->as($cajero)->post(route('appointments.store'), [])->assertForbidden();

        $token = $this->admin->createToken('t')->plainTextToken;
        $api = fn () => tap($this, fn () => $this->app["auth"]->forgetGuards())->withHeaders(["Authorization" => "Bearer {$token}", 'Accept' => 'application/json', 'X-Company-Id' => (string) $this->demo->id]);
        $api()->getJson('/api/v1/appointments?date=' . $this->monday->toDateString())->assertOk()
            ->assertJsonPath('data.0.patient.full_name', 'María Rojas')->assertJsonPath('data.0.next_statuses.0', 'confirmada');
        $api()->postJson('/api/v1/appointments', ['patient_id' => $this->jorge->id, 'personal_id' => $this->ana->id, 'date' => $this->monday->toDateString(), 'time' => '09:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('force');
        $created = $api()->postJson('/api/v1/appointments', ['patient_id' => $this->jorge->id, 'personal_id' => $this->ana->id, 'date' => $this->monday->toDateString(), 'time' => '10:00'])
            ->assertCreated()->json();
        $api()->patchJson("/api/v1/appointments/{$created['id']}/status", ['status' => 'confirmada'])->assertOk()->assertJsonPath('status', 'confirmada');
        $api()->getJson('/api/v1/appointments/slots?personal_id=' . $this->ana->id . '&date=' . $this->monday->toDateString() . '&duration=30')->assertOk()->assertJsonPath('slots.0', '08:00');
    }
}
