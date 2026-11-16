<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Especialidad odontológica: agrupa el tarifario y da color en la agenda. */
class Specialty extends Model
{
    use BelongsToCompany;

    /** Con las que empieza cada clínica: nombre => color. */
    public const DEFAULTS = [
        'Odontología general' => '#0e9f9a',
        'Operatoria y estética' => '#2563eb',
        'Endodoncia' => '#dc2626',
        'Periodoncia' => '#db2777',
        'Cirugía oral' => '#9333ea',
        'Implantología' => '#4f46e5',
        'Prótesis y rehabilitación' => '#ca8a04',
        'Ortodoncia' => '#ea580c',
        'Odontopediatría' => '#059669',
        'Radiología' => '#475569',
    ];

    protected $fillable = ['company_id', 'name', 'color', 'sort_order', 'active'];

    protected $casts = ['active' => 'boolean', 'sort_order' => 'integer'];

    public function procedures(): HasMany
    {
        return $this->hasMany(Procedure::class);
    }

    public static function ensureDefaults(?int $companyId = null): void
    {
        $companyId ??= app(Tenancy::class)->id();
        if (! $companyId || static::forCompany($companyId)->exists()) {
            return;
        }

        $sort = 0;
        foreach (self::DEFAULTS as $name => $color) {
            static::forCompany($companyId)->create(['company_id' => $companyId, 'name' => $name, 'color' => $color, 'sort_order' => $sort++]);
        }
    }
}
