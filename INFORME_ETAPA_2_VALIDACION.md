# Informe de validación — Fase 2, Etapa 2 (Sistema de Pagos)

**Proyecto:** `E:\BadiStudio\sound-management-app`
**Fecha de la validación:** 2026-11-21
**Alcance:** validación técnica de la Etapa 2 ya implementada (pasos 1–7), corrección autorizada de `Producto::$incrementing` y su prueba de regresión. No se implementó funcionalidad nueva ni se inició la Etapa 3.

> **Estado:** las suites de Finanzas e Instalación pasan en SQLite y en MySQL. **Un punto de integridad no se cumple** (§7.2: el arqueo de caja heredado mezcla métodos de pago). Está fuera del alcance de la Etapa 2 según el diseño aprobado (decisión C3), pero debe resolverse en la Etapa 3. La aprobación de la Etapa 2 queda a criterio de la revisión de este informe.

---

## 1. Resumen de la implementación (Etapa 2)

| Área | Qué hace |
|---|---|
| Módulo `Finance` | Módulo independiente (nwidart). Se registra mediante el `composer.json` del propio módulo (plugin *merge*), **sin tocar el `composer.json` raíz**. |
| Dinero | Importes en centavos enteros (`Dinero`), redondeo HALF_UP, BOB como moneda operativa. Conversión BOB→USD explícita, con la tasa congelada en el intento de pago. |
| Tipo de cambio | Lo gestiona solo el administrador. Una tasa por día con `fecha_aplicacion` explícita (zona America/La_Paz). Nunca se hereda la de ayer ni se usa una tasa por defecto. Una tasa ya usada no se puede modificar; las demás cambian dejando revisión. |
| Estados | `pagos.estado` (el dinero) separado de `pagos.estado_aplicacion` (la venta o reserva). Todo cambio pasa por un único punto (`PagoService::transicionar`) y deja un evento en `pago_eventos`, tabla de solo inserción. |
| PayPal | El servidor crea la orden y la captura. Lo que diga el navegador no cambia el estado. Una respuesta incierta deja el pago en `verificando`, que nunca expira. Hay conciliación programada (`finanzas:conciliar`) y webhooks con firma verificada. |
| Idempotencia | Clave de idempotencia por intento, `PayPal-Request-Id` por operación y claves únicas en referencia externa, captura, evento de webhook y reembolso. |
| Caja | Efectivo y tarjeta: el pago queda `recibido` y la venta se registra en la misma transacción. Transferencia y QR: `pendiente_verificacion`, sin venta ni descuento de stock hasta que un administrador o encargado lo verifica. La referencia es obligatoria y única. |
| Reembolsos | Totales y parciales, con la tasa original. El encargado solicita y el administrador autoriza y ejecuta (o hace el proceso completo). En caja se completan al confirmar la entrega del dinero. |
| Devoluciones | No modifican la nota de venta. Generan un registro de devolución con importes y un reembolso vinculado. |
| Permisos | 9 habilidades `finance.*`. Técnico, almacenero y cliente no reciben ninguna. Cada botón exige el mismo permiso que su ruta. Las tablas financieras quedan vetadas para los reportes con IA. |
| Datos históricos | `pagos_externos` se conserva intacta y sus filas se copian a `pagos` de forma idempotente. |

## 2. Archivos creados y modificados

### 2.1 En esta sesión de validación

| Archivo | Tipo | Motivo |
|---|---|---|
| `Modules/Inventory/app/Models/Producto.php` | **Modificado** (autorizado) | Añadidos `public $incrementing = false;` y `protected $keyType = 'int';`. |
| `tests/Feature/Instalacion/ProductoClaveTest.php` | **Creado** | Prueba de regresión, 5 casos (§6). |
| `.tools/validacion-mysql-etapa2.ps1` | Creado (herramienta) | Ejecución reproducible en MySQL: arranca el servidor, verifica el destino, ejecuta las pruebas y lo detiene. |
| `.tools/resultados/*` | Creados (resultados) | Salidas de texto, informes JUnit y huellas de migraciones (§4–§5). |
| `INFORME_ETAPA_2_VALIDACION.md` | Creado | Este informe. |

### 2.2 Implementación de la Etapa 2 (pasos 1–7)

