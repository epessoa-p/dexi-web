<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes de tratamiento / presupuestos: ítems por pieza y cara, en fases,
 * con aprobación del paciente y avance (cada ítem se marca realizado desde
 * la consulta en la que se hizo).
 *
 * Producción sin artisan: database/sql/2026_11_17_tratamientos.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('personal_id')->nullable()->constrained('personal')->nullOnDelete();   // quien lo propone
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();       // de dónde salió
            $table->unsignedInteger('number');                                                     // PT-000001 por clínica
            $table->string('title', 150);
            // borrador → presentado → aprobado (en curso) → terminado · rechazado · cancelado
            $table->string('status', 12)->default('borrador');
            $table->decimal('discount', 12, 2)->default(0);                                        // descuento global (monto)
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('presented_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_by_name', 150)->nullable();                                   // quién aceptó (paciente o tutor)
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();                                            // rechazado o cancelado
            $table->string('close_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['patient_id', 'status']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('treatment_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->unsignedTinyInteger('tooth')->nullable();
            $table->string('surfaces', 5)->nullable();
            $table->unsignedTinyInteger('phase')->default(1);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->string('status', 10)->default('pendiente');                                    // pendiente, realizado, cancelado
            $table->timestamp('done_at')->nullable();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();       // dónde se hizo
            $table->foreignId('consultation_procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['treatment_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_plan_items');
        Schema::dropIfExists('treatment_plans');
    }
};
