<?php

namespace App\Http\Controllers\Concerns;

use App\Support\SettingDefinitions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Validación y guardado genéricos de un formulario de configuraciones a
 * partir del catálogo de SettingDefinitions. Lo comparten la configuración
 * de la clínica y la global del SaaS.
 */
trait HandlesSettingsForms
{
    /**
     * Valida la request contra las definiciones y devuelve los valores listos
     * para guardar (las imágenes ya subidas al disco público).
     *
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<string, mixed>
     */
    protected function validateSettings(array $definitions, ?int $companyId): array
    {
        // Las claves llevan puntos, que en los formularios HTML se envían como
        // guiones bajos para no chocar con la notación de arrays de Laravel.
        $rules = [];
        foreach ($definitions as $key => $definition) {
            $rules[$this->fieldName($key)] = $definition['rules'];
        }

        $input = request()->validate($rules);
        $values = [];

        foreach ($definitions as $key => $definition) {
            $field = $this->fieldName($key);

            if ($definition['input'] === 'image') {
                $file = request()->file($field);

                if ($file instanceof UploadedFile) {
                    $values[$key] = $this->storeImage($file, $companyId);
                } elseif (request()->boolean($field . '_remove')) {
                    $values[$key] = null;
                }

                continue;
            }

            if ($definition['type'] === 'bool') {
                $values[$key] = request()->boolean($field);
                continue;
            }

            $values[$key] = $input[$field] ?? null;
        }

        return $values;
    }

    /**
     * Nombre del campo en el formulario para una clave de configuración.
     */
    protected function fieldName(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    protected function storeImage(UploadedFile $file, ?int $companyId): string
    {
        $folder = $companyId === null ? 'branding/global' : 'branding/companies/' . $companyId;

        return $file->store($folder, 'public');
    }

    /**
     * Valores actuales de todas las definiciones, para precargar el formulario.
     *
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<string, mixed>
     */
    protected function currentSettings(array $definitions, ?int $companyId): array
    {
        $values = [];

        foreach ($definitions as $key => $definition) {
            $values[$key] = settings()->get($key, $definition['default'], $companyId);
        }

        return $values;
    }

    protected function groupLabel(string $group): string
    {
        return SettingDefinitions::groupLabels()[$group] ?? ucfirst($group);
    }

    /**
     * Borra del disco una imagen reemplazada o eliminada.
     */
    protected function deleteStoredImage(?string $path): void
    {
        if ($path && !str_starts_with($path, 'http') && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