**Creados: módulo `Modules/Finance/`**
- `module.json`, `composer.json`, `config/config.php`
- `app/Providers/`: `FinanceServiceProvider`, `RouteServiceProvider`, `EventServiceProvider`
- `app/Enums/`: `EstadoPago`, `EstadoAplicacion`, `EstadoReembolso`
- `app/Support/Dinero.php`
- `app/Models/`: `Pago`, `PagoEvento`, `Reembolso`, `TipoCambio`, `TipoCambioRevision`, `WebhookEvento`
- `app/Gateways/`: `PaymentGateway`, `GatewayIncierto`, `GatewayRechazo`, `Resultados/ResultadoOrden`, `Resultados/ResultadoReembolso`, `PayPal/PayPalClient`, `PayPal/PayPalGateway`
- `app/Exceptions/`: `PagoNoDisponible`, `TransicionInvalida`, `AplicacionFallida`
- `app/Aplicadores/`: `AplicadorPago`, `AplicadorVentaEnLinea`, `AplicadorVentaPresencial`, `AplicadorReserva`
- `app/Services/`: `TipoCambioService`, `PagoService`, `ReembolsoService`, `ConciliacionService`
- `app/Webhooks/PayPalWebhookProcessor.php`
- `app/Console/ConciliarPagos.php`
- `app/Http/Controllers/`: `PagoEnLineaController`, `PayPalWebhookController`, `Admin/PagoController`, `Admin/ReembolsoController`, `Admin/TipoCambioController`
- `routes/web.php`
- `resources/views/`: `pagos/resultado`, `pagos/index`, `pagos/show`, `tipos-cambio/index`, `partials/mensajes`
- `database/migrations/`: las 6 migraciones (§3)
- `tests/`: `Support/PayPalFalso`, `Support/EscenarioFinanzas`, `Unit/DineroTest`, `Feature/{EsquemaFinanzas, TipoCambioServicio, PagoEnLinea, WebhookPayPal, PagoPresencial, Reembolso, PermisosFinanzas, TransicionPagosExternos}Test`

**Creados fuera del módulo**
- `Modules/Sales/app/Services/CarritoService.php`
- `tests/Feature/Instalacion/HabilidadesPorRolTest.php`

**Modificados**
- `modules_statuses.json`: `Finance` activado.
- `bootstrap/app.php`: exclusión CSRF solo para `webhooks/paypal`.
- `config/services.php`: `paypal.webhook_id`.
- `.env.example`: `PAYPAL_WEBHOOK_ID` y `PAGOS_EXPIRACION_MINUTOS` (sin valores).
- `config/access.php`: 9 habilidades `finance.*`.
- `database/seeders/PermisoSeeder.php`: descripciones de las 9 habilidades.
- `app/Support/AccessControl.php`: **corrección de un defecto de la Fase 1** (§10).
- `phpunit.xml`: suite `Modules`.
- `Modules/Sales/app/Http/Controllers/CartController.php` y `VentaController.php`.
- `resources/views/carrito/index.blade.php`, `ventas/create.blade.php` y `components/admin-sidebar.blade.php`.
- `Modules/Procurement/app/Http/Controllers/DevolucionController.php`, `Models/Devolucion.php` y `Models/DevolucionDetalle.php`.
- `Modules/Audit/app/Services/ReadOnlySqlGuard.php`.
- `tests/Feature/Instalacion/EsquemaInstalacionTest.php` y `SeedersInstalacionTest.php`.

**Conservados sin uso (no eliminados):** `Modules/Sales/app/Models/PagoExterno.php` y `Modules/Sales/app/Services/PayPalOrderVerifier.php`.

## 3. Las seis migraciones nuevas

Todas están en `Modules/Finance/database/migrations/`.

