# Informe de validación: Etapa 3 (Operación financiera y gestión de caja)

**Proyecto:** `E:\BadiStudio\sound-management-app`
**Fechas:** 2026-11-21 y 2026-11-22
**Base:** `ETAPA_3_DIAGNOSTICO_CAJA.md` (aprobado), con las decisiones D1–D8.

> **Estado:** se implementaron los 8 pasos del plan. En **SQLite y MySQL**, Finanzas pasa **144 de 144** y la Instalación **50 de 50**, sin fallos ni pruebas omitidas.
> - `composer.json` y `composer.lock` no cambiaron.
> - Ninguna de las 37 migraciones existentes se modificó.
> - No se hizo ninguna operación de Git.
>
> La aprobación de la etapa queda pendiente de la revisión de este informe.

---

## 1. Resumen

| Alcance | Resultado |
|---|---|
| 1. Arqueo por método, ligado a su caja | El efectivo esperado se calcula solo con registros de su `caja_id`: fondo + cobros en efectivo − reembolsos en efectivo + ingresos − egresos. Tarjeta, transferencia, QR y PayPal se informan por separado y no entran al efectivo. Corrige E1, E2 y E8 del diagnóstico |
| 2. Reembolsos en el arqueo | Solo restan los entregados **en efectivo**, desde la caja abierta de quien los entrega (D7). Los de tarjeta, transferencia o QR se registran con su medio y no restan. Corrige E5 |
| 3. Apertura y cierre propios | Administrador, encargado y vendedor abren y cierran su propia caja. Administrador y encargado pueden hacer un **cierre supervisado** de una caja ajena (D2). Corrige E3, E4, E6 y E7 |
| 4. Historial y auditoría | `caja_eventos`, de solo inserción, registra titular, responsable e IP de aperturas, cierres, ingresos, egresos, reversiones, correcciones, reembolsos entregados e intentos rechazados. Además se escribe una entrada en la bitácora general. Corrige E11 |
| 5. Reportes y conciliación | 7 reportes en pantalla y CSV (D8); PDF solo para el corte. Detectan pagos recibidos sin venta, transferencias y QR pendientes, pagos en `verificando` de más de 24 h, ventas sin pago, reembolsos sin entregar o inciertos, y cajas con diferencia |

## 2. Cómo se aplicaron las decisiones aprobadas

