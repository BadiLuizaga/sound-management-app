# FASE 2 — ETAPA 1: BASE DE DATOS

**Estado:** ✅ **Implementada y probada** (2026-11-20). La evidencia está en §6 y en `FASE_2_PRUEBAS.md` §1.
**Base de producción prevista:** `sound_management_db` (instalación nueva y limpia)
**Base usada en las pruebas:** `127.0.0.1:3310/sound_management_test` (MySQL 8.4.13 portátil y aislado) + SQLite en memoria

**Decisiones aplicadas:**
- Métodos de pago Efectivo, Tarjeta, Transferencia bancaria, QR y PayPal, identificados por código.
- Retirada de los datos y credenciales de la ferretería.
- Primer administrador mediante un procedimiento seguro.
- Reservas: en esta etapa, **solo** el esquema.

---

## 1. Resumen

| Antes | Ahora |
|---|---|
| `migrate` abortaba en la migración #21; 5 tablas solo existían en SQL externo | **31 migraciones** construyen una base limpia de **49 tablas** (+ `migrations`) en MySQL 8.4 y en SQLite. `migrate:reset` y `migrate` vuelven a funcionar. |
| `id_pago = 2` y `id_pago = 1` escritos en el código, sin filas en `metodoPago` | Métodos con `codigo` único; el código consulta `MetodoPago::idPorCodigo()` **antes** de registrar y se niega a registrar si el método falta o está inactivo. |
| Columnas más cortas que las validaciones (error 500 con datos válidos) | Longitudes coherentes; unicidad e integridad en la base. |
| `AdminUserSeeder` con `admin@ferre.bo` / `admin123`; `MaquinariaSeeder` con el catálogo de la ferretería | Eliminados. Seeders solo técnicos (métodos, roles, permisos). Primer administrador con `php artisan sound:crear-administrador`. |
| Sin soporte de reservas | Esquema de paquetes, reservas, paquetes por reserva y equipos asignados, con FKs, índices y CHECKs. |
| Permisos solo en `config/access.php` | Tablas `permisos` y `rol_permiso`, sembradas con la misma matriz (la Etapa 3 hará de la base la fuente de verdad). |

---

## 2. Archivos modificados, creados y eliminados

### 2.1 Migraciones
| Acción | Archivo | Motivo |
|---|---|---|
| Renombrada + idempotente | `2026_06_21_221019_add_imagen_to_promociones_table` → **`2026_06_22_000005_add_imagen_to_promociones_table.php`** | Se ejecutaba antes de crear `promociones` |
| Renombrada + idempotente | `2026_06_21_225458_add_cantidad_to_mantenimientos_table` → **`2026_06_22_000006_…`** | Se ejecutaba antes de crear `mantenimientos` |
| Renombrada + idempotente, en pasos separados | `2026_06_21_230100_change_maquinaria_id_to_idproducto_…` → **`2026_06_22_000007_…`** | Ídem; los pasos separados permiten que funcione también en SQLite |
| Eliminadas | `2026_05_13_060426_add_deleted_at_to_producto_table.php`, `2026_05_13_071914_add_deleted_at_to_producto_table.php` | Vacías y duplicadas (se comprobó que no tenían contenido) |
| Corregido solo `down()` | `2023_01_01_000000_create_base_tables.php` | No eliminaba `cliente` ni `metodoPago`, así que `migrate:reset` fallaba al borrar `usuario` (encontrado al probar). `up()` sin cambios. |
| **Nueva** | `2026_06_01_000001_create_proveedor_y_notas_tables.php` | `proveedor`, `NotaVenta`, `detalleNotaVenta`, `NotaCompra`, `detalleNotaCompra`: solo estructura, FKs restrict e índices `fecha` y `(ci_empleado, fecha)`. **Ningún dato.** |
| **Nueva** | `2026_11_20_000002_ajustar_esquema_instalacion_limpia.php` | Longitudes, unicidad, FK de `cajas`, `metodoPago.codigo`/`activo` e índices (§3) |
| **Nueva** | `2026_11_20_000003_create_reservas_tables.php` | Esquema de reservas (§4) |
| **Nueva** | `2026_11_20_000004_create_permisos_tables.php` | `permisos`, `rol_permiso` |
| Sin cambios | Las otras 22 originales y `2026_11_20_000001_create_pagos_externos_table.php` | `pagos_externos` ya se ejecuta en la cadena; su rediseño corresponde a la Etapa 2 |

