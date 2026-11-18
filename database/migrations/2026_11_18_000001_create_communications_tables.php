<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comunicaciones por WhatsApp (enlace wa.me): plantillas de mensaje por clínica,
 * registro de lo enviado y controles / seguimientos programados por paciente.
 *
 * Producción sin artisan: database/sql/2026_11_18_comunicaciones.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Solo se guardan las plantillas que la clínica cambió; las demás salen del código.
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                 // recordatorio · cumpleanos · seguimiento · inasistencia · saldo
            $table->text('body');
            $table->timestamps();
            $table->unique(['company_id', 'kind']);
        });

        Schema::create('patient_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->date('due_date');
            $table->string('reason', 150);              // «Control de 6 meses», «Retiro de puntos»…
            $table->text('notes')->nullable();
            $table->string('status', 12)->default('pendiente');   // pendiente · hecho · descartado
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status', 'due_date']);
        });

        // Lo que se envió (o se abrió en WhatsApp para enviar): historial y «ya enviado».
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('followup_id')->nullable()->constrained('patient_followups')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('channel', 12)->default('whatsapp');
            $table->string('recipient', 40)->nullable();
            $table->text('message');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['company_id', 'created_at']);
            $table->index(['patient_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
        Schema::dropIfExists('patient_followups');
        Schema::dropIfExists('message_templates');
    }
};
