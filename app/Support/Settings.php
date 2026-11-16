<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Lectura y escritura de configuraciones con caché por request.
 * Se registra como singleton en AppServiceProvider y se accede vía el
 * helper global setting() / settings().
 */
class Settings
{
    /** @var array<string, array<string, mixed>> caché: scope => [key => value] */
    protected array $cache = [];

    /**
     * Valor de una configuración. $companyId null = configuración global.
     */
    public function get(string $key, mixed $default = null, ?int $companyId = null): mixed
    {
        $values = $this->all($companyId);

        if (!array_key_exists($key, $values)) {
            return $default ?? $this->definitionDefault($key, $companyId);
        }

        return $values[$key] ?? $default ?? $this->definitionDefault($key, $companyId);
    }

    /**
     * Todas las configuraciones de un ámbito, ya casteadas.
     *
     * @return array<string, mixed>
     */
    public function all(?int $companyId = null): array
    {
        $scope = $this->scopeKey($companyId);

        if (!isset($this->cache[$scope])) {
            $this->cache[$scope] = Setting::query()
                ->when($companyId === null,
                    fn ($q) => $q->whereNull('company_id'),
                    fn ($q) => $q->where('company_id', $companyId))
                ->get()
                ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->typedValue()])
                ->all();
        }

        return $this->cache[$scope];
    }

    /**
     * Guarda una configuración. El tipo y el grupo se toman del catálogo si no
     * se indican explícitamente.
     */
    public function set(string $key, mixed $value, ?int $companyId = null, ?string $type = null, ?string $group = null): void
    {
        $definition = $this->definition($key, $companyId);
        $type ??= $definition['type'] ?? 'string';
        $group ??= $definition['group'] ?? 'general';

        Setting::updateOrCreate(
            ['company_id' => $companyId, 'key' => $key],
            ['value' => Setting::encode($value, $type), 'type' => $type, 'group' => $group],
        );

        unset($this->cache[$this->scopeKey($companyId)]);
    }

    /**
     * Guarda un lote de configuraciones.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, ?int $companyId = null): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $companyId);
        }
    }

    public function forget(string $key, ?int $companyId = null): void
    {
        Setting::where('key', $key)
            ->when($companyId === null,
                fn ($q) => $q->whereNull('company_id'),
                fn ($q) => $q->where('company_id', $companyId))
            ->delete();

        unset($this->cache[$this->scopeKey($companyId)]);
    }

    public function flushCache(): void
    {
        $this->cache = [];
    }

    protected function scopeKey(?int $companyId): string
    {
        return $companyId === null ? 'global' : 'company:' . $companyId;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function definition(string $key, ?int $companyId): ?array
    {
        $definitions = $companyId === null
            ? SettingDefinitions::global()
            : SettingDefinitions::company();

        return $definitions[$key] ?? null;
    }

    protected function definitionDefault(string $key, ?int $companyId): mixed
    {
        return $this->definition($key, $companyId)['default'] ?? null;
    }
}
