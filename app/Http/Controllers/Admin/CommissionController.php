<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\CommissionSettlement;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\Specialty;
use App\Support\Commissions;
use App\Support\Money;
use App\Support\PaymentSource;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Equipo › Comisiones: lo que se debe a cada odontólogo (de cualquier fecha),
 * producción del período, detalle y liquidación (se paga como gasto),
 * comprobantes, reglas y «Mis comisiones» para cada profesional.
 * La API (Api\V1\CommissionController) reutiliza estas validaciones.
 */
class CommissionController extends Controller
{
    public function index()
    {
        [$from, $to] = $this->period();

        return view('admin.commissions.index', [
            'pending' => Commissions::pending(),
            'production' => Commissions::production($from, $to),
            'settlements' => CommissionSettlement::with(['professional', 'payer'])
                ->when(request('personal_id'), fn ($q, $id) => $q->where('personal_id', $id))
                ->latest('number')->paginate(15, ['*'], 'liq')->withQueryString(),
            'activeRules' => CommissionRule::where('active', true)->count(),
            'professionals' => Personal::professionals()->get(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function professional(Personal $personal)
    {
        [$from, $to] = $this->period();
        $oldest = Commission::where('personal_id', $personal->id)->pending()->min('earned_at');

        return view('admin.commissions.professional', [
            'professional' => $personal,
            'rows' => $this->rowsFor($personal, $from, $to),
            'pendingAll' => round((float) Commission::where('personal_id', $personal->id)->pending()->sum('amount'), 2),
            'pendingCount' => Commission::where('personal_id', $personal->id)->pending()->where('kind', 'devengada')->count(),
            'settleFrom' => $oldest ? Carbon::parse($oldest)->toDateString() : now()->toDateString(),
            'source' => PaymentSource::options(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function settle(Personal $personal)
    {
        $settlement = $this->settleFor($personal, request()->all());

        return redirect()->route('commissions.settlement', $settlement)
            ->with('success', "Liquidación {$settlement->code} pagada: " . Money::format($settlement->total) . '.');
    }

    public function settlement(CommissionSettlement $settlement)
    {
        return view('admin.commissions.settlement', [
            'settlement' => $settlement->load(['professional', 'payer', 'canceller', 'expense', 'commissions.sale']),
        ]);
    }

    public function cancelSettlement(CommissionSettlement $settlement)
    {
        $reason = request()->validate(['cancel_reason' => 'required|string|max:255'], [], ['cancel_reason' => 'motivo'])['cancel_reason'];
        Commissions::cancelSettlement($settlement, $reason);

        return back()->with('success', "Liquidación {$settlement->code} anulada: el dinero volvió y sus comisiones quedan pendientes otra vez.");
    }

    /** Cada odontólogo ve solo lo suyo (su ficha de personal vinculada a su usuario). */
    public function mine()
    {
        $personal = Personal::where('user_id', auth()->id())->first();
        abort_unless($personal, 404, 'Tu usuario no está vinculado a una ficha de odontólogo.');
        [$from, $to] = $this->period();

        return view('admin.commissions.mine', [
            'professional' => $personal,
            'rows' => $this->rowsFor($personal, $from, $to),
            'pendingAll' => round((float) Commission::where('personal_id', $personal->id)->pending()->sum('amount'), 2),
            'from' => $from,
            'to' => $to,
        ]);
    }

    // ── Reglas ─────────────────────────────────────────────────────────────

    public function rules()
    {
        $rules = CommissionRule::with(['professional', 'procedure', 'specialty'])->get()
            ->sortBy([fn ($a, $b) => $b->active <=> $a->active, fn ($a, $b) => $a->priority <=> $b->priority, fn ($a, $b) => $a->id <=> $b->id])
            ->values();

        return view('admin.commissions.rules', [
            'rules' => $rules,
            'professionals' => Personal::professionals()->get(),
            'procedures' => Procedure::where('active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'specialties' => Specialty::orderBy('name')->get(['id', 'name']),
            'basis' => Commissions::basis(),
        ]);
    }

    public function storeRule()
    {
        CommissionRule::create($this->validateRule(request()->all()));

        return back()->with('success', 'Regla creada. Se aplica a lo que se cobre de ahora en adelante.');
    }

    public function updateRule(CommissionRule $rule)
    {
        $rule->update($this->validateRule(request()->all() + ['active' => request()->boolean('active')], $rule));

        return back()->with('success', 'Regla actualizada. Las comisiones ya generadas no cambian.');
    }

    public function destroyRule(CommissionRule $rule)
    {
        $rule->delete();

        return back()->with('success', 'Regla eliminada. Las comisiones ya generadas no cambian.');
    }

    public function saveBasis()
    {
        $basis = request()->validate(['basis' => ['required', Rule::in(array_keys(Commissions::BASIS))]])['basis'];
        settings()->set('clinic.commission_basis', $basis, app(Tenancy::class)->id(), 'string', 'clinic');

        return back()->with('success', 'Listo: ' . mb_strtolower(explode(':', Commissions::BASIS[$basis])[0]) . '.');
    }

    // ── Compartido con la API ──────────────────────────────────────────────

    /** @return array{0: Carbon, 1: Carbon} por defecto, el mes en curso */
    protected function period(): array
    {
        $from = request('from') ? Carbon::parse(request('from'))->startOfDay() : now()->startOfMonth();
        $to = request('to') ? Carbon::parse(request('to'))->endOfDay() : now()->endOfDay();

        return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
    }

    protected function rowsFor(Personal $personal, Carbon $from, Carbon $to)
    {
        return Commission::with(['sale.patient', 'settlement'])->where('personal_id', $personal->id)
            ->whereBetween('earned_at', [$from, $to])->latest('earned_at')->latest('id')->get();
    }

    protected function settleFor(Personal $personal, array $input): CommissionSettlement
    {
        $validated = validator($input, [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['period_end.before_or_equal' => 'El período no puede terminar en el futuro.'], [
            'period_start' => 'inicio del período', 'period_end' => 'fin del período',
        ])->validate();

        return Commissions::settle($personal, $validated['period_start'], $validated['period_end'], $validated['notes'] ?? null,
            PaymentSource::validate($input));
    }

    /** @return array<string, mixed> */
    protected function validateRule(array $input, ?CommissionRule $rule = null): array
    {
        $companyId = app(Tenancy::class)->id();
        $validated = validator($input, [
            'personal_id' => ['nullable', 'integer', Rule::exists('personal', 'id')->where('company_id', $companyId)],
            'applies_to' => ['required', Rule::in(array_keys(CommissionRule::APPLIES_TO))],
            'procedure_id' => ['nullable', 'required_if:applies_to,procedimiento', Rule::exists('procedures', 'id')->where('company_id', $companyId)],
            'specialty_id' => ['nullable', 'required_if:applies_to,especialidad', Rule::exists('specialties', 'id')->where('company_id', $companyId)],
            'type' => ['required', Rule::in(array_keys(CommissionRule::TYPES))],
            'value' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'procedure_id.required_if' => 'Elige el procedimiento.',
            'specialty_id.required_if' => 'Elige la especialidad.',
            'personal_id.exists' => 'Esa persona no es del equipo de esta clínica.',
        ], ['applies_to' => 'sobre qué', 'value' => 'valor', 'type' => 'tipo'])->validate();

        if ($validated['type'] === 'porcentaje' && (float) $validated['value'] > 100) {
            throw ValidationException::withMessages(['value' => 'El porcentaje no puede ser mayor a 100.']);
        }

        $data = [
            'personal_id' => ! empty($validated['personal_id']) ? (int) $validated['personal_id'] : null,
            'applies_to' => $validated['applies_to'],
            'procedure_id' => $validated['applies_to'] === 'procedimiento' ? (int) $validated['procedure_id'] : null,
            'specialty_id' => $validated['applies_to'] === 'especialidad' ? (int) $validated['specialty_id'] : null,
            'type' => $validated['type'],
            'value' => round((float) $validated['value'], 2),
            'active' => $rule && array_key_exists('active', $validated) ? (bool) $validated['active'] : ($rule?->active ?? true),
            'notes' => $validated['notes'] ?? null,
        ];

        // No dos reglas activas con la misma combinación: el resultado sería ambiguo.
        $duplicate = $data['active'] && CommissionRule::where('active', true)
            ->where('personal_id', $data['personal_id'])->where('applies_to', $data['applies_to'])
            ->where('procedure_id', $data['procedure_id'])->where('specialty_id', $data['specialty_id'])
            ->when($rule, fn ($q) => $q->whereKeyNot($rule->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['applies_to' => 'Ya hay una regla activa para esa persona y ese concepto: edítala en vez de crear otra.']);
        }

        return $data;
    }
}
