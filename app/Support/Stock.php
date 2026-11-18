<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único punto por el que cambia la existencia de un producto.
 *
 * Cada operación deja su movimiento en el kardex con el saldo resultante y
 * actualiza, en la misma transacción, la existencia por consultorio y los
 * lotes. Las salidas consumen primero el lote que vence antes (FEFO) y nunca
 * uno vencido.
 */
class Stock
{
    /** Activo si el plan incluye inventario. */
    public static function enabled(): bool
    {
        return (bool) app(Tenancy::class)->company()?->planAllows('inventario');
    }

    /** Consultorio donde se mueve el stock cuando la operación no indica otro (se crea si la clínica no tiene). */
    public static function defaultBranch(?int $branchId = null): Branch
    {
        $companyId = app(Tenancy::class)->id();
        if ($branchId && ($branch = Branch::where('active', true)->find($branchId))) {
            return $branch;
        }

        return Branch::where('active', true)->orderBy('id')->first()
            ?? Branch::create(['company_id' => $companyId, 'name' => 'Consultorio principal', 'active' => true]);
    }

    /**
     * Entrada. Con lote, se suma al lote existente o se crea.
     *
     * @param  array{lot?: ?string, expires_at?: mixed, unit_cost?: ?float, notes?: ?string, source?: ?Model, type?: string}  $options
     */
    public static function receive(Product $product, Branch $branch, float $quantity, string $reason, array $options = []): StockMovement
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser mayor a cero.']);
        }
        if ($product->track_batches && empty($options['lot'])) {
            throw ValidationException::withMessages(['lot' => "«{$product->name}» se controla por lote: indica lote y vencimiento."]);
        }

        return DB::transaction(function () use ($product, $branch, $quantity, $reason, $options) {
            $batch = null;
            if (! empty($options['lot'])) {
                $batch = ProductBatch::where('product_id', $product->id)->where('branch_id', $branch->id)->where('lot', $options['lot'])
                    ->lockForUpdate()->first()
                    ?? new ProductBatch(['company_id' => $product->company_id, 'product_id' => $product->id, 'branch_id' => $branch->id,
                        'lot' => $options['lot'], 'quantity' => 0, 'initial_quantity' => 0]);
                if (! empty($options['expires_at'])) {
                    $batch->expires_at = $options['expires_at'];
                }
                $batch->quantity = (float) $batch->quantity + $quantity;
                $batch->initial_quantity = (float) $batch->initial_quantity + $quantity;
                $batch->save();
            }

            if ($reason === 'purchase' && ! empty($options['unit_cost'])) {
                $product->update(['purchase_price' => $options['unit_cost']]);
            }

            return self::record($product, $branch, $batch, $options['type'] ?? 'in', $reason, $quantity, $options);
        });
    }

    /**
     * Salida. Sin lote elegido, consume FEFO entre los lotes no vencidos. Con
     * `allow_negative` (un procedimiento ya realizado) registra la salida aunque
     * falte: la existencia queda negativa y a la vista.
     *
     * @param  array{batch?: ?ProductBatch, notes?: ?string, source?: ?Model, allow_negative?: bool, type?: string}  $options
     * @return Collection<int, StockMovement>
     */
    public static function consume(Product $product, Branch $branch, float $quantity, string $reason, array $options = []): Collection
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser mayor a cero.']);
        }

        return DB::transaction(function () use ($product, $branch, $quantity, $reason, $options) {
            $type = $options['type'] ?? 'out';
            $allowNegative = $options['allow_negative'] ?? false;
            $movements = collect();
            $remaining = $quantity;

            if ($product->track_batches) {
                $batches = isset($options['batch'])
                    ? collect([ProductBatch::lockForUpdate()->findOrFail($options['batch']->id)])
                    : ProductBatch::where('product_id', $product->id)->where('branch_id', $branch->id)->available()
                        ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', today()))
                        ->orderByRaw('expires_at is null')->orderBy('expires_at')->orderBy('id')
                        ->lockForUpdate()->get();

                foreach ($batches as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $take = min($remaining, (float) $batch->quantity);
                    if ($take <= 0) {
                        continue;
                    }
                    $batch->decrement('quantity', $take);
                    $movements->push(self::record($product, $branch, $batch, $type, $reason, -$take, $options));
                    $remaining = round($remaining - $take, 2);
                }
            } else {
                $available = (float) self::stockRow($product, $branch)->quantity;
                $take = $allowNegative ? $remaining : min($remaining, max($available, 0));
                if ($take > 0) {
                    $movements->push(self::record($product, $branch, null, $type, $reason, -$take, $options));
                    $remaining = round($remaining - $take, 2);
                }
            }

            if ($remaining > 0) {
                if (! $allowNegative) {
                    throw ValidationException::withMessages([
                        'quantity' => sprintf('No alcanza el stock de %s en %s: faltan %s %s.',
                            $product->name, $branch->name, self::number($remaining), mb_strtolower($product->unit_label)),
                    ]);
                }
                $movements->push(self::record($product, $branch, null, $type, $reason, -$remaining, $options));
            }

            return $movements;
        });
    }

    /** Ajuste por inventario físico: lleva la existencia (o el lote) a lo contado. */
    public static function adjust(Product $product, Branch $branch, float $counted, ?ProductBatch $batch = null, ?string $notes = null): ?StockMovement
    {
        if ($counted < 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad contada no puede ser negativa.']);
        }
        if ($product->track_batches && ! $batch) {
            throw ValidationException::withMessages(['product_batch_id' => 'Elige el lote que contaste.']);
        }

        return DB::transaction(function () use ($product, $branch, $counted, $batch, $notes) {
            if ($batch) {
                $batch = ProductBatch::lockForUpdate()->findOrFail($batch->id);
                $difference = round($counted - (float) $batch->quantity, 2);
                $batch->update(['quantity' => $counted]);
            } else {
                $difference = round($counted - (float) self::stockRow($product, $branch)->quantity, 2);
            }

            return $difference == 0 ? null : self::record($product, $branch, $batch, 'adjustment', 'count', $difference, ['notes' => $notes]);
        });
    }

    /** Traslado entre consultorios, conservando lote y vencimiento. */
    public static function transfer(Product $product, Branch $from, Branch $to, float $quantity, ?ProductBatch $batch = null, ?string $notes = null): void
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_branch_id' => 'El consultorio de destino debe ser distinto al de origen.']);
        }

        DB::transaction(function () use ($product, $from, $to, $quantity, $batch, $notes) {
            $outs = self::consume($product, $from, $quantity, 'transfer', ['batch' => $batch, 'type' => 'transfer_out', 'notes' => $notes ?? "Hacia {$to->name}"]);
            foreach ($outs as $out) {
                self::receive($product, $to, abs((float) $out->quantity), 'transfer', [
                    'type' => 'transfer_in', 'lot' => $out->batch?->lot, 'expires_at' => $out->batch?->expires_at, 'notes' => $notes ?? "Desde {$from->name}",
                ]);
            }
        });
    }

    /**
     * Devuelve todo lo que sacó un registro (venta anulada, procedimiento
     * quitado), a los mismos lotes. Idempotente: lo ya devuelto no se repite.
     */
    public static function restore(Model $source, string $reason, ?string $notes = null): void
    {
        DB::transaction(function () use ($source, $reason, $notes) {
            $movements = StockMovement::where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->get();

            $pending = $movements->groupBy(fn ($m) => $m->product_id . '|' . $m->branch_id . '|' . $m->product_batch_id)
                ->map(fn ($group) => ['movement' => $group->first(), 'quantity' => -round((float) $group->sum('quantity'), 2)])
                ->filter(fn ($row) => $row['quantity'] > 0);

            foreach ($pending as $row) {
                $m = $row['movement'];
                $batch = $m->product_batch_id ? ProductBatch::lockForUpdate()->find($m->product_batch_id) : null;
                $batch?->increment('quantity', $row['quantity']);
                self::record($m->product, $m->branch, $batch, 'in', $reason, $row['quantity'], ['source' => $source, 'notes' => $notes]);
            }
        });
    }

    protected static function stockRow(Product $product, Branch $branch): ProductStock
    {
        ProductStock::firstOrCreate(['product_id' => $product->id, 'branch_id' => $branch->id], ['company_id' => $product->company_id, 'quantity' => 0]);

        return ProductStock::where('product_id', $product->id)->where('branch_id', $branch->id)->lockForUpdate()->first();
    }

    protected static function record(Product $product, Branch $branch, ?ProductBatch $batch, string $type, string $reason, float $quantity, array $options): StockMovement
    {
        $stock = self::stockRow($product, $branch);
        $stock->quantity = round((float) $stock->quantity + $quantity, 2);
        $stock->save();
        $source = $options['source'] ?? null;

        return StockMovement::create([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'product_batch_id' => $batch?->id,
            'type' => $type,
            'reason' => $reason,
            'quantity' => $quantity,
            'balance_after' => $stock->quantity,
            'unit_cost' => $options['unit_cost'] ?? null,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'notes' => $options['notes'] ?? null,
            'user_id' => auth()->id(),
        ]);
    }

    /** «12», «2.5»: cantidad sin decimales sobrantes. */
    public static function number(float|string|null $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
    }

    // ── Insumos de los procedimientos ──────────────────────────────────────

    /** Descuenta los insumos de un procedimiento registrado en la consulta (aunque falte stock). */
    public static function consumeSupplies(\App\Models\ConsultationProcedure $item): void
    {
        if (! self::enabled() || ! $item->procedure_id) {
            return;
        }
        $supplies = \App\Models\ProcedureSupply::with('product')->where('procedure_id', $item->procedure_id)->get();
        if ($supplies->isEmpty()) {
            return;
        }
        $consultation = $item->consultation;
        $branch = self::defaultBranch($consultation?->branch_id);
        foreach ($supplies as $supply) {
            if (! $supply->product || ! $supply->product->active) {
                continue;
            }
            self::consume($supply->product, $branch, round((float) $supply->quantity * max(1, (int) $item->quantity), 2), 'procedure', [
                'allow_negative' => true, 'source' => $item,
                'notes' => trim(($consultation?->code ? $consultation->code . ' · ' : '') . $item->name . ($consultation?->patient ? ' · ' . $consultation->patient->full_name : '')),
            ]);
        }
    }
}
