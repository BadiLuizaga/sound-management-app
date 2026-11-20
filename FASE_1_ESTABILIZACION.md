# FASE 1 — ESTABILIZACIÓN TÉCNICA DE SOUND MANAGEMENT

**Fecha:** 2026-11-20
**Proyecto:** `E:\BadiStudio\sound-management-app` (repositorio git local, rama `main`, **sin commits**)

> **Nota sobre las restricciones:** el mensaje de la Fase 1 llegó cortado en "## RESTR…". Se aplicaron las restricciones de las fases anteriores: no se instalaron dependencias, no se actualizó Laravel ni ningún paquete, no se ejecutaron migraciones ni la suite de pruebas, no se inició Docker (el daemon ni siquiera estaba activo), no se hicieron commits ni push, y no se tocó `E:\ferreteria`. Si la sección completa decía otra cosa, hay que revisarlo.

**Verificación realizada:**
- `php -l` sobre los 22 archivos PHP tocados: sin errores.
- `docker compose config`: válido, y falla a propósito si faltan contraseñas.
- Script aislado del validador SQL: 26 casos correctos.

**Sin verificar en ejecución:** no hay `vendor/` ni BD. Los cambios de autorización, checkout e IA deben probarse tras `composer install` (§9).

---

## 1. Auditoría técnica de estabilización (estado comprobado)

Cada punto se comprobó en el código. Se marcan las correcciones respecto a la auditoría anterior.

| Área | Estado real comprobado |
|---|---|
| Laravel | **11.51.0** (`composer.lock`). La rama 11 ya no recibe parches de seguridad (fin previsto: marzo de 2026). |
| PHP | Local **8.2.12** (XAMPP, sin `gd`). Docker 8.4. `composer.json` pide `^8.2`. **El lock exige ≥ 8.3** (ver §2). |
| Composer | 2.9.5 local. 84 paquetes + 34 dev. `vendor/` no instalado. |
| nwidart/laravel-modules | **v13.0.0** + `wikimedia/composer-merge-plugin` 2.1.0. Los 6 módulos están activos (`modules_statuses.json`). Migraciones y vistas siguen en la raíz, no en los módulos. |
| Módulos | Access (12 controladores, 6 modelos), Inventory (4/6), Sales (4/8), Rentals (3/4), Procurement (4/7), Audit (2/1). `Modules/Access/Core/*` es código muerto (no está en el autoload). |
| Migraciones | 28 originales + 1 nueva de esta fase. **No construyen una BD limpia** (§5). |
| Modelos | PK naturales manuales (`ci`, `idproducto`, `nro`). `keyType = 'string'` sobre columnas INT. `User` (tabla `users`) no se usa para autenticar. |
| Autenticación | Breeze con provider `Usuario` (tabla `usuario`). Registro público como cliente (`tipoPersona = 'C'`). **Corrección:** el middleware `verified` **no tiene efecto**, porque `Usuario` no implementa `MustVerifyEmail` (la auditoría decía que nadie podría verificarse). |
| Roles y permisos | Antes había 3 Gates con nombres de rol fijos y una consulta por cada `@can`. El volcado original tiene además el rol **"Vendedor"**, que ningún Gate reconocía. **Ahora:** matriz central (§3.4). |
| Middleware | `admin` (redirige a `/` si no lo es). No había Policies ni Form Requests de negocio. |
| Rutas | Las de cada módulo van envueltas en `web` por su `RouteServiceProvider`. `productos.show` y `maquinarias.show` daban error 500 porque el controlador no tiene `show` (ahora excluidos). |
| Docker | Antes: `artisan serve` (servidor de desarrollo), `migrate --force` en cada arranque, MySQL 8.0 con contraseñas `password` y puertos abiertos a toda la red. **Ahora:** §6. |
| MySQL | El código usa tablas con mayúsculas (`NotaVenta`, `estadoRol`, `metodoPago`), lo que es un riesgo entre Windows y Linux (§6). |
| Variables de entorno | `.env.example` apuntaba a `ferreteria_guisella`; la API de Groq se leía con `env()` desde un controlador. **Ahora:** §3.5. |

