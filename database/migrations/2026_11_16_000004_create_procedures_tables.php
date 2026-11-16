<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifario: especialidades y procedimientos con su precio, duración y a qué
 * se aplican (al paciente, a una pieza, a una cara, a una arcada). Lo usan la
 * agenda (duración y color), el odontograma, los presupuestos y las ventas.
 *
 * Producción sin artisan: database/sql/2026_11_16_tarifario.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('color', 7)->nullable();              // #RRGGBB, color en la agenda
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20)->nullable();
            $table->string('name', 150);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_max', 12, 2)->nullable();     // precio «desde–hasta» (se define en el presupuesto)
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->string('applies_to', 10)->default('paciente'); // paciente, pieza, cara, arcada
            $table->string('color', 7)->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'active']);
            $table->index(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedures');
        Schema::dropIfExists('specialties');
    }
};
