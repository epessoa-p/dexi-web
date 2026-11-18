<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Patient;
use App\Models\PatientFollowup;
use App\Models\Personal;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API de comunicaciones para la app: «Para enviar hoy» con el enlace de
 * WhatsApp listo, registrar lo enviado, controles programados (programar,
 * vencidos en la lista, hecho), historial, permisos y aislamiento.
 */
class CommunicationApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Patient $maria;

    private Appointment $tomorrow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();

        app(Tenancy::class)->runFor($this->demo->id, function () {
            $ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true]);
            $this->maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'whatsapp' => '591 70011223', 'active' => true,
                'birth_date' => now()->subYears(30)->toDateString()]);
            $start = now()->addDay()->setTime(9, 30);
            $this->tomorrow = Appointment::create(['patient_id' => $this->maria->id, 'personal_id' => $ana->id, 'reason' => 'Limpieza',
                'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'status' => 'agendada']);
        });
    }

    private function api(?User $user = null)
    {
        $this->app['auth']->forgetGuards();
        $token = ($user ?? $this->admin)->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Company-Id' => $this->demo->id, 'Accept' => 'application/json']);
    }

    public function test_para_enviar_hoy_y_registrar_lo_enviado(): void
    {
        $queue = $this->api()->getJson('/api/v1/communications/queue')->assertOk()
            ->assertJsonPath('remind_for', now()->addDay()->toDateString())
            ->assertJsonPath('data.recordatorio.0.patient.full_name', 'María Rojas')
            ->assertJsonPath('data.recordatorio.0.appointment_id', $this->tomorrow->id)
            ->assertJsonPath('data.cumpleanos.0.sent', null);
        $this->assertStringStartsWith('https://wa.me/59170011223?text=', $queue->json('data.recordatorio.0.whatsapp_url'));
        $this->assertStringContainsString('09:30', $queue->json('data.recordatorio.0.message'));
        $pending = $queue->json('pending');

        $this->api()->postJson('/api/v1/communications/send', [
            'kind' => 'recordatorio', 'patient_id' => $this->maria->id, 'appointment_id' => $this->tomorrow->id,
            'message' => $queue->json('data.recordatorio.0.message'),
        ])->assertCreated();

        $this->api()->getJson('/api/v1/communications/queue')->assertJsonPath('pending', $pending - 1)
            ->assertJsonPath('data.recordatorio.0.sent.user', $this->admin->name);
        $this->api()->getJson("/api/v1/communications/history?patient_id={$this->maria->id}")->assertOk()
            ->assertJsonPath('data.0.kind', 'recordatorio')->assertJsonPath('data.0.kind_label', 'Recordatorio de cita');
    }

    public function test_controles_programados(): void
    {
        $this->api()->postJson("/api/v1/patients/{$this->maria->id}/followups", ['reason' => 'Control de 6 meses'])->assertStatus(422);
        $this->api()->postJson("/api/v1/patients/{$this->maria->id}/followups", ['reason' => 'Control de 6 meses', 'in' => '+6 months'])
            ->assertCreated()->assertJsonPath('due_date', today()->addMonths(6)->toDateString())->assertJsonPath('overdue', false);

        // Uno vencido aparece en «Para enviar hoy».
        $old = app(Tenancy::class)->runFor($this->demo->id, fn () => PatientFollowup::create([
            'patient_id' => $this->maria->id, 'due_date' => today()->subDays(3)->toDateString(), 'reason' => 'Retiro de puntos', 'status' => 'pendiente',
        ]));
        $this->api()->getJson('/api/v1/communications/queue')->assertJsonPath('data.seguimiento.0.followup_id', $old->id);

        $this->api()->getJson("/api/v1/followups?patient_id={$this->maria->id}")->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $old->id)->assertJsonPath('data.0.overdue', true)
            ->assertJsonStructure(['reasons', 'quick' => [['label', 'in']]]);

        $this->api()->postJson("/api/v1/followups/{$old->id}/status", ['status' => 'hecho'])->assertOk()->assertJsonPath('status', 'hecho');
        $this->api()->getJson('/api/v1/followups')->assertJsonCount(1, 'data')->assertJsonPath('counts.hecho', 1);
    }

    public function test_permisos_y_aislamiento(): void
    {
        // El cajero no tiene comunicaciones.
        $user = User::create(['name' => 'caj', 'email' => 'caj@clinicademo.test', 'password' => 'Secreta123']);
        $user->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'cajero')->value('id'), 'active' => true]);
        $this->api($user)->getJson('/api/v1/communications/queue')->assertForbidden();

        $followup = app(Tenancy::class)->runFor($this->demo->id, fn () => PatientFollowup::create([
            'patient_id' => $this->maria->id, 'due_date' => today()->toDateString(), 'reason' => 'Control', 'status' => 'pendiente',
        ]));
        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $this->app['auth']->forgetGuards();
        $token = $sonrisa->createToken('t')->plainTextToken;
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Company-Id' => $sonrisa->companies()->first()->id, 'Accept' => 'application/json'])
            ->postJson("/api/v1/followups/{$followup->id}/status", ['status' => 'hecho'])->assertNotFound();
        $this->assertSame('pendiente', $followup->fresh()->status);
    }
}
