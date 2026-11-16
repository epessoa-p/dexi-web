<?php

namespace App\Support;

use App\Models\FinanceAccount;
use App\Models\FinanceMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tesorería: el ÚNICO lugar que cambia el saldo de una cuenta. Cada ingreso o
 * egreso actualiza el saldo y escribe su renglón del extracto en la misma
 * transacción, con la cuenta bloqueada: el saldo y el historial no pueden
 * descuadrar. Nada se borra: anular escribe el movimiento contrario.
 */
final class Treasury
{
    /**
     * Ingreso o egreso. `$attributes`: origin (manual por defecto), occurred_on,
     * reference, source (el documento que lo originó) y los enlaces internos.
     */
    public static function move(FinanceAccount $account, string $type, float $amount, string $concept, array $attributes = []): FinanceMovement
    {
        $amount = round($amount, 2);
        $origin = $attributes['origin'] ?? 'manual';

        if (! in_array($type, ['ingreso', 'egreso'], true)) {
            throw ValidationException::withMessages(['type' => 'Elige si es un ingreso o un egreso.']);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'El importe debe ser mayor a cero.']);
        }

        return DB::transaction(function () use ($account, $type, $amount, $concept, $attributes, $origin) {
            $account = FinanceAccount::withTrashed()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            // Una anulación siempre se escribe, aunque la cuenta esté inactiva o quede en negativo.
            if ($origin !== 'anulacion') {
                if (! $account->active) {
                    throw ValidationException::withMessages(['finance_account_id' => "La cuenta {$account->name} está inactiva."]);
                }
                if ($type === 'egreso' && $amount > (float) $account->balance) {
                    throw ValidationException::withMessages([
                        'amount' => "En {$account->name} hay " . Money::format($account->balance, app(Tenancy::class)->company()) . ': no alcanza.',
                    ]);
                }
            }

            $balance = round((float) $account->balance + ($type === 'ingreso' ? $amount : -$amount), 2);
            $account->update(['balance' => $balance]);

            $source = $attributes['source'] ?? null;

            return FinanceMovement::create([
                'company_id' => $account->company_id,
                'finance_account_id' => $account->id,
                'type' => $type,
                'origin' => $origin,
                'concept' => $concept,
                'amount' => $amount,
                'balance_after' => $balance,
                'occurred_on' => $attributes['occurred_on'] ?? now()->toDateString(),
                'reference' => $attributes['reference'] ?? null,
                'transfer_movement_id' => $attributes['transfer_movement_id'] ?? null,
                'reverses_movement_id' => $attributes['reverses_movement_id'] ?? null,
                'source_type' => $source instanceof Model ? $source->getMorphClass() : null,
                'source_id' => $source instanceof Model ? $source->getKey() : null,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Transferencia: egreso en el origen e ingreso en el destino, enlazados.
     *
     * @return array{0: FinanceMovement, 1: FinanceMovement} [salida, entrada]
     */
    public static function transfer(FinanceAccount $from, FinanceAccount $to, float $amount, string $concept, ?string $date = null, ?string $reference = null): array
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_account_id' => 'Elige otra cuenta de destino.']);
        }

        return DB::transaction(function () use ($from, $to, $amount, $concept, $date, $reference) {
            // Siempre en el mismo orden, para que dos transferencias cruzadas no se bloqueen entre sí.
            FinanceAccount::whereIn('id', [$from->id, $to->id])->orderBy('id')->lockForUpdate()->get();

            $attributes = ['origin' => 'transferencia', 'occurred_on' => $date, 'reference' => $reference];
            $out = self::move($from, 'egreso', $amount, "{$concept} → {$to->name}", $attributes);
            $in = self::move($to, 'ingreso', $amount, "{$concept} ← {$from->name}", $attributes + ['transfer_movement_id' => $out->id]);
            $out->update(['transfer_movement_id' => $in->id]);

            return [$out, $in];
        });
    }

    /**
     * Anula un movimiento: lo marca y escribe el contrario. Una transferencia
     * se anula entera (sus dos patas).
     */
    public static function cancel(FinanceMovement $movement): void
    {
        DB::transaction(function () use ($movement) {
            $movement = FinanceMovement::whereKey($movement->id)->lockForUpdate()->firstOrFail();

            if ($movement->isCancelled()) {
                throw ValidationException::withMessages(['movement' => 'Ese movimiento ya está anulado.']);
            }
            if ($movement->origin === 'anulacion') {
                throw ValidationException::withMessages(['movement' => 'Una anulación no se anula.']);
            }

            foreach (array_filter([$movement, $movement->transferPair]) as $leg) {
                $leg->update(['cancelled_at' => now(), 'cancelled_by' => auth()->id()]);
                self::move($leg->account, $leg->isIncome() ? 'egreso' : 'ingreso', (float) $leg->amount, "Anula: {$leg->concept}", [
                    'origin' => 'anulacion',
                    'reverses_movement_id' => $leg->id,
                    'source' => $leg->source_type ? $leg->source : null,
                ]);
            }
        });
    }
}