| Migración | Tablas o cambios | SHA-256 (8 primeros) |
|---|---|---|
| `2026_11_22_000001_create_tipos_cambio_tables` | `tipos_cambio` (única por base, cotizada y fecha; CHECK tasa > 0) y `tipo_cambio_revisiones` | `A5FB6AEE` |
| `2026_11_22_000002_create_pagos_tables` | `pagos` (estados del dinero y de la aplicación, claves únicas de idempotencia, referencia y captura, FKs *restrict*, CHECKs de importe) y `pago_eventos` | `77BFA89C` |
| `2026_11_22_000003_create_reembolsos_table` | `reembolsos` | `6A2EAEAB` |
| `2026_11_22_000004_create_webhook_eventos_table` | `webhook_eventos` (única por proveedor y evento) | `70147FA4` |
| `2026_11_22_000005_add_importes_to_devoluciones_tables` | Columnas nuevas en `devoluciones` (`monto_total`, `ci_aprobado_por`, `aprobado_at`) y `devolucion_detalles` (`precio_unitario`, `subtotal`) | `50BDD607` |
| `2026_11_22_000006_transferir_pagos_externos_a_pagos` | Copia de datos `pagos_externos` → `pagos`. Idempotente, conserva el original y su `down()` se niega si hay historial posterior | `FEB50702` |

**Estado de la base de pruebas en MySQL:** 56 tablas (incluida `migrations`). El ciclo `migrate:fresh --seed` → `migrate:reset` → `migrate` → `db:seed` → `rollback --step=6` → `migrate` se ejecutó sin errores en la sesión anterior. Restricciones CHECK presentes: `pagos_monto_check`, `pagos_monto_proveedor_check`, `reembolsos_monto_check`, `tipos_cambio_tasa_check` y `tipo_cambio_revisiones_tasa_check`.

## 4. Resultados en SQLite (en memoria)

Todo se ejecutó con PHPUnit 11.5.55 y PHP 8.4.28 portátil. Los resultados están en `.tools/resultados/v2-sqlite-*.txt` y `*.junit.xml`.

| Suite | Pruebas | Aprobadas | Fallos | Errores | Omitidas | Aserciones | Tiempo |
|---|---|---|---|---|---|---|---|
| Finanzas (`Modules/Finance/tests`) | 86 | **86** | 0 | 0 | 0 | 330 | 181,3 s |
| Instalación (`tests/Feature/Instalacion`) | 50 | **50** | 0 | 0 | 0 | 184 | 44,3 s |
| Completa (sin filtro) | 161 | 140 | 2 | 19 | 0 | 520 | 42,9 s |

- **Instalación:** pasa de 45 a 50 pruebas por las 5 de regresión nuevas (`ProductoClaveTest`). Las 45 anteriores siguen aprobadas.
- **Suite completa:** los 21 fallos son **todos** de las pruebas Breeze heredadas (`Auth\*`, `ProfileTest` y `ExampleTest`), ya registradas como pendientes de la Etapa 5 (§6). **Ningún fallo corresponde a código del proyecto.**

## 5. Resultados en MySQL 8.4.13

**Destino verificado antes de ejecutar** (guardado en `.tools/resultados/v2-mysql-resumen.txt`):

```
sound_test@127.0.0.1 | Badi | 3310 | sound_management_test | MySQL 8.4.13
Bases visibles para el usuario: information_schema, performance_schema, sound_management_test
PDO MySQL OK -> sound_test@127.0.0.1 @ 127.0.0.1:3310 / sound_management_test
Variables: DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3310 DB_DATABASE=sound_management_test DB_USERNAME=sound_test
```

El script aborta si cualquiera de esos cinco valores difiere.

| Suite | Pruebas | Aprobadas | Fallos | Errores | Omitidas | Aserciones | Tiempo |
|---|---|---|---|---|---|---|---|
| Finanzas | 86 | **86** | 0 | 0 | 0 | 330 | 432,4 s |
| Instalación | 50 | **50** | 0 | 0 | 0 | 184 | 574,9 s |

**Antes de la corrección**, la misma suite de Finanzas en MySQL dio 86 pruebas con **35 errores**, todos `SQLSTATE 1452` en la FK `carritos_idproducto_foreign` (se insertaba `idproducto = 0`). Ese resultado se conserva en `.tools/resultados/mysql-finanzas.*`.

**Suite completa en MySQL:** no se ejecutó, porque no se solicitó en esta validación. Sus únicos fallos conocidos son las pruebas Breeze, que fallan por una clase inexistente con independencia del motor.

## 6. Pruebas fallidas u omitidas

