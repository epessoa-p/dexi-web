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

        $this->seedClinical($patients, $ana, $carlos);
    }

    /** Odontogramas, una consulta cerrada (ayer) y la que está en curso. */
    private function seedClinical($patients, Personal $ana, Personal $carlos): void
    {
        $maria = $patients[0];
        $jorge = $patients[1];
        $lucia = $patients[4];

        foreach ([
            [$maria, 16, 'obturacion', ['O']], [$maria, 26, 'caries', ['O', 'M']], [$maria, 36, 'endodoncia', []], [$maria, 36, 'corona', []],
            [$maria, 48, 'ausente', []], [$maria, 11, 'sellante', ['V']],
            [$jorge, 18, 'ausente', []], [$jorge, 28, 'ausente', []], [$jorge, 46, 'extraccion_indicada', []], [$jorge, 25, 'caries', ['D']],
            [$jorge, 14, 'protesis', []], [$jorge, 15, 'protesis', []],
            [$lucia, 46, 'endodoncia_indicada', []], [$lucia, 46, 'caries', ['O', 'D']], [$lucia, 37, 'obturacion', ['O']],
        ] as [$patient, $tooth, $condition, $surfaces]) {
            \App\Support\Odontogram::record($patient, ['tooth' => $tooth, 'condition' => $condition, 'surfaces' => $surfaces], null, $ana->id);
        }

        // Consulta cerrada de María (la limpieza atendida hoy).
        $done = Appointment::where('patient_id', $maria->id)->where('status', 'atendida')->first();
        if ($done) {
            $consultation = \App\Support\Consultations::start($maria, $done);
            $consultation->update([
                'reason' => 'Limpieza y control semestral',
                'exam' => 'Cálculo supragingival en sector anteroinferior. Encías levemente inflamadas. Caries en 26 (O, M).',
                'plan' => 'Profilaxis y destartraje. Resina en 26 en la próxima cita.',
                'indications' => "Cepillado 3 veces al día con técnica de Bass.\nHilo dental diario.",
                'next_visit' => 'Resina en 26 la próxima semana',
                'started_at' => $done->started_at ?? now()->subHour(),
            ]);
            $consultation->diagnoses()->create(['code' => 'calculo', 'label' => 'Cálculo dental (sarro)']);
            $consultation->diagnoses()->create(['code' => 'caries_dentina', 'label' => 'Caries de la dentina', 'tooth' => 26, 'surfaces' => 'MO']);
            $profilaxis = Procedure::where('code', 'GEN-03')->first();
            $consultation->procedures()->create(['procedure_id' => $profilaxis?->id, 'name' => $profilaxis?->name ?? 'Profilaxis', 'price' => $profilaxis?->price ?? 0]);
            $consultation->update(['status' => 'cerrada', 'closed_at' => now()->subMinutes(5)]);
        }

        // Consulta en curso de Lucía (endodoncia con el Dr. Méndez).
        $current = Appointment::where('patient_id', $lucia->id)->where('status', 'en_atencion')->first();
        if ($current) {
            $consultation = \App\Support\Consultations::start($lucia, $current);
            $consultation->update([
                'reason' => 'Dolor espontáneo en molar inferior derecho',
                'history' => 'Dolor pulsátil desde hace 4 días, empeora de noche y con el frío. Toma ibuprofeno con alivio parcial.',
                'exam' => 'Pieza 46 con caries profunda O-D. Percusión vertical positiva. Rx: radiolucidez periapical.',
            ]);
            $consultation->diagnoses()->create(['code' => 'pulpitis_irreversible', 'label' => 'Pulpitis irreversible', 'tooth' => 46]);
        }

        $this->seedPlans($maria, $jorge, $lucia, $ana, $carlos);
        $this->seedCommunications($patients);
        $this->seedInventory();
        $this->seedLab($patients, $ana, $carlos);
    }

    /** Planes de tratamiento demo: uno en curso (María), uno por aprobar (Jorge) y uno en borrador (Lucía). */
    private function seedPlans(Patient $maria, Patient $jorge, Patient $lucia, Personal $ana, Personal $carlos): void
    {
        $tp = \App\Support\TreatmentPlans::class;

        $plan = $tp::create($maria, ['title' => 'Restauración y control', 'personal_id' => $ana->id, 'suggestions' => ['26-caries']]);
        $tp::addItem($plan, ['procedure_id' => Procedure::where('code', 'GEN-05')->value('id'), 'phase' => 2]);
        $tp::setStatus($plan, 'aprobado', $maria->full_name);
        $plan->update(['notes' => "Forma de pago: 50 % al iniciar, saldo al terminar.\nGarantía de 1 año en restauraciones."]);

        $plan = $tp::create($jorge, ['title' => 'Rehabilitación integral', 'personal_id' => $ana->id,
            'suggestions' => ['46-extraccion_indicada', '25-caries']]);
        $tp::addItem($plan, ['procedure_id' => Procedure::where('code', 'IMP-01')->value('id'), 'tooth' => 46, 'phase' => 3]);
        $tp::addItem($plan, ['procedure_id' => Procedure::where('code', 'IMP-02')->value('id'), 'tooth' => 46, 'phase' => 3, 'unit_price' => 2200]);
        $plan->update(['discount' => 500]);
        $tp::setStatus($plan, 'presentado');

        $tp::create($lucia, ['title' => 'Tratamiento de conducto', 'personal_id' => $carlos->id,
            'suggestions' => ['46-endodoncia_indicada', '46-caries']]);

        $this->seedSales($maria, $jorge);
    }

    /** Caja abierta, el cobro de la consulta de María y una venta a crédito de Jorge. */
    private function seedSales(Patient $maria, Patient $jorge): void
    {
        \App\Models\PaymentMethod::ensureDefaults();
        // Comisiones: 30 % para todo el equipo y 40 % en endodoncia (antes de cobrar, para que se generen).
        \App\Models\CommissionRule::create(['applies_to' => 'todos', 'type' => 'porcentaje', 'value' => 30, 'active' => true]);
        if ($endo = Specialty::where('name', 'Endodoncia')->value('id')) {
            \App\Models\CommissionRule::create(['applies_to' => 'especialidad', 'specialty_id' => $endo, 'type' => 'porcentaje', 'value' => 40,
                'active' => true, 'notes' => 'Tratamientos de conducto']);
        }
        $caja = \App\Models\Caja::where('active', true)->orderBy('id')->first();
        if (! $caja) {
            return;
        }
        $session = \App\Support\Cash::open($caja, 200, 'Fondo de cambio');
        $cash = \App\Models\PaymentMethod::where('type', 'cash')->first();
        $qr = \App\Models\PaymentMethod::where('type', 'qr')->first();

        $pending = \App\Support\Sales::pendingFor($maria);
        if ($pending['consultation']->isNotEmpty()) {
            \App\Support\Sales::create([
                'patient_id' => $maria->id, 'cash_session_id' => $session->id,
                'items' => $pending['consultation']->map(fn ($cp) => ['source' => 'consultation', 'id' => $cp->id])->values()->all(),
                'payments' => [['payment_method_id' => $cash->id, 'amount' => 200]],
            ]);
        }

        \App\Support\Sales::create([
            'patient_id' => $jorge->id, 'cash_session_id' => $session->id,
            'items' => [['source' => 'free', 'procedure_id' => Procedure::where('code', 'RAD-02')->value('id')],
                ['source' => 'free', 'procedure_id' => Procedure::where('code', 'GEN-01')->value('id')]],
            'payments' => [['payment_method_id' => $qr->id, 'amount' => 100, 'reference' => 'QR-55821']],
            'due_date' => now()->addDays(15)->toDateString(),
        ]);
    }

    /** Comunicaciones: controles programados (uno vencido) y un recordatorio ya enviado. */
    private function seedCommunications($patients): void
    {
        $f = fn (Patient $p, $due, string $reason, ?string $notes = null) => \App\Models\PatientFollowup::create([
            'patient_id' => $p->id, 'due_date' => $due->toDateString(), 'reason' => $reason, 'notes' => $notes, 'status' => 'pendiente',
        ]);
        $f($patients[2], today()->subDays(3), 'Control de 6 meses', 'Última limpieza hace 6 meses');
        $f($patients[3], today(), 'Retiro de puntos');
        $f($patients[0], today()->addMonths(6), 'Control de 6 meses');
        $f($patients[5] ?? $patients[1], today()->addMonths(3), 'Limpieza / profilaxis');

        $reminded = Appointment::with(['patient', 'professional', 'procedure'])->onDate(now()->addDay())
            ->whereIn('status', ['agendada', 'confirmada'])->orderBy('starts_at')->first();
        if ($reminded?->patient) {
            \App\Models\Communication::create([
                'patient_id' => $reminded->patient_id, 'appointment_id' => $reminded->id, 'kind' => 'recordatorio', 'channel' => 'whatsapp',
                'recipient' => $reminded->patient->whatsapp_link ? substr($reminded->patient->whatsapp_link, 14) : null,
                'message' => $reminded->reminderMessage(),
            ]);
        }
    }

    /** Laboratorio: una corona en proceso, una férula por instalar y un provisional atrasado. */
    private function seedLab($patients, Personal $ana, Personal $carlos): void
    {
        $lab = \App\Models\Supplier::create(['name' => 'Laboratorio Dental Andino', 'contact_name' => 'Téc. Rodrigo Mamani', 'phone' => '72000000', 'active' => true]);
        $order = fn (Patient $p, Personal $who, array $data) => \App\Support\LabOrders::create($data + [
            'patient_id' => $p->id, 'supplier_id' => $lab->id, 'personal_id' => $who->id,
        ]);

        $crownItem = \App\Models\TreatmentPlanItem::whereHas('plan', fn ($q) => $q->where('patient_id', $patients[0]->id))
            ->whereHas('procedure', fn ($q) => $q->where('code', 'like', 'PRO-%'))->value('id');
        $crown = $order($patients[0], $ana, ['work_type' => 'corona', 'teeth' => '16', 'material' => 'Zirconio', 'shade' => 'A2',
            'treatment_plan_item_id' => $crownItem, 'instructions' => 'Hombro en 360°. Contactos proximales firmes.',
            'sent_at' => today()->subDays(3)->toDateString(), 'due_at' => today()->addDays(2)->toDateString(), 'cost' => 280]);
        \App\Support\LabOrders::setStatus($crown, 'en_proceso');

        $splint = $order($patients[4], $carlos, ['work_type' => 'ferula', 'material' => 'Acetato', 'teeth' => 'arcada superior',
            'sent_at' => today()->subDays(8)->toDateString(), 'due_at' => today()->subDay()->toDateString(), 'cost' => 120]);
        \App\Support\LabOrders::setStatus($splint, 'recibido');

        $order($patients[1], $ana, ['work_type' => 'provisional', 'teeth' => '21', 'material' => 'PMMA', 'shade' => 'A3',
            'sent_at' => today()->subDays(5)->toDateString(), 'due_at' => today()->subDays(2)->toDateString(), 'cost' => 60]);
    }

    /** Inventario y compras: insumos con stock (uno bajo el mínimo, un lote por vencer), insumos de la resina y una compra a crédito. */
    private function seedInventory(): void
    {
        \App\Models\Product::ensureCategories();
        $cat = fn (string $name) => \App\Models\ProductCategory::where('name', $name)->value('id');
        $branch = \App\Support\Stock::defaultBranch();
        $make = fn (array $data) => \App\Models\Product::create($data + ['active' => true]);

        $lido = $make(['name' => 'Lidocaína 2% con epinefrina', 'presentation' => 'Caja x 50 cartuchos', 'unit' => 'cartucho', 'product_category_id' => $cat('Anestesia'),
            'purchase_price' => 4.5, 'min_stock' => 20, 'track_batches' => true, 'brand' => 'New Stetic']);
        $resina = $make(['name' => 'Resina compuesta A2', 'presentation' => 'Jeringa 4 g', 'unit' => 'jeringa', 'product_category_id' => $cat('Materiales de restauración'),
            'purchase_price' => 85, 'min_stock' => 3, 'brand' => '3M Filtek']);
        $guantes = $make(['name' => 'Guantes de nitrilo M', 'presentation' => 'Caja x 100', 'unit' => 'par', 'product_category_id' => $cat('Bioseguridad y descartables'),
            'purchase_price' => 0.6, 'min_stock' => 50]);
        $cepillo = $make(['name' => 'Cepillo dental suave', 'unit' => 'unidad', 'product_category_id' => $cat('Higiene oral (venta)'),
            'purchase_price' => 9, 'sale_price' => 20, 'min_stock' => 5, 'for_sale' => true]);
        $enjuague = $make(['name' => 'Enjuague con flúor 500 ml', 'unit' => 'frasco', 'product_category_id' => $cat('Higiene oral (venta)'),
            'purchase_price' => 22, 'sale_price' => 38, 'min_stock' => 3, 'for_sale' => true]);

        \App\Support\Stock::receive($lido, $branch, 30, 'initial', ['lot' => 'L2401', 'expires_at' => now()->addDays(40)->toDateString(), 'unit_cost' => 4.5]);
        \App\Support\Stock::receive($lido, $branch, 50, 'initial', ['lot' => 'L2507', 'expires_at' => now()->addYear()->toDateString(), 'unit_cost' => 4.5]);
        \App\Support\Stock::receive($resina, $branch, 2, 'initial');      // bajo el mínimo
        \App\Support\Stock::receive($guantes, $branch, 300, 'initial');
        \App\Support\Stock::receive($cepillo, $branch, 12, 'initial');
        \App\Support\Stock::receive($enjuague, $branch, 6, 'initial');

        // La resina de 1 cara gasta: 1 cartucho, 1 par de guantes y algo de resina.
        if ($ope = Procedure::where('code', 'OPE-01')->value('id')) {
            foreach ([[$lido, 1], [$guantes, 1], [$resina, 0.25]] as [$product, $qty]) {
                \App\Models\ProcedureSupply::create(['procedure_id' => $ope, 'product_id' => $product->id, 'quantity' => $qty]);
            }
        }

        if ($supplier = \App\Models\Supplier::where('active', true)->orderBy('id')->first()) {
            $purchase = \App\Support\Purchases::create([
                'supplier_id' => $supplier->id, 'purchased_at' => today()->subDays(2)->toDateString(), 'document_number' => '001-4587',
                'due_date' => today()->addDays(28)->toDateString(),
                'items' => [
                    ['product_id' => $resina->id, 'quantity' => 6, 'unit_cost' => 85],
                    ['product_id' => $guantes->id, 'quantity' => 500, 'unit_cost' => 0.55],
                ],
            ]);
            $items = $purchase->items()->get()->keyBy('product_id');
            \App\Support\Purchases::receive($purchase, ['lines' => [['item_id' => $items[$guantes->id]->id, 'quantity' => 500]], 'notes' => 'La resina llega la próxima semana']);
        }
    }
}
