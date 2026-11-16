<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atención clínica: consultas (con diagnósticos y procedimientos realizados
 * por pieza y cara) y el odontograma del paciente (registros por pieza/cara
 * que se «resuelven» cuando se tratan; nunca se borran: son historia clínica).
 *
 * Producción sin artisan: database/sql/2026_11_17_atencion_clinica.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('personal_id')->constrained('personal')->restrictOnDelete();   // el profesional
            $table->unsignedInteger('number');                                                // C-000001 por clínica
            $table->string('status', 10)->default('abierta');                                // abierta, cerrada
            $table->string('reason')->nullable();                                             // motivo de consulta
            $table->text('history')->nullable();                                              // enfermedad actual / anamnesis
            $table->text('exam')->nullable();                                                 // examen extra e intraoral
            $table->text('plan')->nullable();                                                 // plan y evolución
            $table->text('indications')->nullable();                                          // indicaciones / receta
            $table->string('next_visit')->nullable();                                         // «control en 15 días»
            $table->text('addendum')->nullable();                                             // notas de corrección tras cerrar
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['patient_id', 'started_at']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('consultation_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);                                                       // clave de Diagnosis::CATALOG u «otro»
            $table->string('label', 150);
            $table->unsignedTinyInteger('tooth')->nullable();                                 // FDI: 11–48, 51–85
            $table->string('surfaces', 5)->nullable();                                        // «MOD»
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('consultation_procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);                                                      // copia del nombre al momento
            $table->unsignedTinyInteger('tooth')->nullable();
            $table->string('surfaces', 5)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('price', 12, 2)->default(0);                                      // precio unitario congelado
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('odontogram_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('tooth');                                             // FDI
            $table->string('surface', 1)->nullable();                                         // O, M, D, V, L (null = pieza entera)
            $table->string('condition', 25);                                                  // clave de Odontogram::CONDITIONS
            $table->string('notes')->nullable();
            $table->foreignId('personal_id')->nullable()->constrained('personal')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();                                     // ya no está (se trató o se corrigió)
            $table->string('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'tooth']);
            $table->index(['patient_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odontogram_entries');
        Schema::dropIfExists('consultation_procedures');
        Schema::dropIfExists('consultation_diagnoses');
        Schema::dropIfExists('consultations');
    }
};
