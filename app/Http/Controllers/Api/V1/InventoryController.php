<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\StockMovementController;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Support\Purchases;
use App\Support\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventario en la app: buscar productos (también por código de barras),
 * existencia por consultorio y lotes, bajo el mínimo y por vencer; entradas,
 * salidas y conteo; recibir la mercadería de una compra. El alta de productos
 * y las compras completas siguen en la web.
 */
class InventoryController extends Controller
{
    /** ?q= busca por nombre, marca, código o código de barras; ?filter=bajo|vence. */
    public function products(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), ['bajo', 'vence'], true) ? $request->query('filter') : 'todos';
        $expiringIds = ProductBatch::expiring()->distinct()->pluck('product_id');

        $products = Product::with(['stocks', 'category'])->where('active', true)
            ->search($request->query('q'))
            ->when($filter === 'bajo', fn ($q) => $q->lowStock())
            ->when($filter === 'vence', fn ($q) => $q->whereIn('id', $expiringIds))
            ->orderBy('name')->limit(100)->get();

        return response()->json([
            'filter' => $filter,
            'counts' => [
                'low' => Product::where('active', true)->lowStock()->count(),
                'expiring' => $expiringIds->count(),
            ],
            'data' => $products->map(fn (Product $p) => self::summary($p, $expiringIds->contains($p->id)))->values(),
        ]);
    }

    /** Para el escáner: el producto con ese código de barras (o código interno), o 404. */
    public function scan(string $code): JsonResponse
    {
        $product = Product::with(['stocks', 'category'])->where('active', true)
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('code', $code))->first();
        abort_unless($product, 404, 'No hay ningún producto con el código ' . $code . '.');

        return response()->json(self::detail($product));
    }

    public function product(Product $product): JsonResponse
    {
        return response()->json(self::detail($product));
    }

    /** Consultorios y motivos para registrar un movimiento. */
    public function movementOptions(): JsonResponse
    {
        $reasons = fn (array $keys) => collect($keys)->map(fn ($k) => ['key' => $k, 'label' => StockMovement::REASONS[$k]])->values();

        return response()->json([
            'branches' => Branch::where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'default_branch' => Stock::defaultBranch()->id,
            'in_reasons' => $reasons(StockMovement::MANUAL_IN_REASONS),
            'out_reasons' => $reasons(StockMovement::MANUAL_OUT_REASONS),
        ]);
    }

    /** kind: entrada | salida | ajuste (traslados, en la web). */
    public function move(Request $request): JsonResponse
    {
        abort_if($request->input('kind') === 'traslado', 422, 'Los traslados entre consultorios se hacen en la web.');
        [, $product] = StockMovementController::record($request->all());

        return response()->json(self::detail($product->fresh()), 201);
    }

    /** Compras con mercadería por recibir. */
    public function pendingPurchases(): JsonResponse
    {
        $purchases = Purchase::with(['supplier', 'branch', 'items.product'])->whereIn('status', ['pedida', 'parcial'])->orderBy('id')->get();

        return response()->json([
            'data' => $purchases->map(fn (Purchase $p) => self::purchase($p))->values(),
        ]);
    }

    public function purchaseDetail(Purchase $purchase): JsonResponse
    {
        return response()->json(self::purchase($purchase->load(['supplier', 'branch', 'items.product'])));
    }

    /** lines[]: {item_id, quantity, lot?, expires_at?} */
    public function receive(Request $request, Purchase $purchase): JsonResponse
    {
        Purchases::receive($purchase, $request->only(['lines', 'notes']));

        return response()->json(self::purchase($purchase->fresh(['supplier', 'branch', 'items.product'])), 201);
    }

    // ── Presentación ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function summary(Product $p, bool $expiring = false): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'label' => $p->label,
            'brand' => $p->brand,
            'code' => $p->code,
            'barcode' => $p->barcode,
            'category' => $p->category?->name,
            'unit' => $p->unit_label,
            'stock' => $p->stockIn(),
            'min_stock' => (float) $p->min_stock,
            'low' => $p->isLowStock(),
            'expiring' => $expiring,
            'track_batches' => (bool) $p->track_batches,
            'for_sale' => (bool) $p->for_sale,
            'sale_price' => (float) $p->sale_price,
        ];
    }

    /** @return array<string, mixed> */
    private static function detail(Product $p): array
    {
        $p->load(['stocks.branch', 'category']);
        $batches = ProductBatch::with('branch')->where('product_id', $p->id)->available()->orderBy('expires_at')->get();

        return self::summary($p, $batches->contains(fn ($b) => $b->isExpiringSoon() || $b->isExpired())) + [
            'presentation' => $p->presentation,
            'purchase_price' => (float) $p->purchase_price,
            'by_branch' => $p->stocks->map(fn ($s) => ['branch_id' => $s->branch_id, 'branch' => $s->branch?->name, 'quantity' => (float) $s->quantity])->values(),
            'batches' => $batches->map(fn (ProductBatch $b) => [
                'id' => $b->id,
                'lot' => $b->lot,
                'branch_id' => $b->branch_id,
                'branch' => $b->branch?->name,
                'expires_at' => $b->expires_at?->toDateString(),
                'quantity' => (float) $b->quantity,
                'expired' => $b->isExpired(),
                'expiring' => $b->isExpiringSoon(),
            ])->values(),
            'movements' => StockMovement::with(['branch', 'user'])->where('product_id', $p->id)->latest('id')->limit(15)->get()->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'type_label' => $m->type_label,
                'reason' => $m->reason_label,
                'quantity' => (float) $m->quantity,
                'branch' => $m->branch?->name,
                'notes' => $m->notes,
                'at' => $m->created_at?->format('Y-m-d\TH:i:s'),
                'user' => $m->user?->name,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private static function purchase(Purchase $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'status' => $p->status,
            'status_label' => Purchase::STATUSES[$p->status][0] ?? $p->status,
            'supplier' => $p->supplier?->name,
            'branch' => $p->branch?->name,
            'created_at' => $p->created_at?->toDateString(),
            'items' => $p->items->map(fn (PurchaseItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->product?->label,
                'barcode' => $i->product?->barcode,
                'track_batches' => (bool) $i->product?->track_batches,
                'quantity' => (float) $i->quantity,
                'received' => (float) $i->received_quantity,
                'pending' => $i->pendingQuantity(),
            ])->values(),
        ];
    }
}