---

## 2. Compatibilidad

### 2.1 Versiones actuales e incompatibilidades detectadas

| Componente | Versión | Problema |
|---|---|---|
| `laravel/framework` | 11.51.0 | **Sin soporte de seguridad** desde ~marzo de 2026. |
| `nwidart/laravel-modules` | 13.0.0 | Exige **PHP ^8.3**, así que `composer install` **falla con el PHP 8.2.12 local**. Sus `require-dev` son `laravel/framework ^13`, testbench 11 y PHPUnit 12: es la línea para **Laravel 13**. Composer lo aceptó sobre Laravel 11 porque no declara una dependencia dura de Laravel, pero esa combinación **no está garantizada**. |
| PHP local | 8.2.12 | Incompatible con el lock. Además le falta `ext-gd` (dompdf la usa para imágenes). Soporte de seguridad hasta el 31-12-2026. |
| `laravel/tinker` | 2.11.1 | Declara soporte hasta Laravel `^12`; habrá que cambiarlo al pasar a Laravel 13. |
| `symfony/http-foundation` | ^7.0 en `composer.json` | Restricción redundante (la fija Laravel); puede bloquear una actualización. |
| MySQL (Docker) | 8.0 | 8.0 llegó al fin de vida en abril de 2026. |
| `barryvdh/laravel-dompdf` 3.1.2, `laravel/breeze` 2.4.1, `laravel/pail` 1.2.6 | — | Ya declaran soporte para Laravel 13. |
| `phpunit` 11.5, `collision` 8.9 | — | Habrá que revisarlos al actualizar (nwidart v13 usa PHPUnit 12). |

### 2.2 Combinación recomendada

| Componente | Recomendado | Motivo |
|---|---|---|
| PHP | **8.4** en Docker **y** en local | Cumple el lock (≥ 8.3) y Laravel 11 y 13. Unifica entornos. |
| Laravel | **13.x** (actualización planificada, *no* ahora) | Es la línea con soporte y la que usa nwidart v13. |
| nwidart/laravel-modules | **13.x** (ya instalado) | Se alinea con Laravel 13. |
| MySQL | **8.4 LTS** | 8.0 ya sin soporte. |
| PHPUnit | 12.x (con Laravel 13) | Consistencia con el ecosistema de Laravel 13. |
| Docker | PHP-FPM 8.4 + Nginx 1.27 + MySQL 8.4 | §6. |

**Mientras no se actualice:** usar PHP 8.4 en Docker (ya configurado). Para trabajar fuera de Docker hay que instalar PHP ≥ 8.3 con `gd`. La alternativa sin tocar PHP sería bajar nwidart a la línea 11.x (la de Laravel 11), pero es un cambio de versión: **no se hizo**.

**Actualización a Laravel 13 (pendiente de aprobación):**
1. Hacer pasar las pruebas actuales en Laravel 11.
2. Actualizar en una rama propia: `laravel/framework ^13`, `laravel/tinker`, `phpunit ^12`, `nunomaduro/collision`; quitar `symfony/http-foundation` del `require`.
3. Revisar la guía oficial de actualización 11→12→13 y comprobar las fechas de soporte en laravel.com/docs/releases.

---

## 3. Seguridad — cambios aplicados

### 3.1 Consultas SQL del módulo de IA (`Modules/Audit`)

