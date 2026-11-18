<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Procedure;
use App\Models\ProcedureSupply;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Support\Stock;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inventario › Movimientos: kardex de toda la clínica y registro manual de
 * entradas, salidas, ajustes por conteo y traslados entre consultorios.
 * También los insumos que gasta cada procedimiento del tarifario.
 */
class StockMovementController extends Controller
{
    public const KINDS = [
        'entrada' => ['Entrada', 'bi-box-arrow-in-down', '#059669'],
        'salida' => ['Salida', 'bi-box-arrow-up', '#dc2626'],
        'ajuste' => ['Ajuste por conteo', 'bi-clipboard-check', '#ca8a04'],
        'traslado' => ['Traslado', 'bi-arrow-left-right', '#2563eb'],
    ];

    public function index()
    {
        $from = request('from') ? Carbon::parse(request('from'))->startOfDay() : now()->subDays(30)->startOfDay();
        $to = request('to') ? Carbon::parse(request('to'))->endOfDay() : now()->endOfDay();

        return view('admin.stock-movements.index', [
            'movements' => StockMovement::with(['product', 'branch', 'batch', 'user'])
                ->whereBetween('created_at', [$from, $to])
                ->when(request('type'), fn ($q, $t) => $q->where('type', $t))
                ->when(request('reason'), fn ($q, $r) => $q->where('reason', $r))
                ->when(request('branch'), fn ($q, $b) => $q->where('branch_id', $b))
                ->when(request('q'), fn ($q, $term) => $q->whereHas('product', fn ($p) => $p->search($term)))
                ->latest('id')->paginate(40)->withQueryString(),
            'branches' => Branch::where('active', true)->orderBy('name')->get(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function create()
    {
        $kind = array_key_exists(request('kind'), self::KINDS) ? request('kind') : 'entrada';

        return view('admin.stock-movements.create', [
            'kind' => $kind,
            'products' => Product::with(['stocks', 'batches' => fn ($q) => $q->where('quantity', '>', 0)->orderBy('expires_at')])
                ->where('active', true)->orderBy('name')->get(),
            'branches' => Branch::where('active', true)->orderBy('name')->get(),
            'selected' => request('product'),
        ]);
    }

    public function store()
    {
        [$kind, $product] = self::record(request()->all());

        return redirect()->route('products.show', $product)->with('success', self::KINDS[$kind][0] . ' registrada: ' . $product->name . ' (existencia ' . Stock::number($product->fresh()->stockIn()) . ').');
    }

    /**
     * Registra un movimiento manual (web y API): entrada, salida, ajuste por
     * conteo o traslado.
     *
     * @return array{0: string, 1: Product} el tipo y el producto
     */
    public static function record(array $input): array
    {
        $companyId = app(Tenancy::class)->id();
        $kind = validator($input, ['kind' => ['required', Rule::in(array_keys(self::KINDS))]])->validate()['kind'];
        $data = validator($input, [
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'to_branch_id' => ['nullable', 'required_if:kind,traslado', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'quantity' => ['required', 'numeric', $kind === 'ajuste' ? 'min:0' : 'min:0.01', 'max:999999'],
            'reason' => ['nullable', 'string'],
            'product_batch_id' => ['nullable', 'integer'],
            'lot' => ['nullable', 'string', 'max:60'],
            'expires_at' => ['nullable', 'date'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['to_branch_id.required_if' => 'Elige a qué consultorio va.'], ['product_id' => 'producto', 'quantity' => 'cantidad'])->validate();

        $product = Product::findOrFail($data['product_id']);
        $branch = Stock::defaultBranch($data['branch_id'] ?? null);
        $batch = ! empty($data['product_batch_id'])
            ? ProductBatch::where('product_id', $product->id)->where('branch_id', $branch->id)->findOrFail($data['product_batch_id'])
            : null;
        $quantity = (float) $data['quantity'];

        $allowed = match ($kind) {
            'entrada' => StockMovement::MANUAL_IN_REASONS,
            'salida' => StockMovement::MANUAL_OUT_REASONS,
            default => null,
        };
        if ($allowed && ! in_array($data['reason'] ?? null, $allowed, true)) {
            throw ValidationException::withMessages(['reason' => 'Elige el motivo.']);
        }

        match ($kind) {
            'entrada' => Stock::receive($product, $branch, $quantity, $data['reason'], [
                'lot' => $data['lot'] ?? null, 'expires_at' => $data['expires_at'] ?? null,
                'unit_cost' => $data['unit_cost'] ?? null, 'notes' => $data['notes'] ?? null,
            ]),
            'salida' => Stock::consume($product, $branch, $quantity, $data['reason'], ['batch' => $batch, 'notes' => $data['notes'] ?? null]),
            'ajuste' => Stock::adjust($product, $branch, $quantity, $batch, $data['notes'] ?? null),
            'traslado' => Stock::transfer($product, $branch, Branch::findOrFail($data['to_branch_id']), $quantity, $batch, $data['notes'] ?? null),
        };

        return [$kind, $product];
    }

    // ── Insumos por procedimiento ──────────────────────────────────────────

    public function supplies(Procedure $procedure)
    {
        return view('admin.stock-movements.supplies', [
            'procedure' => $procedure,
            'supplies' => ProcedureSupply::with('product')->where('procedure_id', $procedure->id)->get(),
            'products' => Product::where('active', true)->orderBy('name')->get(['id', 'name', 'presentation', 'unit']),
        ]);
    }

    public function storeSupply(Procedure $procedure)
    {
        $data = request()->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', app(Tenancy::class)->id())->whereNull('deleted_at')],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:9999'],
        ], [], ['product_id' => 'insumo', 'quantity' => 'cantidad']);
        ProcedureSupply::updateOrCreate(['procedure_id' => $procedure->id, 'product_id' => $data['product_id']], ['quantity' => $data['quantity']]);

        return back()->with('success', 'Insumo guardado: se descuenta cada vez que se registre «' . $procedure->name . '» en una consulta.');
    }

    public function destroySupply(Procedure $procedure, ProcedureSupply $supply)
    {
        abort_unless($supply->procedure_id === $procedure->id, 404);
        $supply->delete();

        return back()->with('success', 'Insumo quitado.');
    }
}
