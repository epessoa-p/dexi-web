<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orden de trabajo de laboratorio dental (OT-000001): corona, prótesis,
 * férula… Recorre enviado → en proceso → recibido → instalado; si llega mal se
 * devuelve para ajuste (vuelve a «en proceso»). Su costo se paga como gasto.
 */
class LabOrder extends Model
{
    use BelongsToCompany;

    /** estado => [etiqueta, color, ícono] */
    public const STATUSES = [
        'enviado' => ['Enviado', 'info', 'bi-send'],
        'en_proceso' => ['En proceso', 'warning', 'bi-gear'],
        'recibido' => ['Recibido', 'primary', 'bi-box-arrow-in-down'],
        'instalado' => ['Instalado', 'success', 'bi-check2-all'],
        'cancelado' => ['Cancelado', 'dark', 'bi-x-circle'],
    ];

    /** A qué estados se puede pasar desde cada uno. */
    public const TRANSITIONS = [
        'enviado' => ['en_proceso', 'recibido', 'cancelado'],
        'en_proceso' => ['recibido', 'cancelado'],
        'recibido' => ['instalado', 'en_proceso'],      // en_proceso = devuelto para ajuste
        'instalado' => [],
        'cancelado' => [],
    ];

    public const WORK_TYPES = [
        'corona' => 'Corona',
        'puente' => 'Puente fijo',
        'carilla' => 'Carilla',
        'incrustacion' => 'Incrustación (inlay/onlay)',
        'perno_munon' => 'Perno muñón',
        'corona_implante' => 'Corona sobre implante',
        'protesis_total' => 'Prótesis total',
        'protesis_parcial' => 'Prótesis parcial removible',
        'provisional' => 'Provisional',
        'ferula' => 'Férula / placa miorrelajante',
        'ortodoncia' => 'Aparato de ortodoncia / retenedor',
        'modelo' => 'Modelo de estudio / encerado',
        'otro' => 'Otro',
    ];

    public const MATERIALS = ['Zirconio', 'Disilicato de litio', 'Metal-porcelana', 'Metal', 'Resina', 'Acrílico', 'PMMA', 'Cromo-cobalto', 'Acetato'];

    /** Guía de colores VITA clásica. */
    public const SHADES = ['A1', 'A2', 'A3', 'A3.5', 'A4', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'D2', 'D3', 'D4', 'BL1', 'BL2'];

    protected $fillable = [
        'company_id', 'number', 'patient_id', 'personal_id', 'supplier_id', 'consultation_id', 'treatment_plan_item_id', 'work_type', 'teeth',
        'material', 'shade', 'instructions', 'status', 'sent_at', 'due_at', 'received_at', 'installed_at', 'returns_count', 'cost', 'expense_id',
        'notes', 'cancelled_at', 'cancel_reason', 'created_by',
    ];

    protected $casts = [
        'sent_at' => 'date', 'due_at' => 'date', 'received_at' => 'datetime', 'installed_at' => 'datetime', 'cancelled_at' => 'datetime',
        'cost' => 'decimal:2', 'returns_count' => 'integer',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id')->withTrashed();
    }

    public function lab(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id')->withTrashed();
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlanItem::class, 'treatment_plan_item_id');
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LabOrderLog::class)->latest('id');
    }

    public function getCodeAttribute(): string
    {
        return 'OT-' . str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'secondary';
    }

    public function getWorkLabelAttribute(): string
    {
        return self::WORK_TYPES[$this->work_type] ?? $this->work_type;
    }

    /** «Corona · zirconio · 16 · A2». */
    public function getSummaryAttribute(): string
    {
        return collect([$this->work_label, $this->material, $this->teeth ? 'pieza ' . $this->teeth : null, $this->shade])
            ->filter()->implode(' · ');
    }

    /** Todavía en el laboratorio. */
    public function isAtLab(): bool
    {
        return in_array($this->status, ['enviado', 'en_proceso'], true);
    }

    public function isLate(): bool
    {
        return $this->isAtLab() && $this->due_at !== null && $this->due_at->lt(today());
    }

    public function isPaid(): bool
    {
        return $this->expense_id !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), ['enviado', 'en_proceso', 'recibido']);
    }

    /** Con costo y sin pagar (se debe al laboratorio). */
    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), '!=', 'cancelado')->where('cost', '>', 0)->whereNull('expense_id');
    }
}
