<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pacientes: el dato maestro de la clínica (agenda, consultas, odontograma,
 * tratamientos y ventas cuelgan de aquí), con su anamnesis y sus documentos
 * (consentimientos, radiografías, fotos) en el disco privado.
 *
 * Producción sin artisan: database/sql/2026_11_16_pacientes.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();   // consultorio habitual
            $table->unsignedInteger('number');                                         // HC-000001 por clínica

            // Identificación
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('document_type', 12)->nullable();                           // CI, PASAPORTE, OTRO
            $table->string('document_number', 30)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 1)->nullable();                                   // F, M, O
            $table->string('occupation', 100)->nullable();

            // Contacto
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('emergency_contact', 120)->nullable();
            $table->string('emergency_phone', 30)->nullable();

            // Tutor (menores de edad)
            $table->string('guardian_name', 120)->nullable();
            $table->string('guardian_relationship', 40)->nullable();
            $table->string('guardian_phone', 30)->nullable();

            // Cómo llegó
            $table->string('referral_source', 20)->nullable();                         // recomendacion, redes, google…
            $table->string('referred_by', 120)->nullable();
            $table->string('insurance', 120)->nullable();                              // seguro o convenio

            // Anamnesis: lo que hay que saber antes de anestesiar o recetar
            $table->json('conditions')->nullable();                                    // claves de Patient::CONDITIONS
            $table->text('allergies')->nullable();
            $table->text('medications')->nullable();
            $table->boolean('pregnant')->default(false);
            $table->string('blood_type', 3)->nullable();
            $table->text('medical_notes')->nullable();
            $table->timestamp('medical_updated_at')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            // Sin unique en el documento: con soft delete bloquearía el valor para
            // siempre. La unicidad por clínica se valida en el formulario.
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'document_number']);
            $table->index(['company_id', 'last_name', 'first_name']);
        });

        Schema::create('patient_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20);                                            // consentimiento, radiografia, foto…
            $table->string('title', 150);
            $table->string('path');                                                    // disco local (privado)
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->date('taken_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_documents');
        Schema::dropIfExists('patients');
    }
};
