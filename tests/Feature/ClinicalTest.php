<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Consultation;
use App\Models\OdontogramEntry;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\Role;
use App\Models\User;
use App\Support\Odontogram;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Atención clínica: abrir desde la cita o la ficha, notas, diagnósticos,
 * procedimientos que marcan el odontograma, odontograma por caras y piezas,
 * cierre y notas de corrección, permisos, plan y aislamiento.
 */
class ClinicalTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Personal $ana;

    private Patient $maria;

    private Procedure $resina;

    private Procedure $extraccion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();

        app(Tenancy::class)->runFor($this->demo->id, function () {
            $this->ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true]);
            $this->maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'active' => true, 'allergies' => 'Penicilina']);
            $this->resina = Procedure::create(['code' => 'OPE-01', 'name' => 'Resina simple', 'price' => 180, 'duration_minutes' => 30, 'applies_to' => 'cara']);
            $this->extraccion = Procedure::create(['code' => 'CIR-01', 'name' => 'Extracción simple', 'price' => 200, 'duration_minutes' => 30, 'applies_to' => 'pieza']);
        });
    }

    private function as(?User $user = null)
    {
        $this->flushHeaders();

        return $this->actingAs($user ?? $this->admin)->withSession(['current_company_id' => $this->demo->id]);
    }

    private function appointment(string $status = 'en_espera'): Appointment
    {
        return app(Tenancy::class)->runFor($this->demo->id, fn () => Appointment::create([
            'patient_id' => $this->maria->id, 'personal_id' => $this->ana->id, 'procedure_id' => $this->resina->id,
            'starts_at' => now()->setTime(9, 0), 'ends_at' => now()->setTime(9, 30), 'status' => $status, 'reason' => 'Dolor al masticar',
        ]));
    }

    private function consultation(): Consultation
    {
        return Consultation::allCompanies()->latest('id')->firstOrFail();
    }

    private function entries(): \Illuminate\Support\Collection
    {
        return OdontogramEntry::allCompanies()->where('patient_id', $this->maria->id)->get();
    }

    public function test_abrir_desde_la_cita_la_pasa_a_atencion_y_no_duplica(): void
    {
        $appointment = $this->appointment('agendada');

        $this->as()->post(route('consultations.start'), ['appointment_id' => $appointment->id])->assertRedirect();
        $c = $this->consultation();
        $this->assertSame('C-000001', $c->code);
        $this->assertSame($this->ana->id, $c->personal_id);
        $this->assertSame('Dolor al masticar', $c->reason);
        $this->assertSame('en_atencion', $appointment->fresh()->status);

        // Volver a «Atender» abre la misma consulta.
        $this->as()->post(route('consultations.start'), ['appointment_id' => $appointment->id])->assertRedirect(route('consultations.show', $c));
        $this->assertSame(1, Consultation::allCompanies()->count());

        $this->as()->get(route('consultations.show', $c))->assertOk()->assertSee('Antes de atender')->assertSee('Alergia: Penicilina')->assertSee('odontogram');
    }

    public function test_abrir_desde_la_ficha_pide_profesional_si_el_usuario_no_atiende(): void
    {
        $this->as()->post(route('consultations.start'), ['patient_id' => $this->maria->id])->assertSessionHasErrors('personal_id');
        $this->as()->post(route('consultations.start'), ['patient_id' => $this->maria->id, 'personal_id' => $this->ana->id])->assertRedirect();

        // Si el usuario es el odontólogo (su ficha de personal), se elige solo.
        app(Tenancy::class)->runFor($this->demo->id, fn () => $this->ana->update(['user_id' => $this->admin->id]));
        $this->as()->post(route('consultations.start'), ['patient_id' => $this->maria->id])->assertRedirect();
        $this->assertSame(2, Consultation::allCompanies()->count());
    }

    public function test_notas_diagnosticos_y_procedimientos_que_marcan_el_odontograma(): void
    {
        $this->as()->post(route('consultations.start'), ['appointment_id' => $this->appointment()->id]);
        $c = $this->consultation();

        $this->as()->putJson(route('consultations.notes', $c), ['history' => 'Dolor desde hace 3 días', 'exam' => 'Caries profunda en 16'])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('Caries profunda en 16', $c->fresh()->exam);

        // Diagnóstico del catálogo y «otro» con texto.
        $this->as()->post(route('consultations.diagnoses.store', $c), ['code' => 'caries_dentina', 'tooth' => 16, 'surfaces' => ['O', 'M']])->assertSessionHasNoErrors();
        $this->as()->post(route('consultations.diagnoses.store', $c), ['code' => 'otro'])->assertSessionHasErrors('label');
        $this->assertSame('MO', $c->diagnoses()->first()->surfaces);
        $this->assertSame('K02.1', $c->diagnoses()->first()->cie10);

        // Caries en el odontograma (por cara), ligada a la consulta.
        $this->as()->postJson(route('odontogram.store', $this->maria), ['tooth' => 16, 'condition' => 'caries', 'surfaces' => ['O'], 'consultation_id' => $c->id])
            ->assertCreated()->assertJsonPath('summary.findings', 1);

        // Resina en cara: pide pieza y cara; con «marcar» deja obturación y resuelve la caries.
        $this->as()->post(route('consultations.procedures.store', $c), ['procedure_id' => $this->resina->id])->assertSessionHasErrors('tooth');
        $this->as()->post(route('consultations.procedures.store', $c), ['procedure_id' => $this->resina->id, 'tooth' => 16])->assertSessionHasErrors('surfaces');
        $this->as()->post(route('consultations.procedures.store', $c), ['procedure_id' => $this->resina->id, 'tooth' => 16, 'surfaces' => ['O'], 'mark' => 'obturacion'])->assertSessionHasNoErrors();

        $item = $c->procedures()->first();
        $this->assertSame('180.00', $item->price);
        $this->assertSame(16, $item->tooth);
        $caries = $this->entries()->firstWhere('condition', 'caries');
        $this->assertNotNull($caries->resolved_at);
        $this->assertStringStartsWith('Tratado', $caries->resolution);
        $this->assertSame('obturacion', Odontogram::state($this->maria)[16]['surfaces']['O']);

        // Extracción: marca la pieza como ausente.
        $this->as()->post(route('consultations.procedures.store', $c), ['procedure_id' => $this->extraccion->id, 'tooth' => 48, 'mark' => 'ausente', 'price' => 150])->assertSessionHasNoErrors();
        $this->assertSame(['ausente'], Odontogram::state($this->maria)[48]['whole']);
        $this->assertSame(330.0, $c->fresh()->load('procedures')->total);
    }

    public function test_odontograma_por_caras_y_piezas_resolver_e_historia(): void
    {
        $url = route('odontogram.store', $this->maria);

        $this->as()->postJson($url, ['tooth' => 99, 'condition' => 'caries', 'surfaces' => ['O']])->assertUnprocessable()->assertJsonValidationErrors('tooth');
        $this->as()->postJson($url, ['tooth' => 36, 'condition' => 'caries'])->assertUnprocessable()->assertJsonValidationErrors('surfaces');
        $this->as()->postJson($url, ['tooth' => 36, 'condition' => 'caries', 'surfaces' => ['O', 'D']])->assertCreated();
        // Repetir la misma cara no duplica.
        $this->as()->postJson($url, ['tooth' => 36, 'condition' => 'caries', 'surfaces' => ['O']])->assertOk();
        $this->assertSame(2, $this->entries()->count());

        // Condición de pieza entera (sin caras) y dentición temporal.
        $this->as()->postJson($url, ['tooth' => 21, 'condition' => 'corona'])->assertCreated();
        $this->as()->postJson($url, ['tooth' => 85, 'condition' => 'extraccion_indicada'])->assertCreated()->assertJsonPath('summary.findings', 2);

        // Quitar (corrección): queda en la historia.
        $entry = $this->entries()->firstWhere('tooth', 21);
        $this->as()->postJson(route('odontogram.resolve', [$this->maria, $entry]), ['reason' => 'Error al marcar'])->assertOk()->assertJsonPath('summary.treatments', 0);
        $this->as()->getJson(route('odontogram.tooth', [$this->maria, 21]))->assertOk()
            ->assertJsonPath('name', 'Incisivo central superior izquierdo')->assertJsonCount(0, 'current')->assertJsonPath('history.0.resolution', 'Error al marcar');

        // La ficha del paciente muestra el odontograma y la historia.
        $this->as()->get(route('patients.show', [$this->maria, 'tab' => 'odontograma']))->assertOk()->assertSee('odontogram');
        $this->as()->get(route('patients.show', [$this->maria, 'tab' => 'historia']))->assertOk()->assertSee('Sin consultas todavía');
        $this->as()->get(route('patients.show', $this->maria))->assertOk()->assertSee('2 hallazgos pendientes');
    }

    public function test_cerrar_firma_pasa_la_cita_a_atendida_y_solo_admite_correcciones(): void
    {
        $appointment = $this->appointment();
        $this->as()->post(route('consultations.start'), ['appointment_id' => $appointment->id]);
        $c = $this->consultation();
        $c->update(['reason' => null]);

        $this->as()->post(route('consultations.close', $c))->assertSessionHasErrors('close');

        $this->as()->post(route('consultations.close', $c), ['reason' => 'Control', 'indications' => 'Ibuprofeno 400 mg cada 8 h'])->assertRedirect(route('consultations.show', $c));
        $c->refresh();
        $this->assertSame('cerrada', $c->status);
        $this->assertNotNull($c->closed_at);
        $this->assertSame('atendida', $appointment->fresh()->status);

        // Cerrada: no se edita.
        $this->as()->putJson(route('consultations.notes', $c), ['exam' => 'cambio'])->assertUnprocessable();
        $this->as()->post(route('consultations.diagnoses.store', $c), ['code' => 'gingivitis'])->assertSessionHasErrors('close');
        $this->as()->postJson(route('odontogram.store', $this->maria), ['tooth' => 11, 'condition' => 'corona', 'consultation_id' => $c->id])->assertUnprocessable();

        $this->as()->post(route('consultations.addendum', $c), ['addendum' => 'La pieza tratada fue la 26, no la 16.'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('La pieza tratada fue la 26', $c->fresh()->addendum);

        $this->as()->get(route('consultations.prescription', $c))->assertOk()->assertSee('Ibuprofeno 400 mg')->assertSee('Penicilina');
        $this->as()->get(route('patients.show', [$this->maria, 'tab' => 'historia']))->assertOk()->assertSee('C-000001')->assertSee('Cerrada');
        $this->as()->get(route('consultations.index', ['filter' => 'todas']))->assertOk()->assertSee('María Rojas');
    }

    public function test_permisos_plan_y_aislamiento(): void
    {
        $this->as()->post(route('consultations.start'), ['appointment_id' => $this->appointment()->id]);
        $c = $this->consultation();

        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $sonrisa->companies()->first()->subscription->update(['features_override' => ['agenda']]);
        $this->actingAs($sonrisa)->get(route('consultations.index'))->assertForbidden();
        $sonrisa->companies()->first()->subscription->update(['features_override' => ['historia_clinica']]);
        $this->actingAs($sonrisa)->get(route('consultations.show', $c))->assertNotFound();
        $this->actingAs($sonrisa)->getJson(route('odontogram.state', $this->maria))->assertNotFound();

        // Asistente: ve y marca el odontograma, pero no abre consultas.
        $asistente = User::create(['name' => 'asis', 'email' => 'asis@clinicademo.test', 'password' => 'Secreta123']);
        $asistente->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'asistente')->value('id'), 'active' => true]);
        $this->as($asistente)->postJson(route('odontogram.store', $this->maria), ['tooth' => 11, 'condition' => 'corona'])->assertCreated();
        $this->as($asistente)->post(route('consultations.start'), ['patient_id' => $this->maria->id, 'personal_id' => $this->ana->id])->assertForbidden();

        // Recepción: ve el odontograma pero no lo edita.
        $recepcion = User::create(['name' => 'rec', 'email' => 'rec@clinicademo.test', 'password' => 'Secreta123']);
        $recepcion->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'recepcionista')->value('id'), 'active' => true]);
        $this->as($recepcion)->getJson(route('odontogram.state', $this->maria))->assertOk();
        $this->as($recepcion)->postJson(route('odontogram.store', $this->maria), ['tooth' => 12, 'condition' => 'corona'])->assertForbidden();
    }
}
