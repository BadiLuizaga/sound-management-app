# FASE 2 — PRUEBAS

**Estado:**
- Sección 0 (entorno portátil): ✅ terminada.
- Sección 1 (Etapa 1, base de datos): ✅ probada.
- Pruebas 4 a 13 de la Etapa 5: pendientes, salvo las que ya cubre la sección 1.

---

## 1. Etapa 1 — Base de datos (2026-11-20)

Detalle completo en `FASE_2_BASE_DATOS.md` §6 y §7. Resumen de la evidencia:

**Conexión verificada y mostrada antes de cada operación destructiva:**

`sound_test@127.0.0.1 | Badi | 3310 | sound_management_test | MySQL 8.4.13`. Bases visibles: `information_schema`, `performance_schema`, `sound_management_test`.

| Prueba (numeración de la Etapa 5) | Resultado |
|---|---|
| 1. Construcción de una base nueva | ✅ MySQL 8.4.13 y SQLite en memoria |
| 2. Ejecución completa de migraciones | ✅ 31/31 → 50 tablas; `migrate:reset`, `migrate` y `rollback --step=4` correctos |
| 3. Seeders mínimos | ✅ 5 métodos de pago, 5 roles, 11 permisos y 34 asignaciones; idempotentes; 0 filas en las 34 tablas de negocio |
| 4. Creación del primer administrador | ✅ 7 pruebas de `sound:crear-administrador` |
| 5. Registro de clientes | ✅ 3 pruebas (registro, aislamiento, email repetido) |
| 6. Autenticación | ⏳ Parcial: tras registrarse, el cliente queda autenticado. El login completo se probará en la Etapa 5. |
| 11. Pagos simulados | ⏳ Parcial: el checkout sin el método PAYPAL no registra nada. El flujo completo corresponde a la Etapa 2. |

**Suites ejecutadas** (`.tools\ejecutar-pruebas.ps1`):

| Suite | Motor | Resultado |
|---|---|---|
| `tests/Feature/Instalacion` | MySQL 8.4.13 (`sound_management_test`) | **OK (29 tests, 133 assertions)** |
| `tests/Feature/Instalacion` | SQLite en memoria | **OK (29 tests, 133 assertions)** |
| Suite completa (54 pruebas) | SQLite en memoria | 33 OK. **21 fallos, todos en las pruebas heredadas de Breeze**, por causas de las propias pruebas (`User` en lugar de `Usuario`, `UserFactory` sin resolver, `ExampleTest` sin migrar, campos de registro de Breeze). Se corrigen en la Etapa 5. |

**Salvaguarda de pruebas destructivas:** con un destino que no es la base de pruebas (`sound_management_db`, o el puerto 3306), la ejecución aborta con "ABORTADO …", código 1, **sin conectarse**.

**Estado al terminar:** MySQL de pruebas detenido (sin procesos `mysqld`); `.tools\tmp` vacío; `composer.lock` intacto.

---

## 0. Entorno portátil de pruebas (preparado el 2026-11-20)

### 0.1 Condiciones autorizadas y cómo se cumplieron

