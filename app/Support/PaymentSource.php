<?php

namespace App\Support;

use App\Models\CashSession;
use App\Models\Company;
use App\Models\FinanceAccount;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ¿De dónde sale el dinero? De una cuenta de la tesorería (módulo
 * `finanzas`) o de un turno de caja abierto (módulo `caja`). Sin el módulo de
 * finanzas también se puede anotar como «pagado por fuera», como antes.
 * Lo usan gastos y pagos a proveedores; la web y la API validan igual.
 */
final class PaymentSource
{
    /** @return array<string, string> orígenes que permite el plan, en orden de preferencia */
    public static function available(?Company $company = null): array
    {
        $company ??= app(Tenancy::class)->company();
        $finance = (bool) $company?->planAllows('finanzas');

        return array_filter([
            'account' => $finance ? 'Cuenta' : null,
            'cash' => $company?->planAllows('caja') ? 'Caja' : null,
            'external' => $finance ? null : 'Pagado por fuera',
        ]);
    }

    /**
     * Valida el origen elegido.
     *
     * @return array{source: string, account: ?FinanceAccount, session: ?CashSession}
     */
    public static function validate(array $input): array
    {
        $available = self::available();
        $companyId = app(Tenancy::class)->id();

        $validated = validator($input, [
            'source' => ['required', Rule::in(array_keys($available))],
            'finance_account_id' => ['nullable', 'required_if:source,account',
                Rule::exists('finance_accounts', 'id')->where('company_id', $companyId)->where('active', true)->whereNull('deleted_at')],
            'cash_session_id' => ['nullable', 'required_if:source,cash', 'integer'],
        ], [
            'source.required' => 'Elige de dónde sale el dinero.',
            'source.in' => 'Ese origen del dinero no está disponible en tu plan.',
            'finance_account_id.required_if' => 'Elige la cuenta de la que sale el dinero.',
            'finance_account_id.exists' => 'Esa cuenta no existe o está inactiva.',
            'cash_session_id.required_if' => 'Elige la caja abierta de la que sale el dinero.',
        ])->validate();

        return [
            'source' => $validated['source'],
            'account' => $validated['source'] === 'account' ? FinanceAccount::find($validated['finance_account_id']) : null,
            'session' => $validated['source'] === 'cash' ? Cash::sessionFor((int) $validated['cash_session_id']) : null,
        ];
    }

    /**
     * Saca el dinero. Lo que sale de la caja es efectivo: el arqueo lo descuenta.
     *
     * @param  array{source: string, account: ?FinanceAccount, session: ?CashSession}  $from
     * @return array{finance_movement_id: ?int, cash_movement_id: ?int, payment_method_id: ?int}
     */
    public static function charge(array $from, float $amount, string $concept, string $origin, Model $document, ?string $date = null, ?string $reference = null): array
    {
        if ($from['source'] === 'account') {
            $movement = Treasury::move($from['account'], 'egreso', $amount, $concept,
                ['origin' => $origin, 'occurred_on' => $date, 'reference' => $reference, 'source' => $document]);

            return ['finance_movement_id' => $movement->id, 'cash_movement_id' => null, 'payment_method_id' => null];
        }

        if ($from['source'] === 'cash') {
            $session = $from['session'];
            $method = self::cashMethod();
            $inDrawer = $session->expectedCash();
            if (round($amount, 2) > $inDrawer) {
                throw ValidationException::withMessages([
                    'amount' => "En {$session->caja?->name} hay " . Money::format($inDrawer, app(Tenancy::class)->company()) . ' en efectivo: no alcanza.',
                ]);
            }
            $movement = Cash::record($session, 'expense', $method, -round($amount, 2), $concept, $document);

            return ['finance_movement_id' => null, 'cash_movement_id' => $movement->id, 'payment_method_id' => $method->id];
        }

        return ['finance_movement_id' => null, 'cash_movement_id' => null, 'payment_method_id' => null];
    }

    /**
     * Devuelve el dinero de un documento anulado a donde salió: a la cuenta, o
     * a la caja (el mismo turno si sigue abierto; si no, otro abierto de la sucursal).
     */
    public static function refund(Model $document, string $concept): void
    {
        if ($document->finance_movement_id) {
            $movement = $document->financeMovement;
            if ($movement && ! $movement->isCancelled()) {
                Treasury::cancel($movement);
            }

            return;
        }

        if ($document->source === 'cash' || $document->cash_session_id) {
            $session = $document->cashSession;
            if (! $session?->isOpen()) {
                $branchId = $session?->caja?->branch_id ?? $document->branch_id;
                $session = Cash::openSessions()->first(fn (CashSession $s) => $s->caja?->branch_id === $branchId)
                    ?? Cash::openSessions()->first();
            }
            if (! $session) {
                throw ValidationException::withMessages(['cancel_reason' => 'El dinero vuelve a la caja y no hay ninguna abierta: abre una caja para anular.']);
            }

            Cash::record($session, 'income', $document->paymentMethod ?? self::cashMethod(), abs((float) $document->amount), $concept, $document);
        }
    }

    /** El método «efectivo» de la clínica: lo único que hay en el cajón. */
    public static function cashMethod(): PaymentMethod
    {
        PaymentMethod::ensureDefaults();

        return PaymentMethod::where('type', 'cash')->where('active', true)->orderBy('sort')->first()
            ?? throw ValidationException::withMessages(['source' => 'Activa un método de pago «Efectivo» para pagar desde la caja.']);
    }

    /**
     * Lo que necesita el selector «¿De dónde sale el dinero?».
     *
     * @return array{sources: array<string, string>, accounts: \Illuminate\Support\Collection, sessions: \Illuminate\Support\Collection, preferred_session_id: ?int, preferred_account_id: ?int}
     */
    public static function options(): array
    {
        $sources = self::available();
        $accounts = isset($sources['account'])
            ? FinanceAccount::where('active', true)->orderBy('name')->get()
            : collect();
        $sessions = isset($sources['cash']) ? Cash::openSessions() : collect();

        // Por defecto, el turno de la sucursal del usuario (si tiene una caja propia, esa).
        $branchId = auth()->user()?->branch_id ?? null;
        $preferred = $sessions->first(fn (CashSession $s) => $branchId && $s->caja?->branch_id === $branchId) ?? $sessions->first();

        return [
            'sources' => $sources,
            'accounts' => $accounts,
            'sessions' => $sessions,
            'preferred_session_id' => $preferred?->id,
            'preferred_account_id' => $accounts->first()?->id,
        ];
    }
}
