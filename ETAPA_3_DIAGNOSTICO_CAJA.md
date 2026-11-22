# Etapa 3: Operación financiera y gestión de caja

## Diagnóstico técnico y plan de implementación

**Proyecto:** `E:\BadiStudio\sound-management-app`
**Fecha:** 2026-11-21
**Tipo:** solo diagnóstico. No se modificó código, migraciones, bases de datos, dependencias ni configuración, y no se hizo ninguna operación de Git.
**Punto de partida:** `INFORME_ETAPA_2_VALIDACION.md`, sobre todo §7.2.

---

## 1. Qué existe hoy

### 1.1 Caja (código heredado, módulo `Sales`)

| Pieza | Ubicación | Qué hace |
|---|---|---|
| Tabla `cajas` | `database/migrations/2026_05_11_191528_create_cajas_table.php` | `id`, `user_id` (CI), `monto_apertura`, `monto_cierre`, `diferencia`, `estado` (abierta o cerrada), `fecha_apertura`, `fecha_cierre` y marcas de tiempo |
| FK e índice | `2026_11_20_000002_ajustar_esquema_instalacion_limpia.php:73-77` | `user_id` → `usuario.ci` (*restrict*); índice `(user_id, estado)` |
| Modelo `Caja` | `Modules/Sales/app/Models/Caja.php` | `fillable` y relación `user()`; sin *casts* ni otras relaciones |
| `CajaController` | `Modules/Sales/app/Http/Controllers/CajaController.php` | `index`, `apertura`, `corte` (cierre) y `reporte` (PDF con DomPDF) |
| Rutas | `Modules/Sales/routes/web.php:57-61` | `/caja`, `/caja/apertura`, `/caja/corte/{caja}` y `/caja/reporte/{caja}`, todas tras el middleware **`admin`** |
| Vistas | `resources/views/admin/caja/index.blade.php` y `reporte.blade.php` | Listado, ventanas de apertura y corte, y PDF del corte |
| Menú lateral | `resources/views/components/admin-sidebar.blade.php:136` | Enlace «Caja» |

### 1.2 Lo que la Etapa 2 dejó preparado

- `pagos.caja_id` con FK a `cajas`. Todo cobro presencial queda **asociado a su caja**: `PagoService::registrarPresencial`, `PagoService.php:505-525`.
- El cobro presencial **bloquea la fila de la caja** (`lockForUpdate`) y exige que esté `abierta`. Es la base para serializar los cobros frente al cierre.
- Solo el método EFECTIVO rellena `pagos.monto_recibido_efectivo` y `pagos.cambio_entregado`.
- Los estados del dinero (`recibido`, `reembolsado_parcial`, `reembolsado`…) están separados de los de aplicación, y el historial es de solo inserción (`pago_eventos`).
- **Reembolsos presenciales:** `ReembolsoService::confirmarEntrega` marca `completado` y deja `entrega_confirmada_por` en el evento, pero **no registra la caja ni el medio por el que se devolvió el dinero**.
- **Bitácora genérica:** `Bitacora::registrar(accion, tabla, registro_id, descripcion)`, con usuario e IP. No es de solo inserción a nivel de modelo.
- **Configuración horaria coherente:** la aplicación usa `America/La_Paz`, MySQL `-04:00` y los aplicadores asignan `NotaVenta.fecha` con `now()`. No hay desfase horario.

### 1.3 Otros consumidores de la caja

- `AlquilerController@store` (`Modules/Rentals`, líneas 82-92) exige una caja abierta, pero **no registra ningún pago**. Guarda `metodo_pago_id`, `monto_garantia` y `total_estimado` en `alquileres`.
- `AlquilerController@registrarDevolucion` (líneas 183-254) calcula `total_real` al devolver el equipo, **sin cobro ni devolución de garantía registrados**.
- `IaReportController` describe la tabla `cajas` al modelo de IA. `ReadOnlySqlGuard` permite leer `cajas`.
- En las pruebas, `EscenarioFinanzas::abrirCaja()` crea filas de `cajas` directamente.

---

## 2. Errores encontrados