| Antes | Ahora |
|---|---|
| `/reportes-ia` abierto a **cualquier usuario autenticado** (incluidos clientes autorregistrados). | Solo `admin` + habilidad `reports.ai` + `throttle:10,1`. |
| Siempre activo si había clave. | **Desactivado por defecto** (`AI_REPORTS_ENABLED=false`). |
| `env('GROQ_API_KEY')` dentro del controlador (no funciona con `config:cache`). | `config('services.groq.*')`. |
| Validación "empieza por SELECT". | `ReadOnlySqlGuard`: una sola sentencia; sin comentarios, `INTO`, `OUTFILE`, `LOAD_FILE`, `SLEEP`, bloqueos, variables `@`/`@@` ni esquemas del sistema; **tablas permitidas** solo de negocio; prohibidos `password`, `remember_token`, `sessions`, `users`, `cache`, `jobs`, `pagos_externos`, etc.; **sin `SELECT *`** (salvo `COUNT(*)`). |
| `DB::select` sobre la conexión principal. | Conexión `mysql_reports` (usuario de solo lectura opcional, ver `docker/mysql/reports-user.sql.example`), `SET TRANSACTION READ ONLY`, `MAX_EXECUTION_TIME`, transacción siempre revertida y máximo de 500 filas. |
| Errores internos devueltos al navegador. | Mensaje genérico; el detalle va al log. |
| Sin rastro. | Cada consulta (aceptada o rechazada) queda en la bitácora. |
| **XSS almacenado:** los resultados se insertaban con `innerHTML` sin escapar (un nombre de cliente con HTML ejecutaba código en la sesión del admin). | `escapeHtml()` en cabeceras y celdas. |

**Impacto:** quien no sea administrador pierde el acceso a reportes IA, y la función queda apagada hasta activarla en `.env`.

### 3.2 Autorización de operaciones CRUD

| Operación | Antes | Ahora |
|---|---|---|
| Crear, editar o borrar productos (`productos.*`) | Cualquier autenticado | `can:inventory.manage` (admin, encargado, almacenero) |
| `GET /api/producto/{id}` (incluye **costo**) | Cualquier autenticado | `can:inventory.manage` |
| POS: `ventas.index/create/store` | Cualquier autenticado | `can:sales.operate` (admin, encargado, vendedor) |
| Alquileres: listar y ver | Cualquier empleado | `can:rentals.view` |
| Alquileres: crear y devolver | Cualquier empleado | `can:rentals.operate` (admin, encargado, vendedor) |
| Maquinarias: listar | Cualquier empleado | `can:equipment.view` (alta, edición y baja siguen exigiendo `admin`) |
| Mantenimientos | Solo `admin` | `can:maintenance.manage` (admin, encargado, **técnico**) |

### 3.3 Protección de rutas administrativas y datos de clientes

- `GET /api/clientes/buscar/{ci}` y `POST /api/clientes/rapido`: antes **cualquier autenticado** obtenía nombre, teléfono y email de otro cliente por su CI. Ahora exigen `can:customers.lookup` + `throttle:60,1`.
- **IDOR en comprobantes:** `ventas/comprobante/{nro}` permitía a cualquier usuario descargar la nota de **otro** cliente. Ahora solo el personal con `sales.operate` o el **cliente dueño** de la nota.
- `alquileres/{id}/comprobante`: personal con `rentals.view` o el **cliente titular**.
- **Comprobado sin cambios:** las cotizaciones guardadas ya filtraban por el cliente (`where('ci_cliente', $ci)`), así que no había fuga ahí. `/api/usuario/{ci}`, usuarios, proveedores, compras, devoluciones, pedidos, caja y promociones ya estaban bajo `admin`.
- La barra lateral solo muestra cada enlace a quien tiene la habilidad correspondiente (antes se veían Ventas, Alquileres, Maquinarias y Reportes IA aunque dieran 403).

**Resultado:** un cliente ya no puede consultar datos de otros clientes por ninguna de las vías encontradas (API de clientes, comprobantes, reportes IA, alquileres).

### 3.4 Separación de permisos: administrador, encargado, técnico y cliente

Nuevo `config/access.php` + `App\Support\AccessControl`, sin paquetes nuevos:
- Los roles salen de `estadoRol` activo, igual que antes. Los nombres se comparan sin mayúsculas ni tildes.
- Se calculan **una vez por petición** (servicio *scoped*), en lugar de una consulta por cada `@can`.
- Los Gates `admin`, `almacenero` y `cliente` mantienen su significado. El Gate `almacenero` ahora también incluye al **encargado**.

