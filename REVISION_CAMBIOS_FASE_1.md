# REVISIÓN DE CAMBIOS — FASE 1 SOUND MANAGEMENT

**Fecha:** 2026-11-20
**Alcance:** revisión de solo lectura de todo lo que se cambió en la Fase 1 en `E:\BadiStudio\sound-management-app`. No se modificó, revirtió ni corrigió ningún archivo; este informe es el único archivo nuevo.

## 0. Método y limitaciones (léase primero)

- **Git no se usó.** Por indicación expresa ("no hagas nada de git") no se ejecutaron `git status`, `git diff`, `git ls-files` ni ningún otro comando git, tampoco de lectura. Además, el repositorio no tiene commits: `git diff` saldría vacío y todo aparecería como "sin seguimiento". Por eso el inventario de cambios se obtuvo **comparando la copia con el original**.
- **Acceso al original:** se ejecutó **una sola** comparación de solo lectura por nombre de archivo (`diff -rq`, excluyendo `.git` y `.env`) entre `E:\ferreteria` y la copia. Al intentar leer el contenido detallado del original, el sistema de permisos lo bloqueó (por la regla "no tocar `E:\ferreteria`"), y no se volvió a acceder. El "antes" de cada cambio se describe a partir de lo que se leyó del original antes de editarlo en la Fase 1.
- **Disco C:** en esta revisión no se escribió nada en C:. En las fases 0 y 1 sí se dejaron en el scratchpad de la sesión (`C:\Users\Dj\AppData\Local\Temp\claude\...\scratchpad\`) cuatro archivos auxiliares pequeños (`orig_before.sha1`, `copy.sha1`, `guard_check.php`, `sidebar.php`, en total menos de 100 KB). Siguen ahí; se pueden borrar si lo indicas.
- **Pruebas:** ni en la Fase 1 ni en esta revisión se ejecutó la suite del proyecto, porque no hay `vendor/` ni BD. Lo único que se ejecutó en la Fase 1 fue `php -l` (sintaxis), `docker compose config` (sin arrancar contenedores) y un script aislado contra `ReadOnlySqlGuard`.

---

## 1. Lista completa de archivos

Resultado de la comparación con `E:\ferreteria`. No hay archivos **eliminados**.

### 1.1 Modificados (21)

| # | Archivo | Área |
|---|---|---|
| 1 | `.env.example` | Entorno |
| 2 | `.gitignore` | Entorno |
| 3 | `Dockerfile` | Docker |
| 4 | `docker-compose.yml` | Docker |
| 5 | `config/database.php` | IA (conexión `mysql_reports`) |
| 6 | `config/services.php` | IA y PayPal |
| 7 | `app/Providers/AppServiceProvider.php` | Roles y permisos |
| 8 | `Modules/Audit/routes/web.php` | IA |
| 9 | `Modules/Audit/app/Http/Controllers/IaReportController.php` | IA |
| 10 | `Modules/Inventory/routes/web.php` | Autorización CRUD |
| 11 | `Modules/Sales/routes/web.php` | Clientes, POS, checkout, documentos |
| 12 | `Modules/Sales/app/Http/Controllers/CartController.php` | Checkout PayPal |
| 13 | `Modules/Sales/app/Http/Controllers/VentaController.php` | Documentos y finanzas |
| 14 | `Modules/Sales/app/Http/Controllers/CajaController.php` | Finanzas |
| 15 | `Modules/Rentals/routes/web.php` | Autorización |
| 16 | `Modules/Rentals/app/Http/Controllers/AlquilerController.php` | Documentos |
| 17 | `Modules/Procurement/app/Http/Controllers/CompraController.php` | Finanzas (correlativos) |
| 18 | `Modules/Access/app/Http/Controllers/TrabajoController.php` | Finanzas (correlativos) |
| 19 | `resources/views/bitacora/ia-reports.blade.php` | IA (XSS) |
| 20 | `resources/views/carrito/index.blade.php` | Checkout PayPal |
| 21 | `resources/views/components/admin-sidebar.blade.php` | Menú lateral |

### 1.2 Nuevos (13 archivos + este informe)

| Archivo | Área |
|---|---|
| `config/access.php` | Roles y permisos |
| `app/Support/AccessControl.php` | Roles y permisos |
| `Modules/Audit/app/Services/ReadOnlySqlGuard.php` | IA |
| `Modules/Sales/app/Services/PayPalOrderVerifier.php` | Checkout |
| `Modules/Sales/app/Models/PagoExterno.php` | Checkout |
| `database/migrations/2026_11_20_000001_create_pagos_externos_table.php` | Checkout (**migración nueva**) |
| `docker/php/app.ini` | Docker |
| `docker/nginx/default.conf` | Docker |
| `docker/mysql/reports-user.sql.example` | Docker / IA |
| `FASE_0_PREPARACION.md` | Documentación (Fase 0) |
| `FASE_1_ESTABILIZACION.md` | Documentación |
| `REVISION_CAMBIOS_FASE_1.md` | Este informe |

En las vistas solo se tocaron 3 archivos. No se tocaron modelos existentes, seeders, factories, tests, `composer.*` ni otras vistas.

---

## 2. Cambios de seguridad (archivo por archivo)

Leyenda de pruebas: **Ejecutadas** = lo que realmente se corrió. Ninguna prueba funcional, de integración ni de navegador se ejecutó.

### 2.1 Reportes IA y validador SQL

| Archivo | Problema original | Modificación | Riesgo de regresión | Ejecutadas | Pendientes |
|---|---|---|---|---|---|
| `Modules/Audit/routes/web.php` | `/reportes-ia` y `/consultar` abiertos a cualquier autenticado (incluidos clientes). | Dentro de `admin` + `can:reports.ai` + `throttle:10,1`. | Quien no sea admin pierde el acceso (intencionado). El middleware `admin` responde a un `fetch` no autorizado con una **redirección HTML**, no con JSON. | `php -l` | Comprobar que un cliente o un empleado sin rol admin recibe la redirección o 403. |
| `IaReportController.php` | `env()` en el controlador; SQL del LLM ejecutado con `DB::select` sin restricciones; errores internos devueltos al navegador. | Config `services.groq` (desactivado por defecto); `ReadOnlySqlGuard`; conexión `mysql_reports`; `SET SESSION MAX_EXECUTION_TIME` + `SET TRANSACTION READ ONLY` + rollback; máximo de filas; bitácora; error genérico. | **Bug confirmado (§3.1):** con el `.env.example` actual la conexión `mysql_reports` queda con **usuario vacío**. **Bug confirmado (§3.2):** el validador rechaza consultas con multiplicación. | `php -l` | Ejecutar contra MySQL con y sin usuario de solo lectura; comprobar que `SET TRANSACTION READ ONLY` aplica a la transacción que abre PDO. |
| `ReadOnlySqlGuard.php` (nuevo) | No existía. | Una sola sentencia `SELECT`; sin comentarios, `INTO`, funciones peligrosas, bloqueos, variables ni esquemas del sistema; tablas permitidas; columnas y tablas sensibles prohibidas; sin `*`. | Falsos rechazos: `precio * cantidad`, literales `'b'` o `'x'`, `FROM` dentro de funciones distintas de `EXTRACT`, `TRIM` y `SUBSTRING`. Los falsos rechazos son seguros, pero degradan la función. | Script aislado, 26 casos correctos (sin casos de multiplicación). | Pruebas unitarias en la suite; añadir casos de multiplicación, `UNION` y alias entre backticks. |
| `config/database.php` | — | Conexión `mysql_reports` con *fallback* a las credenciales principales. | El *fallback* no funciona si las variables existen vacías (§3.1). | `php -l` | — |
| `ia-reports.blade.php` | **XSS almacenado:** resultados insertados con `innerHTML` sin escapar. | `escapeHtml()` en cabeceras y celdas; cabecera `Accept: application/json`. | Bajo. Los valores que contengan `<` ahora se ven escapados (correcto). | Ninguna | Probar en el navegador con un valor `<img src=x onerror=alert(1)>`. |

### 2.2 Checkout PayPal

| Archivo | Problema original | Modificación | Riesgo de regresión | Ejecutadas | Pendientes |
|---|---|---|---|---|---|
| `CartController.php` | Un POST a `/carrito/checkout` creaba una venta "pagada" sin pago; la misma compra se podía repetir; `max()+1` sin bloqueo; stock sin bloqueo. | Exige `paypal_order_id`; verificación en el servidor; idempotencia con `pagos_externos`; bloqueo de productos y correlativo; estado `requiere_reembolso` si la venta falla después del cobro. | **(a)** Un cobro **no queda registrado** en `pagos_externos` si la verificación falla después de capturar (API caída, carrito cambiado entre la carga y el pago): solo queda en la bitácora (§3.4). **(b)** Un *deadlock* entre dos checkouts simultáneos marcaría un pago válido como `requiere_reembolso` (§3.5). **(c)** El checkout depende de la tabla `pagos_externos`, que no existirá mientras la cadena de migraciones esté rota (§3.6). | `php -l` | Pruebas de integración con PayPal sandbox: orden válida, monto distinto, orden reutilizada, doble envío, stock insuficiente tras el pago, API caída. |
| `PayPalOrderVerifier.php` (nuevo) | No existía. | OAuth con el secreto del servidor; `GET /v2/checkout/orders/{id}`; exige `COMPLETED` y captura `COMPLETED`; compara moneda y monto en centavos. | **No vincula la orden con el usuario ni con el carrito**: solo verifica monto y moneda (§3.7). Valida como correcto el cobro de bolivianos como USD (§3.8). | `php -l` | Pruebas con respuestas de PayPal simuladas (`Http::fake`). |
| `PagoExterno.php` + migración `2026_11_20_000001` (nuevos) | No existía. | Tabla con clave única `(proveedor, referencia_externa)`, sin FKs. | Es una migración nueva: no modifica las existentes, pero sí añade un archivo a `database/migrations`. | `php -l` | `migrate` en una BD desechable. |
| `carrito/index.blade.php` | Client-ID escrito en la vista; el formulario se enviaba sin datos. | Client-ID y moneda desde la configuración; campo oculto `paypal_order_id`; sin client-ID no aparece el botón. | El botón se muestra con **solo** `PAYPAL_CLIENT_ID`, aunque falte el secreto: el cliente pagaría y el servidor rechazaría la venta **sin registrar el cobro** (§3.4). | Ninguna | Probar en el navegador con sandbox. |
| `Modules/Sales/routes/web.php` | — | `throttle:10,1` en el checkout. | Bajo. | `php -l` | — |

### 2.3 Roles y permisos

| Archivo | Problema original | Modificación | Riesgo de regresión | Ejecutadas | Pendientes |
|---|---|---|---|---|---|
| `config/access.php` (nuevo) | Nombres de rol fijos en el código; el rol "Vendedor" del volcado no tenía permisos. | Matriz de roles y habilidades (admin, encargado, técnico, almacenero, vendedor, cliente). | Cualquier rol personalizado que no esté en la matriz pierde el acceso a Ventas y Alquileres. El Gate `almacenero` ahora incluye al encargado (amplía permisos). | `php -l` | Pruebas por rol. |
| `app/Support/AccessControl.php` (nuevo) | Una consulta por cada `@can`. | Roles resueltos una vez por petición (servicio *scoped*); comparación sin mayúsculas ni tildes. | La caché de roles vive toda la petición: un cambio de rol dentro de la misma petición no se refleja hasta la siguiente (hoy no ocurre, porque `asignar` y `darDeBaja` redirigen). | `php -l` | Pruebas unitarias. |
| `AppServiceProvider.php` | Tres Gates escritos a mano. | Gates generados desde la configuración. | Para invitados devuelven `false` (igual que antes, porque el parámetro `$user` no admite null). | `php -l` | Comprobar `@can` en las vistas públicas sin sesión. |

### 2.4 Autorización de clientes y CRUD

| Archivo | Problema original | Modificación | Riesgo de regresión | Ejecutadas | Pendientes |
|---|---|---|---|---|---|
| `Modules/Inventory/routes/web.php` | CRUD de productos y `/api/producto/{id}` (con el **costo**) abiertos a cualquier autenticado; `productos.show` daba 500. | `can:inventory.manage`; `productos.index` explícito; sin `show`. | Un empleado sin rol de inventario ya no puede editar productos (intencionado). | `php -l` | Pruebas por rol; recorrer las vistas de admin y almacenero. |
| `Modules/Sales/routes/web.php` | POS y API de clientes abiertos; cualquiera podía consultar datos de otros clientes por CI. | `can:sales.operate` en el POS; `can:customers.lookup` + `throttle:60,1` en la API de clientes. | Un vendedor que solo tuviera un rol personalizado pierde el POS. | `php -l` | Comprobar que un cliente recibe 403 en `/api/clientes/buscar/{ci}`. |
| `Modules/Rentals/routes/web.php` | Alquileres abiertos a cualquier empleado; `maquinarias.show` daba 500; mantenimientos solo para admin. | `rentals.view`, `rentals.operate`, `equipment.view`, `maintenance.manage`. | **Inconsistencia de interfaz:** técnico y almacenero ven el botón "Nuevo alquiler" (`alquileres/index.blade.php:13`) y el formulario de devolución (`alquileres/show.blade.php:145`), que ahora les dan 403. Pasa de redirección (`admin`) a 403 (`can:`) en mantenimientos. | `php -l` | Pruebas por rol. |

### 2.5 Descarga de documentos

| Archivo | Problema original | Modificación | Riesgo de regresión | Ejecutadas | Pendientes |
|---|---|---|---|---|---|
| `VentaController@imprimir` | **IDOR:** cualquier autenticado descargaba la nota de venta de otro cliente. | Solo el dueño (`ci_cliente`) o `sales.operate`. | Un vendedor puede descargar las notas de otros cajeros (igual que antes). | `php -l` | Pruebas: dueño 200, otro cliente 403, vendedor 200. |
| `AlquilerController@imprimir` | Bastaba con no ser cliente. | Titular (`ci_cliente`) o `rentals.view`. | El titular **gana** acceso a su comprobante (antes, como cliente, recibía 403). | `php -l` | Pruebas equivalentes. |

### 2.6 Operaciones financieras

| Archivo | Problema original | Modificación | Riesgo de regresión | Ejecutadas | Pendientes |
|---|---|---|---|---|---|
| `VentaController@store` | `max('nro')+1` sin bloqueo. | `lockForUpdate()->max('nro')`. | §3.5 (bloqueos y *deadlock*). | `php -l` | Prueba de concurrencia. |
| `CompraController` | `max()+1` sin bloqueo; `idproducto` ignoraba los productos con SoftDeletes (**ID duplicado**). | `lockForUpdate()` y `withTrashed()`. | Bajo. | `php -l` | Alta rápida con un producto borrado previamente. |
| `TrabajoController@store` | `max()+1` sin transacción; fallaba si la descripción estaba vacía (NOT NULL). | Transacción + bloqueo; `descripcion ?? ''`. | Bajo. | `php -l` | Crear un rol sin descripción. |
| `CajaController@apertura` | Se podían abrir varias cajas a la vez para el mismo usuario. | Comprobación con `lockForUpdate()` dentro de una transacción. | Sin índice en `cajas(user_id, estado)`, `FOR UPDATE` recorre y **bloquea todas las filas** de `cajas` durante la transacción; con la tabla vacía, dos aperturas simultáneas pueden acabar en *deadlock* (error 500 en lugar de un mensaje). | `php -l` | Apertura doble y apertura simultánea. |

### 2.7 Menú lateral

| Archivo | Problema original | Modificación | Riesgo de regresión | Ejecutadas | Pendientes |
|---|---|---|---|---|---|
| `components/admin-sidebar.blade.php` | Enlaces visibles para todos aunque dieran 403. | `@can` por habilidad en Ventas, Alquileres, Maquinarias y Reportes IA; Mantenimientos sale del bloque `admin` hacia `maintenance.manage`. | Bajo. Hecho con un script PHP de reemplazo: conviene revisar el HTML resultante a ojo. | Ninguna (`grep` de la estructura) | Revisión visual con cada rol. |

---

## 3. Errores o inconsistencias encontrados

Ordenados por severidad. **Ninguno se ha corregido.**

| # | Severidad | Problema | Ubicación |
|---|---|---|---|
| 3.1 | **Alta** | **El fallback de `mysql_reports` no funciona.** `.env.example` define `DB_REPORTS_USERNAME=` y `DB_REPORTS_PASSWORD=` vacíos. En Laravel, `env()` devuelve `''` (no `null`) para una variable definida y vacía, así que nunca se usa el valor por defecto `env('DB_USERNAME')`. Resultado: si se activan los reportes IA sin crear el usuario de solo lectura, la conexión falla (usuario vacío). El informe de la Fase 1 afirmaba lo contrario. | `config/database.php:77-78`, `.env.example:40-41` |
| 3.2 | **Alta** | **El validador rechaza toda multiplicación**: la regla "sin `*` salvo `COUNT(*)`" bloquea `SUM(precio_unitario * cantidad)`, el cálculo más habitual en reportes de ventas. Las 26 pruebas no incluían este caso. | `ReadOnlySqlGuard.php` (comprobación de `*`) |
| 3.3 | **Alta** | **Imágenes subidas inaccesibles con Docker:** las vistas usan `asset('storage/...')` (logos de marcas, imágenes de categorías y productos, en 8 vistas), pero el servicio `web` (Nginx) solo monta `./public`. El enlace `public/storage → storage/app/public` apunta a una ruta que no existe dentro del contenedor de Nginx, así que dan **404**. Además, `storage:link` crea un enlace simbólico absoluto que puede fallar sobre un *bind mount* de Windows. | `docker-compose.yml` (servicio `web`) |
| 3.4 | **Alta** | **Cobro sin registro en dos casos:** (a) si `verify()` falla **después** de la captura (API de PayPal caída o el carrito cambió entre la carga de la página y el pago), solo queda una línea en la bitácora y no un registro en `pagos_externos`; (b) si está `PAYPAL_CLIENT_ID` pero falta `PAYPAL_CLIENT_SECRET`, la vista muestra el botón, el cliente paga y el servidor responde "no disponible" sin registrar nada. | `CartController@checkout`, `carrito/index.blade.php` |
| 3.5 | Media | **Bloqueos con `MAX() … FOR UPDATE`:** con la tabla vacía, InnoDB toma *gap locks* compatibles entre sí y dos transacciones pueden acabar en *deadlock* al insertar. En el checkout, un *deadlock* marcaría un pago válido como `requiere_reembolso`. No hay reintentos. La garantía de bloqueo sobre un agregado optimizado **no está verificada** en MySQL 8.4. | `CartController`, `VentaController`, `CompraController`, `TrabajoController`, `CajaController` |
| 3.6 | Media | El checkout depende de `pagos_externos`, pero `migrate` se detiene en la migración #21 (cadena rota de antes): en una BD nueva la tabla no llega a crearse. | `database/migrations` |
| 3.7 | Media | La orden de PayPal no queda vinculada al usuario ni al carrito: la orden se crea en el navegador y el servidor solo comprueba monto y moneda. Un ID de orden ajeno con el mismo monto sería aceptado una vez. Lo correcto es crear la orden **en el servidor** (con `custom_id`/`invoice_id`) y verificar ese vínculo. | `PayPalOrderVerifier`, `carrito/index.blade.php` |
| 3.8 | Media | Problema anterior que ahora se "valida": el total en BOB se cobra como USD (≈7 veces más). El verificador da por bueno ese monto. | Checkout |
| 3.9 | Media | Interfaz inconsistente con los nuevos permisos: botón "Nuevo alquiler" y formulario de devolución visibles para técnico y almacenero (403 al usarlos). | `alquileres/index.blade.php:13`, `alquileres/show.blade.php:145` |
| 3.10 | Baja | El middleware `admin` responde a peticiones AJAX/JSON con una redirección HTML (afecta a `/reportes-ia/consultar` para no admins). | `AdminMiddleware` (sin cambios) + rutas de Audit |
| 3.11 | Baja | Falsos rechazos del validador: literal `'b'`/`'x'` (regex de hex/binario sobre la consulta sin limpiar) y `FROM` dentro de funciones no contempladas. | `ReadOnlySqlGuard.php` |
| 3.12 | Baja | `FASE_1_ESTABILIZACION.md` contiene afirmaciones que esta revisión desmiente: el *fallback* de §3.5/§4, y que "no hay ventas sin pago" sin matizar el caso 3.4(b). | `FASE_1_ESTABILIZACION.md` |

---

## 4. Estado real de Docker

### 4.1 Dockerfile
| Aspecto | Estado |
|---|---|
| Etapas | `base` → `dev` y `base` → `prod`. Sin instrucciones duplicadas. `ARG PHP_VERSION` antes del primer `FROM`: correcto. |
| PHP-FPM | `php:8.4-fpm-bookworm`; escucha en 9000 (configuración por defecto de la imagen), sin publicar. Correcto. |
| Extensiones | pdo_mysql, mbstring, exif, pcntl, bcmath, gd (freetype/jpeg), zip, intl, opcache. Suficientes para Laravel 11, dompdf y nwidart. |
| `app.ini` | `opcache.validate_timestamps = ${PHP_OPCACHE_VALIDATE_TIMESTAMPS}` con `ENV` 1 en base y 0 en prod: coherente. |
| Target `dev` | Corre como root (el maestro de FPM; los workers son `www-data`). `composer install` dentro del contenedor deja `vendor` como root (legible por los workers). Válido en Docker Desktop/Windows; en un host Linux, `storage/` montado podría no ser escribible por `www-data`. |
| Target `prod` | `COPY . .` + `composer install --no-dev` + `USER www-data`. **No es desplegable por sí solo** (no tiene servidor HTTP; necesita un Nginx delante). Copia también `docs/*.md`, `tests`, `stubs` y `docker/`. `.dockerignore` **no excluye** `*.sql`, `storage/app/public/*` ni `storage/app/private/*` (podrían entrar volcados o archivos subidos en la imagen). `.dockerignore` excluye `storage/logs` completo (Monolog lo recrea; hay `chown`, así que es aceptable). No hay `EXPOSE` ni `HEALTHCHECK` (informativo). |

### 4.2 docker-compose.yml
| Aspecto | Estado |
|---|---|
| Servicios | `app` (FPM), `web` (Nginx), `db` (MySQL 8.4) y, con el perfil `workers`, `queue` y `scheduler`. |
| Ancla `x-app` | La comparten `app`, `queue` y `scheduler`, incluidos `build` e `image` con la misma etiqueta: Compose puede construir la misma imagen por servicio. Funciona, pero es redundante. Sin variables de entorno duplicadas. |
| Puertos | `127.0.0.1:${APP_PORT:-8080}:80` y `127.0.0.1:${DB_FORWARD_PORT:-3307}:3306`. Sin duplicados ni choques con XAMPP (3306). |
| Volúmenes | `vendor` y `sound_management_mysql`. Sin duplicados. El *bind mount* `.:/var/www` + `vendor` en volumen es coherente. **Falta montar `storage/app/public` en `web`** (§3.3). |
| Variables | Las contraseñas son obligatorias (`${VAR:?}`), comprobado. `DB_HOST`/`DB_PORT` se sobrescriben dentro de los contenedores (las variables de proceso tienen prioridad sobre `.env` en Laravel): correcto. No hay `env_file`, pero Laravel lee `.env` desde el *bind mount*: correcto. |
| MySQL | utf8mb4, `lower_case_table_names=1` (solo válido al inicializar el volumen; si después se quita, MySQL **no arranca** con ese volumen). Healthcheck con `mysqladmin`, y `depends_on: service_healthy` en los servicios PHP. `MYSQL_USER` no puede ser `root` (documentado). |
| Persistencia | Volumen propio `sound_management_mysql` (no reutiliza datos de la ferretería). |
| Nginx | Solo `index.php` es ejecutable; se deniegan los archivos ocultos; cabeceras básicas. El orden de las `location` regex es correcto (`^/index.php` antes de `\.php$`). `SCRIPT_FILENAME` fijo: correcto para el front controller único. |
| Usuario de reportes | `docker/mysql/reports-user.sql.example` requiere que las tablas existan (GRANT por tabla), así que se ejecuta después de migrar. Coherente con `lower_case_table_names=1` (nombres en minúsculas). |
| Validación ejecutada | `docker compose config --quiet` correcto con un `.env` de prueba; error esperado si falta la contraseña de root. **Nunca se construyó una imagen ni se arrancó un contenedor.** |

### 4.3 `.env.example`, `.gitignore` y `.dockerignore`
- `.env.example`:
  - Sin claves duplicadas ni secretos.
  - Inconsistencias: `APP_URL=http://localhost` cuando la app se sirve en `:8080`; `DB_USERNAME=sound_app` no existe en XAMPP local (hay que crearlo o usar root fuera de Docker); `DB_REPORTS_*` vacíos rompen el *fallback* (§3.1).
- `.gitignore`:
  - Correcto para `.env*` (con excepción de `.env.example`), claves, `.sql` y `storage/app/private`.
  - Reglas redundantes, inofensivas: `/storage/*.key` frente a `*.key`, y `!docker/mysql/*.sql.example` (que `*.sql` ya no ignoraba).
  - `*.crt` ignoraría un certificado CA que hiciera falta para `MYSQL_ATTR_SSL_CA`.
- `.dockerignore`: sin cambios en la Fase 1; ver las carencias en §4.1.

---

## 5. Riesgos de regresión (resumen)

1. **Pérdida de acceso por rol:** los empleados con roles que no están en `config/access.php` pierden Ventas, Alquileres y Maquinarias.
2. **Reportes IA:** desactivados por defecto; al activarlos fallan por el usuario vacío (§3.1) y rechazan consultas con multiplicación (§3.2).
3. **Checkout:** desactivado sin credenciales completas; riesgo de cobros sin registro (§3.4) y de *deadlock* (§3.5); depende de una migración que hoy no llega a ejecutarse (§3.6).
4. **Bloqueos:** `FOR UPDATE` sobre agregados y sobre `cajas` sin índice puede serializar o bloquear operaciones concurrentes.
5. **Interfaz:** botones visibles que dan 403 (§3.9); mantenimientos pasa de redirección a 403.
6. **Docker:** imágenes subidas con 404 (§3.3).

---

## 6. Correcciones recomendadas (no aplicadas)

| Prioridad | Corrección |
|---|---|
| 1 | `config/database.php`: usar `env('DB_REPORTS_USERNAME') ?: env('DB_USERNAME', 'root')` (y lo mismo para la contraseña y el host), **o** comentar esas líneas en `.env.example`. |
| 2 | `ReadOnlySqlGuard`: prohibir solo el comodín de columnas (`SELECT *`, `, *`, `alias.*`) en lugar de cualquier `*`. Añadir casos de prueba con multiplicación. |
| 3 | `docker-compose.yml`: montar `./storage/app/public:/var/www/storage/app/public:ro` en `web` y servir `/storage` con un `alias` en Nginx (evita depender del enlace simbólico). |
| 4 | Checkout: registrar en `pagos_externos` (estado `requiere_revision`) **todo** pago capturado cuya verificación falle; mostrar el botón solo si `PayPalOrderVerifier::isConfigured()`; a medio plazo, crear la orden en el servidor, vincularla al usuario y al carrito, y añadir el webhook. |
| 5 | Correlativos: tabla `sequences` con bloqueo de fila (sin *gap locks*) o columnas `AUTO_INCREMENT`; mientras tanto, `DB::transaction(..., attempts: 3)` para reintentar *deadlocks*. Índice `cajas(user_id, estado)`. |
| 6 | Vistas de alquileres: envolver el botón "Nuevo alquiler" y el formulario de devolución con `@can('rentals.operate')`. |
| 7 | `AdminMiddleware`: responder 403 JSON cuando `expectsJson()`. |
| 8 | `.dockerignore`: excluir `*.sql`, `storage/app/public/*`, `storage/app/private/*`, `tests`, `*.md`, `docker/mysql`. |
| 9 | `.env.example`: `APP_URL=http://localhost:8080`; aclarar el uso de `DB_USERNAME` en XAMPP. |
| 10 | Corregir las afirmaciones de `FASE_1_ESTABILIZACION.md` (§3.12). |
| 11 | Antes de todo lo anterior: aplicar la propuesta de migraciones (Fase 1 §5.4) para que exista `pagos_externos`, y escribir las pruebas listadas en §2. |

---

## 7. Archivos que requieren revisión manual

| Archivo | Motivo |
|---|---|
| `Modules/Sales/app/Http/Controllers/CartController.php` | Es el cambio más grande y delicado (dinero). Revisar el flujo de errores después del cobro (§3.4, §3.5). |
| `Modules/Sales/app/Services/PayPalOrderVerifier.php` | Vínculo orden–usuario (§3.7) y moneda (§3.8). |
| `Modules/Audit/app/Services/ReadOnlySqlGuard.php` | Reglas demasiado amplias (§3.2, §3.11). Revisar la lista de palabras prohibidas frente a consultas reales. |
| `config/database.php` + `.env.example` | *Fallback* roto (§3.1). |
| `config/access.php` | Confirmar con el negocio la matriz de roles y los nombres de rol reales de la BD. |
| `resources/views/components/admin-sidebar.blade.php` | Editado por script: revisión visual. |
| `docker-compose.yml` + `docker/nginx/default.conf` | `storage` público (§3.3). |
| `Modules/Sales/app/Http/Controllers/CajaController.php` | Bloqueo de toda la tabla sin índice. |
| `database/migrations/2026_11_20_000001_create_pagos_externos_table.php` | Única migración añadida; confirmar que se acepta antes de reordenar la cadena. |

---

## 8. Integridad

| Comprobación | Resultado | Evidencia |
|---|---|---|
| Migraciones existentes no modificadas | ✅ | La comparación con el original no marca ninguna de las 28 como distinta. **Se añadió una nueva** (`2026_11_20_000001_create_pagos_externos_table.php`). |
| `composer.json` no modificado | ✅ | No aparece en la comparación. |
| `composer.lock` no modificado | ✅ | No aparece en la comparación. |
| Proyecto original no modificado | ✅ (según la última verificación completa) | Al cierre de la Fase 1 se compararon los SHA-1 de todos los archivos de `E:\ferreteria` con la huella tomada antes de copiar: sin cambios, y HEAD seguía en `d39fe9c`. En esta revisión solo se ejecutó un `diff -rq` de lectura; el intento de leer contenido fue bloqueado y no se repitió. |
| Sin commits ni pushes | ✅ (no reverificado con git) | Durante las fases 0 y 1 no se ejecutó ningún `git commit`, `git push` ni `git remote add`. Al cierre de la Fase 1, el repositorio estaba sin commits y sin remotos. En esta revisión **no se usó git** por instrucción. |
| Sin escrituras en C: en esta revisión | ✅ | Solo se leyó el proyecto en E: y se escribió este informe en E:. |
