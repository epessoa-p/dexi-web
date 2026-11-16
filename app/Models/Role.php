<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Conjunto de permisos con nombre. Catálogo GLOBAL: el mismo rol se asigna en
 * cualquier clínica vía company_user.role_id. Ver ARQUITECTURA §5.
 */
class Role extends Model
{
    use HasFactory, SoftDeletes;

    /** Roles estructurales que no se pueden eliminar desde la interfaz. */
    public const PROTECTED_SLUGS = ['super_admin', 'admin'];

    protected $fillable = ['name', 'slug', 'description'];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    /** Usuarios que tienen este rol en alguna clínica. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
                    ->withPivot('company_id', 'active');
    }

    public function isProtected(): bool
    {
        return in_array($this->slug, self::PROTECTED_SLUGS, true);
    }
}
