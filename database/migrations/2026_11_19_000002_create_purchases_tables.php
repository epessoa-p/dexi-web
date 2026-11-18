<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras a proveedores (P-000001): líneas, recepciones (parciales) que
 * generan entrada de stock y pagos en cuotas con el mismo origen del dinero
 * que un gasto (cuenta o caja). ESTRUCTURA_CUENTAS_Y_GASTOS.md §2.7.
 *
 * Producción sin artisan: database/sql/2026_11_19_inventario_y_compras.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();      // donde se recibe
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('document_number', 60)->nullable();                     // factura del proveedor
            $table->date('purchased_at');
            $table->string('status', 12)->default('pedida');                       // pedida · parcial · recibida · anulada
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);                          // cuenta por pagar
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('received_at')->nullable();
            $table->string('closing_notes')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'balance']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->decimal('received_quantity', 12, 2)->default(0);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });

        Schema::create('purchase_receptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->dateTime('received_at');
            $table->string('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_reception_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_reception_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->string('lot', 60)->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });

        // Cada pago (cuota), con el mismo origen del dinero que un gasto.
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10);                                          // account · cash · external
            $table->foreignId('finance_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reference', 80)->nullable();
            $table->date('paid_at');
            $table->string('status', 10)->default('pagado');                        // pagado · anulado
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('purchase_reception_items');
        Schema::dropIfExists('purchase_receptions');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
