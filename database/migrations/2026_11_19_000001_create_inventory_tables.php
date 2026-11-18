<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario: productos e insumos, existencia por consultorio, lotes con
 * vencimiento, kardex inmutable, insumos que consume cada procedimiento y
 * venta de productos en Cobrar.
 *
 * Producción sin artisan: database/sql/2026_11_19_inventario_y_compras.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 40)->nullable();
            $table->string('barcode', 60)->nullable();
            $table->string('name', 160);
            $table->string('brand', 80)->nullable();
            $table->string('presentation', 120)->nullable();
            $table->string('unit', 20)->default('unidad');               // Product::UNITS
            $table->decimal('purchase_price', 12, 2)->default(0);        // último costo
            $table->decimal('sale_price', 12, 2)->default(0);
            $table->decimal('min_stock', 12, 2)->default(0);
            $table->boolean('track_batches')->default(false);            // pide lote y vencimiento al ingresar
            $table->boolean('for_sale')->default(false);                 // se ofrece en Cobrar (cepillos, enjuagues…)
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'code']);
        });

        // Existencia por consultorio: caché de la suma de movimientos, en la misma transacción.
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'branch_id']);
        });

        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('lot', 60);
            $table->date('expires_at')->nullable();
            $table->decimal('quantity', 12, 2)->default(0);
            $table->decimal('initial_quantity', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'branch_id', 'lot']);
            $table->index(['company_id', 'expires_at']);
        });

        // Kardex: cada movimiento es inmutable; un error se corrige con otro movimiento.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 14);                                   // in · out · adjustment · transfer_in · transfer_out
            $table->string('reason', 20);                                 // StockMovement::REASONS
            $table->decimal('quantity', 12, 2);                           // con signo
            $table->decimal('balance_after', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->nullableMorphs('source');                             // compra, venta, procedimiento…
            $table->string('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'branch_id', 'id']);
            $table->index(['company_id', 'created_at']);
        });

        // Insumos que gasta cada procedimiento del tarifario (se descuentan al registrarlo en la consulta).
        Schema::create('procedure_supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->timestamps();
            $table->unique(['procedure_id', 'product_id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('procedure_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('product_id'));
        Schema::dropIfExists('procedure_supplies');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