| Decisión | Implementación | Prueba que lo demuestra |
|---|---|---|
| **D1.** Alquileres heredados fuera del arqueo, con aviso | `ArqueoService::alquileresHeredados()` lista los alquileres del titular dentro del horario de la caja (método, total estimado y real, garantía, estado). Aparecen en pantalla, en la foto del cierre y en el PDF, y **no** suman al efectivo | `ArqueoCajaTest::test_alquileres_heredados_se_listan_pero_no_entran_al_efectivo` |
| **D2.** Cierre supervisado por administrador y encargado | Permiso `cash.close_any`. La caja **conserva su titular** (`user_id`) y registra al responsable (`ci_cerrado_por`) y `cierre_supervisado = true`. Las dos personas quedan en la foto (`titular`, `cerrado_por`) y en el evento (`ci_titular`, `ci_responsable`). La observación es obligatoria | `AperturaCierreCajaTest::test_cierre_supervisado_identifica_titular_y_responsable`, `test_el_administrador_tambien_cierra_cajas_ajenas` |
| **D3.** El vendedor registra ingresos pero no egresos | `cash.movements.income` (admin, encargado, vendedor) y `cash.movements.expense` (admin, encargado). El egreso no puede dejar negativo el efectivo esperado | `MovimientosCajaTest::test_el_vendedor_registra_ingresos_de_cambio_pero_no_egresos`, `test_el_encargado_registra_egresos_sin_dejar_saldo_negativo` |
| **D4.** Sin reaperturas; correcciones con movimientos referenciados | No hay ninguna ruta ni método para reabrir. Una caja cerrada y su foto no se modifican. Las correcciones son movimientos **nuevos**: `revierte_id` (movimiento inverso que referencia al original, una sola vez por movimiento) y `caja_corregida_id` (corrección de una caja cerrada, registrada en una caja abierta). El original queda **intacto, columna por columna** | `MovimientosCajaTest::test_reversion_en_caja_abierta_preserva_el_original`, `test_reversion_de_una_caja_cerrada_va_en_otra_caja_y_la_referencia`, `test_correccion_referenciada_de_una_caja_cerrada`; `AperturaCierreCajaTest::test_el_cierre_es_idempotente_y_no_se_reabre` |
| **D5.** Observación obligatoria ante cualquier diferencia | `CajaService::cerrar` rechaza el cierre si la diferencia no es 0 y no hay observación | `test_diferencia_sin_observacion_se_rechaza` |
| **D6.** Cierre con transferencias o QR pendientes, mostrados aparte | Los pendientes quedan en la foto y en el PDF. Si se verifican después, **la foto no cambia** y siguen visibles en la conciliación | `test_cierre_con_transferencia_pendiente_y_verificacion_posterior_no_altera_la_foto` |
| **D7.** El reembolso en efectivo sale de la caja de quien lo entrega | `ReembolsoService::confirmarEntrega(…, $medio)` exige y bloquea la caja abierta del que entrega, y registra `caja_id`, `medio_devolucion`, `ci_entregado_por` y `entregado_at`. Confirmar dos veces no resta dos veces | `ReembolsoEnCajaTest` (8 pruebas) |
| **D8.** Pantalla y CSV; PDF solo para el corte | `/finanzas/reportes` y `/finanzas/reportes/csv`; `caja.reporte` es el único PDF | `ReportesFinancierosTest` (9 pruebas) |

## 3. Ajustes respecto al diagnóstico (explícitos)

Ninguno contradice una decisión aprobada. Los dejo a la vista para la revisión.

1. **Permiso de movimientos dividido en dos.** El diagnóstico (§6) preveía un único `cash.movements.register`. Como D3 permite ingresos pero no egresos al vendedor, quedaron `cash.movements.income` y `cash.movements.expense`. Las correcciones de cajas cerradas exigen `cash.movements.expense`.
2. **Tope en el reembolso en efectivo.** No se puede entregar más efectivo del que espera la caja de quien lo entrega. Es la misma regla que §4.3 fija para los egresos, aplicada a otra salida de efectivo.
3. **Cajas abiertas al migrar.** `version_arqueo` vale 1 por defecto, como decía el diagnóstico; el servicio nuevo fija 2 al abrir y al cerrar. Una caja que esté abierta al migrar se cierra con el arqueo nuevo, según R2.
4. **Prueba de migraciones sin `DatabaseMigrations`.** Ese *trait* revierte **todas** las migraciones al terminar cada prueba. Los `down()` de etapas anteriores usan `dropForeign('nombre')`, que SQLite no admite («does not support dropping foreign keys by name»), y no se pueden modificar. Por eso `MigracionCajasTest` hace su propio `migrate:fresh --seed` y revierte solo las 5 migraciones de esta etapa. Las migraciones nuevas usan `dropForeign(['columna'])`, que sí funciona en ambos motores.
5. **Dos pruebas existentes ajustadas** por el cambio de comportamiento aprobado:
   - `ReembolsoTest::test_reembolso_en_caja_se_completa_al_confirmar_la_entrega`: el administrador abre su caja antes de entregar efectivo (D7). Se añadieron aserciones de `medio_devolucion` y `caja_id`.
   - El ayudante `EscenarioFinanzas::abrirCaja()` reutiliza la caja abierta del usuario, porque la base ya impide tener dos abiertas.
6. **`CajaController` heredado de Sales:** se conserva sin rutas (mismo criterio que `PagoExterno`). Su vista PDF (`admin/caja/reporte.blade.php`) se sigue usando, sin cambios, para el corte de las cajas heredadas.

## 4. Archivos

### 4.1 Creados