| # | Gravedad | Error | Ubicación | Efecto |
|---|---|---|---|---|
| E1 | **Alta** | El arqueo suma `NotaVenta.total` de todo lo que vendió el empleado desde la apertura, **sin filtrar por método de pago** | `CajaController.php:21-24, 82-85, 106-110` | Tarjeta, transferencia y QR verificados entran al efectivo esperado y producen un faltante ficticio (§7.2 del informe) |
| E2 | **Alta** | El arqueo se calcula por **empleado y fecha**, no por `caja_id` | Mismas líneas | Si un empleado cierra y reabre el mismo día, o si una venta se aplica más tarde (transferencia verificada después), la venta cae en la caja equivocada. Las ventas sin pago ligado se cuentan igual |
| E3 | **Alta** | El corte **no verifica quién cierra**: cualquier usuario con el Gate `admin` puede cerrar la caja de otro, y no queda registrado quién lo hizo | `CajaController@corte` | No hay responsable del cierre |
| E4 | **Alta** | El corte **no bloquea la fila ni usa transacción**: comprueba `estado` y luego hace `update` | `CajaController.php:77-98` | Un cobro concurrente puede entrar entre el cálculo y el cierre y quedar fuera del arqueo. Un doble envío del formulario puede cerrar dos veces y sobrescribir `monto_cierre` y `diferencia` |
| E5 | **Alta** | **Los reembolsos no entran al arqueo**: el efectivo devuelto en caja no se resta | `reembolsos` no tiene `caja_id` ni `medio_devolucion` | Sobrante ficticio igual a lo devuelto |
| E6 | Media | **Solo el administrador puede abrir caja** (middleware `admin`), aunque vendedor y encargado tienen `finance.payments.register` y `sales.operate` | `Sales/routes/web.php:57` | El vendedor no puede cobrar sin que el administrador abra una caja **a nombre del administrador**. Hoy el cobro exige una caja abierta **del propio cajero**, así que el vendedor queda bloqueado |
| E7 | Media | `index` lista **todas** las cajas y calcula el esperado con la fórmula de E1 | `CajaController@index` | Con la apertura habilitada para vendedores, cada uno vería las cajas de los demás |
| E8 | Media | Aritmética en coma flotante (`$request->monto_real - $saldo_esperado`) | `CajaController.php:90-91` | Diferencias de centavos; el resto de Finanzas usa `Dinero` en centavos enteros |
| E9 | Media | `try { … } catch (\Exception) { $ventas = 0; }` | Las tres consultas | Ante un error de la base, el arqueo muestra 0 ventas en silencio y la diferencia sale enorme |
| E10 | Baja | El PDF del corte se descarga por el id de la caja **sin comprobar a quién pertenece** | `CajaController@reporte` | Hoy lo limita el middleware `admin`; al abrirlo a otros roles, cualquiera podría descargar cortes ajenos |
| E11 | Baja | No hay apertura ni cierre auditados (ni bitácora ni eventos) | `CajaController` | No hay historial de responsables |
| E12 | Baja | No existen **ingresos ni egresos manuales** (pagar un flete, retirar efectivo a bóveda, agregar cambio) | — | Cualquier salida de efectivo real aparece como faltante |

---

## 3. Riesgos

| Riesgo | Detalle | Mitigación propuesta |
|---|---|---|
| R1. Datos históricos de `cajas` | Las cajas ya cerradas tienen `diferencia` calculada con la fórmula errónea | **No recalcularlas ni modificarlas.** Marcarlas como arqueo «heredado» (`version_arqueo = 1`) y mostrar su reporte antiguo tal cual. Solo las cajas nuevas usan el arqueo nuevo |
| R2. Una caja abierta durante el despliegue | Tendría cobros antes y después del cambio | Las columnas nuevas son nulas; al cerrarla, el arqueo nuevo usa `pagos.caja_id`, que ya existe desde la Etapa 2. Los cobros anteriores a la Etapa 2 no tienen pago: se avisa en el cierre |
| R3. Reembolsos ya completados sin caja | No se puede saber de qué caja salió el dinero | Quedan con `caja_id` nulo, como «sin caja» en los reportes. No se inventa la asignación |
| R4. Verificaciones posteriores al cierre | Una transferencia registrada en la caja A puede verificarse después de cerrar A | El cierre guarda una **foto fija** (totales por método y pendientes). Lo que se verifique después no modifica la foto; aparece en la conciliación |
| R5. Concurrencia cobro ↔ cierre | Ver E4 | El cierre bloquea la fila de la caja, como ya hace el cobro: se serializan. Un cobro posterior al cierre recibe «caja cerrada» |
| R6. Alquileres heredados | Mueven dinero (garantía y total) sin registro en `pagos` | **Requiere decisión (§7, D1).** Sin integrarlos, el arqueo no los verá |
| R7. Una caja abierta por usuario | Hoy solo lo garantiza la aplicación, con bloqueo y comprobación | Se mantiene ese bloqueo. Como refuerzo opcional, un índice único sobre una columna generada (`abierta_por = user_id si estado='abierta'`), compatible con MySQL 8 y SQLite |
| R8. Crecimiento del alcance | Los reportes pueden crecer indefinidamente | Se fija un conjunto cerrado de reportes (§5.5) |
| R9. Pruebas | Las pruebas que crean cajas directamente siguen siendo válidas si las columnas nuevas son nulas o tienen valor por defecto | Las migraciones solo añaden columnas nulas o con valor por defecto |

