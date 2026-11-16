<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesSettingsForms;
use App\Support\SettingDefinitions;
use Illuminate\Support\Facades\Log;

/**
 * Configuración de la clínica activa.
 */
class SettingsController extends Controller
{
    use HandlesSettingsForms;

    public function edit()
    {
        $company = $this->currentCompany();
        $definitions = SettingDefinitions::company();

        return view('settings.edit', [
            'company' => $company,
            'grouped' => SettingDefinitions::grouped('company'),
            'values' => $this->currentSettings($definitions, $company->id),
            'scope' => 'company',
        ]);
    }

    public function update()
    {
        $company = $this->currentCompany();

        try {
            $previous = $this->currentSettings(SettingDefinitions::company(), $company->id);
            $values = $this->validateSettings(SettingDefinitions::company(), $company->id);

            // Si el logo cambió, borramos el archivo anterior.
            if (array_key_exists('company.logo', $values) && $values['company.logo'] !== $previous['company.logo']) {
                $this->deleteStoredImage($previous['company.logo']);
            }

            settings()->setMany($values, $company->id);

            return redirect()->route('settings.edit')->with('success', 'Configuración actualizada exitosamente.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Error al guardar configuración de clínica', [
                'company_id' => $company->id,
                'message' => $exception->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'No fue posible guardar la configuración.']);
        }
    }

    protected function currentCompany(): \App\Models\Company
    {
        $company = auth()->user()->getCurrentCompany();

        abort_if($company === null, 403, 'No tienes una clínica activa.');

        return $company;
    }
}
