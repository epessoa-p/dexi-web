<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Catálogo GLOBAL de roles (ARQUITECTURA §5). Los permisos de cada uno se
 * asignan en RolePermissionSeeder, después de sembrar los permisos.
 */
class RoleSeeder extends Seeder
{
    public const ROLES = [
        // Estructurales: el código los busca por slug (Role::PROTECTED_SLUGS).
        ['slug' => 'super_admin', 'name' => 'Super Administrador', 'description' => 'Operador de la plataforma Dexi'],
        ['slug' => 'admin', 'name' => 'Administrador de Clínica', 'description' => 'Administra una clínica: usuarios, personal, consultorios, cajas y configuración'],

        // Operativos de una clínica dental.
        ['slug' => 'odontologo', 'name' => 'Odontólogo', 'description' => 'Atiende pacientes: agenda, historia clínica y tratamientos'],
        ['slug' => 'asistente', 'name' => 'Asistente dental', 'description' => 'Apoya al odontólogo en el consultorio'],
        ['slug' => 'recepcionista', 'name' => 'Recepcionista', 'description' => 'Agenda citas y recibe a los pacientes'],
        ['slug' => 'cajero', 'name' => 'Cajero', 'description' => 'Registra cobros y movimientos de caja'],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
