<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Persona que trabaja en una clínica. Puede tener (o no) usuario de acceso.
 * Dato de tenant y recurso limitado por plan.
 *
 * Si `attends_patients`, es un profesional de la agenda: tiene horario semanal,
 * color y especialidad (Agenda › Disponibilidad).
 */
class Personal extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /** Colores para la agenda cuando el profesional no eligió uno. */
    public const PALETTE = ['#0e9f9a', '#2563eb', '#db2777', '#ea580c', '#7c3aed', '#059669', '#ca8a04', '#dc2626'];

    /**
     * La migración crea la tabla en singular; sin esto Eloquent buscaría `personals`.
     */
    protected $table = 'personal';

    protected $fillable = [
        'company_id',
        'cargo_id',
        'user_id',
        'full_name',
        'id_number',
        'phone',
        'email',
        'address',
        'hire_date',
        'notes',
        'active',
        'attends_patients',
        'specialty_id',
        'agenda_color',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'active' => 'boolean',
        'attends_patients' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class)->orderBy('weekday')->orderBy('starts_at');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** Profesionales activos de la agenda. */
    public function scopeProfessionals(Builder $query): Builder
    {
        return $query->where('active', true)->where('attends_patients', true)->orderBy('full_name');
    }

    public function getColorAttribute(): string
    {
        return $this->agenda_color ?: self::PALETTE[$this->id % count(self::PALETTE)];
    }

    public function getInitialsAttribute(): string
    {
        return collect(explode(' ', (string) $this->full_name))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    }

    /** «Dra. Ana Ruiz» no se infiere: se muestra el nombre tal cual. */
    public function getShortNameAttribute(): string
    {
        $parts = explode(' ', trim((string) $this->full_name));

        return count($parts) > 2 ? "{$parts[0]} {$parts[count($parts) - 2]}" : (string) $this->full_name;
    }
}
