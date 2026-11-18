<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas y cobros: el cobro de lo realizado en consultas y de los planes de
 * tratamiento (por adelantado o por avance), con varios métodos de pago,
 * saldo como cuenta por cobrar, abonos posteriores y anulación. Todo lo
 * cobrado entra al turno de caja abierto.
 *
 * Producción sin artisan: database/sql/2026_11_17_ventas.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();      // venta de mostrador sin paciente: permitida si se paga todo
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('number');                                              // V-000001 por clínica
            $table->string('status', 10)->default('completada');                           // completada, anulada
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);                                  // saldo: cuenta por cobrar
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'created_at']);
            $table->index(['patient_id', 'balance']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procedure_id')->nullable()->constrained()->nullOnDelete();
            // De dónde viene: lo realizado en una consulta o un ítem de plan de tratamiento.
            $table->foreignId('consultation_procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('treatment_plan_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->unsignedTinyInteger('tooth')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10)->default('venta');                                  // venta (al cobrar), abono (después)
            $table->decimal('amount', 12, 2);
            $table->string('reference', 80)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'paid_at']);
        });

        // Lo cobrado ya no aparece como pendiente de cobro.
        Schema::table('consultation_procedures', function (Blueprint $table) {
            $table->foreignId('sale_item_id')->nullable()->after('notes')->constrained()->nullOnDelete();
        });
        Schema::table('treatment_plan_items', function (Blueprint $table) {
            $table->foreignId('sale_item_id')->nullable()->after('consultation_procedure_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('treatment_plan_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_item_id'));
        Schema::table('consultation_procedures', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_item_id'));
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
