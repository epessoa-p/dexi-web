<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\PurchaseReception;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Compras (ESTRUCTURA_CUENTAS_Y_GASTOS.md §2.7): alta, recepción (parcial o
 * total, entra al stock), pagos en cuotas con el mismo origen del dinero que
 * un gasto (cuenta o caja), cierre con diferencias y anulación.
 */
class Purchases
{
    public static function create(array $input): Purchase
    {
        $companyId = app(Tenancy::class)->id();
        $data = validator($input, [
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'purchased_at' => ['required', 'date', 'before_or_equal:today'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'receive_now' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.lot' => ['nullable', 'string', 'max:60'],
            'items.*.expires_at' => ['nullable', 'date'],
            'pay_amount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'items.required' => 'Agrega al menos un producto a la compra.',
            'purchased_at.before_or_equal' => 'La fecha de compra no puede ser futura.',
        ], ['supplier_id' => 'proveedor', 'purchased_at' => 'fecha', 'items.*.product_id' => 'producto', 'items.*.quantity' => 'cantidad', 'items.*.unit_cost' => 'costo'])->validate();

        $lines = collect($data['items'])->map(function (array $item) {
            $gross = round((float) $item['quantity'] * (float) $item['unit_cost'], 2);
            $discount = min(round((float) ($item['discount'] ?? 0), 2), $gross);

            return $item + ['discount' => $discount, 'total' => round($gross - $discount, 2)];
        });
        $subtotal = round($lines->sum('total'), 2);
        $discount = min(round((float) ($data['discount'] ?? 0), 2), $subtotal);
        $total = round($subtotal - $discount, 2);
        $payNow = round((float) ($data['pay_amount'] ?? 0), 2);
        if ($payNow > $total) {
            throw ValidationException::withMessages(['pay_amount' => 'Lo pagado no puede superar el total de la compra (' . Money::format($total) . ').']);
        }
        $from = $payNow > 0 ? PaymentSource::validate($input) : null;
        $branch = Stock::defaultBranch($data['branch_id'] ?? null);

        return DB::transaction(function () use ($data, $lines, $subtotal, $discount, $total, $payNow, $from, $branch, $companyId) {
            Company::whereKey($companyId)->lockForUpdate()->first();
            $purchase = Purchase::create([
                'branch_id' => $branch->id,
                'supplier_id' => $data['supplier_id'],
                'number' => (int) Purchase::max('number') + 1,
                'document_number' => $data['document_number'] ?? null,
                'purchased_at' => $data['purchased_at'],
                'status' => 'pedida',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'paid' => 0,
                'balance' => $total,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => auth()->id(),
            ]);

            $receive = [];
            foreach ($lines as $line) {
                $item = $purchase->items()->create(collect($line)->only(['product_id', 'quantity', 'unit_cost', 'discount', 'total'])->all());
                $receive[] = ['item_id' => $item->id, 'quantity' => (float) $line['quantity'], 'lot' => $line['lot'] ?? null, 'expires_at' => $line['expires_at'] ?? null];
            }

            // La mercadería llegó con la factura: se recibe en el mismo acto.
            if (! empty($data['receive_now'])) {
                self::receive($purchase, ['lines' => $receive, 'notes' => 'Recibida al registrar la compra']);
            }
            if ($payNow > 0) {
                self::pay($purchase, ['amount' => $payNow], $from);
            }

            return $purchase->fresh();
        });
    }

    /**
     * Recepción: lo recibido entra al stock del consultorio de la compra, con
     * su lote, al costo real (descuentos repartidos).
     *
     * @param  array{lines: array<int, array{item_id: int, quantity: float, lot?: ?string, expires_at?: ?string}>, notes?: ?string}  $input
     */
    public static function receive(Purchase $purchase, array $input): PurchaseReception
    {
        return DB::transaction(function () use ($purchase, $input) {
            $purchase = Purchase::lockForUpdate()->with(['items.product', 'items.purchase', 'branch', 'supplier'])->findOrFail($purchase->id);
            if (! $purchase->isPending()) {
                throw ValidationException::withMessages(['lines' => 'Esta compra no tiene mercadería pendiente de recibir.']);
            }

            $rows = collect($input['lines'] ?? [])
                ->map(fn ($line) => $line + ['item' => $purchase->items->firstWhere('id', (int) ($line['item_id'] ?? 0))])
                ->filter(fn ($line) => $line['item'] && (float) ($line['quantity'] ?? 0) > 0);
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Indica qué cantidad llegó.']);
            }

            $reception = PurchaseReception::create(['purchase_id' => $purchase->id, 'received_at' => now(), 'notes' => $input['notes'] ?? null, 'user_id' => auth()->id()]);

            foreach ($rows as $line) {
                $item = $line['item'];
                $quantity = round((float) $line['quantity'], 2);
                if ($quantity > $item->pendingQuantity()) {
                    throw ValidationException::withMessages(['lines' => "De «{$item->product->name}» quedan " . Stock::number($item->pendingQuantity()) . ' por recibir.']);
                }
                Stock::receive($item->product, $purchase->branch, $quantity, 'purchase', [
                    'lot' => $line['lot'] ?? null, 'expires_at' => $line['expires_at'] ?? null,
                    'unit_cost' => $item->netUnitCost(), 'source' => $purchase,
                    'notes' => "Compra {$purchase->code} · {$purchase->supplier?->name}",
                ]);
                $reception->items()->create(['purchase_item_id' => $item->id, 'quantity' => $quantity, 'lot' => $line['lot'] ?? null, 'expires_at' => $line['expires_at'] ?? null]);
                $item->increment('received_quantity', $quantity);
            }

            $complete = $purchase->items->every(fn ($item) => $item->fresh()->pendingQuantity() <= 0);
            $purchase->update(['status' => $complete ? 'recibida' : 'parcial', 'received_at' => $complete ? now() : $purchase->received_at]);

            return $reception;
        });
    }