| Archivo | Paso |
|---|---|
| `Modules/Finance/database/migrations/2026_11_23_000001_add_arqueo_to_cajas_table.php` | 1 |
| `Modules/Finance/database/migrations/2026_11_23_000002_create_movimientos_caja_table.php` | 1 |
| `Modules/Finance/database/migrations/2026_11_23_000003_add_caja_to_reembolsos_table.php` | 1 |
| `Modules/Finance/database/migrations/2026_11_23_000004_create_caja_eventos_table.php` | 1 |
| `Modules/Finance/database/migrations/2026_11_23_000005_marcar_cajas_heredadas.php` | 1 |
| `Modules/Finance/app/Models/MovimientoCaja.php`, `CajaEvento.php` | 2 |
| `Modules/Finance/app/Support/ResultadoArqueo.php` | 2 |
| `Modules/Finance/app/Services/ArqueoService.php` | 2 |
| `Modules/Finance/app/Services/CajaService.php` | 3, 5, 6 |
| `Modules/Finance/app/Http/Controllers/Admin/CajaController.php` | 3, 5, 6 |
| `Modules/Finance/resources/views/caja/index.blade.php`, `caja/show.blade.php`, `caja/corte-pdf.blade.php`, `caja/partials/arqueo.blade.php` | 3, 6 |
| `Modules/Finance/app/Services/ReporteFinancieroService.php` | 7 |
| `Modules/Finance/app/Http/Controllers/Admin/ReporteFinancieroController.php` | 7 |
| `Modules/Finance/resources/views/reportes/index.blade.php` | 7 |
| Pruebas en `Modules/Finance/tests/Feature/`: `MigracionCajasTest`, `ArqueoCajaTest`, `AperturaCierreCajaTest`, `ReembolsoEnCajaTest`, `MovimientosCajaTest`, `AuditoriaCajaTest`, `ReportesFinancierosTest` | 1–7 |
| `.tools/validacion-mysql-etapa3.ps1` (herramienta reproducible) y `.tools/resultados/e3-*`, `huellas-migraciones-etapa3.txt` | 8 |
| `INFORME_ETAPA_3_VALIDACION.md` | 8 |

### 4.2 Modificados

| Archivo | Cambio |
|---|---|
| `Modules/Sales/app/Models/Caja.php` | Constantes; `fillable` y *casts* de las columnas nuevas; relaciones `cerradoPor`, `pagos`, `reembolsos`, `movimientos`, `correcciones` y `eventos`; bloqueo de eliminación |
| `Modules/Sales/routes/web.php` | Las rutas `caja.*` pasan al controlador de Finance, con `can:cash.operate_own` en lugar de `admin`. Se añaden `caja.show`, `caja.movimientos.store` y `caja.movimientos.revertir`; los nombres y URLs anteriores se conservan |
| `Modules/Finance/app/Models/Reembolso.php` | Constantes de medio de devolución; campos y relaciones `caja` y `entregadoPor` |
| `Modules/Finance/app/Services/ReembolsoService.php` | `confirmarEntrega(…, ?string $medio)` integrada a la caja (D7); `medioPorDefecto()` |
| `Modules/Finance/app/Http/Controllers/Admin/ReembolsoController.php` | Valida y pasa `medio_devolucion` |
| `Modules/Finance/app/Http/Controllers/Admin/PagoController.php` | Pasa el medio por defecto a la vista |
| `Modules/Finance/resources/views/pagos/show.blade.php` | Selector del medio de devolución al confirmar la entrega |
| `Modules/Finance/routes/web.php` | Rutas `finanzas.admin.reportes.index` y `.csv`, con `can:finance.reports.view` |
| `config/access.php` | 7 habilidades nuevas (§6) |
| `database/seeders/PermisoSeeder.php` | Descripciones de las 7 habilidades |
| `resources/views/components/admin-sidebar.blade.php` | «Caja» sale del bloque `@can('admin')` y pasa a `@can('cash.operate_own')`; nuevo enlace «Reportes financieros» con `@can('finance.reports.view')` |
| `Modules/Audit/app/Services/ReadOnlySqlGuard.php` | La IA no puede consultar `movimientos_caja` ni `caja_eventos` |
| `Modules/Audit/app/Http/Controllers/IaReportController.php` | Descripción de las columnas nuevas de `cajas` |
| `Modules/Finance/tests/Support/EscenarioFinanzas.php` | `abrirCaja()` reutiliza la caja abierta (§3.5) |
| `Modules/Finance/tests/Feature/ReembolsoTest.php` | Ajuste por D7 (§3.5) |
| `tests/Feature/Instalacion/EsquemaInstalacionTest.php`, `SeedersInstalacionTest.php` | Tablas nuevas (57 en la lista) |

