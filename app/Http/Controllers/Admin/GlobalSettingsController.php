<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Support\SettingDefinitions;
use Illuminate\Support\Facades\Log;

/**
 * Configuración global del SaaS (solo super admin).
 */
class GlobalSettingsController extends Controller
{
    use HandlesSettingsForms;

    public function edit()
    {
        $definitions = SettingDefinitions::global();

        return view('admin.global-settings.edit', [
            'grouped' => SettingDefinitions::grouped('global'),
            'values' => $this->currentSettings($definitions, null),
            'plans' => Plan::orderBy('sort_order')->pluck('name', 'id'),
            'scope' => 'global',
        ]);
    }

    public function update()
    {
        try {
            $previous = $this->currentSettings(SettingDefinitions::global(), null);
            $values = $this->validateSettings(SettingDefinitions::global(), null);

            if (array_key_exists('saas.logo', $values) && $values['saas.logo'] !== $previous['saas.logo']) {
                $this->deleteStoredImage($previous['saas.logo']);
            }

            settings()->setMany($values, null);

            return redirect()->route('global-settings.edit')->with('success', 'Configuración global actualizada exitosamente.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Error al guardar configuración global', ['message' => $exception->getMessage()]);

            return back()->withInput()->withErrors(['error' => 'No fue posible guardar la configuración global.']);
        }
    }
}
