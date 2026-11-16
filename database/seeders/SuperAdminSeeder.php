<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Cuentas de demostración. Todas con la contraseña Admin@1234.
 *
 *   superadmin@dexi.test       → operador de la plataforma (sin clínica)
 *   admin@clinicademo.test     → admin de Clínica Dental Demo
 *   odontologo@clinicademo.test→ odontólogo en Demo y en Sonrisa (multi-clínica)
 *   admin@sonrisa.test         → admin de Clínica Sonrisa
 */
class SuperAdminSeeder extends Seeder
{
    public const PASSWORD = 'Admin@1234';

    public function run(): void
    {
        $this->user('superadmin', 'superadmin@dexi.test', 'Super Administrador', isSuperAdmin: true);

        $demo = Company::where('ruc', '1000000001')->first();
        $sonrisa = Company::where('ruc', '1000000002')->first();

        $this->member($this->user('admin_demo', 'admin@clinicademo.test', 'Admin Demo'), $demo, 'admin');
        $this->member($this->user('admin_sonrisa', 'admin@sonrisa.test', 'Admin Sonrisa'), $sonrisa, 'admin');

        $odontologo = $this->user('dra_perez', 'odontologo@clinicademo.test', 'Dra. Pérez');
        $this->member($odontologo, $demo, 'odontologo');
        $this->member($odontologo, $sonrisa, 'odontologo');
    }

    /**
     * Crea el usuario o lo actualiza sin pisar su contraseña.
     */
    protected function user(string $username, string $email, string $label, bool $isSuperAdmin = false): User
    {
        $user = User::firstOrNew(['email' => $email]);

        $user->fill([
            'name' => $username,
            'phone' => null,
            'is_super_admin' => $isSuperAdmin,
            'active' => true,
        ]);

        if (! $user->exists) {
            $user->password = self::PASSWORD;
        }

        $user->save();

        return $user;
    }

    protected function member(User $user, ?Company $company, string $roleSlug): void
    {
        $roleId = Role::where('slug', $roleSlug)->value('id');

        if ($company && $roleId) {
            $user->companies()->syncWithoutDetaching([
                $company->id => ['role_id' => $roleId, 'active' => true],
            ]);
        }
    }
}
