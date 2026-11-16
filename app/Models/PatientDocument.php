<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Archivo de la ficha del paciente: consentimiento, radiografía, foto clínica…
 * Vive en el disco privado (`local`) y solo se sirve por una ruta con permiso:
 * son datos de salud.
 */
class PatientDocument extends Model
{
    use BelongsToCompany;

    /** categoría => [etiqueta, ícono] */
    public const CATEGORIES = [
        'consentimiento' => ['Consentimiento', 'bi-pen'],
        'radiografia' => ['Radiografía', 'bi-radioactive'],
        'foto_intraoral' => ['Foto intraoral', 'bi-camera'],
        'foto_extraoral' => ['Foto extraoral', 'bi-person-bounding-box'],
        'receta' => ['Receta', 'bi-prescription2'],
        'laboratorio' => ['Laboratorio', 'bi-eyedropper'],
        'otro' => ['Otro', 'bi-file-earmark'],
    ];

    protected $fillable = [
        'company_id', 'patient_id', 'category', 'title', 'path', 'mime', 'size', 'taken_on', 'notes', 'uploaded_by',
    ];

    protected $casts = [
        'taken_on' => 'date',
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        // El archivo se va con su registro.
        static::deleted(fn (self $document) => Storage::disk('local')->delete($document->path));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category][0] ?? $this->category;
    }

    public function getIconAttribute(): string
    {
        return $this->mime === 'application/pdf' ? 'bi-file-earmark-pdf' : (self::CATEGORIES[$this->category][1] ?? 'bi-file-earmark');
    }

    public function getSizeLabelAttribute(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1) . ' MB'
            : max(1, (int) round($this->size / 1024)) . ' KB';
    }
}