    /** Cierra una compra recibida en parte: lo que no llegó ya no se espera y la deuda se ajusta a lo recibido. */
    public static function closeWithDifferences(Purchase $purchase, string $notes): void
    {
        DB::transaction(function () use ($purchase, $notes) {
            $purchase = Purchase::lockForUpdate()->with('items.purchase')->findOrFail($purchase->id);
            if ($purchase->status !== 'parcial') {
                throw ValidationException::withMessages(['closing_notes' => 'Solo se cierra con diferencias una compra recibida en parte.']);
            }
            $received = round($purchase->items->sum(fn ($i) => (float) $i->received_quantity * (float) $i->total / max((float) $i->quantity, 0.01)), 2);
            $ratio = (float) $purchase->subtotal > 0 ? $received / (float) $purchase->subtotal : 0;
            $discount = round((float) $purchase->discount * $ratio, 2);
            $total = round($received - $discount, 2);
            if ($total < (float) $purchase->paid) {
                throw ValidationException::withMessages(['closing_notes' => 'Ya se pagó más de lo recibido (' . Money::format($purchase->paid) . '): anula un pago antes de cerrar.']);
            }
            $purchase->update([
                'subtotal' => $received, 'discount' => $discount, 'total' => $total,
                'balance' => round($total - (float) $purchase->paid, 2),
                'status' => 'recibida', 'received_at' => now(), 'closing_notes' => $notes,
            ]);
        });
    }

    /**
     * Pago (cuota) al proveedor: sale de una cuenta o de la caja, igual que un gasto.
     *
     * @param  array{source: string, account: ?\App\Models\FinanceAccount, session: ?\App\Models\CashSession}|null  $from
     */
    public static function pay(Purchase $purchase, array $input, ?array $from = null): PurchasePayment
    {
        $data = validator($input, [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:80'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
        ], [], ['amount' => 'monto', 'paid_at' => 'fecha'])->validate();
        $from ??= PaymentSource::validate($input);
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($purchase, $data, $from, $amount) {
            $purchase = Purchase::lockForUpdate()->with('supplier')->findOrFail($purchase->id);
            if ($purchase->isCancelled()) {
                throw ValidationException::withMessages(['amount' => 'La compra está anulada.']);
            }
            if ($amount > (float) $purchase->balance) {
                throw ValidationException::withMessages(['amount' => 'El pago no puede superar el saldo de ' . Money::format($purchase->balance) . '.']);
            }

            $payment = PurchasePayment::create([
                'purchase_id' => $purchase->id,
                'branch_id' => $from['session']?->caja?->branch_id ?? $from['account']?->branch_id ?? $purchase->branch_id,
                'source' => $from['source'],
                'finance_account_id' => $from['account']?->id,
                'cash_session_id' => $from['session']?->id,
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'paid_at' => $data['paid_at'] ?? today()->toDateString(),
                'status' => 'pagado',
                'user_id' => auth()->id(),
            ]);
            $payment->update(PaymentSource::charge($from, $amount, "Pago compra {$purchase->code} · {$purchase->supplier?->name}", 'compra',
                $payment, $payment->paid_at->toDateString(), $payment->reference));

            $purchase->update(['paid' => round((float) $purchase->paid + $amount, 2), 'balance' => round((float) $purchase->balance - $amount, 2)]);

            return $payment;
        });
    }

    /** Anula un pago: el dinero vuelve a donde salió y la deuda sube otra vez. */
    public static function cancelPayment(PurchasePayment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason) {
            $payment = PurchasePayment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->isCancelled()) {
                throw ValidationException::withMessages(['cancel_reason' => 'Ese pago ya está anulado.']);
            }
            $purchase = Purchase::lockForUpdate()->findOrFail($payment->purchase_id);
            PaymentSource::refund($payment, "Anula pago de la compra {$purchase->code}");
            $payment->update(['status' => 'anulado', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $purchase->update(['paid' => round((float) $purchase->paid - (float) $payment->amount, 2), 'balance' => round((float) $purchase->balance + (float) $payment->amount, 2)]);
        });
    }

    /** Solo se anula una compra sin recepciones ni pagos vigentes: lo demás ya es historia. */
    public static function cancel(Purchase $purchase, string $reason): void
    {
        if ($purchase->receptions()->exists() || $purchase->payments()->where('status', 'pagado')->exists()) {
            throw ValidationException::withMessages(['cancel_reason' => 'La compra ya tiene mercadería recibida o pagos: no se puede anular.']);
        }
        $purchase->update(['status' => 'anulada', 'balance' => 0, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
    }
}
