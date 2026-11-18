<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Producto o insumo dental (anestesia, resinas, guantes, cepillos…). La
 * existencia vive en ProductStock (por consultorio) y solo cambia a través de
 * App\Support\Stock, que deja el movimiento en el kardex.
 */
class Product extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const UNITS = [
        'unidad' => 'Unidad',
        'caja' => 'Caja',
        'cartucho' => 'Cartucho',
        'jeringa' => 'Jeringa',
        'frasco' => 'Frasco',
        'sobre' => 'Sobre',
        'par' => 'Par',
        'rollo' => 'Rollo',
        'kit' => 'Kit',
        'ml' => 'Mililitro (ml)',
        'g' => 'Gramo (g)',
    ];

    /** Categorías sugeridas la primera vez que la clínica entra a Inventario. */
    public const SUGGESTED_CATEGORIES = ['Anestesia', 'Materiales de restauración', 'Endodoncia', 'Bioseguridad y descartables', 'Instrumental', 'Higiene oral (venta)', 'Medicamentos'];

    protected $fillable = [
        'company_id', 'product_category_id', 'code', 'barcode', 'name', 'brand', 'presentation', 'unit',
        'purchase_price', 'sale_price', 'min_stock', 'track_batches', 'for_sale', 'notes', 'active',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'min_stock' => 'decimal:2',
        'track_batches' => 'boolean',
        'for_sale' => 'boolean',
        'active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function getLabelAttribute(): string
    {
        return $this->presentation ? "{$this->name} — {$this->presentation}" : $this->name;
    }

    public function getUnitLabelAttribute(): string
    {
        return self::UNITS[$this->unit] ?? $this->unit;
    }

    /** Existencia total (todos los consultorios) o de uno. */
    public function stockIn(?int $branchId = null): float
    {
        $stocks = $this->relationLoaded('stocks') ? $this->stocks : $this->stocks()->get();

        return round((float) ($branchId ? $stocks->where('branch_id', $branchId)->sum('quantity') : $stocks->sum('quantity')), 2);
    }

    public function isLowStock(?int $branchId = null): bool
    {
        return (float) $this->min_stock > 0 && $this->stockIn($branchId) <= (float) $this->min_stock;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$term}%")
            ->orWhere('code', $term)->orWhere('barcode', $term)->orWhere('brand', 'like', "%{$term}%"));
    }

    /** Existencia total en o por debajo del mínimo (solo con mínimo definido). */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('min_stock', '>', 0)
            ->whereRaw('(select coalesce(sum(quantity), 0) from product_stocks where product_stocks.product_id = products.id) <= products.min_stock');
    }

    public static function ensureCategories(): void
    {
        if (ProductCategory::exists()) {
            return;
        }
        foreach (self::SUGGESTED_CATEGORIES as $name) {
            ProductCategory::create(['name' => $name, 'active' => true]);
        }
    }
}