**Sin cambios:** `composer.json`, `composer.lock`, las 37 migraciones existentes, la lógica de alquileres y el `CajaController` heredado.

## 5. Migraciones y tablas

| Migración | Contenido |
|---|---|
| `000001_add_arqueo_to_cajas_table` | Columnas nuevas en `cajas`:<br>• `version_arqueo` (1 heredado / 2 por método)<br>• `efectivo_esperado`, `efectivo_contado`<br>• `resumen` (JSON con la foto del cierre)<br>• `ci_cerrado_por` (FK)<br>• `cierre_supervisado`, `observaciones`<br>• `clave_cierre` (única; cierre idempotente)<br>Además, una columna generada `abierta_de` con **índice único**: la base impide dos cajas abiertas por usuario. CHECK `monto_apertura >= 0` en MySQL. **Se detiene sin cambiar nada** si ya hay aperturas negativas o cajas abiertas duplicadas |
| `000002_create_movimientos_caja_table` | Ingresos y egresos, de solo inserción: `revierte_id` (único, FK a sí misma), `caja_corregida_id`, `clave_idempotencia` única; CHECK `monto > 0` |
| `000003_add_caja_to_reembolsos_table` | Columnas nuevas en `reembolsos`: `caja_id`, `medio_devolucion`, `ci_entregado_por` y `entregado_at`, todas nulas. Los reembolsos anteriores quedan «sin caja»: no se inventa su asignación |
| `000004_create_caja_eventos_table` | Historial de solo inserción, con `ci_titular` y `ci_responsable` |
| `000005_marcar_cajas_heredadas` | `version_arqueo = 1` en las cajas ya cerradas. **No toca importes** |

- **Ciclo en MySQL** (`.tools/resultados/e3-final-mysql-resumen.txt`): `migrate:fresh --seed` (58 tablas) → `migrate:rollback --step=5` (56) → `migrate` (58) → `db:seed`, todo con código 0 y sin fallos.
- **Restricciones CHECK nuevas presentes:** `cajas_monto_apertura_check` y `movimientos_caja_monto_check`.
- **Cajas heredadas:** `MigracionCajasTest` comprueba, columna por columna, que una caja cerrada antes de migrar conserva sus importes.

## 6. Permisos nuevos

| Habilidad | Roles | Uso |
|---|---|---|
| `cash.operate_own` | admin, encargado, vendedor | Abrir, cerrar y ver la caja propia (rutas `caja.*`) |
| `cash.view_all` | admin, encargado | Ver todas las cajas |
| `cash.close_any` | admin, encargado | Cierre supervisado (D2) |
| `cash.movements.income` | admin, encargado, vendedor | Ingresos (D3) |
| `cash.movements.expense` | admin, encargado | Egresos y correcciones (D3) |
| `cash.movements.revert` | admin | Reversión correctiva |
| `finance.reports.view` | admin, encargado | Reportes y conciliación |

Técnico, almacenero y cliente no reciben ninguna. Los botones y los enlaces del menú usan el mismo permiso que su ruta, y eso está probado.

## 7. Resultados de las pruebas

Se usaron PHPUnit 11.5.55 y PHP 8.4.28 portátil. Los resultados están en `.tools/resultados/e3-*.txt` y `*.junit.xml`.

### 7.1 Por paso