### 2.2 Seeders, factories e instalación
| Acción | Archivo |
|---|---|
| Reescrito | `database/seeders/DatabaseSeeder.php` → `MetodoPagoSeeder`, `RolSeeder`, `PermisoSeeder` (sin usuarios) |
| Nuevo | `database/seeders/MetodoPagoSeeder.php` |
| Nuevo | `database/seeders/RolSeeder.php` |
| Nuevo | `database/seeders/PermisoSeeder.php` |
| **Eliminado** | `database/seeders/AdminUserSeeder.php` (credencial pública `admin@ferre.bo` / `admin123`) |
| **Eliminado** | `database/seeders/MaquinariaSeeder.php` (catálogo de la ferretería) |
| Nuevo | `database/factories/UsuarioFactory.php` (solo para pruebas) |
| Nuevo | `app/Console/Commands/CrearAdministrador.php` → `php artisan sound:crear-administrador` |

### 2.3 Modelos
| Acción | Archivo |
|---|---|
| Modificado | `Modules/Sales/app/Models/MetodoPago.php`: constantes de código, `scopeActivos`, `scopePresenciales`, `reglaPresencial()`, `idPorCodigo()` (lanza `DomainException`) y `disponible()` |
| Modificado | `Modules/Rentals/app/Models/Maquinaria.php`: `cantidad_total` y las relaciones `paqueteItems()` y `reservaEquipos()` |
| Nuevos | `Modules/Rentals/app/Models/{Paquete, PaqueteItem, Reserva, ReservaPaquete, ReservaEquipo}.php`: solo relaciones y tipos, sin lógica |

### 2.4 Código que usaba IDs de pago fijos
| Archivo | Cambio |
|---|---|
| `Modules/Sales/app/Http/Controllers/CartController.php` | `'id_pago' => 2` → `MetodoPago::idPorCodigo(PAYPAL)`, resuelto **al inicio** del checkout (si falta, no se registra nada). `index()` pasa `$pagoEnLineaDisponible`. |
| `resources/views/carrito/index.blade.php` | El botón y el SDK de PayPal solo aparecen si el método PAYPAL está disponible. |
| `Modules/Procurement/app/Http/Controllers/CompraController.php` | `'id_pago' => 1` → `MetodoPago::idPorCodigo(EFECTIVO)`, resuelto antes de la transacción. |
| `Modules/Sales/app/Http/Controllers/VentaController.php` | El POS lista y valida solo métodos **activos y presenciales** (excluye PayPal, que es un pago en línea verificado por el servidor). |
| `Modules/Rentals/app/Http/Controllers/AlquilerController.php` | Ídem para el alquiler en mostrador. |
| `resources/views/ventas/create.blade.php`, `resources/views/alquileres/create.blade.php` | El JS tenía `metodoPagoId: '1'` fijo; ahora usa el primer método disponible. |
| `Modules/Access/app/Http/Controllers/TrabajoController.php` | `nombre` del rol: `max:100` + `unique:rol,nombre` (coherente con el índice único nuevo). |

### 2.5 Pruebas, documentación y entorno
| Acción | Archivo |
|---|---|
| Modificado | `tests/TestCase.php`: **salvaguarda** que aborta antes de conectarse si la base no es SQLite en memoria o `127.0.0.1:3310/sound_management_test` |
| Nuevos | `tests/Feature/Instalacion/{EsquemaInstalacionTest, SeedersInstalacionTest, CrearAdministradorTest, RegistroClienteTest, MetodoPagoTest}.php` (29 pruebas) |
| Modificado | `phpunit.xml`: `APP_KEY` exclusiva de pruebas (32 bytes; no se usa en ningún entorno real) |
| Reescrito | `README.md`: instalación limpia, sin credenciales predeterminadas |
| Nuevos (en `.tools/`, fuera de Git) | `entorno.ps1`, `ejecutar-pruebas.ps1`, `artisan-prueba.ps1` (verifican y muestran la conexión antes de ejecutar) |
| Regenerado | `vendor/composer/autoload_*` (`composer dump-autoload --no-scripts`; **`composer.lock` intacto**) |

