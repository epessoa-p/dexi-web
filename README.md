# Dexi — Panel web

SaaS multi-clínica para clínicas y consultorios dentales. Cada clínica es un
*tenant* aislado, con su plan, su suscripción y sus propios datos.
Producto de **SCZ SOFT**. Laravel 12 · PHP 8.2 · MySQL/MariaDB.

Arquitectura: [`../ARQUITECTURA-EMPRESAS-PLANES-PERMISOS.md`](../ARQUITECTURA-EMPRESAS-PLANES-PERMISOS.md)
(clínica = empresa = tenant).

## Instalación

```bash
composer install
cp .env.example .env          # ajusta DB_* (en esta máquina, DB_PORT=3317)
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

## Credenciales de demostración

Todas con la contraseña `Admin@1234`.

| Usuario | Qué es |
|---|---|
| `superadmin@dexi.test` | Operador de la plataforma (Modo Global, puede «Entrar» a cualquier clínica) |
| `admin@clinicademo.test` | Admin de **Clínica Dental Demo** (plan Clínica, en prueba) |
| `admin@sonrisa.test` | Admin de **Clínica Sonrisa** (plan Consultorio, al día, cupo de 1 consultorio lleno) |
| `odontologo@clinicademo.test` | Odontólogo en las dos clínicas (prueba el cambio de clínica) |

## Cómo está armado

| Pieza | Dónde |
|---|---|
| Clínica activa por petición | `App\Support\Tenancy` — la fijan `SetTenant` (web, sesión) y `Api\ResolveCompany` (API, header `X-Company-Id`) |
| Aislamiento automático | trait `App\Models\Concerns\BelongsToCompany` + `CompanyScope` (fail-closed: sin clínica no se ve nada) |
| Modelos de tenant | `Patient`, `PatientDocument`, `Specialty`, `Procedure`, `Appointment`, `Schedule`, `ScheduleBlock`, `Branch` (consultorios), `Caja`, `Cargo`, `Personal`, `PaymentMethod`, `CashSession`, `CashMovement`, `FinanceAccount`, `FinanceMovement`, `ExpenseCategory`, `Expense`, `Supplier` |
| Menú | `App\Support\Navigation` — secciones con color; cada enlace se muestra solo con permiso + módulo del plan |
| Diseño | `public/css/dexi.css` (sin build) + paleta en `config/brand.php`. Componentes: `.page-header`, `.kpi-card`, `.empty-state`, `.quick-action`, `.table-stack` |
| Dinero | `Treasury` (único que cambia saldos de cuentas), `Cash` (turnos de caja), `PaymentSource` (¿de dónde sale el dinero?), `Expenses` (alta/anulación de gastos), `BasicServices` (control del mes), `Money` (formato con el símbolo de la clínica) |
| Modelos globales | `User`, `Company`, `Role`, `Permission`, `Plan`, `Subscription`, `SubscriptionPayment`, `Setting` |
| Módulos de plan | `Plan::MODULES` (agenda, historia_clinica, tratamientos, ventas, caja, finanzas, inventario, compras, comisiones, laboratorio, recordatorios, estadisticas) |
| Permiso → módulo | `Plan::PERMISSION_MODULE_FEATURES` (lo que no figura es administrativo: siempre disponible) |
| Estado de la suscripción | derivado de fechas: `Subscription::allowsWrite()/allowsRead()/inGrace()` |
| Overrides por clínica | `subscriptions.max_*_override` y `features_override` (se editan en Suscripciones → Plan y cupos) |
| Cupos al crear | `Controller::planLimitReached()` / `planLimitStatus()` |

Orden de las capas por petición: `auth → set-tenant → subscription → plan:<módulo> → check-permission:<a,b> → cupo`.

### Agregar un módulo del dominio dental (p. ej. agenda)

1. Migración con `company_id` y modelo con `use BelongsToCompany`.
2. Permisos `appointments.view|create|edit|delete` en `PermissionSeeder` y su reparto en `RolePermissionSeeder`.
3. Mapear `'appointments' => 'agenda'` en `Plan::PERMISSION_MODULE_FEATURES`.
4. Rutas dentro de `Route::middleware(['tenant', 'plan:agenda'])` con `check-permission:` por acción.
5. Enlace en `App\Support\Navigation` (sección correspondiente, con `$use('appointments.view', 'agenda')`).
6. Marcarlo en [`../CHECKLIST-DEXI.md`](../CHECKLIST-DEXI.md) y, si cambia la base, su script en `database/sql/`.

## Pacientes

- `/pacientes`: lista con buscador y filtros; ficha con pestañas; alta por secciones con anamnesis y tutor para menores.
- Validación única web/API en `App\Support\Patients`; documentos en el disco privado (`PatientDocumentController`).
- Producción sin artisan: `database/sql/2026_11_16_pacientes.sql`.

## Tarifario y agenda

- `/configuracion/tarifario`: procedimientos por especialidad (precio, duración, a qué se aplica); catálogo sugerido y ajuste por %.
- `/agenda`: calendario (FullCalendar por CDN), `/agenda/hoy` (sala de espera), `/agenda/disponibilidad` (quién atiende, horario semanal, bloqueos).
- Reglas en `App\Support\Agenda` (choques, sobreturno, horas libres, historial). El calendario trabaja en «hora de pared» de la clínica: las fechas viajan sin zona.
- **Procesos de consola** (seeders, futuros recordatorios): fijar la zona horaria de la clínica antes de usar `now()` (ver `AgendaDemoSeeder`).
- Producción sin artisan: `database/sql/2026_11_16_tarifario_y_agenda.sql`.

## Finanzas y caja

Según [`../ESTRUCTURA_CUENTAS_Y_GASTOS.md`](../ESTRUCTURA_CUENTAS_Y_GASTOS.md):

- **Caja** (`/caja`, módulo `caja`): turnos con apertura, ingresos/egresos/retiros, arqueo y cierre.
- **Cuentas** (`/finanzas/cuentas`, módulo `finanzas`): banco, caja chica, billeteras… con extracto, transferencias y anulación.
- **Gastos** (`/finanzas/gastos`, módulo `caja` o `finanzas`): pestañas Generales · Proveedores · Personal · Servicios básicos · Todos; el dinero sale de una cuenta o de la caja y vuelve al anular.
- **Proveedores** (`/proveedores`) y **Métodos de pago** (`/configuracion/metodos-de-pago`).
- Producción sin artisan: `database/sql/2026_11_16_finanzas.sql` (idempotente).
- Clínica Dental Demo trae datos de ejemplo (`FinanceDemoSeeder`).

## API (clientes móviles / SPA)

`/api/v1` con Sanctum. Headers: `Authorization: Bearer <token>`, `X-Company-Id: <id>`, `Accept: application/json`.

- `POST /api/v1/auth/login` `{email, password, device_name}` → token + perfil
- `GET /api/v1/me` → usuario, clínica, `permissions`, `plan.features`, `subscription.allows_write`
- `POST /api/v1/auth/logout`
- `/api/v1/patients` (+ `options`, `{id}`): pacientes
- `/api/v1/procedures`: tarifario · `/api/v1/appointments` (+ `slots`, `{id}`, `{id}/status`): agenda
- `/api/v1/finance/*`: `options`, `accounts` (+ `movements`, `transfer`), `movements/{id}/cancel`, `expenses` (+ `cancel`), `basics?month=YYYY-MM`, `expense-categories`

Errores con `code`: `company_required` (409), `company_forbidden`, `permission_denied`,
`plan_module_missing`, `subscription_inactive`, `subscription_grace_readonly` (403).

## Tests

```bash
php artisan test
```

`MultiTenancyTest` cubre aislamiento, permisos, plan, suscripción (gracia/bloqueo),
cupos, overrides y la API; `AgendaTest` y `ProcedureTest` cubren agenda y tarifario; `PatientTest` cubre pacientes, anamnesis, documentos y permisos; `FinanceTest` cubre cuentas, gastos, caja, servicios básicos y la API de finanzas;
`PagesSmokeTest` renderiza todas las pantallas con cada tipo de usuario.