---

## 4. Diseño propuesto

### 4.1 Fuente de verdad del arqueo

El efectivo esperado de una caja se calcula **solo** con registros ligados a su `caja_id`:

```
efectivo_esperado = fondo de apertura
                  + Σ pagos EFECTIVO de la caja con dinero recibido
                      (estado ∈ {recibido, reembolsado_parcial, reembolsado})
                  − Σ reembolsos completados con medio_devolucion = efectivo y caja_id = la caja
                  + Σ ingresos manuales de la caja
                  − Σ egresos manuales de la caja
```

- Los pagos **reembolsados** se cuentan por su importe original y el reembolso se resta aparte. Así el reembolso no se descuenta dos veces.
- Tarjeta, transferencia, QR y PayPal se muestran **por separado e informativamente**, sin entrar en el efectivo. Para cada método: recibido, pendiente de verificación y rechazado.
- Todo el cálculo se hace con `Dinero` (centavos enteros).

### 4.2 Reembolsos en caja

- Campos nuevos en `reembolsos`: `caja_id`, `medio_devolucion` (efectivo, tarjeta, transferencia, QR o PayPal), `ci_entregado_por` y `entregado_at`.
- Para confirmar la entrega **en efectivo** hay que tener **la caja propia abierta**. El reembolso se asocia a esa caja (la de quien entrega el dinero, que puede no ser la caja del cobro original).
- Una devolución por tarjeta (anulación en el terminal) o por transferencia se registra con su medio y **no** resta efectivo.
- Los reembolsos de PayPal siguen como en la Etapa 2: `medio_devolucion = paypal`, sin caja.

### 4.3 Ingresos y egresos manuales

- Tabla nueva `movimientos_caja`, de **solo inserción**: el modelo impide editar y eliminar, como en `pago_eventos`.
- Campos: `tipo` (ingreso o egreso), `monto`, `motivo` (obligatorio), `ci_registrado_por` y `clave_idempotencia` (única).
- Una corrección no edita el movimiento: se registra un movimiento inverso que **referencia** al original.
- El egreso no puede dejar el efectivo esperado por debajo de 0.

### 4.4 Apertura y cierre

| Acción | Administrador | Encargado | Vendedor |
|---|---|---|---|
| Abrir caja propia | ✅ | ✅ | ✅ |
| Cerrar caja propia | ✅ | ✅ | ✅ |
| Ver cajas propias | ✅ | ✅ | ✅ |
| Ver todas las cajas | ✅ | ✅ | ❌ |
| Cerrar caja ajena (cierre supervisado) | ✅ | (D2) | ❌ |
| Registrar ingreso o egreso | ✅ | ✅ | (D3) |
| Reportes y conciliación | ✅ | ✅ | ❌ |

**El cierre:**
- Se hace en una transacción con la fila de la caja bloqueada y es idempotente: si la caja ya está cerrada, devuelve el mismo resultado.
- Guarda una **foto** en columnas nuevas de `cajas`: `efectivo_esperado`, `efectivo_contado`, `diferencia`, `resumen` (JSON con los totales por método, los pendientes y la cantidad de operaciones), `ci_cerrado_por`, `cierre_supervisado`, `observaciones` y `version_arqueo = 2`.
- Si la diferencia no es cero, **las observaciones son obligatorias**.
- Se permite cerrar con transferencias o QR pendientes. Quedan listados en la foto y siguen en la conciliación.
- **Sin reapertura** de cajas cerradas (D4).

### 4.5 Auditoría