| Paso | SQLite | MySQL |
|---|---|---|
| 1. Migraciones | `MigracionCajasTest` 7/7; regresión Finanzas 93/93 e Instalación 50/50 | Validado al cierre del paso 2 |
| 2. Modelos y arqueo | `ArqueoCajaTest` 10/10 | Ciclo de migraciones sin errores. Finanzas: 103 de 103 en las clases de los pasos 1–2 y anteriores. Las 13 de `AperturaCierreCajaTest`, escrita durante esa ejecución, fallaron porque sus rutas aún no existían. Instalación 50/50 |
| 3. Apertura y cierre | 13/13; regresión 116/116 y 50/50 | Validado en el paso 5 |
| 4. Reembolsos | `ReembolsoEnCajaTest` 8/8; `ReembolsoTest` 12/12; `PermisosFinanzasTest` 11/11 | Validado en el paso 5 |
| 5. Movimientos | 7/7 | **Finanzas 131/131, Instalación 50/50** |
| 6. Auditoría | 4/4 | Validación final |
| 7. Reportes | 9/9 | Validación final |

### 7.2 Validación final (paso 8)

| Suite | Motor | Pruebas | Fallos | Errores | Omitidas | Aserciones |
|---|---|---|---|---|---|---|
| Finanzas | SQLite | **144** | 0 | 0 | 0 | 632 |
| Finanzas | MySQL 8.4.13 (`sound_test@127.0.0.1:3310/sound_management_test`) | **144** | 0 | 0 | 0 | 633 |
| Instalación | SQLite | **50** | 0 | 0 | 0 | 191 |
| Instalación | MySQL | **50** | 0 | 0 | 0 | 191 |
| Completa | SQLite | 219 | 2 | 19 | 0 | 829 |

- **Una aserción más en MySQL:** la de `MigracionCajasTest::test_restricciones_check_en_mysql`, porque las CHECK solo existen en MySQL.
- **Suite completa:** sus 21 fallos son las mismas pruebas Breeze heredadas (`Auth\*`, `ProfileTest`, `ExampleTest`) documentadas desde la Etapa 2. **Ninguno es del código del proyecto.**

### 7.3 Desglose de Finanzas (idéntico en ambos motores)

| Clase | Pruebas | | Clase | Pruebas |
|---|---|---|---|---|
| AperturaCierreCajaTest | 13 | | PagoPresencialTest | 13 |
| ArqueoCajaTest | 10 | | PermisosFinanzasTest | 11 |
| AuditoriaCajaTest | 4 | | ReembolsoEnCajaTest | 8 |
| MigracionCajasTest | 7 | | ReembolsoTest | 12 |
| MovimientosCajaTest | 7 | | ReportesFinancierosTest | 9 |
| EsquemaFinanzasTest | 6 | | TipoCambioServicioTest | 8 |
| PagoEnLineaTest | 20 | | TransicionPagosExternosTest | 4 |
| WebhookPayPalTest | 7 | | DineroTest | 5 |

Son 58 pruebas nuevas y las 86 de la Etapa 2, que siguen pasando.

## 8. Integridad

| Comprobación | Resultado |
|---|---|
| `composer.lock` SHA-256 | `BF67396908FC1897DB675778EC14C98F73248F64C3EA952CFA63E827FAE09D0F`, ✅ idéntico |
| `composer.json` SHA-256 | `D74776677D2CC6BB219B66396DE1A7D49FF40F7DC2D376E9A941B13438879E01`, ✅ idéntico |
| Migraciones existentes | Comparadas con la línea base de la Etapa 2 (`huellas-migraciones.txt`): 37 de 37 idénticas, 0 modificadas, 0 ausentes. Las únicas nuevas son las 5 de esta etapa. Nueva línea base de 42 migraciones en `huellas-migraciones-etapa3.txt` |
| Bases de datos | Todas las ejecuciones en MySQL verificaron antes `sound_test@127.0.0.1:3310/sound_management_test`; el script aborta si difiere. El usuario de pruebas solo ve esa base. No hubo ninguna conexión a `sound_management_db` ni a otro servidor. SQLite, en memoria |
| Dependencias | Ninguna nueva. Se usan DomPDF (ya instalado) y `fputcsv` nativo |
| Git | Ninguna operación |
| Otros proyectos | `E:\ferreteria` y `E:\BadiStudio\sound-management` no se tocaron |
| Estado final de MySQL | Detenido: 0 procesos `mysqld`, puerto 3310 sin escuchar, 0 procesos PHP |

