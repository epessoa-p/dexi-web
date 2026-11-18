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
| Modelos de tenant | `Patient`, `PatientDocument`, `Specialty`, `Procedure`, `Appointment`, `Schedule`, `ScheduleBlock`, `Consultation`, `OdontogramEntry`, `TreatmentPlan`, `Sale`, `SalePayment`, `Branch` (consultorios), `Caja`, `Cargo`, `Personal`, `PaymentMethod`, `CashSession`, `CashMovement`, `FinanceAccount`, `FinanceMovement`, `ExpenseCategory`, `Expense`, `Supplier` |
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

## Atención clínica y odontograma

- `/atencion/consultas`: consultas (se abren con «Atender» desde la cita o la ficha). Reglas en `App\Support\Consultations` (abrir, notas, diagnósticos, procedimientos que marcan el odontograma, cerrar y firmar, notas de corrección).
- Odontograma FDI en `App\Support\Odontogram` (condiciones, caras, qué hallazgo resuelve cada tratamiento) y el dibujo SVG interactivo en `resources/views/admin/odontogram/chart.blade.php` (reutilizable: ficha del paciente y consulta).
- Producción sin artisan: `database/sql/2026_11_17_atencion_clinica.sql`.

## Planes de tratamiento

- `/atencion/tratamientos`: presupuestos por pieza y fase, sugeridos desde el odontograma (`App\Support\TreatmentPlans::suggestions`).
- Lo registrado en una consulta marca el ítem del plan aprobado (`TreatmentPlans::linkFromConsultation`); con todo hecho, el plan se termina solo.
- Producción sin artisan: `database/sql/2026_11_17_tratamientos.sql`.

## Ventas y cobros

- `/ventas/cobrar`: cobra lo pendiente del paciente (consultas y planes) y lo suelto; varios métodos, vuelto solo en efectivo, saldo como cuenta por cobrar. `/ventas`, `/cuentas-por-cobrar` (abono FIFO a la cuenta del paciente).
- Reglas en `App\Support\Sales` (cobrar una sola vez, caja obligatoria con el módulo `caja`, anulación con devolución).
- Producción sin artisan: `database/sql/2026_11_17_ventas.sql`.

## Reportes

- `/reportes/{resumen|finanzas|ventas|pacientes|profesionales|inventario}` con un solo filtro de período (compara con el anterior) y consultorio; `/reportes/exportar/{section}` descarga Excel.
- Cifras en `App\Support\Reports\ReportData` (cobrado, costo de productos e insumos, gastos, utilidad estimada); período en `ReportPeriod`; Excel sin dependencias en `XlsxWriter`. Permisos `reports.view` (sin dinero), `reports.finance`, `reports.export`; módulo `estadisticas`.
- Sin tablas nuevas: en producción basta con `db:seed --class=PermissionSeeder` y `RolePermissionSeeder`, y dar `estadisticas` a los planes.

## Laboratorio dental

- `/atencion/laboratorio`: órdenes de trabajo (OT-000001) con recorrido enviado → en proceso → recibido → instalado (y devolución para ajuste), hoja imprimible, filtros de atrasados / por instalar / por pagar.
- Lógica en `App\Support\LabOrders`. «Por pagar» paga varias órdenes con un gasto de proveedor (categoría LABORATORIO DENTAL); anular el gasto las libera. El costo se descuenta de la base de la comisión (`clinic.commission_minus_lab`).
- Producción sin artisan: `database/sql/2026_11_20_laboratorio.sql`.

## Inventario y compras

- `/inventario/productos` (alertas de mínimo y vencimiento, ficha con existencia por consultorio, lotes y kardex), `/inventario/movimientos` (entradas, salidas, ajustes por conteo, traslados), `/configuracion/tarifario/{procedure}/insumos`.
- `/compras` (P-000001: recepción parcial, pagos en cuotas desde cuenta o caja, cierre con lo recibido) y `/cuentas-por-pagar`.
- Todo cambio de existencia pasa por `App\Support\Stock` (kardex inmutable, FEFO); compras en `App\Support\Purchases`. Enganches: insumos al registrar un procedimiento (`Consultations::addProcedure`), venta de productos y su anulación (`Sales`).
- Producción sin artisan: `database/sql/2026_11_19_inventario_y_compras.sql`.

## Comisiones y producción

- `/equipo/comisiones`: comisiones por pagar (de cualquier fecha), producción por profesional y liquidaciones. `/equipo/comisiones/reglas` (regla más específica gana; momento «sobre lo cobrado» o «al vender» en `clinic.commission_basis`), `/equipo/comisiones/mias` para cada odontólogo.
- Lógica en `App\Support\Commissions` (se engancha en `Sales`: al vender, en cada pago y al anular). Liquidar crea un gasto de la categoría COMISIONES con `Expenses::record`.
- API: `GET /api/v1/commissions/pending`, `GET /commissions/{personal}`, `POST /commissions/{personal}/settle`.
- Producción sin artisan: `database/sql/2026_11_18_comisiones.sql`.

## Comunicaciones (WhatsApp)

- `/comunicaciones`: lista del día con el mensaje listo (recordatorios de las citas de mañana, cumpleaños, controles vencidos, inasistencias, saldos vencidos). «Enviar» abre `wa.me` con el texto y registra el envío. También `/comunicaciones/seguimientos`, `/historial` y `/mensajes` (plantillas con variables).
- Lógica en `App\Support\Communications`; módulo de plan `recordatorios`, permisos `communications.*`. Los controles se programan en la ficha del paciente (pestaña Recordatorios).
- Producción sin artisan: `database/sql/2026_11_18_comunicaciones.sql`.

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

## Logo e íconos

- Fuente: `recursos/logo.webp` (raíz del repo). `php recursos/scripts/generar-iconos.php .` recorta el diente (sin el texto) y regenera todo: favicon, íconos PWA y `public/images/logo*.{png,webp}` de la web, y los íconos (Android adaptativo e iOS), la pantalla de arranque y `assets/images` de `dexi_movil`.
- En la web: `layouts/partials/favicons` (todas las páginas e imprimibles), `public/site.webmanifest`, `<x-tooth-icon>` muestra el diente y el login usa el logo completo.

## Tests

```bash
php artisan test
```

`MultiTenancyTest` cubre aislamiento, permisos, plan, suscripción (gracia/bloqueo),
cupos, overrides y la API; `SaleTest` cubre cobros, abonos y anulación; `TreatmentPlanTest` cubre presupuestos y su avance; `ClinicalTest` cubre consultas y odontograma; `AgendaTest` y `ProcedureTest` cubren agenda y tarifario; `PatientTest` cubre pacientes, anamnesis, documentos y permisos; `FinanceTest` cubre cuentas, gastos, caja, servicios básicos y la API de finanzas;
`PagesSmokeTest` renderiza todas las pantallas con cada tipo de usuario.