- Tabla nueva `caja_eventos`, de solo inserción: apertura, cierre, cierre supervisado, ingreso, egreso, reembolso entregado e intento rechazado (caja cerrada o ajena). Cada evento registra el responsable, la IP y los datos del momento.
- Además, una entrada en `bitacoras` por cada apertura y cierre, para que aparezcan en la bitácora general que ya existe.
- Los cobros ya quedan en `pago_eventos`: no se duplican.

### 4.6 Ubicación del código

- La lógica nueva va en el módulo **Finance**: `CajaService`, `ArqueoService`, `ConciliacionReporteService`, controladores `Admin/CajaController` y `Admin/ReporteFinancieroController`, y sus vistas.
- El modelo `Caja` sigue en `Modules/Sales`, porque lo usan `PagoService`, `AlquilerController` y las pruebas; moverlo rompería esas referencias. Se le añaden *casts* y relaciones.
- Las rutas `/caja/*` antiguas pasan a apuntar al controlador nuevo, conservando los nombres `caja.index`, `caja.apertura`, `caja.corte` y `caja.reporte`, para que no se rompan los enlaces del menú y las vistas. El `CajaController` heredado deja de usarse y no se elimina (mismo criterio que con `PagoExterno`).

---

## 5. Plan de implementación por pasos

Cada paso termina ejecutando sus pruebas en SQLite. MySQL se valida al cierre de los pasos 2, 5 y 8.

### Paso 1. Migraciones (todas nuevas y aditivas, en `Modules/Finance/database/migrations/`)

| Migración propuesta | Contenido |
|---|---|
| `2026_11_23_000001_add_arqueo_to_cajas_table` | Columnas en `cajas`: `efectivo_esperado`, `efectivo_contado`, `resumen` (json), `ci_cerrado_por` (FK a `usuario.ci`), `cierre_supervisado` (bool), `observaciones`, `version_arqueo` (tinyint, por defecto 1), `clave_cierre` (única y nula, para la idempotencia del cierre). CHECK `monto_apertura >= 0` en MySQL. Opcional: columna generada y única `abierta_por` (R7) |
| `2026_11_23_000002_create_movimientos_caja_table` | `movimientos_caja`, con FKs *restrict*, CHECK monto > 0, `clave_idempotencia` única y `revierte_id` (FK a sí misma) |
| `2026_11_23_000003_add_caja_to_reembolsos_table` | Columnas en `reembolsos`: `caja_id` (FK), `medio_devolucion` (enum, nulo), `ci_entregado_por` (FK) y `entregado_at` |
| `2026_11_23_000004_create_caja_eventos_table` | `caja_eventos`, de solo inserción |
| `2026_11_23_000005_marcar_cajas_heredadas` | Datos: `version_arqueo = 1` en las cajas **ya cerradas**. No toca importes. Su `down()` no destruye nada |

**Todas son reversibles.** No se modifica ninguna migración existente.

### Paso 2. Modelos y servicio de arqueo

- Modelos nuevos `MovimientoCaja` y `CajaEvento` (solo inserción). `Caja` recibe *casts*, relaciones (`pagos`, `reembolsos`, `movimientos`, `eventos`) y bloqueo de eliminación.
- `ArqueoService::calcular(Caja)` aplica la fórmula de §4.1 y devuelve `Dinero` por concepto y método.

### Paso 3. Apertura y cierre

- `CajaService::abrir` y `CajaService::cerrar`, con cierre supervisado e idempotencia.
- Rutas con permisos `cash.*` (§6) y la vista nueva de caja: estado actual, desglose por método y formulario de cierre con conteo.
- El PDF del corte se rehace con el desglose. Las cajas de `version_arqueo = 1` siguen con el PDF anterior.

### Paso 4. Reembolsos integrados

- `ReembolsoService::confirmarEntrega` recibe el medio de devolución y, si es efectivo, exige la caja propia abierta y la bloquea.
- Se ajusta la vista `pagos/show`: selector del medio de devolución.

### Paso 5. Ingresos y egresos manuales

- `CajaService::registrarMovimiento` y `revertirMovimiento`, con idempotencia, motivo obligatorio y saldo no negativo.

### Paso 6. Auditoría e historial

- Eventos de caja y bitácora en cada operación.
- Vista de historial de una caja: línea de tiempo con apertura, cobros, reembolsos, movimientos y cierre, con sus responsables.

### Paso 7. Reportes y conciliación

