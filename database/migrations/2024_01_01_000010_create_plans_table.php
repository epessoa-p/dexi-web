<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes comerciales del operador de la plataforma.
 *
 * Tabla GLOBAL: no pertenece a ninguna clínica. Ver ARQUITECTURA §2 y §3.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->enum('billing_period', ['monthly', 'quarterly', 'yearly', 'lifetime'])->default('monthly');
            $table->unsignedSmallInteger('trial_days')->default(0);

            // Límites del plan. NULL = ilimitado.
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_personal')->nullable();
            $table->unsignedInteger('max_branches')->nullable();

            // Módulos incluidos: ["agenda","caja",…]. Ver Plan::MODULES.
            $table->json('features')->nullable();

            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
