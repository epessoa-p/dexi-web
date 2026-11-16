<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sesión de clientes API (app móvil, SPA) con tokens de Sanctum, y `GET /me`
 * (ARQUITECTURA §11): con él el cliente arma su menú; el servidor igual
 * revalida cada petición.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'required|string|max:100',
        ], [], ['device_name' => 'dispositivo']);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'El correo o la contraseña no son correctos.']);
        }

        if (! $user->active) {
            throw ValidationException::withMessages(['email' => 'Tu usuario está desactivado. Habla con el administrador de tu clínica.']);
        }

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return response()->json(['token' => $token, 'token_type' => 'Bearer'] + $this->profile($user, null));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user(), $request->attributes->get('tenant_company')));
    }

    /** @return array<string, mixed> */
    protected function profile(User $user, ?Company $company): array
    {
        $companies = $user->is_super_admin
            ? Company::where('active', true)->orderBy('name')->get()
            : $user->activeCompanies()->orderBy('name')->get();

        $subscription = $company?->subscription;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'is_super_admin' => (bool) $user->is_super_admin,
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->displayName(),
                'currency' => settings()->get('regional.currency', null, $company->id),
                'currency_symbol' => settings()->get('regional.currency_symbol', null, $company->id),
                'timezone' => settings()->get('regional.timezone', null, $company->id),
                'phone' => $company->phone,
                'address' => $company->address,
                'logo_url' => $company->logoUrl(),
            ] : null,
            'companies' => $companies->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'permissions' => $company ? array_values($company->getPermissionsForUser($user)) : [],
            'plan' => $company ? [
                'name' => $subscription?->plan?->name,
                'features' => array_values($company->effectiveFeatures()),
            ] : null,
            'subscription' => $subscription ? [
                'status' => match (true) {
                    $subscription->isBlocked() => 'blocked',
                    $subscription->onTrial() => 'trial',
                    $subscription->isCurrent() => 'active',
                    $subscription->inGrace() => 'grace',
                    default => 'inactive',
                },
                'label' => $subscription->statusLabel(),
                'ends_at' => $subscription->periodEnd()?->toIso8601String(),
                'allows_write' => $subscription->allowsWrite(),
            ] : null,
        ];
    }
}
