<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cita de la agenda. Flujo:
 *   agendada → confirmada → en_espera (llegó) → en_atencion → atendida
 *   y en cualquier momento antes de atender: cancelada · no_asistio.
 * Reprogramar cambia la hora y queda en el historial (appointment_logs).
 */
class Appointment extends Model
{
    use BelongsToCompany, SoftDeletes;

    /** estado => [etiqueta, color bootstrap, ícono] */
    public const STATUSES = [
        'agendada' => ['Agendada', 'secondary', 'bi-calendar'],
        'confirmada' => ['Confirmada', 'primary', 'bi-check2-circle'],
        'en_espera' => ['En sala de espera', 'warning', 'bi-hourglass-split'],
        'en_atencion' => ['En atención', 'info', 'bi-activity'],
        'atendida' => ['Atendida', 'success', 'bi-check2-all'],
        'cancelada' => ['Cancelada', 'dark', 'bi-x-circle'],
        'no_asistio' => ['No asistió', 'danger', 'bi-person-x'],
    ];

    /** A qué estados se puede pasar desde cada uno. */
    public const TRANSITIONS = [
        'agendada' => ['confirmada', 'en_espera', 'cancelada', 'no_asistio'],
        'confirmada' => ['en_espera', 'agendada', 'cancelada', 'no_asistio'],
        'en_espera' => ['en_atencion', 'confirmada', 'cancelada', 'no_asistio'],
        'en_atencion' => ['atendida', 'en_espera'],
        'atendida' => ['en_atencion'],
        'cancelada' => ['agendada'],
        'no_asistio' => ['agendada'],
    ];

    /** Los que ocupan el horario (para choques). */
    public const ACTIVE = ['agendada', 'confirmada', 'en_espera', 'en_atencion', 'atendida'];

    protected $fillable = [
        'company_id', 'branch_id', 'patient_id', 'personal_id', 'procedure_id', 'reason', 'starts_at', 'ends_at', 'status',
        'confirmed_at', 'arrived_at', 'started_at', 'finished_at', 'cancelled_at', 'cancel_reason',
        'reschedule_count', 'overbooked', 'notes', 'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'arrived_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'overbooked' => 'boolean',
        'reschedule_count' => 'integer',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id')->withTrashed();
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function consultation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Consultation::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AppointmentLog::class)->latest('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), self::ACTIVE);
    }

    public function scopeOnDate(Builder $query, $date): Builder
    {
        $day = \Carbon\Carbon::parse($date);

        return $query->whereBetween('starts_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'secondary';
    }

    public function getStatusIconAttribute(): string
    {
        return self::STATUSES[$this->status][2] ?? 'bi-calendar';
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['atendida', 'cancelada', 'no_asistio'], true);
    }

    /** Lo que se muestra como título: el procedimiento o el motivo. */
    public function getTitleAttribute(): string
    {
        return $this->procedure?->name ?: ($this->reason ?: 'Consulta');
    }

    public function getDurationMinutesAttribute(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** Minutos en la sala de espera (si llegó y todavía no pasa). */
    public function waitingMinutes(): ?int
    {
        return $this->status === 'en_espera' && $this->arrived_at ? max(0, (int) $this->arrived_at->diffInMinutes(now())) : null;
    }

    /** Recordatorio por WhatsApp con el texto ya escrito. */
    public function reminderLink(string $clinic): ?string
    {
        $link = $this->patient?->whatsapp_link;
        if (! $link) {
            return null;
        }
        $when = $this->starts_at->locale('es')->translatedFormat('l j \d\e F \a \l\a\s H:i');
        $text = "Hola {$this->patient->first_name}, te recordamos tu cita en {$clinic} el {$when} con {$this->professional?->full_name}. Responde SÍ para confirmar. ¡Gracias!";

        return $link . '?text=' . rawurlencode($text);
    }
}