- **Omitidas:** ninguna, en ninguna suite.
- **Fallidas en Finanzas e Instalación:** ninguna, en ninguno de los dos motores.
- **Fallidas en la suite completa (21, heredadas, fuera del alcance de la Etapa 2):**
  - 19 errores en `Auth\AuthenticationTest` (3), `EmailVerificationTest` (3), `PasswordConfirmationTest` (3), `PasswordResetTest` (3), `PasswordUpdateTest` (2) y `ProfileTest` (5): `Class "Database\Factories\Modules\Access\Models\UserFactory" not found`.
  - 1 fallo en `Auth\RegistrationTest::test_new_users_can_register`: «The user is not authenticated».
  - 1 fallo en `ExampleTest::test_the_application_returns_a_successful_response`: se esperaba 200 y se recibió 500.
  - Son las pruebas por defecto de Breeze, escritas para el modelo `User` que ya no existe. Están pendientes de la Etapa 5.

### Corrección de `Producto` y su prueba de regresión

- **Causa:** `producto.idproducto` es un entero asignado por la aplicación, sin AUTO_INCREMENT, pero el modelo heredaba `$incrementing = true`. Tras `create()`, Eloquent sustituía la clave por `lastInsertId()`. MySQL devuelve 0; SQLite devuelve el `rowid`, que coincide con el id asignado, por eso el defecto solo aparecía en MySQL.
- **Alcance del cambio:** los dos lugares que crean productos (`ProductoController@store` y `CompraController`) **siempre** indican `idproducto`, así que la corrección no cambia qué se guarda en la base. Sí corrige que `ProductoController@store` registrara en bitácora el id 0 en MySQL.
- **Prueba `tests/Feature/Instalacion/ProductoClaveTest.php`** (5 casos, todos aprobados en MySQL):

| Caso | Comprueba |
|---|---|
| `test_el_modelo_declara_una_clave_no_incremental` | `getIncrementing() === false`, clave `idproducto` |
| `test_create_conserva_el_idproducto_asignado` | El modelo en memoria conserva 101 y no existe ninguna fila con id 0 |
| `test_el_id_del_modelo_recien_creado_sirve_para_claves_foraneas` | El id sirve para insertar en `carritos` (la FK que fallaba) |
| `test_crear_y_modificar_no_altera_otros_productos_existentes` | Crear, modificar y dar de baja un producto deja las filas de los demás idénticas, campo a campo |
| `test_find_y_lock_por_clave_siguen_funcionando` | `find()` y `lockForUpdate()` por clave |

- Estos casos solo detectan el defecto si se ejecutan en MySQL: en SQLite pasaban también sin la corrección.

## 7. Verificación de integridad de datos

### 7.1 Puntos que se cumplen

Cada punto está respaldado por pruebas que pasaron en **SQLite y MySQL**.

| Punto | Resultado | Evidencia |
|---|---|---|
| `pagos_externos` conserva sus registros | ✅ | `TransicionPagosExternosTest::test_copia_los_registros_conservando_el_original` (la tabla queda idéntica, fila a fila) |
| La migración de datos no duplica | ✅ | `test_repetir_la_transicion_no_duplica`; `test_se_detiene_sin_copiar_si_falta_el_metodo_paypal` |
| Revertir la migración no destruye historial | ✅ | `test_revertir_se_niega_si_hay_historial_posterior` |
| Pagos y reembolsos conservan su historial | ✅ | `EsquemaFinanzasTest::test_pagos_y_eventos_no_se_pueden_eliminar_ni_editar_el_historial`; `ReembolsoTest::test_reembolsos_y_pagos_no_se_eliminan`; `PagoEnLineaTest::test_un_fallo_tecnico_al_aplicar_no_borra_el_pago`; `WebhookPayPalTest::test_reverso_marca_revertido_y_conserva_el_pago` |
| Estado del dinero separado del estado de la aplicación | ✅ | `test_sin_stock_tras_el_cobro_el_pago_se_conserva_en_revision` (`recibido` + `requiere_revision`); `test_captura_con_monto_distinto_queda_recibida_en_revision_sin_venta`; `PagoPresencialTest::test_verificada_sin_stock_conserva_el_pago_en_revision` |
| Sin cobros duplicados por reintentos | ✅ | `test_misma_clave_devuelve_el_mismo_intento`; `test_doble_captura_no_duplica_cobro_ni_venta`; `test_doble_envio_del_pos_no_duplica`; `test_reembolso_idempotente`; `test_captura_completada_por_webhook_y_evento_repetido_ignorado`; `EsquemaFinanzasTest::test_una_captura_no_se_reutiliza` |
| El navegador no decide el estado | ✅ | `test_lo_que_diga_el_navegador_no_cambia_el_estado` |
| Transferencia y QR no generan venta ni descuentan stock sin verificar | ✅ | `test_transferencia_queda_pendiente_sin_venta_ni_descuento_de_stock`; `test_rechazar_transferencia_no_genera_venta` |
| En el pago, el efectivo se registra solo como efectivo | ✅ | `monto_recibido_efectivo` y `cambio_entregado` solo se rellenan con el método EFECTIVO (`PagoService.php:480-521`); `test_efectivo_registra_pago_recibido_y_venta_con_cambio`; `test_tarjeta_exige_referencia` |
| Las devoluciones no modifican la venta | ✅ | `test_devolucion_no_modifica_la_nota_y_genera_reembolso_vinculado` |