| Reporte | Contenido |
|---|---|
| Resumen por caja | Foto del cierre (o cálculo en vivo si está abierta), desglose por método, diferencia |
| Resumen por periodo | Totales por día, método y usuario, entre dos fechas |
| Diferencias | Cajas cerradas con diferencia ≠ 0, con observaciones y responsable |
| Conciliación de pagos | Pagos `recibido` sin venta (`requiere_revision`); ventas presenciales sin pago ligado; transferencias y QR pendientes con antigüedad; pagos `verificando` de más de 24 h |
| Conciliación de reembolsos | Reembolsos `autorizado` sin entregar, `incierto`, y en efectivo sin caja |
| Movimientos | Ingresos y egresos por caja y usuario |

- Formatos: HTML y **CSV**, generado con funciones nativas de PHP, sin dependencias nuevas. **PDF** para el corte, con DomPDF, que ya está instalado.
- `ReadOnlySqlGuard`: añadir `movimientos_caja` y `caja_eventos` a las tablas vetadas para la IA.

### Paso 8. Validación completa

- Suites de SQLite y MySQL (`127.0.0.1:3310/sound_management_test`).
- Huellas de `composer.json` y `composer.lock`, y comparación con `.tools/resultados/huellas-migraciones.txt` para demostrar que no se tocó ninguna migración existente.
- Informe `INFORME_ETAPA_3_VALIDACION.md`.

---

## 6. Permisos nuevos (`config/access.php` y `PermisoSeeder`)

| Habilidad | Roles por defecto |
|---|---|
| `cash.operate_own` | admin, encargado, vendedor: abrir, cerrar y ver la caja propia |
| `cash.view_all` | admin, encargado |
| `cash.close_any` | admin y, según D2, encargado |
| `cash.movements.register` | admin, encargado y, según D3, vendedor |
| `cash.movements.revert` | admin |
| `finance.reports.view` | admin, encargado |

Técnico, almacenero y cliente no reciben ninguna. Un rol personalizado no recibe nada por defecto.

---

## 7. Decisiones que necesito antes de implementar

| # | Decisión | Recomendación |
|---|---|---|
| **D1** | **Alquileres heredados.** Mueven garantía y total sin registro en `pagos`, así que el arqueo nuevo no los ve | **a)** Dejarlos fuera de esta etapa y mostrar en el cierre un aviso con los alquileres de la caja (importe y método), para que el cajero los cuente aparte. Su integración con `pagos` corresponde a la etapa de reservas y alquileres. Alternativas: b) registrarlos como movimientos manuales automáticos; c) integrarlos ahora con `pagos` (amplía mucho el alcance y toca la lógica de alquileres) |
| **D2** | ¿Puede el **encargado** cerrar la caja de otro (por ejemplo, un vendedor que se fue sin cerrar)? | **Sí**, como «cierre supervisado» con conteo y observación obligatorios, registrado como tal |
| **D3** | ¿Puede el **vendedor** registrar egresos manuales? | **No.** Solo ingresos de cambio; los egresos, administrador y encargado. Alternativa: que el vendedor registre el egreso y el encargado lo apruebe (añade un estado) |
| **D4** | ¿Reabrir una caja cerrada? | **No.** Un error del cierre se corrige con un movimiento en una caja abierta que referencie a la caja original, más una observación |
| **D5** | ¿Hace falta un **umbral de diferencia** que exija la aprobación del encargado? | Observación obligatoria con cualquier diferencia ≠ 0; **sin** aprobación adicional en esta etapa |
| **D6** | ¿Se puede **cerrar con transferencias o QR pendientes**? | **Sí**, listándolos en la foto del cierre. Siguen en la conciliación |
| **D7** | Reembolso en efectivo: ¿de qué caja sale? | De la **caja abierta de quien entrega el dinero**, que debe tenerla abierta |
| **D8** | Formatos de los reportes | HTML + CSV; PDF solo para el corte de caja |

---

## 8. Pruebas necesarias

### 8.1 Arqueo (SQLite y MySQL)

- **Mezcla de métodos:** efectivo 100, tarjeta 50, transferencia verificada 30, QR pendiente 20 y PayPal en línea 40 → efectivo esperado = fondo + 100. Cada método aparece en su línea.
- Un reembolso en efectivo de 25 confirmado en la caja → esperado − 25. Uno por tarjeta → sin cambio en el efectivo.
- **Pago reembolsado:** se cuenta su importe original y el reembolso se resta una sola vez (sin doble descuento).
- Un ingreso de 10 y un egreso de 15 → ±. Un egreso que deja el saldo negativo → rechazado.
- **Cajas distintas del mismo usuario el mismo día:** cada cobro cuenta solo en su `caja_id` (regresión de E2).
- Una transferencia registrada en la caja A y verificada después de cerrarla no cambia la foto de A y aparece en la conciliación.
- **Centavos:** 0,10 + 0,20 da exactamente 0,30, sin error de coma flotante.

