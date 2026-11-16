<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pacientes: alta con número de historia, anamnesis y alertas, tutor de
 * menores, búsqueda, documentos privados, aislamiento entre clínicas,
 * permisos por rol y la API.
 */
class PatientTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();
    }

    private function as(?User $user = null)
    {
        $this->flushHeaders();

        return $this->actingAs($user ?? $this->admin)->withSession(['current_company_id' => $this->demo->id]);
    }

    private function patient(array $attributes = []): Patient
    {
        return app(Tenancy::class)->runFor($this->demo->id, fn () => Patient::create($attributes + [
            'number' => Patient::nextNumber(), 'first_name' => 'Ana', 'last_name' => 'Pérez', 'active' => true,
        ]));
    }

    private function userWithRole(string $slug): User
    {
        $user = User::create(['name' => "{$slug}_demo", 'email' => "{$slug}@clinicademo.test", 'password' => 'Secreta123']);
        $user->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', $slug)->value('id'), 'active' => true]);

        return $user;
    }

    public function test_alta_con_numero_de_historia_nombre_ordenado_y_alertas(): void
    {
        $this->as()->post(route('patients.store'), [
            'first_name' => 'maría josé', 'last_name' => 'ROJAS VACA', 'document_type' => 'CI', 'document_number' => '6234517',
            'birth_date' => '1990-03-14', 'gender' => 'F', 'phone' => '70012345',
            'conditions' => ['hipertension', 'asma'], 'allergies' => 'Penicilina', 'pregnant' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $patient = Patient::allCompanies()->where('document_number', '6234517')->firstOrFail();
        $this->assertSame('HC-000001', $patient->code);
        $this->assertSame('María José', $patient->first_name);
        $this->assertSame('Rojas Vaca', $patient->last_name);
        $this->assertSame($this->demo->id, $patient->company_id);
        $this->assertNotNull($patient->medical_updated_at);

        // Las críticas primero: alergia, embarazo, hipertensión; asma al final.
        $labels = array_column($patient->medicalAlerts(), 'label');
        $this->assertSame('Alergia: Penicilina', $labels[0]);
        $this->assertSame('Asma', end($labels));

        $this->as()->get(route('patients.show', $patient))->assertOk()
            ->assertSee('Antes de atender')->assertSee('Alergia: Penicilina')->assertSee('HC-000001');

        // El siguiente toma el número siguiente.
        $this->as()->post(route('patients.store'), ['first_name' => 'Luis', 'last_name' => 'Paz'])->assertSessionHasNoErrors();
        $this->assertSame(2, Patient::allCompanies()->where('first_name', 'Luis')->value('number'));
    }

    public function test_validaciones_documento_unico_fecha_y_tutor_de_menores(): void
    {
        $this->patient(['document_number' => '111']);

        $this->as()->post(route('patients.store'), ['first_name' => 'Otro', 'last_name' => 'X', 'document_number' => '111'])
            ->assertSessionHasErrors('document_number');
        $this->as()->post(route('patients.store'), ['first_name' => 'Otro', 'last_name' => 'X', 'birth_date' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('birth_date');

        // Menor sin tutor: no; con tutor: sí.
        $minor = ['first_name' => 'Valentina', 'last_name' => 'Gómez', 'birth_date' => now()->subYears(8)->toDateString()];
        $this->as()->post(route('patients.store'), $minor)->assertSessionHasErrors('guardian_name');
        $this->as()->post(route('patients.store'), $minor + ['guardian_name' => 'Patricia Gómez', 'guardian_phone' => '70000000'])->assertSessionHasNoErrors();
        $this->assertTrue(Patient::allCompanies()->where('first_name', 'Valentina')->firstOrFail()->isMinor());

        // El mismo documento en OTRA clínica sí se permite.
        $sonrisa = Company::where('ruc', '1000000002')->firstOrFail();
        app(Tenancy::class)->runFor($sonrisa->id, fn () => Patient::create(['number' => 1, 'first_name' => 'X', 'last_name' => 'Y', 'document_number' => '222']));
        $this->as()->post(route('patients.store'), ['first_name' => 'Otro', 'last_name' => 'X', 'document_number' => '222'])->assertSessionHasNoErrors();
    }

    public function test_busqueda_por_nombre_documento_telefono_y_numero_y_filtros(): void
    {
        $ana = $this->patient(['first_name' => 'Ana', 'last_name' => 'Rojas', 'document_number' => '5555', 'phone' => '70011122']);
        $this->patient(['first_name' => 'Luis', 'last_name' => 'Paz', 'allergies' => 'Látex']);
        $this->patient(['first_name' => 'Inés', 'last_name' => 'Soria', 'active' => false]);

        foreach (['ana rojas', '5555', '70011122', 'HC-1', 'hc000001'] as $term) {
            $this->as()->get(route('patients.index', ['q' => $term]))->assertOk()->assertSee('Ana Rojas')->assertDontSee('Luis Paz');
        }
        $this->as()->get(route('patients.index', ['filter' => 'alerts']))->assertSee('Luis Paz')->assertDontSee('Ana Rojas');
        $this->as()->get(route('patients.index'))->assertDontSee('Inés Soria');
        $this->as()->get(route('patients.index', ['filter' => 'inactive']))->assertSee('Inés Soria');

        $this->as()->getJson(route('patients.search', ['q' => 'roj']))->assertOk()->assertJsonPath('0.id', $ana->id);
    }

    public function test_editar_actualiza_anamnesis_y_puede_desactivar(): void
    {
        $patient = $this->patient(['conditions' => ['diabetes']]);

        $this->as()->put(route('patients.update', $patient), ['first_name' => 'Ana', 'last_name' => 'Pérez', 'conditions' => ['diabetes', 'cardiopatia']])
            ->assertSessionHasNoErrors()->assertRedirect(route('patients.show', $patient));

        $patient->refresh();
        $this->assertSame(['diabetes', 'cardiopatia'], $patient->conditions);
        $this->assertFalse($patient->active); // el switch «activo» no vino marcado
        $this->assertNotNull($patient->medical_updated_at);
    }

    public function test_documentos_privados_subir_ver_y_eliminar(): void
    {
        $patient = $this->patient();

        $this->as()->post(route('patients.documents.store', $patient), [
            'files' => [UploadedFile::fake()->image('panoramica.jpg'), UploadedFile::fake()->create('consentimiento.pdf', 100, 'application/pdf')],
            'category' => 'radiografia',
        ])->assertSessionHasNoErrors()->assertRedirect(route('patients.show', [$patient, 'tab' => 'documentos']));

        $documents = PatientDocument::allCompanies()->where('patient_id', $patient->id)->get();
        $this->assertCount(2, $documents);
        $image = $documents->firstWhere('title', 'panoramica');
        Storage::disk('local')->assertExists($image->path);
        $this->assertStringStartsWith("patients/{$this->demo->id}/{$patient->id}/", $image->path);

        $this->as()->get(route('patients.documents.show', [$patient, $image]))->assertOk();
        $this->as()->get(route('patients.show', [$patient, 'tab' => 'documentos']))->assertOk()->assertSee('panoramica');

        // Con documentos, el paciente no se borra: se desactiva.
        $this->as()->delete(route('patients.destroy', $patient))->assertRedirect();
        $this->assertFalse($patient->fresh()->active);
        $this->assertNotSoftDeleted($patient);

        $this->as()->delete(route('patients.documents.destroy', [$patient, $image]))->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($image->path);

        // Un tipo de archivo no permitido se rechaza.
        $this->as()->post(route('patients.documents.store', $patient), ['files' => [UploadedFile::fake()->create('virus.exe', 10)], 'category' => 'otro'])
            ->assertSessionHasErrors('files.0');
    }

    public function test_sin_documentos_el_paciente_se_elimina(): void
    {
        $patient = $this->patient();
        $this->as()->delete(route('patients.destroy', $patient))->assertRedirect(route('patients.index'));
        $this->assertSoftDeleted($patient);
    }

    public function test_aislamiento_un_paciente_de_otra_clinica_responde_404(): void
    {
        $patient = $this->patient();
        $document = app(Tenancy::class)->runFor($this->demo->id, fn () => PatientDocument::create([
            'patient_id' => $patient->id, 'category' => 'otro', 'title' => 'x', 'path' => 'patients/x.pdf', 'mime' => 'application/pdf',
        ]));
        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();

        $this->actingAs($sonrisa)->get(route('patients.show', $patient))->assertNotFound();
        $this->actingAs($sonrisa)->get(route('patients.documents.show', [$patient, $document]))->assertNotFound();
        $this->actingAs($sonrisa)->get(route('patients.index'))->assertOk()->assertDontSee('Ana Pérez');
    }

    public function test_permisos_por_rol(): void
    {
        $patient = $this->patient();
        $cajero = $this->userWithRole('cajero');
        $recepcion = $this->userWithRole('recepcionista');

        // El cajero ve pacientes pero no los edita ni ve sus documentos clínicos.
        $this->as($cajero)->get(route('patients.index'))->assertOk();
        $this->as($cajero)->get(route('patients.edit', $patient))->assertForbidden();
        $this->as($cajero)->post(route('patients.documents.store', $patient), ['category' => 'otro'])->assertForbidden();

        // Recepción registra pacientes, pero no los elimina.
        $this->as($recepcion)->post(route('patients.store'), ['first_name' => 'Nuevo', 'last_name' => 'Paciente'])->assertSessionHasNoErrors();
        $this->as($recepcion)->delete(route('patients.destroy', $patient))->assertForbidden();

        // El odontólogo demo también.
        $this->as(User::where('email', 'odontologo@clinicademo.test')->firstOrFail())->get(route('patients.show', $patient))->assertOk();
    }

    public function test_api_lista_alta_detalle_y_edicion(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;
        $api = fn () => $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json', 'X-Company-Id' => (string) $this->demo->id]);

        $api()->getJson('/api/v1/patients/options')->assertOk()->assertJsonFragment(['key' => 'hipertension', 'critical' => true]);

        $created = $api()->postJson('/api/v1/patients', ['first_name' => 'Carlos', 'last_name' => 'Vaca', 'allergies' => 'Látex', 'phone' => '+591 700-11223'])
            ->assertCreated()->assertJsonPath('code', 'HC-000001')->assertJsonPath('alerts.0.critical', true)->json();
        $this->assertSame('https://wa.me/59170011223', $created['whatsapp_link']);

        $api()->postJson('/api/v1/patients', ['first_name' => 'Niño', 'last_name' => 'X', 'birth_date' => now()->subYears(5)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('guardian_name');

        $api()->getJson('/api/v1/patients?q=vaca')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.full_name', 'Carlos Vaca');
        $api()->putJson("/api/v1/patients/{$created['id']}", ['first_name' => 'Carlos', 'last_name' => 'Vaca', 'allergies' => null])
            ->assertOk()->assertJsonPath('alerts', [])->assertJsonPath('active', true);
        $api()->getJson("/api/v1/patients/{$created['id']}")->assertOk()->assertJsonPath('documents_count', 0);
    }

    public function test_pantallas_y_dashboard(): void
    {
        $patient = $this->patient(['birth_date' => now()->subYears(30)->toDateString(), 'phone' => '70000001']);

        $this->as()->get(route('patients.create', ['name' => 'Ana Pérez']))->assertOk()->assertSee('value="Ana"', false);
        $this->as()->get(route('patients.edit', $patient))->assertOk();
        foreach (array_keys(\App\Http\Controllers\Admin\PatientController::TABS) as $tab) {
            $this->as()->get(route('patients.show', [$patient, 'tab' => $tab]))->assertOk();
        }
        $this->as()->get(route('dashboard'))->assertOk()->assertSee('Pacientes activos')->assertSee('Cumplen años hoy')->assertSee('Ana Pérez');
    }
}
