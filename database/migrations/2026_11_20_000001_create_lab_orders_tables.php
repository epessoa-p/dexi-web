<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratorio dental: órdenes de trabajo (OT-000001) a un laboratorio
 * (proveedor) con pieza, material, color y fechas; su recorrido
 * enviado → en proceso → recibido → instalado (con devoluciones para ajuste)
 * y el costo, que se paga como gasto al proveedor.
 *
 * Producción sin artisan: database/sql/2026_11_20_laboratorio.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('personal_id')->nullable()->constrained('personal')->nullOnDelete();          // odontólogo que lo pide
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();                            // el laboratorio
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('treatment_plan_item_id')->nullable()->constrained()->nullOnDelete();         // el trabajo del plan
            $table->string('work_type', 30);                                                                // LabOrder::WORK_TYPES
            $table->string('teeth', 120)->nullable();                                                       // «11, 21» o «arcada superior»
            $table->string('material', 40)->nullable();
            $table->string('shade', 20)->nullable();                                                        // color: A2, B1…
            $table->text('instructions')->nullable();
            $table->string('status', 12)->default('enviado');                                               // enviado · en_proceso · recibido · instalado · cancelado
            $table->date('sent_at');
            $table->date('due_at')->nullable();                                                             // fecha comprometida
            $table->dateTime('received_at')->nullable();
            $table->dateTime('installed_at')->nullable();
            $table->unsignedSmallInteger('returns_count')->default(0);                                      // veces que se devolvió para ajuste
            $table->decimal('cost', 12, 2)->default(0);                                                     // lo que cobra el laboratorio
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();                     // con qué gasto se pagó
            $table->text('notes')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'due_at']);
        });

        Schema::create('lab_order_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12);
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_order_logs');
        Schema::dropIfExists('lab_orders');
    }
};