### 8.2 Apertura y cierre

- Administrador, encargado y vendedor abren su propia caja. Técnico, almacenero y cliente reciben 403.
- No se puede abrir una segunda caja propia.
- Cerrar la caja propia guarda la foto completa y `version_arqueo = 2`. Repetir el cierre no cambia nada (idempotente).
- El vendedor no puede cerrar una caja ajena (403). El encargado y el administrador sí, como cierre supervisado, según D2.
- Con diferencia ≠ 0 y sin observación → rechazado.
- **Un cobro después del cierre → «caja cerrada»**, y el pago no se crea.
- **Cajas heredadas** (`version_arqueo = 1`): sus importes no cambian y su PDF antiguo sigue funcionando.

### 8.3 Reembolsos

- La entrega en efectivo sin caja propia abierta se rechaza.
- La entrega en efectivo queda con `caja_id` y `ci_entregado_por`.
- Repetir la confirmación no duplica la resta.

### 8.4 Auditoría

- Cada apertura, cierre, movimiento y entrega crea un evento en `caja_eventos` con su responsable.
- `caja_eventos` y `movimientos_caja` no se pueden editar ni eliminar.
- Revertir un movimiento crea uno nuevo y deja intacto el original.

### 8.5 Reportes y permisos

- El vendedor solo ve sus cajas; el listado no muestra cajas ajenas.
- Solo administrador y encargado acceden a los reportes.
- El CSV tiene columnas fijas e importes con dos decimales.
- La conciliación detecta los casos sembrados en la prueba.
- La IA no puede consultar `movimientos_caja` ni `caja_eventos`.
- Cada botón exige el mismo permiso que su ruta.

### 8.6 Migraciones (MySQL)

- Ciclo `migrate:fresh --seed` → `rollback` de las 5 nuevas → `migrate` sin errores.
- Con cajas cerradas sembradas antes de migrar: sus importes quedan idénticos y se marcan `version_arqueo = 1`.
- Las restricciones CHECK y las FKs están presentes.

### 8.7 Regresión

- Las 86 pruebas de Finanzas y las 50 de Instalación siguen pasando en ambos motores. Las de cobro presencial no cambian.

---

## 9. Archivos afectados (previsión)

**Nuevos (en Finance)**
- 5 migraciones.
- Modelos `MovimientoCaja` y `CajaEvento`.
- Servicios `CajaService`, `ArqueoService` y `ConciliacionReporteService`.
- Controladores `Admin/CajaController` y `Admin/ReporteFinancieroController`.
- Vistas de caja, historial, reportes y PDF del corte.
- Pruebas `ArqueoCajaTest`, `AperturaCierreCajaTest`, `ReembolsoEnCajaTest`, `MovimientosCajaTest`, `ReportesFinancierosTest` y `MigracionCajasTest`.

**Modificados**
- `Modules/Sales/app/Models/Caja.php`: *casts*, relaciones y bloqueo de eliminación.
- `Modules/Sales/routes/web.php`: las rutas `caja.*` pasan al controlador nuevo, con permisos `cash.*` en lugar de `admin`.
- `Modules/Finance/app/Services/ReembolsoService.php` (`confirmarEntrega`) y `ReembolsoController.php`.
- `Modules/Finance/routes/web.php` y `resources/views/pagos/show.blade.php`.
- `config/access.php` y `database/seeders/PermisoSeeder.php`.
- `resources/views/components/admin-sidebar.blade.php`: enlaces Caja y Reportes con `@can`.
- `Modules/Audit/app/Services/ReadOnlySqlGuard.php` e `IaReportController.php` (descripción del esquema).
- `tests/Feature/Instalacion/EsquemaInstalacionTest.php`, por las tablas nuevas.

**Sin cambios:** `composer.json`, `composer.lock`, las migraciones existentes y la lógica de alquileres (salvo que D1 diga otra cosa).

**Dependencias:** ninguna nueva. Se usan DomPDF, que ya está instalado, y PHP nativo para el CSV.
