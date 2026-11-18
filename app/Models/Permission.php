<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Permission extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Módulos de la plataforma (solo el superadmin): clínicas, planes,
     * suscripciones, roles y usuarios. No se asignan a los roles de las
     * clínicas ni aparecen en sus pantallas de permisos.
     */
    public const PLATFORM_MODULES = ['companies', 'plans', 'subscriptions', 'roles', 'users'];

    protected $fillable = ['name', 'slug', 'module', 'description'];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    /** Los permisos que se pueden dar a un rol de clínica. */
    public function scopeForClinics(Builder $query): Builder
    {
        return $query->whereNotIn('module', self::PLATFORM_MODULES);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