---

## 3. Ajustes de esquema (`2026_11_20_000002`)

| Tabla | Cambio |
|---|---|
| `usuario` | `email` VARCHAR(255) **UNIQUE**; `nombre`/`apellido` 100; `domicilio` 255; `telefono` VARCHAR(20) null |
| `producto` | `nombre` 255; `imagen` VARCHAR(2048) |
| `rol` | `nombre` 100 **UNIQUE**; `descripcion` null |
| `categoria` | `descripcion` null |
| `marca` | `nombre` **UNIQUE** |
| `metodoPago` | + `codigo` VARCHAR(30) **UNIQUE**; + `activo` BOOLEAN |
| `cajas` | FK `user_id → usuario(ci)` restrict; índice `(user_id, estado)` |
| `carritos` | UNIQUE `(ci_usuario, idproducto)` |
| `alquileres`, `maquinarias`, `bitacoras` | índices de consulta |

Al revertir, las longitudes ampliadas **no** se reducen, a propósito: hacerlo podría truncar datos.

---

## 4. Esquema de reservas (`2026_11_20_000003`): solo estructura

| Tabla | Propósito | Claves e índices |
|---|---|---|
| `maquinarias.cantidad_total` | Unidades de cada equipo (por defecto 1: conserva "una fila = una máquina") | CHECK implícito: unsigned |
| `paquetes` | Única forma de alquilar; `precio_hora` en BOB, `horas_minimas` y `horas_maximas`, `activo`, borrado lógico | `codigo` UNIQUE; CHECK precio ≥ 0; CHECK horas |
| `paquete_items` | Equipo y cantidad de cada paquete | FK paquete (cascade), FK maquinaria (restrict); UNIQUE (paquete, maquinaria); CHECK cantidad > 0 |
| `reservas` | Reserva por horas. **`estado`** (pendiente → confirmada → en curso → finalizada / cancelada / expirada / rechazada) y **`estado_pago`** (pendiente / parcial / pagado / reembolsado) son **independientes**. `expira_at` para las pendientes. | `codigo` UNIQUE; FK cliente (restrict), FK registrado_por (set null); índices (estado, fecha_inicio), (fecha_inicio, fecha_fin), (estado, expira_at); CHECK fecha_fin > fecha_inicio, horas > 0, total ≥ 0 |
| `reserva_paquetes` | Paquetes de la reserva con **precio congelado** | FK reserva (cascade), FK paquete (restrict); UNIQUE (reserva, paquete); CHECK cantidad, horas, subtotal |
| `reserva_equipos` | Unidades **bloqueadas solo al confirmar**; `estado` reservado / liberado (al cancelar o expirar) | FK reserva (cascade), FK maquinaria (restrict); UNIQUE (reserva, maquinaria); índice **(maquinaria, estado, fecha_inicio, fecha_fin)** para calcular la disponibilidad; CHECK cantidad > 0 y el intervalo |

**Cómo se evitará la sobreventa** (la lógica es de la etapa de reservas):
- Disponibilidad = `cantidad_total` − Σ `reserva_equipos.cantidad` en estado `reservado` cuyo intervalo se solapa.
- La confirmación se hará en una transacción con `SELECT … FOR UPDATE` sobre las filas de `maquinarias` afectadas: es un bloqueo de fila, sin *gap locks*.
- MySQL no tiene restricciones de exclusión por rango, así que esa garantía queda en la capa de servicio, apoyada en este índice.
- Una reserva pendiente **no** tiene filas en `reserva_equipos`, así que no bloquea equipos mientras el cliente paga.

**Las 9 restricciones CHECK** se crean en MySQL. SQLite no permite añadirlas después de crear la tabla; ahí las cubrirá la validación.