| Habilidad | admin | encargado | técnico | almacenero¹ | vendedor¹ | cliente |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `admin` | ✅ | | | | | |
| `inventory.manage` | ✅ | ✅ | | ✅ | | |
| `sales.operate`, `customers.lookup` | ✅ | ✅ | | | ✅ | |
| `rentals.view`, `equipment.view` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `rentals.operate` | ✅ | ✅ | | | ✅ | |
| `maintenance.manage` | ✅ | ✅ | ✅ | | | |
| `reports.ai` | ✅ | | | | | |
| Sus propios comprobantes y cotizaciones | ✅ | ✅ | | | ✅ | ✅ (solo los suyos) |

¹ Roles heredados de la ferretería. "vendedor" acepta los nombres `Vendedor`, `Cajero` y `Secretaria`.

**Impacto:** un empleado con un rol personalizado creado en "Trabajos" que no esté en la matriz **pierde** el acceso a Ventas y Alquileres, que antes tenía de forma implícita. Para restaurarlo, se añade su nombre a `config/access.php`. Los roles **Encargado** y **Técnico** todavía no existen en la BD: se crean desde "Trabajos" o con el seeder de la Fase 2.

### 3.5 Credenciales y variables de entorno
- `.env.example`: `DB_DATABASE=sound_management_db`, `DB_USERNAME=sound_app` y `APP_NAME="Sound Management"`. Se añadieron como **placeholders vacíos**: `DB_ROOT_PASSWORD`, `DB_REPORTS_*`, `GROQ_*`, `AI_REPORTS_*` y `PAYPAL_*`.
- El client-ID de PayPal escrito en la vista pasó a `PAYPAL_CLIENT_ID`. El secreto solo se usa en el servidor.
- `docker-compose.yml` ya no contiene contraseñas: las exige desde `.env`, sin valores por defecto.
- No se encontró ningún secreto real en archivos versionables. **La contraseña de Railway sigue pendiente de rotar en el proveedor.**

### 3.6 Validación de operaciones financieras
- Correlativos `max()+1` con `lockForUpdate()` dentro de la transacción: `NotaVenta` (POS y checkout), `NotaCompra`, `rol.id` (ahora en transacción) e `idproducto`.
- **Bug corregido:** el `idproducto` de la alta rápida en Compras ignoraba los productos borrados (SoftDeletes) y podía repetir un ID existente. Ahora usa `withTrashed()`.
- Checkout: los productos se bloquean (`FOR UPDATE`) antes de descontar el stock.
- Caja: ya no se pueden abrir **dos cajas a la vez** para el mismo usuario (los cortes duplicaban las ventas).
- **Bug corregido:** crear un rol sin descripción fallaba, porque `rol.descripcion` es NOT NULL.

### 3.7 Verificación de pagos del lado del servidor (checkout PayPal)

| Antes | Ahora |
|---|---|
| PayPal se capturaba en el navegador y después se enviaba un formulario vacío: **un POST directo creaba una venta "pagada" y descontaba stock sin pagar**. | El formulario envía solo `paypal_order_id`. `PayPalOrderVerifier` consulta la orden en la API de PayPal (OAuth con el secreto del servidor) y exige `COMPLETED`, captura `COMPLETED`, **moneda** y **monto exacto** del carrito (en centavos). |
| La misma orden podía reutilizarse. | Tabla nueva `pagos_externos` con clave única `(proveedor, referencia_externa)`: una orden solo genera una venta. |
| Si fallaba el stock tras cobrar, el pago se perdía sin rastro. | El pago queda con estado `requiere_reembolso` y se registra en la bitácora. |
| Sin credenciales, el botón seguía funcionando. | Sin `PAYPAL_CLIENT_ID` y `PAYPAL_CLIENT_SECRET`, **el checkout en línea queda desactivado** (no hay ventas sin pago). |

**Pendiente:**
- PayPal no admite BOB. El monto en bolivianos se cobra como USD, igual que antes, así que hace falta conversión o la pasarela definitiva.
- `id_pago = 2` sigue fijo.
- Conviene sumar el webhook de PayPal como segunda vía de confirmación.

### 3.8 Acceso a documentos privados
- Los PDF (comprobantes, cotización, corte de caja) se generan al vuelo y **no se guardan en disco**. El acceso queda controlado en los controladores (§3.3).
- Logos e imágenes de marcas y categorías van al disco `public`, porque son contenido público por diseño.
- `/storage/app/private` está excluido de git. Nginx deniega los archivos ocultos (`.env`, `.git`) y cualquier `.php` que no sea `index.php`.

