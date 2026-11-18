<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Texto propio de la clínica para un tipo de mensaje (si no existe, se usa el sugerido). */
class MessageTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'kind', 'body'];
}
