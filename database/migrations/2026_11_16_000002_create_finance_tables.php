<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finanzas (ESTRUCTURA_CUENTAS_Y_GASTOS.md): cuentas de la tesorería con su
 * extracto, categorías de gasto por tipo, proveedores y gastos con su origen
 * del dinero (cuenta o caja) y anulación.
 *
 * Producción sin artisan: database/sql/2026_11_16_finanzas.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->string('type', 20);                            // capital, caja_chica, banco, billetera, otro
            $table->string('bank_name', 80)->nullable();
            $table->string('account_number', 40)->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);         // solo lo escribe App\Support\Treasury
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'active']);
        });

        Schema::create('finance_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_account_id')->constrained()->restrictOnDelete();
            $table->string('type', 10);                            // ingreso, egreso
            $table->string('origin', 20);                          // manual, apertura, transferencia, gasto, compra, anulacion
            $table->string('concept');
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->date('occurred_on');
            $table->string('reference', 80)->nullable();
            $table->foreignId('transfer_movement_id')->nullable()->constrained('finance_movements')->nullOnDelete();
            $table->foreignId('reverses_movement_id')->nullable()->constrained('finance_movements')->nullOnDelete();
            $table->nullableMorphs('source');                      // el gasto que lo originó
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['finance_account_id', 'id']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('payee', 12)->default('ninguno');       // ninguno, proveedor, personal
            $table->boolean('recurring')->default(false);          // servicio básico
            $table->string('frequency', 10)->nullable();           // mensual, anual
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);                           // nombre o razón social
            $table->string('tax_id', 32)->nullable();              // NIT / RUC
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('number');                     // G-000001, correlativo por clínica
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('personal_id')->nullable()->constrained('personal')->nullOnDelete();
            $table->unsignedBigInteger('commission_settlement_id')->nullable(); // llega con el módulo de comisiones
            $table->string('concept');
            $table->decimal('amount', 15, 2);
            $table->date('spent_at');
            $table->string('reference', 80)->nullable();
            $table->string('source', 10);                          // account, cash, external
            $table->foreignId('finance_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt')->nullable();                 // comprobante adjunto (disco privado)
            $table->text('notes')->nullable();
            $table->string('status', 12)->default('registrado');   // registrado, anulado
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'spent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('finance_movements');
        Schema::dropIfExists('finance_accounts');
    }
};
