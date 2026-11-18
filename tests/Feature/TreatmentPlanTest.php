<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Support\Odontogram;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Planes de tratamiento: sugerencias del odontograma, edición, estados,
 * aprobación, avance automático desde la consulta, «Hecho hoy», cierre solo
 * al terminar, permisos y aislamiento.
 */
class TreatmentPlanTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Personal $ana;

    private Patient $jorge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();

        app(Tenancy::class)->runFor($this->demo->id, function () {
            Procedure::loadSuggested();
            Procedure::where('code', 'OPE-01')->update(['price' => 180]);
            Procedure::where('code', 'OPE-02')->update(['price' => 280]);
            Procedure::where('code', 'END-03')->update(['price' => 1200]);
            Procedure::where('code', 'CIR-03')->update(['price' => 900]);
            $this->ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true]);
            $this->jorge = Patient::create(['number' => 1, 'first_name' => 'Jorge', 'last_name' => 'Paz', 'active' => true]);
            // Hallazgos: caries 16 (O y M = una resina MO), endodoncia indicada en 36, tercer molar a extraer.
            foreach ([[16, 'caries', ['O', 'M']], [36, 'endodoncia_indicada', []], [48, 'extraccion_indicada', []], [21, 'corona', []]] as [$tooth, $condition, $surfaces]) {
                Odontogram::record($this->jorge, ['tooth' => $tooth, 'condition' => $condition, 'surfaces' => $surfaces]);
            }
        });
    }

    private function as(?User $user = null)
    {
        $this->flushHeaders();

        return $this->actingAs($user ?? $this->admin)->withSession(['current_company_id' => $this->demo->id]);
    }

    private function plan(): TreatmentPlan
    {
        return TreatmentPlan::allCompanies()->latest('id')->firstOrFail();
    }

    private function createPlan(): TreatmentPlan
    {
        $this->as()->post(route('treatment-plans.store'), [
            'patient_id' => $this->jorge->id, 'personal_id' => $this->ana->id, 'title' => 'Rehabilitación',
            'suggestions' => ['16-caries', '36-endodoncia_indicada', '48-extraccion_indicada'],
        ])->assertSessionHasNoErrors();

        return $this->plan();
    }

    public function test_sugerencias_del_odontograma_por_pieza_con_fase_y_precio(): void
    {
        $this->as()->get(route('treatment-plans.create', ['patient_id' => $this->jorge->id]))->assertOk()
            ->assertSee('Resina compuesta (2 o más caras)')->assertSee('Endodoncia multirradicular')->assertSee('Extracción de tercer molar')
            ->assertDontSee('Corona'); // los tratamientos (azul) no se sugieren

        $plan = $this->createPlan();
        $this->assertSame('PT-000001', $plan->code);
        $this->assertSame('borrador', $plan->status);
        $items = $plan->items;
        $this->assertCount(3, $items);
        $this->assertSame([1, 1, 2], $items->pluck('phase')->all()); // urgente primero
        $resina = $items->firstWhere('tooth', 16);
        $this->assertSame('MO', $resina->surfaces);
        $this->assertSame('280.00', $resina->unit_price);
        $this->assertSame(2380.0, $plan->totals()['total']);
    }

    public function test_edicion_descuento_y_estados_hasta_aprobar(): void
    {
        $plan = $this->createPlan();
        $item = $plan->items->firstWhere('tooth', 48);

        // Precio rápido y descuento.
        $this->as()->patchJson(route('treatment-plans.items.update', [$plan, $item]), ['unit_price' => 800])->assertOk()->assertJsonPath('totals.total', 2280);
        $this->as()->put(route('treatment-plans.update', $plan), ['title' => 'Rehabilitación', 'discount' => 280])->assertSessionHasNoErrors();
        $this->assertSame(2000.0, $plan->fresh()->totals()['total']);

        // Ítem a mano: pide pieza si el procedimiento es por pieza.
        $endo = Procedure::allCompanies()->where('company_id', $this->demo->id)->where('code', 'END-01')->firstOrFail();
        $this->as()->post(route('treatment-plans.items.store', $plan), ['procedure_id' => $endo->id])->assertSessionHasErrors('tooth');
        $this->as()->post(route('treatment-plans.items.store', $plan), ['procedure_id' => $endo->id, 'tooth' => 11, 'unit_price' => 500, 'phase' => 3])->assertSessionHasNoErrors();
        $added = $plan->items()->where('tooth', 11)->firstOrFail();
        $this->as()->delete(route('treatment-plans.items.destroy', [$plan, $added]))->assertSessionHasNoErrors();
        $this->assertNull($added->fresh()); // en borrador se borra

        $this->as()->post(route('treatment-plans.status', $plan), ['status' => 'presentado'])->assertSessionHasNoErrors();
        $this->as()->post(route('treatment-plans.status', $plan), ['status' => 'aprobado'])->assertSessionHasErrors('approved_by_name');
        $this->as()->post(route('treatment-plans.status', $plan), ['status' => 'aprobado', 'approved_by_name' => 'Jorge Paz'])->assertSessionHasNoErrors();
        $plan->refresh();
        $this->assertSame('aprobado', $plan->status);
        $this->assertNotNull($plan->approved_at);

        // Aprobado: el precio y el descuento ya no cambian; quitar un ítem lo cancela.
        $this->as()->patchJson(route('treatment-plans.items.update', [$plan, $item]), ['unit_price' => 1])->assertUnprocessable();
        $this->as()->put(route('treatment-plans.update', $plan), ['title' => 'Otro', 'discount' => 0])->assertSessionHasNoErrors();
        $this->assertSame('280.00', $plan->fresh()->discount);
        $this->as()->delete(route('treatment-plans.items.destroy', [$plan, $item]))->assertSessionHasNoErrors();
        $this->assertSame('cancelado', $item->fresh()->status);

        $this->as()->get(route('treatment-plans.show', $plan))->assertOk()->assertSee('Jorge Paz');
        $this->as()->get(route('treatment-plans.print', $plan))->assertOk()->assertSee('Acepto el plan')->assertSee('PT-000001');
    }

    public function test_la_consulta_avanza_el_plan_y_lo_termina_solo(): void
    {
        $plan = $this->createPlan();
        $this->as()->post(route('treatment-plans.status', $plan), ['status' => 'aprobado', 'approved_by_name' => 'Jorge Paz']);
        $resina = $plan->items->firstWhere('tooth', 16);
        $endo = $plan->items->firstWhere('tooth', 36);
        $extraccion = $plan->items->firstWhere('tooth', 48);

        $this->as()->post(route('consultations.start'), ['patient_id' => $this->jorge->id, 'personal_id' => $this->ana->id]);
        $c = Consultation::allCompanies()->latest('id')->firstOrFail();
        $this->as()->get(route('consultations.show', $c))->assertOk()->assertSee('Hecho hoy')->assertSee('Endodoncia multirradicular');

        // 1) Registrar en la consulta el mismo procedimiento en la misma pieza marca el ítem.
        $this->as()->post(route('consultations.procedures.store', $c), ['procedure_id' => $endo->procedure_id, 'tooth' => 36, 'mark' => 'endodoncia'])->assertSessionHasNoErrors();
        $this->assertSame('realizado', $endo->fresh()->status);
        $this->assertSame($c->id, $endo->fresh()->consultation_id);
        // …y en el odontograma la endodoncia indicada queda resuelta.
        $this->assertSame(['endodoncia'], Odontogram::state($this->jorge)[36]['whole']);

        // Quitarlo de la consulta lo devuelve a pendiente.
        $this->as()->delete(route('consultations.procedures.destroy', [$c, $c->procedures()->first()]))->assertSessionHasNoErrors();
        $this->assertSame('pendiente', $endo->fresh()->status);

        // 2) «Hecho hoy»: registra con el precio del plan y las caras.
        $this->as()->post(route('consultations.plan-item', [$c, $resina]))->assertSessionHasNoErrors();
        $this->assertSame('realizado', $resina->fresh()->status);
        $done = $c->procedures()->latest('id')->first();
        $this->assertSame('MO', $done->surfaces);
        $this->assertSame('280.00', $done->price);
        $this->assertSame('obturacion', Odontogram::state($this->jorge)[16]['surfaces']['O']);

        $this->as()->post(route('consultations.plan-item', [$c, $endo]))->assertSessionHasNoErrors();
        $this->assertSame('aprobado', $plan->fresh()->status);
        $this->assertSame(67, $plan->fresh()->totals()['progress']);

        // 3) Marcado a mano el último: el plan se termina solo; desmarcar lo reabre.
        $this->as()->post(route('treatment-plans.items.toggle', [$plan, $extraccion]), ['done' => 1])->assertSessionHasNoErrors();
        $this->assertSame('terminado', $plan->fresh()->status);
        $this->as()->post(route('treatment-plans.items.toggle', [$plan, $extraccion]), ['done' => 0]);
        $this->assertSame('aprobado', $plan->fresh()->status);

        $this->as()->get(route('patients.show', [$this->jorge, 'tab' => 'tratamiento']))->assertOk()->assertSee('Rehabilitación')->assertSee('2 de 3');
    }

    public function test_rechazar_cancelar_y_reabrir(): void
    {
        $plan = $this->createPlan();
        $this->as()->post(route('treatment-plans.status', $plan), ['status' => 'presentado'])->assertSessionHasNoErrors();

        $this->as()->post(route('treatment-plans.status', $plan), ['status' => 'rechazado'])->assertSessionHasErrors('close_reason');
        $this->as()->post(route('treatment-plans.status', $plan), ['status' => 'rechazado', 'close_reason' => 'Costo'])->assertSessionHasNoErrors();
        $this->assertSame('rechazado', $plan->fresh()->status);
        $this->as()->post(route('treatment-plans.items.store', $plan), ['procedure_id' => $plan->items->first()->procedure_id, 'tooth' => 11])->assertSessionHasErrors('procedure_id');

        $this->as()->post(route('treatment-plans.status', $plan), ['status' => 'borrador'])->assertSessionHasNoErrors();
        $this->as()->post(route('treatment-plans.status', $plan), ['status' => 'cancelado', 'close_reason' => 'Se atiende en otro lugar'])->assertSessionHasNoErrors();
        $this->as()->post(route('treatment-plans.status', $plan), ['status' => 'aprobado', 'approved_by_name' => 'x'])->assertSessionHasErrors('status');

        $this->as()->get(route('treatment-plans.index', ['filter' => 'cerrados']))->assertOk()->assertSee('Jorge Paz');
        $this->as()->get(route('treatment-plans.index'))->assertOk()->assertDontSee('Jorge Paz');
    }

    public function test_permisos_plan_y_aislamiento(): void
    {
        $plan = $this->createPlan();

        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $sonrisa->companies()->first()->subscription->update(['features_override' => ['agenda']]);
        $this->actingAs($sonrisa)->get(route('treatment-plans.index'))->assertForbidden();
        $sonrisa->companies()->first()->subscription->update(['features_override' => ['tratamientos']]);
        $this->actingAs($sonrisa)->get(route('treatment-plans.show', $plan))->assertNotFound();

        $asistente = User::create(['name' => 'asis', 'email' => 'asis@clinicademo.test', 'password' => 'Secreta123']);
        $asistente->companies()->attach($this->demo->id, ['role_id' => \App\Models\Role::where('slug', 'asistente')->value('id'), 'active' => true]);
        $this->as($asistente)->get(route('treatment-plans.show', $plan))->assertOk()->assertDontSee('Agregar procedimiento');
        $this->as($asistente)->post(route('treatment-plans.status', $plan), ['status' => 'presentado'])->assertForbidden();

        // Un ítem de otro plan no se toca por la URL de este.
        $other = TreatmentPlanItem::create(['treatment_plan_id' => $plan->id, 'name' => 'x', 'unit_price' => 1]);
        $this->as()->post(route('treatment-plans.store'), ['patient_id' => $this->jorge->id]);
        $second = $this->plan();
        $this->as()->delete(route('treatment-plans.items.destroy', [$second, $other]))->assertNotFound();
    }
}