---

## 5. Seeders: datos iniciales mínimos (sin datos comerciales)

| Seeder | Contenido | Idempotencia |
|---|---|---|
| `MetodoPagoSeeder` | 1 EFECTIVO, 2 TARJETA, 3 TRANSFERENCIA (Transferencia bancaria), 4 QR, 5 PAYPAL | Por `codigo`. Si una fila con el mismo nombre no tiene código (p. ej. tras revertir y rehacer el ajuste), se le asigna en lugar de duplicarla. No reactiva un método que el administrador haya desactivado. |
| `RolSeeder` | Administrador, Encargado, Técnico, Almacenero, Vendedor | Por `nombre`; no pisa descripciones editadas. El cliente no es un rol (`tipoPersona = 'C'`). |
| `PermisoSeeder` | 11 permisos (los de `config/access.php` salvo `cliente`) y 34 asignaciones por defecto | `insertOrIgnore`; no quita permisos asignados a mano. |

**Sin usuarios, clientes, productos, equipos, ventas, compras ni reservas.** Comprobado sobre 34 tablas de negocio: todas con 0 filas tras sembrar.

---

## 6. Pruebas realizadas (evidencia)

Antes de cada operación destructiva en MySQL, los scripts mostraron la conexión:

`sound_test@127.0.0.1 | Badi | 3310 | sound_management_test | MySQL 8.4.13`, con bases visibles `information_schema`, `performance_schema` y `sound_management_test` (ninguna ajena).

| # | Prueba | MySQL 8.4.13 | SQLite |
|---|---|---|---|
| 1 | `migrate:fresh`: las 31 migraciones de una base vacía | ✅ 50 tablas (49 + `migrations`) | ✅ (vía `RefreshDatabase`) |
| 2 | `db:seed` dos veces seguidas | ✅ 5 métodos, 5 roles, 11 permisos, 34 asignaciones (sin cambios en la segunda) | ✅ prueba automatizada |
| 3 | 0 filas en las tablas de negocio tras sembrar | ✅ | ✅ |
| 4 | 9 restricciones CHECK presentes | ✅ (`information_schema.check_constraints`) | n/a |
| 5 | `migrate:reset` completo → solo queda `migrations`; `migrate` → 50 tablas; `migrate:rollback --step=4` y `migrate` de nuevo | ✅ (tras las 2 correcciones de §7) | — |
| 6 | Suite `tests/Feature/Instalacion` (29 pruebas, 133 aserciones): esquema, FKs, índices, longitudes, unicidad, seeders, instalador, registro, métodos de pago, checkout sin método | ✅ **OK (29/29)** | ✅ **OK (29/29)** |
| 7 | La salvaguarda aborta con otra base (`sound_management_db`) y con otro puerto (3306) **sin conectarse** | ✅ "ABORTADO …", código 1 | — |

**Cobertura de las 29 pruebas:**
- Las 49 tablas existen.
- Las FKs nuevas y las heredadas del volcado (NotaVenta→cliente/empleado/metodoPago, devoluciones→NotaVenta, mantenimientos→producto, cajas→usuario, reservas, permisos).
- Los índices de disponibilidad y de caja.
- La forma final de las migraciones reordenadas.
- Longitudes: email de 250, nombre de 255, URL de 2021 y teléfono de texto.
- Email y rol repetidos rechazados por la base.
- Seeders exactos e idempotentes; no reactivan métodos; los seeders heredados ya no existen.
- Instalador: crea `usuario` + `empleado` + `estadoRol`; contraseña con hash; Gate `admin` verdadero; bitácora; se niega si ya hay un administrador; rechaza contraseña débil, confirmación distinta y email inválido; exige los seeders; rechaza `--no-interaction`.
- Registro: el cliente crea su cuenta con un email de 193 caracteres; recibe 403 o redirección en ventas, la API de clientes, alquileres, usuarios y reportes; un email repetido no crea una segunda cuenta.
- `idPorCodigo`: resuelve, lanza excepción si falta o está inactivo, y los presenciales excluyen PayPal.
- **Checkout sin el método PAYPAL: no crea `NotaVenta` ni `pagos_externos`.**

