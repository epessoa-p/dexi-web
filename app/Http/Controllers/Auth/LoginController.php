<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\Company;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request)
    {
        try {
            $login = trim((string) $request->email);
            $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

            if (!Auth::attempt([$field => $login, 'password' => $request->password], $request->boolean('remember'))) {
                return back()->withErrors(['email' => 'Las credenciales no son válidas']);
            }

            // Nuevo id de sesión tras autenticar (evita fijación de sesión).
            $request->session()->regenerate();

            $user = Auth::user();

            if (!$user->active) {
                Auth::logout();
                return back()->withErrors(['email' => 'Tu usuario ha sido desactivado']);
            }

            if ($user->is_super_admin) {
                session(['current_company_id' => null]);
                return redirect()->route('dashboard');
            }

            $companies = $user->activeCompanies()->get();

            if ($companies->isEmpty()) {
                Auth::logout();
                return back()->withErrors(['email' => 'No tienes acceso a ninguna empresa']);
            }

            if ($companies->count() === 1) {
                session(['current_company_id' => $companies->first()->id]);
                return redirect()->route('dashboard');
            }

            return redirect()->route('select-company')->with('companies', $companies);
        } catch (\Throwable $exception) {
            Log::error('Error al iniciar sesión', [
                'login' => $request->email,
                'message' => $exception->getMessage(),
            ]);

            return back()->withErrors(['email' => 'No fue posible iniciar sesión en este momento.']);
        }
    }

    public function selectCompany()
    {
        $user = auth()->user();
        $companies = $user->activeCompanies()->get();

        if ($companies->isEmpty()) {
            Auth::logout();
            return redirect('login')->with('error', 'No tienes acceso a ninguna empresa');
        }

        return view('auth.select-company', compact('companies'));
    }

    /**
     * Fija la clínica activa. Un usuario solo puede elegir una de las suyas;
     * el superadmin puede "entrar como" cualquier clínica activa.
     */
    public function setCompany($companyId)
    {
        $user = auth()->user();

        $company = $user->is_super_admin
            ? Company::where('active', true)->findOrFail($companyId)
            : $user->activeCompanies()->findOrFail($companyId);

        session(['current_company_id' => $company->id]);
        app(Tenancy::class)->setCompany($company);

        return redirect()->route('dashboard')
            ->with('success', $user->is_super_admin ? "Estás trabajando dentro de {$company->name}." : null);
    }

    /**
     * Superadmin: sale de la clínica y vuelve al Modo Global (sin filtro).
     */
    public function exitCompany()
    {
        abort_unless(auth()->user()->is_super_admin, 403);

        session()->forget('current_company_id');
        app(Tenancy::class)->unrestrict();

        return redirect()->route('dashboard')
            ->with('success', 'Volviste al Modo Global: estás viendo todas las clínicas.');
    }

    public function logout()
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        app(Tenancy::class)->forget();

        return redirect('/login');
    }
}
