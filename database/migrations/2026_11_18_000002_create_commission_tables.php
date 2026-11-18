<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comisiones de los odontólogos: reglas (porcentaje o monto fijo, por
 * profesional y procedimiento o especialidad), comisión por línea cobrada
 * (con ajustes negativos al anular) y liquidaciones que se pagan como un gasto
 * de Finanzas (categoría COMISIONES). ESTRUCTURA_CUENTAS_Y_GASTOS.md §6.
 *
 * Producción sin artisan: database/sql/2026_11_18_comisiones.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Quién atendió: la venta guarda el profesional de lo suelto; cada línea, el suyo.
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('personal_id')->nullable()->after('user_id')->constrained('personal')->nullOnDelete();
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('personal_id')->nullable()->after('treatment_plan_item_id')->constrained('personal')->nullOnDelete();
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_id')->nullable()->constrained('personal')->cascadeOnDelete();   // null = todo el equipo
            $table->string('applies_to', 15);                                                        // todos · especialidad · procedimiento
            $table->foreignId('specialty_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('procedure_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 12);                                                              // porcentaje · monto_fijo
            $table->decimal('value', 10, 2);
            $table->boolean('active')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'active']);
        });

        Schema::create('commission_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_id')->constrained('personal')->restrictOnDelete();
            $table->unsignedInteger('number');                                                       // L-000001 por clínica
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('commissions_count');
            $table->decimal('total', 12, 2);
            $table->string('status', 12)->default('pagada');                                         // pagada · anulada
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_payment_id')->nullable()->constrained()->nullOnDelete();         // «sobre lo cobrado»: el pago que la generó
            $table->foreignId('personal_id')->constrained('personal')->restrictOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('commission_settlement_id')->nullable()->constrained()->nullOnDelete(); // null = pendiente de pagar
            $table->string('kind', 12)->default('devengada');                                        // devengada · ajuste (negativo)
            $table->foreignId('adjusts_commission_id')->nullable()->constrained('commissions')->nullOnDelete();
            $table->string('description');
            $table->decimal('base', 12, 2);
            $table->string('type', 12);                                                              // copia de la regla
            $table->decimal('value', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->dateTime('earned_at');
            $table->timestamps();
            $table->index(['company_id', 'personal_id', 'earned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_settlements');
        Schema::dropIfExists('commission_rules');
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('personal_id'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropConstrainedForeignId('personal_id'));
    }
};