---

## 7. Errores encontrados y corregidos durante las pruebas

| # | Error | Causa | Corrección |
|---|---|---|---|
| 1 | `migrate:reset` fallaba en `ajustar_esquema` (MySQL 1553) | Al crear UNIQUE `(ci_usuario, idproducto)`, MySQL elimina el índice implícito de la FK `ci_usuario` y usa el nuevo; al revertir no deja borrarlo | `down()` crea antes un índice simple para la FK |
| 2 | `migrate:reset` fallaba en la migración base (MySQL 3730) | Su `down()` original no eliminaba `cliente` (FK a `usuario`) ni `metodoPago` | Se añadieron al `down()`; `up()` sin cambios |
| 3 | `MetodoPagoSeeder` habría duplicado los métodos tras revertir y rehacer el ajuste (códigos NULL) | Buscaba solo por código | Reasigna el código a la fila con el mismo nombre |
| 4 | **La salvaguarda de `TestCase` no se ejecutaba** | El método `beforeRefreshingDatabase()` vacío del trait `RefreshDatabase` anula al de la clase padre | Movida a `setUpTraits()`. Antes de corregirlo hubo **un** intento de conexión al servidor de pruebas aislado con un usuario inexistente (`nadie`), rechazado por MySQL sin acceso a datos. |
| 5 | Prueba de seeders eliminados | *Classmap* optimizado con las clases borradas | `composer dump-autoload --no-scripts` (lock intacto) |

**Incidencia de uso de la consola (no es un error de la aplicación):** un `Select-Object -First 3` en mi comando de PowerShell cortó la tubería e **interrumpió** un `migrate:fresh --seed`, que dejó 4 tablas. Se repitió sin cortar la salida: 31/31. Ocurrió solo en la base de pruebas.

---

## 8. Pendientes y observaciones (no bloquean esta etapa)

1. **Suite heredada de Breeze:** 21 de 25 pruebas fallan por causas de las propias pruebas, no de la aplicación (se tratará en la Etapa 5):
   - usan `User` en lugar de `Usuario` y no resuelven `UserFactory` para un modelo de `Modules\`;
   - `ExampleTest` hace `GET /` sin migrar;
   - `RegistrationTest` envía campos de Breeze (`name`) que este formulario no usa.
2. **Mantenimientos** apuntan a `producto` (decisión heredada de la ferretería), no a `maquinarias`. La disponibilidad de reservas deberá decidir si un equipo en mantenimiento se descuenta de `cantidad_total`: es una decisión de la etapa de reservas.
3. **Compras y POS con el mismo producto dos veces en la misma nota** chocarían con la PK compuesta `(nro_factura, id_producto)`, que ya existía en el volcado. Comportamiento heredado; queda documentado.
4. `usuario.tipoPersona` (30) frente a la validación `max:50` de `UsuarioController`: se corrige en la Etapa 3, que restringirá el valor a `C`/`E`.
5. `keyType = 'string'` en `Usuario`/`Cliente`/`Empleado` sobre columnas INT: se mantiene, porque cambiarlo tiene riesgo y no bloquea.
6. `pagos_externos`: `ci_usuario` NOT NULL y sin columnas de conciliación. Se rediseña en la **Etapa 2**.

---

## 9. Restricción del disco C: en esta etapa

- **No se usó Git ni la herramienta Bash (Git Bash).** No se ejecutó ningún programa de C: (PHP de XAMPP, Composer global, Docker, curl, tar).
- Se usaron:
  - las herramientas de archivo de Claude Code (leer, escribir, editar, buscar);
  - PowerShell como consola, solo con cmdlets, para lanzar **exclusivamente** `E:\…\.tools\php\php.exe`, `composer.phar` y los binarios de MySQL de `.tools`, con `PATH`, `TEMP`, `TMP` y las cachés limitados a E:.
- Límites ya informados:
  - la consola, Claude Code y las bibliotecas de Windows residen en C:;
  - los cmdlets de red (`Get-NetTCPConnection`) son del sistema operativo.
