<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda: profesionales (personal que atiende), su horario semanal, bloqueos
 * (vacaciones, feriados) y las citas con su historial de cambios.
 *
 * Producción sin artisan: database/sql/2026_11_16_agenda.sql hace lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Quién atiende pacientes y cómo se ve en la agenda.
        Schema::table('personal', function (Blueprint $table) {
            $table->boolean('attends_patients')->default(false)->after('active');
            $table->foreignId('specialty_id')->nullable()->after('attends_patients')->constrained()->nullOnDelete();
            $table->string('agenda_color', 7)->nullable()->after('specialty_id');
        });

        // Horario semanal: varios bloques por día (mañana y tarde).
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_id')->constrained('personal')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('weekday');                 // 1 = lunes … 7 = domingo (ISO)
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->index(['personal_id', 'weekday']);
        });

        // Bloqueos: vacaciones, congreso, feriado (sin profesional = toda la clínica).
        Schema::create('schedule_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_id')->nullable()->constrained('personal')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason', 120);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'starts_at']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('personal_id')->constrained('personal')->restrictOnDelete();   // el profesional
            $table->foreignId('procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 255)->nullable();                                       // motivo de consulta
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // agendada → confirmada → en_espera → en_atencion → atendida · cancelada · no_asistio
            $table->string('status', 12)->default('agendada');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->unsignedSmallInteger('reschedule_count')->default(0);
            $table->boolean('overbooked')->default(false);                                   // sobreturno aceptado
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'starts_at']);
            $table->index(['personal_id', 'starts_at']);
            $table->index(['patient_id', 'starts_at']);
        });

        // Historial de la cita: creada, reprogramada, cambios de estado.
        Schema::create('appointment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20);                                                   // creada, reprogramada, estado
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_logs');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('schedule_blocks');
        Schema::dropIfExists('schedules');
        Schema::table('personal', function (Blueprint $table) {
            $table->dropConstrainedForeignId('specialty_id');
            $table->dropColumn(['attends_patients', 'agenda_color']);
        });
    }
};
