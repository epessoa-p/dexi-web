<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * API de atención clínica para la app: consulta desde la cita, diagnósticos,
 * procedimientos que marcan el odontograma, odontograma, cierre y adenda,
 * historia, fotos del paciente, permisos y aislamiento.
 */
class ClinicalApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Patient $maria;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();

        app(Tenancy::class)->runFor($this->demo->id, function () {
            Procedure::loadSuggested();
            $ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true, 'user_id' => $this->admin->id]);
            $this->maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'active' => true]);
            $start = today()->setTime(10, 0);
            $this->appointment = Appointment::create(['patient_id' => $this->maria->id, 'personal_id' => $ana->id, 'reason' => 'Dolor',
                'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'status' => 'en_espera']);
        });
    }

    private function api(?User $user = null)
    {
        $this->app['auth']->forgetGuards();
        $token = ($user ?? $this->admin)->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Company-Id' => $this->demo->id, 'Accept' => 'application/json']);
    }

    public function test_consulta_completa_desde_la_cita(): void
    {
        $this->api()->getJson('/api/v1/clinical/options')->assertOk()->assertJsonStructure(['diagnoses', 'surfaces', 'conditions', 'rows', 'marks', 'professionals']);
        $this->api()->getJson('/api/v1/consultations')->assertOk()->assertJsonPath('waiting.0.id', $this->appointment->id);

        $consultation = $this->api()->postJson('/api/v1/consultations', ['appointment_id' => $this->appointment->id])->assertCreated()
            ->assertJsonPath('open', true)->assertJsonPath('reason', 'Dolor');
        $id = $consultation->json('id');
        $this->assertSame('en_atencion', $this->appointment->fresh()->status);
        // Volver a abrirla desde la misma cita la retoma.
        $this->api()->postJson('/api/v1/consultations', ['appointment_id' => $this->appointment->id])->assertJsonPath('id', $id);

        $this->api()->putJson("/api/v1/consultations/{$id}", ['exam' => 'Caries profunda en 16'])->assertOk()->assertJsonPath('exam', 'Caries profunda en 16');
        $this->api()->postJson("/api/v1/consultations/{$id}/diagnoses", ['code' => 'caries_dentina', 'tooth' => 16])->assertCreated()
            ->assertJsonPath('diagnoses.0.icd', 'K02.1');

        // Resina en la 16 (O) que marca el odontograma.
        $resina = app(Tenancy::class)->runFor($this->demo->id, fn () => Procedure::where('code', 'OPE-01')->value('id'));
        $this->api()->postJson("/api/v1/consultations/{$id}/procedures", ['procedure_id' => $resina, 'tooth' => 16, 'surfaces' => ['O'], 'mark' => 'obturacion'])
            ->assertCreated()->assertJsonPath('procedures.0.surfaces', 'O')->assertJsonPath('odontogram.state.16.surfaces.O', 'obturacion');

        $this->api()->postJson("/api/v1/consultations/{$id}/close", ['indications' => 'No comer en 2 horas'])->assertOk()->assertJsonPath('open', false);
        $this->assertSame('atendida', $this->appointment->fresh()->status);
        $this->api()->putJson("/api/v1/consultations/{$id}", ['exam' => 'x'])->assertStatus(422);
        $this->api()->postJson("/api/v1/consultations/{$id}/addendum", ['addendum' => 'Se indicó control en 6 meses'])->assertOk()
            ->assertJsonPath('addendum', fn ($v) => str_contains($v, 'control en 6 meses'));

        $this->api()->getJson("/api/v1/patients/{$this->maria->id}/consultations")->assertOk()->assertJsonPath('data.0.diagnoses.0.label', 'Caries de la dentina');
    }

    public function test_odontograma_y_detalle_de_pieza(): void
    {
        $this->api()->postJson("/api/v1/patients/{$this->maria->id}/odontogram", ['tooth' => 26, 'condition' => 'caries', 'surfaces' => ['M', 'O']])
            ->assertCreated()->assertJsonPath('summary.findings', 1);
        $this->api()->postJson("/api/v1/patients/{$this->maria->id}/odontogram", ['tooth' => 26, 'condition' => 'caries'])->assertStatus(422);

        $tooth = $this->api()->getJson("/api/v1/patients/{$this->maria->id}/odontogram/teeth/26")->assertOk()
            ->assertJsonPath('name', 'Primer molar superior izquierdo')->assertJsonCount(2, 'current');
        $this->api()->postJson("/api/v1/patients/{$this->maria->id}/odontogram/{$tooth->json('current.0.id')}/resolve")->assertOk();
        $this->api()->getJson("/api/v1/patients/{$this->maria->id}/odontogram")->assertOk()->assertJsonPath('dentition', 'permanente');
    }

    public function test_fotos_del_paciente(): void
    {
        Storage::fake('local');
        $upload = $this->api()->post("/api/v1/patients/{$this->maria->id}/documents", [
            'file' => UploadedFile::fake()->image('boca.jpg', 800, 600), 'category' => 'foto_intraoral',
        ])->assertCreated()->assertJsonPath('data.0.is_image', true);

        $this->api()->getJson("/api/v1/patients/{$this->maria->id}/documents")->assertOk()->assertJsonCount(1, 'data');
        $this->api()->get('/api/v1' . $upload->json('data.0.file_path'))->assertOk();
        $this->assertSame(1, PatientDocument::allCompanies()->count());
    }

    public function test_permisos_y_aislamiento(): void
    {
        // La asistente ve las consultas, pero no las abre.
        $user = User::create(['name' => 'asi', 'email' => 'asi@clinicademo.test', 'password' => 'Secreta123']);
        $user->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'asistente')->value('id'), 'active' => true]);
        $this->api($user)->getJson('/api/v1/consultations')->assertOk();
        $this->api($user)->postJson('/api/v1/consultations', ['patient_id' => $this->maria->id])->assertForbidden();

        $id = $this->api()->postJson('/api/v1/consultations', ['patient_id' => $this->maria->id])->assertCreated()->json('id');

        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $this->app['auth']->forgetGuards();
        $token = $sonrisa->createToken('t')->plainTextToken;
        $other = $sonrisa->companies()->first()->id;
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Company-Id' => $other, 'Accept' => 'application/json'])
            ->getJson("/api/v1/consultations/{$id}")->assertNotFound();
        $this->assertSame(1, Consultation::allCompanies()->count());
    }
}
