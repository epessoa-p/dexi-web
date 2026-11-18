<?php

namespace App\Support;

/**
 * Catálogo único de configuraciones. Lo consumen el seeder (valores por
 * defecto), los controladores (reglas de validación y casteo) y las vistas
 * (etiquetas y tipo de campo), para no repetir la definición en tres lugares.
 *
 * Cada definición: key => [group, label, type, input, rules, default, options?, help?]
 *   type  : string|bool|int|json  (cómo se guarda en la tabla settings)
 *   input : text|textarea|select|number|switch|time|image
 */
class SettingDefinitions
{
    /**
     * Configuración de cada clínica.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function company(): array
    {
        return [
            // --- General ---
            'company.trade_name' => [
                'group' => 'general', 'label' => 'Nombre comercial', 'type' => 'string',
                'input' => 'text', 'rules' => ['nullable', 'string', 'max:255'], 'default' => null,
                'help' => 'Se muestra en el menú lateral y en los documentos.',
            ],
            'company.logo' => [
                'group' => 'general', 'label' => 'Logo de la clínica', 'type' => 'string',
                'input' => 'image', 'rules' => ['nullable', 'image', 'max:2048'], 'default' => null,
                'help' => 'PNG o JPG, máximo 2 MB. Reemplaza al icono por defecto.',
            ],
            'company.phone' => [
                'group' => 'general', 'label' => 'Teléfono de contacto', 'type' => 'string',
                'input' => 'text', 'rules' => ['nullable', 'string', 'max:30'], 'default' => null,
            ],
            'company.email' => [
                'group' => 'general', 'label' => 'Email de contacto', 'type' => 'string',
                'input' => 'text', 'rules' => ['nullable', 'email', 'max:255'], 'default' => null,
            ],
            'company.address' => [
                'group' => 'general', 'label' => 'Dirección', 'type' => 'string',
                'input' => 'textarea', 'rules' => ['nullable', 'string', 'max:500'], 'default' => null,
            ],

            // --- Regional ---
            'regional.currency' => [
                'group' => 'regional', 'label' => 'Moneda', 'type' => 'string',
                'input' => 'select', 'rules' => ['required', 'string', 'size:3'], 'default' => 'BOB',
                'options' => ['BOB' => 'BOB — Boliviano', 'USD' => 'USD — Dólar', 'EUR' => 'EUR — Euro', 'ARS' => 'ARS — Peso argentino', 'MXN' => 'MXN — Peso mexicano', 'COP' => 'COP — Peso colombiano', 'PEN' => 'PEN — Sol'],
            ],
            'regional.currency_symbol' => [
                'group' => 'regional', 'label' => 'Símbolo de moneda', 'type' => 'string',
                'input' => 'text', 'rules' => ['nullable', 'string', 'max:5'], 'default' => 'Bs',
            ],
            'regional.timezone' => [
                'group' => 'regional', 'label' => 'Zona horaria', 'type' => 'string',
                'input' => 'select', 'rules' => ['required', 'timezone'], 'default' => 'America/La_Paz',
                'options' => 'timezones',
            ],
            'regional.date_format' => [
                'group' => 'regional', 'label' => 'Formato de fecha', 'type' => 'string',
                'input' => 'select', 'rules' => ['required', 'string', 'max:20'], 'default' => 'd/m/Y',
                'options' => ['d/m/Y' => '31/12/2026', 'm/d/Y' => '12/31/2026', 'Y-m-d' => '2026-12-31'],
            ],

            // --- Clínica ---
            'clinic.opens_at' => [
                'group' => 'clinic', 'label' => 'Hora de apertura', 'type' => 'string',
                'input' => 'time', 'rules' => ['nullable', 'date_format:H:i'], 'default' => '08:00',
            ],
            'clinic.closes_at' => [
                'group' => 'clinic', 'label' => 'Hora de cierre', 'type' => 'string',
                'input' => 'time', 'rules' => ['nullable', 'date_format:H:i'], 'default' => '18:00',
            ],
            'clinic.appointment_minutes' => [
                'group' => 'clinic', 'label' => 'Duración por defecto de cita (min)', 'type' => 'int',
                'input' => 'number', 'rules' => ['nullable', 'integer', 'min:5', 'max:480'], 'default' => 30,
            ],
            'clinic.works_saturday' => [
                'group' => 'clinic', 'label' => 'Atiende sábados', 'type' => 'bool',
                'input' => 'switch', 'rules' => ['nullable', 'boolean'], 'default' => true,
            ],
            'clinic.works_sunday' => [
                'group' => 'clinic', 'label' => 'Atiende domingos', 'type' => 'bool',
                'input' => 'switch', 'rules' => ['nullable', 'boolean'], 'default' => false,
            ],
            'clinic.commission_basis' => [
                'group' => 'clinic', 'label' => 'Cuándo se ganan las comisiones', 'type' => 'string',
                'input' => 'select', 'rules' => ['nullable', 'in:cobrado,vendido'], 'default' => 'cobrado',
                'options' => ['cobrado' => 'Sobre lo cobrado (cada pago del paciente genera su parte)', 'vendido' => 'Al vender (todo de una vez, aunque quede saldo)'],
                'help' => 'Solo con el módulo de comisiones. Las reglas se definen en Equipo › Comisiones.',
            ],
            'clinic.commission_minus_lab' => [
                'group' => 'clinic', 'label' => 'Descontar el laboratorio de la comisión', 'type' => 'bool',
                'input' => 'switch', 'rules' => ['nullable', 'boolean'], 'default' => true,
                'help' => 'El porcentaje del odontólogo se calcula sobre lo cobrado menos el costo del trabajo de laboratorio (corona, prótesis…).',
            ],

            // --- Documentos ---
            'documents.tax_id' => [
                'group' => 'documents', 'label' => 'NIT / RUC', 'type' => 'string',
                'input' => 'text', 'rules' => ['nullable', 'string', 'max:50'], 'default' => null,
            ],
            'documents.legal_name' => [
                'group' => 'documents', 'label' => 'Razón social', 'type' => 'string',
                'input' => 'text', 'rules' => ['nullable', 'string', 'max:255'], 'default' => null,
            ],
            'documents.footer_note' => [
                'group' => 'documents', 'label' => 'Pie de comprobantes', 'type' => 'string',
                'input' => 'textarea', 'rules' => ['nullable', 'string', 'max:500'], 'default' => null,
                'help' => 'Texto que aparece al final de recibos e informes.',
            ],
        ];
    }

    /**
     * Configuración global del SaaS (solo super admin).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function global(): array
    {
        return [
            // --- Marca ---
            'saas.app_name' => [
                'group' => 'brand', 'label' => 'Nombre del sistema', 'type' => 'string',
                'input' => 'text', 'rules' => ['required', 'string', 'max:60'], 'default' => 'Dexi',
            ],
            'saas.tagline' => [
                'group' => 'brand', 'label' => 'Descripción corta', 'type' => 'string',
                'input' => 'text', 'rules' => ['nullable', 'string', 'max:120'],
                'default' => 'Gestión para clínicas dentales',
            ],
            'saas.logo' => [
                'group' => 'brand', 'label' => 'Logo del sistema (modo global)', 'type' => 'string',
                'input' => 'image', 'rules' => ['nullable', 'image', 'max:2048'], 'default' => null,
                'help' => 'Si no subes ninguno se usa el logo por defecto de SCZ SOFT.',
            ],

            // --- Soporte ---
            'saas.support_email' => [
                'group' => 'support', 'label' => 'Email de soporte', 'type' => 'string',
                'input' => 'text', 'rules' => ['nullable', 'email', 'max:255'], 'default' => null,
                'help' => 'Se muestra a las clínicas con la suscripción vencida.',
            ],
            'saas.support_phone' => [
                'group' => 'support', 'label' => 'Teléfono / WhatsApp de soporte', 'type' => 'string',
                'input' => 'text', 'rules' => ['nullable', 'string', 'max:30'], 'default' => null,
            ],

            // --- Suscripciones ---
            'saas.default_plan_id' => [
                'group' => 'subscriptions', 'label' => 'Plan por defecto para nuevas clínicas', 'type' => 'int',
                'input' => 'select', 'rules' => ['nullable', 'integer', 'exists:plans,id'], 'default' => null,
                'options' => 'plans',
            ],
            'saas.default_trial_days' => [
                'group' => 'subscriptions', 'label' => 'Días de prueba por defecto', 'type' => 'int',
                'input' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:365'], 'default' => 15,
                'help' => 'Se usa cuando el plan elegido no define sus propios días de prueba.',
            ],
            'saas.default_grace_days' => [
                'group' => 'subscriptions', 'label' => 'Días de gracia por defecto', 'type' => 'int',
                'input' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:60'], 'default' => 3,
                'help' => 'Días tras el vencimiento en que la clínica aún puede entrar, solo para consultar.',
            ],
            'saas.block_on_expire' => [
                'group' => 'subscriptions', 'label' => 'Bloquear acceso al vencer la suscripción', 'type' => 'bool',
                'input' => 'switch', 'rules' => ['nullable', 'boolean'], 'default' => true,
                'help' => 'Si lo apagas, las clínicas vencidas siguen operando y solo verán un aviso.',
            ],
            'saas.expiry_warning_days' => [
                'group' => 'subscriptions', 'label' => 'Avisar vencimiento con (días) de antelación', 'type' => 'int',
                'input' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:90'], 'default' => 15,
            ],
        ];
    }

    /**
     * Etiquetas de los grupos, para los encabezados de las pantallas.
     */
    public static function groupLabels(): array
    {
        return [
            'general' => 'Datos generales',
            'regional' => 'Regional',
            'clinic' => 'Clínica',
            'documents' => 'Documentos',
            'brand' => 'Marca',
            'support' => 'Soporte',
            'subscriptions' => 'Suscripciones',
        ];
    }

    /**
     * Definiciones agrupadas por su clave `group`, en orden de declaración.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function grouped(string $scope = 'company'): array
    {
        $definitions = $scope === 'global' ? self::global() : self::company();
        $grouped = [];

        foreach ($definitions as $key => $definition) {
            $grouped[$definition['group']][$key] = $definition;
        }

        return $grouped;
    }
}
