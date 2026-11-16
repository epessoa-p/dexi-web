<?php

namespace App\Models;

use App\Support\Brand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Clínica = tenant. Todo dato operativo le pertenece vía company_id.
 *
 * Modelo GLOBAL: nunca lleva BelongsToCompany — es la tabla de tenants,
 * no un dato de tenant. Ver ARQUITECTURA §2.
 */
class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'ruc', 'address', 'phone', 'email', 'logo', 'description', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    /** Permisos efectivos ya calculados, por usuario (dura lo que la petición). */
    protected array $permissionCache = [];

    /**
     * Usuarios que pertenecen a esta clínica
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
                    ->withPivot('role_id', 'active')
                    ->withTimestamps();
    }

    /**
     * Consultorios / sedes de esta clínica
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function cajas(): HasMany
    {
        return $this->hasMany(Caja::class);
    }

    public function cargos(): HasMany
    {
        return $this->hasMany(Cargo::class);
    }

    public function personals(): HasMany
    {
        return $this->hasMany(Personal::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /**
     * Nombre comercial configurado, o la razón social si no hay ninguno.
     */
    public function displayName(): string
    {
        return settings()->get('company.trade_name', null, $this->id) ?: $this->name;
    }

    /**
     * URL del logo de la clínica. null si no tiene uno cargado.
     */
    public function logoUrl(): ?string
    {
        return Brand::resolveUrl(settings()->get('company.logo', null, $this->id) ?: $this->logo);
    }

    // ── Roles y permisos (ARQUITECTURA §5) ───────────────────────────────────

    /**
     * Rol de un usuario dentro de esta clínica
     */
    public function getRoleForUser(User $user): ?Role
    {
        $roleId = DB::table('company_user')
            ->where('company_id', $this->id)
            ->where('user_id', $user->id)
            ->value('role_id');

        return $roleId ? Role::find($roleId) : null;
    }

    /**
     * Permisos efectivos = los del rol ∪ los directos del usuario en esta clínica.
     *
     * @return array<int, string>
     */
    public function getPermissionsForUser(User $user): array
    {
        if ($user->is_super_admin) {
            return Permission::pluck('slug')->all();
        }

        return $this->permissionCache[$user->id] ??= $this->resolvePermissions($user);
    }

    protected function resolvePermissions(User $user): array
    {
        $role = $this->getRoleForUser($user);

        $base = $role ? $role->permissions()->pluck('slug')->all() : [];

        $direct = DB::table('user_permission')
            ->where('user_permission.user_id', $user->id)
            ->where('user_permission.company_id', $this->id)
            ->join('permissions', 'user_permission.permission_id', '=', 'permissions.id')
            ->pluck('permissions.slug')
            ->all();

        return array_values(array_unique(array_merge($base, $direct)));
    }

    // ── Suscripción, plan y cupos (fachada sobre Subscription, §8–9) ─────────

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function currentPlan(): ?Plan
    {
        return $this->subscription?->plan;
    }

    /**
     * Módulos realmente disponibles para esta clínica.
     *
     * @return array<int, string>
     */
    public function effectiveFeatures(): array
    {
        return $this->subscription?->effectiveFeatures() ?? [];
    }

    public function planAllows(string $feature): bool
    {
        return in_array($feature, $this->effectiveFeatures(), true);
    }

    /**
     * Tope real de un recurso para ESTA clínica. NULL = ilimitado.
     * Sin suscripción asignada no se aplica ningún tope (el acceso ya lo
     * corta EnsureSubscriptionActive).
     *
     * @param  string  $key  'users' | 'personal' | 'branches'
     */
    public function effectiveLimit(string $key): ?int
    {
        return $this->subscription?->effectiveLimitFor($key);
    }

    /**
     * Consumo actual de un recurso limitado.
     *
     * Usa forCompany() a propósito: con el CompanyScope activo, un
     * where('company_id', …) normal añadiría un segundo filtro por la clínica
     * activa y devolvería 0 al contar desde el panel del superadmin.
     */
    public function usageFor(string $key): int
    {
        return match ($key) {
            'users'    => $this->users()->wherePivot('active', true)->count(),
            'personal' => Personal::forCompany($this->id)->count(),
            'branches' => Branch::forCompany($this->id)->count(),
            default    => 0,
        };
    }

    public function withinLimit(string $key): bool
    {
        $limit = $this->effectiveLimit($key);

        return $limit === null || $this->usageFor($key) < $limit;
    }

    public function subscriptionAllowsWrite(): bool
    {
        return $this->subscription?->allowsWrite() ?? false;
    }

    public function subscriptionAllowsRead(): bool
    {
        return $this->subscription?->allowsRead() ?? false;
    }
}
