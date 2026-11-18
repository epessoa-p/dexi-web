<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API para la app móvil: inicio (/dashboard), hora de la clínica en /me,
 * «solo mis citas», recordatorio por WhatsApp en la cita y registro del envío.
 */
class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();
        // Los datos van en la hora de la clínica, como la API.
        config(['app.timezone' => 'America/La_Paz']);
        date_default_timezone_set('America/La_Paz');

        app(Tenancy::class)->runFor($this->demo->id, function () {
            $ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true, 'user_id' => $this->admin->id]);
            $maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'whatsapp' => '59170011223', 'active' => true]);
            $start = today()->setTime(12, 0);
            $this->appointment = Appointment::create(['patient_id' => $maria->id, 'personal_id' => $ana->id, 'reason' => 'Limpieza',
                'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'status' => 'agendada']);
        });
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    private function api(?User $user = null)
    {
        $this->app['auth']->forgetGuards();
        $token = ($user ?? $this->admin)->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Company-Id' => $this->demo->id, 'Accept' => 'application/json']);
    }

    public function test_me_trae_el_desfase_de_la_clinica_y_el_inicio(): void
    {
        $this->api()->getJson('/api/v1/me')->assertOk()->assertJsonPath('company.utc_offset', -240);

        $this->api()->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonStructure(['greeting', 'today' => ['total', 'waiting', 'next'], 'alerts', 'finance' => ['today', 'month', 'receivable']])
            ->assertJsonPath('today.total', 1)
            ->assertJsonPath('today.mine', 1)
            ->assertJsonPath('today.next.0.patient.full_name', 'María Rojas')
            ->assertJsonPath('today.next.0.status_color', 'secondary');
    }

    public function test_el_inicio_no_muestra_dinero_a_quien_no_vende(): void
    {
        $user = User::create(['name' => 'asi', 'email' => 'asi@clinicademo.test', 'password' => 'Secreta123']);
        $user->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'asistente')->value('id'), 'active' => true]);

        $this->api($user)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('finance', null)->assertJsonPath('today.total', 1);
    }

    public function test_solo_mis_citas_recordatorio_y_registro_del_envio(): void
    {
        $this->api()->getJson('/api/v1/appointments?mine=1')->assertOk()->assertJsonCount(1, 'data');

        $show = $this->api()->getJson("/api/v1/appointments/{$this->appointment->id}")->assertOk()
            ->assertJsonPath('reminder.log', true)
            ->assertJsonPath('reminder.sent_at', null);
        $this->assertStringStartsWith('https://wa.me/59170011223?text=', $show->json('reminder.whatsapp_url'));

        $this->api()->postJson('/api/v1/communications/send', [
            'kind' => 'recordatorio', 'patient_id' => $this->appointment->patient_id, 'appointment_id' => $this->appointment->id,
            'message' => $show->json('reminder.message'),
        ])->assertCreated();
        $this->assertSame(1, Communication::allCompanies()->count());
        $this->api()->getJson("/api/v1/appointments/{$this->appointment->id}")->assertJsonPath('reminder.sent_at', fn ($v) => $v !== null);
    }
}
