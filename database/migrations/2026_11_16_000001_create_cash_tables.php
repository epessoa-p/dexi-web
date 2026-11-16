<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caja: métodos de pago, turnos (apertura → movimientos → cierre con arqueo)
 * y sus movimientos. Solo el método de tipo `cash` cuenta para el arqueo.
 *
 * Producción sin artisan: database/sql/2026_11_16_finanzas.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 12);                            // cash, transfer, qr, card, other
            $table->boolean('requires_reference')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'active']);
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caja_id')->constrained('cajas')->restrictOnDelete();
            $table->string('status', 10)->default('open');         // open, closed
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('opened_at');
            $table->decimal('opening_amount', 14, 2)->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();   // lo que debería haber en efectivo
            $table->decimal('counted_cash', 14, 2)->nullable();    // lo que se contó
            $table->decimal('difference', 14, 2)->nullable();      // contado − esperado
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['caja_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);                            // sale, receivable, income, expense, withdrawal, refund
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);                      // + entra, − sale
            $table->string('description');
            $table->nullableMorphs('source');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('payment_methods');
    }
};