### 3.9 Hallazgos de seguridad documentados pero NO corregidos (requieren decisión o son de otra fase)
1. **Contraseña por defecto = CI** en el alta rápida de clientes (`VentaController@registrarCliente`). Cambiarla afecta al proceso de atención: queda por decidir.
2. `GET /api/clientes/buscar` **escribe en la BD** (crea `cliente`). Ahora solo lo usa el personal.
3. Interpolación sin escapar en `innerHTML` en `admin/devoluciones/create` y `admin/pedidos/create` (solo admin; módulos de retail que se retirarán).
4. 13 controladores devuelven `$e->getMessage()` al usuario.
5. La caja **no suma los ingresos por alquiler** (aunque alquilar exige tener la caja abierta).
6. Solo el admin puede abrir caja, así que un vendedor no puede registrar ventas en el POS sin una caja abierta a su nombre (igual que antes).
7. Scripts de terceros sin versión fija (Alpine `3.x.x`, Tailwind CDN) y el chatbot Botpress de la ferretería.
8. Credenciales de demostración (`admin@ferre.bo`) en el README y el seeder.
9. `AdminUserSeeder` fija el rol `id=3` como "Cliente". En el SQL original ese id era "Almacenero": en una BD existente lo renombraría.

---

## 4. Archivos modificados y creados en la Fase 1

**Creados:**
- `config/access.php`
- `app/Support/AccessControl.php`
- `Modules/Audit/app/Services/ReadOnlySqlGuard.php`
- `Modules/Sales/app/Services/PayPalOrderVerifier.php`
- `Modules/Sales/app/Models/PagoExterno.php`
- `database/migrations/2026_11_20_000001_create_pagos_externos_table.php`
- `docker/php/app.ini`, `docker/nginx/default.conf`, `docker/mysql/reports-user.sql.example`
- `FASE_1_ESTABILIZACION.md`

**Modificados:**
- `app/Providers/AppServiceProvider.php`, `config/services.php`, `config/database.php`
- `Modules/{Inventory,Sales,Rentals,Audit}/routes/web.php`
- `Modules/Audit/app/Http/Controllers/IaReportController.php`
- `Modules/Sales/app/Http/Controllers/{CartController,VentaController,CajaController}.php`
- `Modules/Rentals/app/Http/Controllers/AlquilerController.php`
- `Modules/Procurement/app/Http/Controllers/CompraController.php`
- `Modules/Access/app/Http/Controllers/TrabajoController.php`
- `resources/views/carrito/index.blade.php`, `resources/views/bitacora/ia-reports.blade.php`, `resources/views/components/admin-sidebar.blade.php`
- `.env.example`, `.gitignore`, `Dockerfile`, `docker-compose.yml`

No se eliminó ninguna funcionalidad. Las que se restringieron o desactivaron están documentadas con su impacto en §3.

---

## 5. Base de datos

### 5.1 Orden de ejecución en una BD vacía (`sound_management_db`)

