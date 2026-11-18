<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ProcedureSupply;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Support\Stock;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Inventario › Productos e insumos: lista con alertas, ficha con existencia, lotes y kardex. */
class ProductController extends Controller
{
    public const FILTERS = [
        'todos' => ['Activos', 'bi-box-seam'],
        'bajo' => ['Bajo mínimo', 'bi-exclamation-triangle'],
        'vencer' => ['Por vencer', 'bi-hourglass-bottom'],
        'venta' => ['A la venta', 'bi-cart'],
        'inactivos' => ['Inactivos', 'bi-archive'],
    ];

    public function index()
    {
        Product::ensureCategories();
        $filter = array_key_exists(request('filter'), self::FILTERS) ? request('filter') : 'todos';
        $expiringIds = ProductBatch::expiring()->pluck('product_id')->unique();

        $products = Product::with(['category', 'stocks'])
            ->when($filter === 'inactivos', fn ($q) => $q->where('active', false), fn ($q) => $q->where('active', true))
            ->when($filter === 'bajo', fn ($q) => $q->lowStock())
            ->when($filter === 'vencer', fn ($q) => $q->whereIn('id', $expiringIds))
            ->when($filter === 'venta', fn ($q) => $q->where('for_sale', true))
            ->when(request('category'), fn ($q, $c) => $q->where('product_category_id', $c))
            ->search(request('q'))
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'filter' => $filter,
            'counts' => [
                'todos' => Product::where('active', true)->count(),
                'bajo' => Product::where('active', true)->lowStock()->count(),
                'vencer' => $expiringIds->count(),
                'venta' => Product::where('active', true)->where('for_sale', true)->count(),
            ],
            'categories' => ProductCategory::where('active', true)->orderBy('name')->get(),
            'expiringIds' => $expiringIds->all(),
            'value' => round((float) DB::table('product_stocks')->join('products', 'products.id', '=', 'product_stocks.product_id')
                ->where('products.company_id', app(Tenancy::class)->id())->whereNull('products.deleted_at')->where('product_stocks.quantity', '>', 0)
                ->sum(DB::raw('product_stocks.quantity * products.purchase_price')), 2),
        ]);
    }

    public function create()
    {
        Product::ensureCategories();

        return view('admin.products.create', $this->formData() + ['product' => null]);
    }

    public function store()
    {
        $data = $this->validateProduct();
        $initial = request()->validate([
            'initial_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'initial_branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', app(Tenancy::class)->id())],
            'initial_lot' => ['nullable', 'string', 'max:60'],
            'initial_expires_at' => ['nullable', 'date'],
        ]);

        $product = DB::transaction(function () use ($data, $initial) {
            $product = Product::create($data);
            if ((float) ($initial['initial_quantity'] ?? 0) > 0) {
                Stock::receive($product, Stock::defaultBranch($initial['initial_branch_id'] ?? null), (float) $initial['initial_quantity'], 'initial', [
                    'lot' => $initial['initial_lot'] ?? null, 'expires_at' => $initial['initial_expires_at'] ?? null,
                    'unit_cost' => (float) $product->purchase_price ?: null,
                ]);
            }

            return $product;
        });

        return redirect()->route('products.show', $product)->with('success', "«{$product->name}» registrado.");
    }

    public function show(Product $product)
    {
        $product->load(['category', 'stocks.branch']);

        return view('admin.products.show', [
            'product' => $product,
            'batches' => $product->batches()->with('branch')->where('quantity', '>', 0)->orderByRaw('expires_at is null')->orderBy('expires_at')->get(),
            'movements' => StockMovement::with(['branch', 'batch', 'user'])->where('product_id', $product->id)
                ->when(request('branch'), fn ($q, $b) => $q->where('branch_id', $b))
                ->latest('id')->paginate(25)->withQueryString(),
            'supplies' => ProcedureSupply::with('procedure')->where('product_id', $product->id)->get(),
            'branches' => Branch::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function edit(Product $product)
    {
        return view('admin.products.edit', $this->formData() + ['product' => $product]);
    }

    public function update(Product $product)
    {
        $data = $this->validateProduct($product);
        if ($product->track_batches && ! $data['track_batches'] && $product->batches()->where('quantity', '>', 0)->exists()) {
            return back()->withInput()->withErrors(['track_batches' => 'Tiene lotes con existencia: no se puede dejar de controlar por lote.']);
        }
        $product->update($data);

        return redirect()->route('products.show', $product)->with('success', 'Producto actualizado.');
    }

    public function destroy(Product $product)
    {
        if ($product->stockIn() != 0 || $product->movements()->exists()) {
            $product->update(['active' => false]);

            return redirect()->route('products.index')->with('success', "«{$product->name}» quedó inactivo (tiene historial en el kardex).");
        }
        $product->delete();

        return redirect()->route('products.index')->with('success', "«{$product->name}» eliminado.");
    }

    // ── Categorías ─────────────────────────────────────────────────────────

    public function storeCategory()
    {
        $name = $this->validateCategory();
        ProductCategory::create(['name' => $name, 'active' => true]);

        return back()->with('success', "Categoría «{$name}» creada.");
    }

    public function updateCategory(ProductCategory $category)
    {
        $name = $this->validateCategory($category);
        $category->update(['name' => $name, 'active' => request()->boolean('active', true)]);

        return back()->with('success', 'Categoría actualizada.');
    }

    // ── Compartido ─────────────────────────────────────────────────────────

    protected function formData(): array
    {
        return [
            'categories' => ProductCategory::where('active', true)->orderBy('name')->get(),
            'branches' => Branch::where('active', true)->orderBy('name')->get(),
        ];
    }

    protected function validateCategory(?ProductCategory $category = null): string
    {
        return trim(request()->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('product_categories', 'name')
                ->where('company_id', app(Tenancy::class)->id())->ignore($category?->id)],
        ], ['name.unique' => 'Ya hay una categoría con ese nombre.'], ['name' => 'nombre'])['name']);
    }

    protected function validateProduct(?Product $product = null): array
    {
        $companyId = app(Tenancy::class)->id();
        $data = request()->validate([
            'product_category_id' => ['nullable', Rule::exists('product_categories', 'id')->where('company_id', $companyId)],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('products', 'code')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:160'],
            'brand' => ['nullable', 'string', 'max:80'],
            'presentation' => ['nullable', 'string', 'max:120'],
            'unit' => ['required', Rule::in(array_keys(Product::UNITS))],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['code.unique' => 'Ya hay un producto con ese código.'], ['name' => 'nombre', 'unit' => 'unidad', 'sale_price' => 'precio de venta']);

        return array_merge($data, [
            'purchase_price' => (float) ($data['purchase_price'] ?? 0),
            'sale_price' => (float) ($data['sale_price'] ?? 0),
            'min_stock' => (float) ($data['min_stock'] ?? 0),
            'track_batches' => request()->boolean('track_batches'),
            'for_sale' => request()->boolean('for_sale'),
            'active' => $product ? request()->boolean('active') : true,
        ]);
    }
}