**Disco C:** el proyecto y sus herramientas escribieron solo en E:. Aun así, la consola de Claude Code escribió por su cuenta en C: en dos situaciones:
- Crea un archivo de seguimiento por cada tarea en segundo plano (`C:\Users\Dj\AppData\Local\Temp\claude\…\tasks\`). Las ejecuciones de pruebas descartaron su salida (`*> $null`), así que esos archivos quedan vacíos.
- Tres bucles de espera superaron el límite de 10 min y la consola los pasó a segundo plano. Sus pocas líneas de salida se guardaron allí: horas, y el resumen de resultados con usuario, servidor, puerto y base, **sin contraseña**.

## 9. Incidencias durante la validación

- **El equipo se suspendió dos veces** con MySQL en marcha (≈21:06–05:47 y ≈06:10–10:49). Las suites se reanudaron solas y terminaron sin errores, pero los tiempos que reporta PHPUnit incluyen esas pausas (por ejemplo, 32 787 s en el paso 5).
- **El DDL de MySQL es lento en este disco** (3–7 s por sentencia en la validación final). `MigracionCajasTest` hace un `migrate:fresh --seed` por prueba, así que domina el tiempo de Finanzas en MySQL (≈34 min en la validación final).

## 10. Riesgos y pendientes

1. **Alquileres heredados (D1).** Siguen fuera del arqueo; solo se avisan. Integrarlos con `pagos` (garantía, cobro y devolución) corresponde a la etapa de reservas y alquileres.
2. **Medio de los reembolsos de PayPal.** Los reembolsos ejecutados por PayPal no rellenan `medio_devolucion`. No afecta al arqueo, porque no tienen caja, pero en el reporte «Reembolsos entregados» aparecen como «sin medio registrado». Mejora sugerida: asignar `paypal` al completarlos.
3. **Permisos.** Se siguen leyendo de `config/access.php`; `rol_permiso` se siembra, pero aún no es la fuente de autorización (pendiente desde la Etapa 2).
4. **Concurrencia.** La serialización entre cobro y cierre se apoya en el bloqueo de la fila de la caja, el índice único `abierta_de` y la idempotencia. Las pruebas cubren las secuencias (un cobro después del cierre se rechaza, el doble cierre es idempotente), pero no hay una prueba con procesos realmente simultáneos.
5. **`down()` de etapas anteriores en SQLite.** Algunas migraciones de las Etapas 1 y 2 no se pueden revertir en SQLite (`dropForeign` por nombre). En MySQL funcionan. No se modificaron, por restricción.
6. **Código heredado sin uso.** `Modules/Sales/app/Http/Controllers/CajaController.php` y `resources/views/admin/caja/index.blade.php` se pueden retirar con autorización expresa.
7. **CSV.** Usa coma como separador y punto decimal. Si se abre en un Excel con configuración regional boliviana, puede requerir «Datos → Desde texto».
8. **Pruebas Breeze heredadas.** Las 21 que fallan siguen pendientes de la Etapa 5.
9. **PayPal *sandbox*.** Sigue pendiente una prueba manual con credenciales de *sandbox*, nunca de producción.

---

**Reproducir la validación:**
- SQLite: `.tools\ejecutar-pruebas.ps1 -Motor sqlite -Ruta 'Modules/Finance/tests'` (y `'tests/Feature/Instalacion'`).
- MySQL: `.tools\validacion-mysql-etapa3.ps1 -Prefijo <nombre>`. Arranca el servidor, verifica el destino, ejecuta el ciclo de migraciones y ambas suites, y detiene MySQL.
