<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Paciente de la clínica: el dato maestro del que cuelgan la agenda, la
 * historia clínica, el odontograma, los tratamientos y las ventas.
 *
 * Su número de historia clínica (HC-000001) es correlativo por clínica y no
 * se reutiliza. Un paciente con historia no se borra: se desactiva.
 */
class Patient extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const DOCUMENT_TYPES = ['CI' => 'CI', 'PASAPORTE' => 'Pasaporte', 'OTRO' => 'Otro'];

    public const GENDERS = ['F' => 'Femenino', 'M' => 'Masculino', 'O' => 'Otro'];

    public const REFERRAL_SOURCES = [
        'recomendacion' => 'Recomendación de un paciente',
        'redes' => 'Redes sociales',
        'google' => 'Google / internet',
        'paso' => 'Pasó por la clínica',
        'convenio' => 'Seguro o convenio',
        'otro' => 'Otro',
    ];

    public const BLOOD_TYPES = ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'];

    /**
     * Antecedentes que cambian la atención odontológica (anestesia, sangrado,
     * antibióticos, extracciones). clave => [etiqueta, crítico].
     */
    public const CONDITIONS = [
        'hipertension' => ['Hipertensión', true],
        'cardiopatia' => ['Cardiopatía / marcapasos', true],
        'anticoagulantes' => ['Anticoagulantes / problemas de coagulación', true],
        'diabetes' => ['Diabetes', true],
        'bifosfonatos' => ['Bifosfonatos / osteoporosis', true],
        'asma' => ['Asma', false],
        'epilepsia' => ['Epilepsia', false],
        'hepatitis' => ['Hepatitis', false],
        'vih' => ['VIH', false],
        'renal' => ['Enfermedad renal', false],
        'tiroides' => ['Tiroides', false],
        'fumador' => ['Fumador', false],
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'number',
        'first_name', 'last_name', 'document_type', 'document_number', 'birth_date', 'gender', 'occupation',
        'phone', 'whatsapp', 'email', 'address', 'emergency_contact', 'emergency_phone',
        'guardian_name', 'guardian_relationship', 'guardian_phone',
        'referral_source', 'referred_by', 'insurance',
        'conditions', 'allergies', 'medications', 'pregnant', 'blood_type', 'medical_notes', 'medical_updated_at',
        'notes', 'active', 'created_by',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'conditions' => 'array',
        'pregnant' => 'boolean',
        'active' => 'boolean',
        'medical_updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PatientDocument::class)->latest('taken_on')->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Búsqueda y filtros ──────────────────────────────────────────────────

    /** Nombre, apellido, documento, teléfono o nº de historia (HC-12 o 12). */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $number = preg_match('/^(hc-?)?0*(\d+)$/i', $term, $m) ? (int) $m[2] : null;

        return $query->where(function (Builder $q) use ($term, $number) {
            foreach (preg_split('/\s+/', $term) as $word) {
                $q->where(fn (Builder $w) => $w->where('first_name', 'like', "%{$word}%")->orWhere('last_name', 'like', "%{$word}%"));
            }
            $q->orWhere('document_number', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('whatsapp', 'like', "%{$term}%");
            if ($number) {
                $q->orWhere('number', $number);
            }
        });
    }

    /** Con algo que el odontólogo debe saber antes de atender. */
    public function scopeWithAlerts(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn ($a) => $a->whereNotNull('allergies')->where('allergies', '!=', ''))
            ->orWhere('pregnant', true)
            ->orWhere(fn ($c) => $c->whereNotNull('conditions')->where('conditions', '!=', '[]')));
    }

    public function scopeMinors(Builder $query): Builder
    {
        return $query->whereNotNull('birth_date')->where('birth_date', '>', now()->subYears(18)->toDateString());
    }

    /** Cumplen años hoy (para el dashboard y, más adelante, los saludos). */
    public function scopeBirthdayToday(Builder $query): Builder
    {
        return $query->whereNotNull('birth_date')
            ->whereMonth('birth_date', now()->month)
            ->whereDay('birth_date', now()->day);
    }

    // ── Presentación ────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getCodeAttribute(): string
    {
        return 'HC-' . str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function getInitialsAttribute(): string
    {
        return mb_strtoupper(mb_substr($this->first_name, 0, 1) . mb_substr((string) $this->last_name, 0, 1));
    }

    public function getAgeAttribute(): ?int
    {
        return $this->birth_date ? (int) $this->birth_date->diffInYears(now()) : null;
    }

    /** «34 años» o «8 meses». */
    public function getAgeLabelAttribute(): ?string
    {
        if (! $this->birth_date) {
            return null;
        }
        $years = $this->age;
        if ($years >= 1) {
            return $years . ' ' . ($years === 1 ? 'año' : 'años');
        }
        $months = (int) $this->birth_date->diffInMonths(now());

        return $months . ' ' . ($months === 1 ? 'mes' : 'meses');
    }

    public function isMinor(): bool
    {
        return $this->age !== null && $this->age < 18;
    }

    public function getDocumentLabelAttribute(): ?string
    {
        return $this->document_number ? trim(($this->document_type ? $this->document_type . ' ' : '') . $this->document_number) : null;
    }

    /** Número para wa.me: solo dígitos (con el código de país si lo escribieron). Un menor sin celular, al tutor. */
    public function getWhatsappLinkAttribute(): ?string
    {
        $number = preg_replace('/\D+/', '', (string) ($this->whatsapp ?: $this->phone ?: $this->guardian_phone));

        return $number ? "https://wa.me/{$number}" : null;
    }

    /**
     * Alertas médicas, las críticas primero: lo que se muestra en rojo en toda
     * la ficha y en la lista.
     *
     * @return array<int, array{label: string, critical: bool}>
     */
    public function medicalAlerts(): array
    {
        $alerts = [];
        if (trim((string) $this->allergies) !== '') {
            $alerts[] = ['label' => 'Alergia: ' . \Illuminate\Support\Str::limit(trim($this->allergies), 60), 'critical' => true];
        }
        if ($this->pregnant) {
            $alerts[] = ['label' => 'Embarazo', 'critical' => true];
        }
        foreach ($this->conditions ?? [] as $key) {
            if (isset(self::CONDITIONS[$key])) {
                [$label, $critical] = self::CONDITIONS[$key];
                $alerts[] = ['label' => $label, 'critical' => $critical];
            }
        }

        usort($alerts, fn ($a, $b) => $b['critical'] <=> $a['critical']);

        return $alerts;
    }

    public function hasAlerts(): bool
    {
        return $this->medicalAlerts() !== [];
    }

    /** Siguiente número de historia de la clínica activa (llamar dentro de una transacción). */
    public static function nextNumber(): int
    {
        return (int) static::withTrashed()->lockForUpdate()->max('number') + 1;
    }

    public static function birthdayIsToday(?Carbon $date): bool
    {
        return $date !== null && $date->month === now()->month && $date->day === now()->day;
    }
}