### 7.2 Punto que **no se cumple**: el arqueo de caja mezcla métodos de pago

**Pregunta verificada:** «No se contabilizan transferencias, QR ni tarjetas como efectivo».

- **Dónde está el problema:** en el arqueo, no en el registro del pago. `Modules/Sales/app/Http/Controllers/CajaController.php` es código heredado que la Etapa 2 no modificó. Calcula el saldo esperado de la caja así:
  ```php
  DB::table('NotaVenta')->where('ci_empleado', $caja->user_id)
      ->where('fecha', '>=', $caja->fecha_apertura)->sum('total');
  ```
  No filtra por `id_pago`. Ocurre en las líneas 21-24 (listado), 82-85 (cierre) y 106-110 (reporte).
- **Consecuencia:** una venta con tarjeta, o una transferencia o un QR ya verificados (cuya venta lleva `ci_empleado` = el cajero que lo registró), **se suma al saldo en efectivo esperado**. Al cerrar la caja aparecería un faltante aparente igual al importe no cobrado en efectivo. Los reembolsos entregados en efectivo tampoco se restan. Las ventas en línea no se ven afectadas, porque tienen `ci_empleado` nulo.
- **Situación respecto al diseño:** la decisión C3 dejó el arqueo por método de pago y los reembolsos de caja para la **Etapa 3**. No se corrigió, conforme a la instrucción de no corregir automáticamente durante la validación. Es la primera tarea de la Etapa 3 (§10).

### 7.3 Migraciones existentes

- No hay migraciones existentes modificadas en la Etapa 2: los cambios de esquema de esta etapa están únicamente en las 6 migraciones nuevas de `Modules/Finance`.
- **Limitación de la evidencia:** todos los archivos del proyecto tienen la misma hora de modificación (21-11-2026, 12:40), así que la fecha no sirve como prueba. No existía una huella previa de las migraciones y no se usó Git, por restricción. Desde ahora queda una **línea base** con la SHA-256 de las 37 migraciones (31 de la raíz y 6 de Finance) en `.tools/resultados/huellas-migraciones.txt`, para comparar en las etapas siguientes.

## 8. `composer.json` y `composer.lock`

| Archivo | SHA-256 registrado antes de la implementación | SHA-256 actual | Resultado |
|---|---|---|---|
| `composer.lock` | `BF67396908FC1897DB675778EC14C98F73248F64C3EA952CFA63E827FAE09D0F` | `BF67396908FC1897DB675778EC14C98F73248F64C3EA952CFA63E827FAE09D0F` | ✅ idéntico |
| `composer.json` | `D74776677D2CC6BB219B66396DE1A7D49FF40F7DC2D376E9A941B13438879E01` | `D74776677D2CC6BB219B66396DE1A7D49FF40F7DC2D376E9A941B13438879E01` | ✅ idéntico |

No se instaló, actualizó ni eliminó ninguna dependencia. Solo se regeneró el *autoload* de clases (`dump-autoload --no-scripts`), que no toca ninguno de los dos archivos.

## 9. Estado final de MySQL

- Se detuvo con `.tools/mysql-detener.ps1`, que respondió «MySQL de pruebas detenido».
- Comprobación posterior: **0 procesos `mysqld`** y **el puerto 3310 sin escuchar**.