| # | Migración | Resultado |
|---|---|---|
| 1–3 | `0001_01_01_*` (users, cache, jobs) | ✅ |
| 4 | `2023_01_01_000000_create_base_tables` | ✅ marca, color, medida, volumen, metodoPago, categoria, usuario, producto, empleado, cliente, rol, estadoRol, bitacora |
| 5–17 | `2026_04_13` … `2026_06_01` | ✅ (dos vacías: `2026_05_13_060426` y `2026_05_13_071914`) |
| 18–20 | `2026_06_02_*` maquinarias, alquileres, alquiler_detalles | ✅ |
| 21 | `2026_06_21_221019_add_imagen_to_promociones_table` | ❌ **Primera falla:** `promociones` todavía no existe (se crea en la #27) |
| 22 | `2026_06_21_225458_add_cantidad_to_mantenimientos_table` | ❌ `mantenimientos` no existe (se crea en la #25) |
| 23 | `2026_06_21_230100_change_maquinaria_id_to_idproducto_...` | ❌ mismo motivo |
| 24 | `2026_06_22_000001_create_devoluciones_table` | ❌ FK a `NotaVenta`, que **no tiene migración** |
| 25 | `2026_06_22_000002_create_mantenimientos_table` | ✅ (se crea con `maquinaria_id`, la forma antigua) |
| 26 | `2026_06_22_000003_create_pedidos_reabastecimiento_table` | ✅ |
| 27 | `2026_06_22_000004_create_promociones_table` | ✅ (sin `imagen`) |
| 28 | `2026_06_23_000001_create_cotizaciones_tables` | ✅ |
| 29 | `2026_11_20_000001_create_pagos_externos_table` (nueva) | ✅ (sin FKs a propósito) |

**Conclusión:** las migraciones **no construyen una BD limpia**. `migrate` se detiene en la #21.

### 5.2 Tablas que solo existen en SQL externo (`ferreteria.sql`)

| Tabla | FKs (según el volcado) | Usada por |
|---|---|---|
| `proveedor` | — | Proveedor, CompraController |
| `NotaVenta` | ci_cliente→cliente, ci_empleado→empleado, id_pago→metodoPago | FacturaVenta, Caja, Devoluciones, checkout |
| `detalleNotaVenta` | PK (nro_factura, id_producto); →NotaVenta, →producto | DetalleFacturaVenta |
| `NotaCompra` | ci_proveedor→proveedor, id_pago→metodoPago | NotaCompra |
| `detalleNotaCompra` | PK (nro_factura, id_producto); →NotaCompra, →producto | DetalleNotaCompra |

Datos que el código **necesita** y nadie siembra:
- `metodoPago` (el checkout usa `id=2`; alquileres y POS exigen que exista algún método).
- Roles **Encargado** y **Técnico**.

### 5.3 Dependencias y claves foráneas
```
usuario ← empleado ← estadoRol → rol
usuario ← cliente
usuario ← alquileres(ci_cliente, ci_empleado) → metodoPago ; alquiler_detalles → alquileres, maquinarias
producto → marca, categoria(→padre), color, medida, volumen
producto ← carritos, mantenimientos, devolucion_detalles, pedido_..._detalles, promocion_productos, cotizacion_detalles
cliente/empleado/metodoPago ← NotaVenta ← detalleNotaVenta → producto     (sin migración)
proveedor/metodoPago ← NotaCompra ← detalleNotaCompra → producto           (sin migración)
NotaVenta ← devoluciones
cajas.user_id → (sin FK) usuario.ci
```

### 5.4 Propuesta de corrección (no aplicada: requiere aprobación)

La BD `sound_management_db` es nueva y no hay ninguna instalación de esta app con migraciones registradas, así que **se pueden reordenar migraciones sin riesgo**. La BD de la ferretería no se usa.

1. **Nueva** `2026_06_01_000000_create_legacy_sales_purchase_tables.php`: crea `proveedor`, `NotaVenta`, `detalleNotaVenta`, `NotaCompra` y `detalleNotaCompra` exactamente como en el volcado (tipos INT, PK compuestas, FKs), con `Schema::hasTable` para no chocar con BD importadas. Va antes de `devoluciones`.
2. **Renombrar** (y hacer idempotentes con `hasColumn`):
   - `2026_06_21_221019_add_imagen_to_promociones_table` → `2026_06_22_000005_…`
   - `2026_06_21_225458_add_cantidad_to_mantenimientos_table` → `2026_06_22_000006_…`
   - `2026_06_21_230100_change_maquinaria_id_to_idproducto_…` → `2026_06_22_000007_…`
3. Retirar las dos migraciones vacías `2026_05_13_*_add_deleted_at_to_producto_table`.
4. Seeders: `MetodoPagoSeeder` (1 Efectivo, 2 Tarjeta/PayPal) y roles base (Administrador, Encargado, Técnico, sin pisar el `id=3`). `DatabaseSeeder` debe dejar de crear un `User` en la tabla `users`.
5. Añadir después una FK `cajas.user_id → usuario.ci` (comprobando antes que no queden huérfanos).
6. Validar en una BD **desechable** (`sound_management_db` vacía en Docker): `migrate:fresh --seed` y la suite de pruebas. Nunca sobre una BD existente.
7. Con `lower_case_table_names=1` (Docker) los nombres quedan en minúsculas, igual que en XAMPP. No hace falta renombrar tablas en esta fase.

El rediseño del esquema para el negocio de sonido (IDs sustitutos, equipos y unidades, reservas, pagos) corresponde a la siguiente fase, según la auditoría.

---

## 6. Docker

| Aspecto | Antes | Ahora |
|---|---|---|
| Imagen | `php:8.4-cli` + `artisan serve` (servidor de desarrollo) | `php:8.4-fpm` + servicio **Nginx 1.27** |
| Extensiones | pdo_mysql, mbstring, exif, pcntl, bcmath, gd, zip | Las mismas, más **intl** y **opcache**; gd con freetype y jpeg |
| Configuración PHP | por defecto | `docker/php/app.ini`: zona horaria `America/La_Paz`, límites y OPcache (revalida en dev, no en prod) |
| Targets | uno | `dev` (código montado) y `prod` (código y dependencias sin dev, usuario `www-data`) |
| Migraciones | `migrate --force` en **cada arranque** | **Manuales**: `docker compose exec app php artisan migrate` |
| MySQL | 8.0, usuario `sail` y contraseña `password` en el YAML | **8.4 LTS**; BD `sound_management_db`; credenciales **solo desde `.env`** y obligatorias (`${VAR:?}`); utf8mb4; `lower_case_table_names=1`; healthcheck |
| Persistencia | volumen `mysql_data` | volumen nuevo **`sound_management_mysql`** (no reutiliza datos de la ferretería) + volumen `vendor` |
| Puertos | `8080` y `3307` en **todas las interfaces** | `127.0.0.1:${APP_PORT:-8080}` y `127.0.0.1:${DB_FORWARD_PORT:-3307}` |
| Servicios | app, db | app (FPM), web (Nginx), db y, con el perfil `workers`, queue y scheduler |
| Cabeceras | — | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`; sin `server_tokens` ni `X-Powered-By` |

**Pendiente:** despliegue de producción en una PaaS de un solo contenedor (como Railway, que usaba `$PORT`). El target `prod` necesita un Nginx delante; se definirá en la fase de despliegue.

---

## 7. Estado de Git
- Rama `main`, **sin commits**, sin remotos. No se hizo ningún push.
- `.gitignore` también ignora ahora los `.sql` reales y mantiene versionables los `docker/mysql/*.sql.example`.

---

## 8. Problemas abiertos (resumen)
1. El lock exige PHP ≥ 8.3, pero el PHP local es 8.2.12: no se puede instalar fuera de Docker sin actualizar PHP.
2. Laravel 11 sin soporte de seguridad y nwidart v13 pensado para Laravel 13 (§2).
3. Las migraciones no construyen una BD limpia (§5).
4. Las pruebas de Breeze crean `User` (tabla `users`) mientras la autenticación usa `Usuario`: probablemente fallan. `phpunit.xml` no incluye `Modules/*/tests`.
5. Pendientes de seguridad no corregidos (§3.9).
6. Rotar la contraseña de Railway.

## 9. Próximos pasos propuestos (con aprobación)
1. Revisar este informe y el diff, y hacer el primer commit.
2. Crear `.env` con contraseñas propias, `docker compose build` y `docker compose run --rm app composer install`.
3. Aplicar la propuesta de migraciones (§5.4) y validarla con `migrate:fresh --seed` en la BD vacía de Docker.
4. Arreglar las pruebas existentes y añadir pruebas de lo hecho en esta fase:
   - autorización por rol, incluido que un cliente reciba 403 en la API de clientes y en los comprobantes ajenos;
   - checkout con orden de pago simulada (válida, monto distinto, reutilizada);
   - `ReadOnlySqlGuard`.
5. Planificar la actualización a Laravel 13 + PHP 8.4 (§2.2).
