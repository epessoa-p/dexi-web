<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Diagnóstico de una consulta, por pieza y cara si corresponde. */
class ConsultationDiagnosis extends Model
{
    /** Diagnósticos odontológicos frecuentes (con su CIE-10 de referencia). clave => [etiqueta, CIE-10] */
    public const CATALOG = [
        'caries_esmalte' => ['Caries limitada al esmalte', 'K02.0'],
        'caries_dentina' => ['Caries de la dentina', 'K02.1'],
        'pulpitis_reversible' => ['Pulpitis reversible', 'K04.0'],
        'pulpitis_irreversible' => ['Pulpitis irreversible', 'K04.0'],
        'necrosis_pulpar' => ['Necrosis pulpar', 'K04.1'],
        'periodontitis_apical' => ['Periodontitis apical', 'K04.5'],
        'absceso' => ['Absceso periapical', 'K04.7'],
        'gingivitis' => ['Gingivitis', 'K05.1'],
        'periodontitis' => ['Periodontitis crónica', 'K05.3'],
        'fractura' => ['Fractura dental', 'S02.5'],
        'abrasion' => ['Abrasión / erosión dental', 'K03.1'],
        'sensibilidad' => ['Hipersensibilidad dentinaria', 'K03.8'],
        'tercer_molar' => ['Tercer molar retenido / incluido', 'K01.1'],
        'edentulismo' => ['Edentulismo parcial', 'K08.1'],
        'maloclusion' => ['Maloclusión', 'K07.4'],
        'bruxismo' => ['Bruxismo', 'F45.8'],
        'atm' => ['Trastorno de la ATM', 'K07.6'],
        'estomatitis' => ['Estomatitis / aftas', 'K12.0'],
        'calculo' => ['Cálculo dental (sarro)', 'K03.6'],
        'otro' => ['Otro', null],
    ];

    protected $fillable = ['consultation_id', 'code', 'label', 'tooth', 'surfaces', 'notes'];

    protected $casts = ['tooth' => 'integer'];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function getCie10Attribute(): ?string
    {
        return self::CATALOG[$this->code][1] ?? null;
    }
}