**No se modificaron otras bases:**
- Todas las conexiones usaron el usuario `sound_test`, que solo tiene acceso a `sound_management_test`. Así lo muestra `SHOW DATABASES` en cada verificación.
- No hubo ninguna conexión a `sound_management_db`, al MySQL de XAMPP ni a ningún otro servidor o puerto.
- Las pruebas en SQLite usan una base en memoria, sin archivo.
- No se modificaron `E:\ferreteria` ni `E:\BadiStudio\sound-management`.

**Disco C:**
- Ni el proyecto ni las herramientas escribieron en C:.
- La consola de Claude Code crea por su cuenta un archivo de seguimiento por cada tarea en segundo plano, en `C:\Users\Dj\AppData\Local\Temp\claude\…\tasks\`. Para minimizarlo, la salida de esas tareas se descartó (`*> $null`), así que esos archivos quedan vacíos; todos los resultados se escribieron en `E:\…\.tools\resultados\`.
- En una ejecución anterior (la primera ejecución en MySQL, antes de la corrección) uno de esos archivos sí recibió el texto de la verificación de conexión: usuario, servidor, puerto y base, sin contraseña. La salida de las pruebas fue a E:.

**Incidencia del script de validación (resuelta):** los dos primeros intentos de `validacion-mysql-etapa2.ps1` terminaron sin ejecutar pruebas.
- El segundo falló porque el script estaba en UTF-8 sin BOM y Windows PowerShell 5.1 lo lee como ANSI: un carácter acentuado rompía una cadena. Se resolvió dejando el script en ASCII.
- La causa exacta del primero no quedó registrada. Desde entonces el script espera a que el servidor acepte conexiones y registra cualquier excepción.
- Ningún intento llegó a ejecutar pruebas ni operaciones contra la base antes de verificar el destino.

## 10. Riesgos y pendientes para la Etapa 3

1. **Arqueo de caja por método de pago (§7.2), prioritario.** `CajaController` debe calcular el efectivo esperado solo con los pagos `EFECTIVO` `recibido` de esa caja (`pagos.caja_id`), restar los reembolsos entregados en efectivo y mostrar por separado tarjeta, transferencia y QR.
2. **Apertura de caja para encargado y vendedor.** Hoy solo puede abrirla el administrador, así que el vendedor no puede cobrar sin una caja abierta por otra persona.
3. **Permisos desde la base de datos.** Las habilidades se leen de `config/access.php`. `rol_permiso` se siembra, pero todavía no es la fuente de autorización en tiempo de ejecución.
4. **Defecto corregido de la Fase 1, para dejar constancia.** `AccessControl` leía `config("access.abilities.{$ability}")` y la notación de puntos rompía todas las habilidades con punto: se denegaban a todos, incluido el administrador. Se corrigió en la Etapa 2 y lo cubre `HabilidadesPorRolTest`.
5. **Prueba manual en el entorno *sandbox* de PayPal.** Toda la integración se validó con `Http::fake` (simulador `PayPalFalso`). Falta una prueba manual con credenciales de *sandbox*, nunca de producción, antes de usarlo con dinero real.
6. **Ventas antiguas sin registro en `pagos`.** Sus reembolsos deben hacerse manualmente.
7. **Código obsoleto.** `PagoExterno` y `PayPalOrderVerifier` siguen en `Modules/Sales` sin uso; conviene retirarlos en una etapa con autorización expresa.
8. **Validación nueva en el punto de venta.** `productos.*.id` ahora es `distinct`: un mismo producto no puede repetirse en dos líneas de una venta.
9. **Pruebas Breeze heredadas.** Las 21 que fallan están pendientes de la Etapa 5.
10. **Lógica de reservas.** Solo existe el esquema y el recálculo de `estado_pago`; la confirmación y la asignación de equipos son de una etapa posterior.
11. **Otros modelos con clave asignada.** Conviene revisar si hay más modelos heredados con clave primaria sin AUTO_INCREMENT y `$incrementing` sin declarar, el mismo patrón que `Producto`. No se revisó en esta validación, para no ampliar el alcance.

---

**Reproducir la validación:**
- SQLite: `.tools\ejecutar-pruebas.ps1 -Motor sqlite -Ruta 'Modules/Finance/tests'` y `-Ruta 'tests/Feature/Instalacion'`.
- MySQL: `.tools\validacion-mysql-etapa2.ps1`. Arranca el servidor, verifica el destino, ejecuta ambas suites, guarda todo en `.tools\resultados\` y detiene el servidor.