| Condición | Cumplimiento |
|---|---|
| Todo dentro de `E:\BadiStudio\sound-management-app\.tools\` | ✅ PHP, Composer, MySQL, descargas, temporales, caché, logs, certificados y credenciales están en `.tools`. `vendor/` y `bootstrap/cache/` están en la carpeta del proyecto (E:). |
| No usar herramientas de C: | ✅ Para las descargas, la descompresión y las sumas se usaron solo cmdlets internos de PowerShell (`Invoke-WebRequest`, `Expand-Archive`, `Get-FileHash`). No se usaron el PHP de XAMPP, el Composer global, Docker, `curl`, `tar` ni `gpg`. ⚠️ **Una excepción no intencionada** (§0.5). |
| No modificar XAMPP ni las instalaciones globales | ✅ No se tocaron. El PHP portátil usa su propio `php.ini` (`php -c`) y no lee ningún `php.ini` del sistema. |
| `composer install` respetando el lock | ✅ SHA-256 del `composer.lock` antes y después: `BF67396908FC1897DB675778EC14C98F73248F64C3EA952CFA63E827FAE09D0F` (idéntico). `composer.json` también idéntico (`D74776677D2CC6BB219B66396DE1A7D49FF40F7DC2D376E9A941B13438879E01`). `install --dry-run` → "Nothing to install, update or remove". |
| Solo la BD aislada `sound_management_test`, puerto 3310 | ✅ Instancia nueva, inicializada sobre un directorio vacío; escucha solo en `127.0.0.1:3310`; no contiene ninguna otra base de negocio. |
| No tocar `E:\ferreteria` ni `E:\BadiStudio\sound-management` | ✅ No se modificó nada. Ver la nota de §0.5 sobre una lectura fallida en la carpeta antigua. |
| Mostrar la conexión antes de cualquier prueba destructiva | ✅ Se definió el procedimiento (§0.4) y lo aplica el script `verificar-mysql.php`. Todavía no se ha ejecutado ninguna prueba destructiva. |

### 0.2 Herramientas instaladas y verificadas

| Herramienta | Versión | Origen | Verificación de integridad | Funciona desde E: |
|---|---|---|---|---|
| PHP (NTS, x64, VS17) | **8.4.28** | `windows.php.net/downloads/releases/php-8.4.28-nts-Win32-vs17-x64.zip` | SHA-256 `c1f92805…ac1ed`, igual al del índice oficial `releases.json` | ✅ `php -v` |
| Almacén de certificados CA | versión actual | `curl.se/ca/cacert.pem` | SHA-256 `427c0642…97285`, igual a `cacert.pem.sha256` | ✅ HTTPS a packagist correcto |
| Composer | **2.11.2** | `getcomposer.org/download/latest-stable/composer.phar` | SHA-256 `dd158c0a…5d028`, igual a `composer.phar.sha256` | ✅ `composer --version`; home y caché en E: |
| MySQL Community Server | **8.4.13 LTS** | `cdn.mysql.com/Downloads/MySQL-8.4/mysql-8.4.13-winx64.zip` | MD5 `7b94489d…25a8`, igual al `.md5` oficial del CDN (SHA-256 registrado: `e9cd98b1…a54`). La firma GPG `.asc` existe pero **no se verificó** porque requiere `gpg`, que solo está en C:. | ✅ arranque, conexión y parada ordenada |

**Extensiones de PHP cargadas y comprobadas:** ctype, curl, dom, fileinfo, filter, gd, hash, intl, json, mbstring, openssl, pdo_mysql, pdo_sqlite, session, simplexml, sodium, tokenizer, xml, zip, bcmath. SQLite 3.54.1 disponible (para las pruebas en memoria).

**Rutas de escritura de PHP comprobadas** (`.tools/verificar-entorno.php`): `sys_temp_dir`, `upload_tmp_dir`, `session.save_path`, `error_log`, `curl.cainfo`, `openssl.cafile`, `extension_dir`, `TEMP`, `TMP`, `COMPOSER_HOME` y `COMPOSER_CACHE_DIR` están todas en `E:\…\.tools`. Ninguna en C:.

### 0.3 Proyecto con las dependencias instaladas

| Comprobación | Resultado |
|---|---|
| `composer check-platform-reqs --lock` | ✅ Todos los requisitos cumplidos con PHP 8.4.28 (incluido el `php ^8.3` de nwidart). |
| `composer install --no-scripts --prefer-dist` | ✅ 118 paquetes (84 + 34 dev), exactamente los del lock. |
| `php artisan package:discover` (ejecutado a mano, en lugar del script de Composer) | ✅ dompdf, breeze, pail, tinker, carbon, collision, termwind y nwidart. |
| `php artisan --version` | ✅ Laravel Framework 11.51.0 |
| `php artisan module:list` | ✅ Los 6 módulos activos: Access, Audit, Inventory, Procurement, Rentals, Sales. |

Esto también confirma en ejecución que nwidart v13 funciona sobre Laravel 11.51 (en la Fase 1 era solo una hipótesis).

### 0.4 MySQL de pruebas aislado

**Configuración** (`.tools/mysql/my.ini`):
- `bind_address=127.0.0.1`, `port=3310`, `mysqlx=OFF`, sin *named pipe* ni memoria compartida.
- `datadir`, `tmpdir`, `log_error`, `pid_file` y `secure_file_priv` en E:.
- `lower_case_table_names=1`, `utf8mb4` / `utf8mb4_unicode_ci` (igual que el servicio `db` de Docker).
- Sin *binlog* ni *general log*.

**Cuentas:**
- `root@127.0.0.1` y `root@localhost` con una contraseña aleatoria de 32 caracteres.
- `sound_test@127.0.0.1`, con privilegios **solo** sobre `sound_management_test.*`.
- Las contraseñas se generaron con un generador criptográfico. Se guardan **solo** en `.tools/mysql/root.cnf` y `.tools/mysql/test.cnf` (archivos de opciones del cliente; nunca en la línea de comandos ni en este informe). El `init-file` temporal con el que se crearon las cuentas se borró al terminar.

**Conexión verificada** (mostrada antes de cualquier uso):

| Servidor | Puerto | Bind | Usuario | Base | Versión | lctn | charset |
|---|---|---|---|---|---|---|---|
| Badi | 3310 | 127.0.0.1 | sound_test@127.0.0.1 | **sound_management_test** | 8.4.13 | 1 | utf8mb4 |

- **Bases visibles para `sound_test`:** `information_schema`, `performance_schema`, `sound_management_test`.
- **Todas las bases de la instancia (root):** las de sistema (`information_schema`, `mysql`, `performance_schema`, `sys`) y `sound_management_test` (**0 tablas**).
- **PDO desde el PHP portátil** (`verificar-mysql.php`): `sound_test@127.0.0.1 @ 127.0.0.1:3310 / sound_management_test (MySQL 8.4.13)`. El script **aborta** si la configuración no apunta exactamente a `127.0.0.1:3310/sound_management_test`.

**Scripts de uso:**
- `.tools/mysql-iniciar.ps1`: se niega a arrancar si el puerto 3310 está ocupado por otro proceso.
- `.tools/mysql-detener.ps1`: parada ordenada con `mysqladmin shutdown`.

Se probaron en el ciclo parar → arrancar → parar: "Normal shutdown / Shutdown complete" en el log. **Estado actual: detenido, sin procesos `mysqld`.**

**Procedimiento obligatorio antes de cualquier prueba destructiva** (`migrate:fresh`, `RefreshDatabase`, etc.):
1. Ejecutar `verificar-mysql.php` y mostrar el servidor, el puerto, el usuario y la base.
2. Confirmar que la base es exactamente `sound_management_test` en `127.0.0.1:3310`.
3. Pasar a Laravel esa conexión de forma explícita (variables de entorno de la prueba), nunca un `.env` que pueda apuntar a otra base.

### 0.5 Incidencias y límites (con transparencia)

1. **Composer ejecutó `git` por su cuenta** (el `git` instalado en C:) al ejecutar `composer --version` y `config`, para deducir la versión del proyecto.
   - El directorio de trabajo de la consola era, en ese momento, la carpeta antigua `E:\BadiStudio\sound-management`. Git se negó a trabajar ("dubious ownership in repository at 'E:/'"), así que **no se escribió ni se modificó nada** allí.
   - Aun así, se ejecutó una herramienta de C: y probablemente leyó su configuración global.
   - Corregido para todo lo posterior: `PATH` del proceso limitado a `.tools\php` (Composer ya no encuentra ningún ejecutable de C:), `COMPOSER_ROOT_VERSION=dev-main`, `--no-scripts` (los scripts de Composer se lanzan a través de `cmd.exe`) y rutas absolutas del proyecto. Las ejecuciones siguientes no volvieron a mostrar el aviso.
   - Las tuberías temporales que creó Symfony Process al lanzar `git` (40 archivos `sf_proc_*`, de 0 bytes) quedaron en `.tools\tmp` (E:), no en C:, y se borraron.
2. **Límites que no se pueden evitar:**
   - La propia consola (PowerShell/Git Bash) y Claude Code están instalados en C: y registran allí la sesión.
   - Windows carga sus bibliotecas del sistema (p. ej. el runtime de Visual C++ que necesitan `php.exe` y `mysqld.exe`) desde `C:\Windows`.
   - No se leyó ni escribió ningún archivo de datos en C:, pero **no es posible demostrarlo inspeccionando C:**, porque eso exigiría leer ese disco.
3. **Verificación de MySQL:** se verificó con MD5, porque es la suma que publica el CDN; la página de descargas de MySQL devolvió 403 a las peticiones automáticas. MD5 se considera débil para integridad. La firma GPG queda pendiente hasta disponer de `gpg` en E:.

### 0.6 Archivos creados o modificados en este paso

**Dentro de `.tools/` (excluida de Git y de Docker):**
- `php/` (PHP 8.4.28 y `php.ini` propio)
- `composer/composer.phar`, `composer/home`, `composer/cache`
- `mysql/mysql-8.4.13-winx64/`, `mysql/my.ini`, `mysql/root.cnf`, `mysql/test.cnf`
- `mysql-data/`
- `downloads/` (los ZIP originales de PHP y MySQL)
- `certs/cacert.pem`
- `logs/`, `tmp/`
- `verificar-entorno.php`, `verificar-mysql.php`, `mysql-iniciar.ps1`, `mysql-detener.ps1`

**En el proyecto:**
- `vendor/` (nuevo, 65,6 MB; ya estaba ignorado por Git)
- `bootstrap/cache/packages.php`, `services.php`, `modules.php` (generados por artisan)
- `.gitignore` (+ `/.tools/`)
- `.dockerignore` (+ `.tools`)

**Espacio usado:** unos 1,9 GB en E:. MySQL descomprimido ocupa 1,2 GB.

**No se modificó:** `composer.json`, `composer.lock`, las migraciones, los seeders ni ningún código de la aplicación.
