# Etapa 4: diagnóstico de reservas y alquileres (ERP + POS)

**Proyecto:** `E:\BadiStudio\sound-management-app`
**Fecha:** 2026-11-25
**Estado:** diagnóstico final, con las decisiones D1–D8 aprobadas y las cinco correcciones (C1–C5) incorporadas. **La implementación espera una autorización expresa.**

> Para elaborar este diagnóstico no se modificó código, migraciones, bases de datos, dependencias ni configuración. No se ejecutaron pruebas ni operaciones Git. El único archivo creado es este documento.

**Reglas que se mantienen:**
- Se alquila solo por **paquetes** y solo por **horas**.
- La **confirmación es automática** según la disponibilidad, e **independiente del pago**.
- Los equipos se reservan **al confirmar**, nunca durante el *checkout*.
- El ERP y el POS **comparten** inventario, clientes, ventas, pagos y cajas, sin duplicar funcionalidades.

---

## 1. Estado actual

### 1.1 Lo que ya sirve

| Área | Estado |
|---|---|
| Finanzas (Etapas 2 y 3) | Está completa y probada: 144 de 144 en SQLite y MySQL. Incluye `PagoService` (presencial y en línea), `ReembolsoService`, `CajaService`, `ArqueoService`, conciliación, reportes y eventos de solo inserción (`pago_eventos`, `caja_eventos`) |
| Esquema de reservas (`2026_11_20_000003`) | `paquetes`, `paquete_items`, `reservas` (`estado` y `estado_pago` separados), `reserva_paquetes` (precio congelado), `reserva_equipos` (índice `reserva_equipos_disponibilidad_index`) y `maquinarias.cantidad_total`. En MySQL tiene restricciones CHECK |
| Modelos de Rentals | `Paquete`, `PaqueteItem`, `Reserva`, `ReservaPaquete`, `ReservaEquipo`: solo relaciones y constantes |
| `AplicadorReserva` | Recalcula únicamente `reservas.estado_pago` y nunca cambia `reservas.estado` |
| `PagoService::iniciarEnLinea(..., ?int $reservaId)` | Ya acepta una reserva como destino del pago |
| POS de productos | `VentaController::store` cobra con `PagoService::registrarPresencial` y exige la caja abierta |

### 1.2 Problemas encontrados

| # | Problema | Ubicación | Gravedad |
|---|---|---|---|
| P1 | **El alquiler heredado incumple las reglas.** Alquila equipos sueltos, no paquetes; admite tarifa por **día**; bloquea el equipo al registrarlo e ignora `cantidad_total` | `Modules/Rentals/app/Http/Controllers/AlquilerController.php` (`store`, `registrarDevolucion`) | Alta |
| P2 | **La disponibilidad depende de un indicador global.** `maquinarias.estado` (disponible / alquilado / mantenimiento) no tiene intervalo ni unidades. Con `cantidad_total > 1`, una sola unidad alquilada bloquea todas | `AlquilerController`, `MaquinariaController`, `CategoriaController` | Alta |
| P3 | **El alquiler heredado no registra dinero en `pagos`** (ni la garantía ni el total), así que el arqueo no lo ve; solo se avisa (D1 de la Etapa 3) | `AlquilerController`, `ArqueoService::alquileresHeredados` | Alta |
| P4 | **Las reservas no tienen lógica:** no hay servicios, controladores, rutas, vistas, permisos ni pruebas. `Modules/Rentals/tests/` está vacío | Rentals | Alta (alcance) |
| P5 | **`AplicadorReserva` no tiene pruebas** y ningún flujo crea pagos con `reserva_id` | `Modules/Finance/tests` | Media |
| P6 | **Un depósito de garantía contaría como pago.** `AplicadorReserva` suma **todos** los pagos de la reserva, así que la garantía inflaría `estado_pago` | `AplicadorReserva::recalcular` | Alta (diseño) |
| P7 | **Los mantenimientos apuntan a `producto`, no a `maquinarias`**, y por eso no pueden descontar unidades de la disponibilidad | Migración `2026_06_22_000007` | Media |
| P8 | **No hay una tarea programada para vencer reservas.** `routes/console.php` está vacío; solo Finanzas programa `finanzas:conciliar` | `routes/console.php`, `RentalsServiceProvider` | Media |
| P9 | **No hay tablas para entregas, devoluciones, garantías, incidencias, cargos, bloqueos ni eventos de la reserva** | — | Alta (alcance) |
| P10 | **Devolver una garantía en mostrador es lento:** el circuito actual (solicitar → autorizar → entregar) necesita al administrador | `config/access.php` (`finance.refunds.*`) | Media |
| P11 | **Hay dos inventarios:** `producto` (stock de venta) y `maquinarias` (flota de alquiler). No es un error, pero se fija como regla del ERP | Inventory / Rentals | Baja |
| P12 | **Los permisos se leen de `config/access.php`;** `rol_permiso` se siembra pero no se usa (pendiente desde la Etapa 2) | — | Baja |

---

## 2. Línea base de migraciones (C5)

| Momento | Migraciones | Fuente |
|---|---|---|
| Fin de la Etapa 2 | **37** | `.tools/resultados/huellas-migraciones.txt` |
| La Etapa 3 añade | **5**: `2026_11_23_000001_add_arqueo_to_cajas_table`, `000002_create_movimientos_caja_table`, `000003_add_caja_to_reembolsos_table`, `000004_create_caja_eventos_table` y `000005_marcar_cajas_heredadas` (todas en `Modules/Finance`) | `INFORME_ETAPA_3_VALIDACION.md` §4.1 y §8 |
| Línea base vigente | **42** | `.tools/resultados/huellas-migraciones-etapa3.txt` |
| La Etapa 4 añadirá | **8** (§7) | Este documento |
| Al terminar la Etapa 4 | **50** | Nueva línea base: `huellas-migraciones-etapa4.txt` |

**Verificación del 2026-11-25 (solo lectura, sin escribir archivos):**
- El proyecto tiene **42** migraciones: 31 en `database/migrations` y 11 en `Modules/Finance/database/migrations`. Los demás módulos no tienen ninguna.
- Al comparar las dos líneas base, la única diferencia son esas 5 migraciones. Las otras 37 conservan su hash.
- El hash SHA-256 de los 42 archivos actuales es **idéntico** al registrado en `huellas-migraciones-etapa3.txt`: ninguno cambió, falta ni sobra.
- La cifra «37» del informe de la Etapa 3 se refiere a las migraciones que existían **antes** de esa etapa. No contradice las 42.

**Regla de la Etapa 4:** ninguna de las 42 migraciones se modifica. Al terminar se comprobará que los 42 hashes siguen intactos y que solo aparecen las 8 nuevas.

---

## 3. Arquitectura propuesta

```
        ┌──────────── Canales (solo controladores y vistas) ────────────┐
        │ ERP (admin/encargado) │ POS mostrador │ Portal cliente │ Catálogo │
        └───────────┬───────────────────┬───────────────┬──────────┘
                    ▼                   ▼               ▼
Rentals/Core ── DisponibilidadService · CotizadorReserva · ReservaService
                EntregaService · GarantiaService · IncidenciaService
                (EstadoReserva con su mapa de transiciones, reserva_eventos)
                    │ solo a través de servicios
Finance ─────── PagoService · AplicadorReserva · ReembolsoService · CajaService · ArqueoService
Access/Sales ── Usuario/Cliente · MetodoPago · Caja · búsqueda y alta rápida de clientes
Audit ───────── Bitacora
```

**Principio:** cada operación existe una sola vez, en su servicio. El ERP, el POS y el portal son canales que llaman al mismo `ReservaService`. Así comparten el inventario (`maquinarias`), los clientes (`usuario` / `cliente`), los pagos, las cajas y los reembolsos.

### 3.1 Ciclo de la reserva (el `enum` actual no cambia)

```
crear (pendiente, sin equipos) ─► confirmación automática (maquinarias FOR UPDATE)
   ├─ hay disponibilidad ─► confirmada + reserva_equipos «reservado»
   └─ no hay ────────────► rechazada (con el motivo y los equipos que faltan)   [D2]
confirmada ─► entregar ─► en_curso ─► devolver ─► liquidar ─► finalizada
confirmada / pendiente ─► cancelada (libera equipos) · pendiente vencida ─► expirada
```

- **El pago es independiente:** los anticipos, los saldos y el pago en línea solo cambian `estado_pago`.
- **«Nunca durante el *checkout*»:** confirmar es un paso propio, que se dispara al enviar la solicitud de reserva. Iniciar o capturar un pago de PayPal nunca crea `reserva_equipos`.
- **Devuelta pero sin liquidar** (incidencias o garantía pendientes) = `en_curso` más el registro de devolución (`devuelta_at`). No se añade un estado: cambiar un `enum` en SQLite obliga a reconstruir la tabla.

### 3.2 Disponibilidad con margen de preparación (C1, D4)

**Margen:** **M = 60 minutos** por defecto, configurable en el archivo nuevo `config/rentals.php`. Se aplica **antes y después de cada reserva** para que haya tiempo de preparar, transportar y revisar los equipos entre reservas consecutivas.

Los intervalos son semiabiertos `[inicio, fin)`. Una reserva existente `[s, e)` **choca** con una solicitud `[a, b)` si:

```
s − M < b   Y   e + M > a
```

Equivale a exigir, para cada unidad, al menos M minutos libres entre el fin de una reserva y el inicio de la siguiente, en ambos sentidos.

| Ejemplo (M = 60) | Resultado |
|---|---|
| Existente 10:00–14:00 y nueva 15:00–18:00 | Permitida: hay justo 60 min |
| Existente 10:00–14:00 y nueva 14:30–18:00 | Choca: solo 30 min después |
| Existente 15:00–18:00 y nueva 10:00–14:30 | Choca: solo 30 min antes |
| Existente 10:00–14:00 y nueva 08:00–09:00 | Permitida: 60 min antes |

**Fórmula de disponibilidad:**

```
disponible(m, a, b) = cantidad_total(m)
  − Σ reserva_equipos «reservado» de m que chocan con [a, b) aplicando el margen M
  − Σ alquileres heredados «activo» o «atrasado» que incluyen m   (solo durante la transición, D1;
                                                                     su fin se toma como «hasta que se devuelva»)
  − Σ maquinaria_bloqueos activos de m que se solapan con [a, b)    (mantenimiento o daño; sin margen)
```

**Detalles:**
- `reserva_equipos` guarda el intervalo **real** de la reserva. El margen se aplica en la consulta, así que un cambio de configuración afecta solo a las confirmaciones nuevas, nunca a las ya confirmadas.
- El mismo margen se aplica al comprobar la **prolongación por devolución tardía** (§3.4).
- El índice existente `(maquinaria_id, estado, fecha_inicio, fecha_fin)` sirve para la consulta ampliada: solo cambian los límites.

### 3.3 Concurrencia y bloqueo

La confirmación se hace dentro de una transacción:
1. Bloquear las filas de `maquinarias` afectadas con `lockForUpdate`, **ordenadas por id** para evitar interbloqueos.
2. Volver a calcular la disponibilidad dentro del bloqueo.
3. Insertar las filas de `reserva_equipos`, pasar a `confirmada`, registrar el evento y confirmar la transacción.

La segunda transacción espera el bloqueo y, al obtenerlo, ve las unidades ya reservadas. Si no alcanzan, la rechaza. La prueba real está en §10.2 (C3).

### 3.4 Dinero: el alquiler separado de la garantía (C2)

| Movimiento | Registro | Efecto en `estado_pago` | Caja y arqueo | Reportes |
|---|---|---|---|---|
| Cobro del alquiler (anticipo o saldo) | `pagos` con `concepto = alquiler` → `AplicadorReserva` | **Sí** | Línea «Cobros de alquiler», por método | Ingresos por alquiler |
| Depósito de garantía en dinero | `pagos` con `concepto = garantia` y `reserva_garantias` | **No** | Línea propia **«Garantías recibidas»**, por método | Sección propia de garantías |
| Devolución de la garantía | Reembolso **del pago de garantía** (`ReembolsoService`, regla D7: sale de la caja de quien entrega) | **No** | Línea propia **«Garantías devueltas»** | Garantías devueltas |
| Retención de la garantía (total o parcial) | `reserva_garantias.monto_retenido`, ligada a una incidencia | **No** | Sin movimiento: el dinero ya estaba en caja | Garantías retenidas, con su incidencia |
| Horas extra, daños o faltantes | `reserva_cargos` → `reservas.total_cargos` | Sí (importe a cobrar = `total + total_cargos`) | Se cobran como alquiler | Cargos por concepto |
| Garantía con documento | `reserva_garantias` con `tipo = documento` y sin pago | No | No aplica | Documentos retenidos o devueltos |

**Reglas:**
- `AplicadorReserva` solo suma los pagos `concepto = alquiler` y solo resta sus reembolsos. Las garantías nunca cambian `estado_pago`.
- **Caja:** el efectivo de las garantías **sí** forma parte del efectivo esperado, porque está físicamente en el cajón. En pantalla, en la foto del cierre (`cajas.resumen`) y en el PDF del corte se muestra **aparte**: cobros de alquiler y ventas, garantías recibidas y garantías devueltas. Así el cajero sabe qué parte del efectivo no es ingreso.
- **Cajas cerradas:** las anteriores no cambian. Su foto ya está guardada y no contiene garantías, así que no hace falta otra `version_arqueo`.
- **Reportes financieros:** las garantías no se cuentan como ingresos. Tienen su propia sección: recibidas, devueltas, retenidas y pendientes de devolver.
- **Al confirmar la entrega de un reembolso** (D7), el servicio comprueba que el pago origen sea `concepto = garantia` para que aparezca en la línea correcta.

---

## 4. Decisiones aprobadas (D1–D8)

| # | Decisión aprobada |
|---|---|
| **D1** | **Alquiler heredado:** se congela el alta y se conservan la consulta, la devolución y el comprobante de los activos. Sus unidades cuentan como bloqueo hasta que se devuelvan. No se migran a reservas |
| **D2** | **Sin disponibilidad:** la reserva se **rechaza al instante**, indicando qué falta. `pendiente` solo existe durante la creación, con un `expira_at` de seguridad |
| **D3** | **Confirmadas sin pago:** no se cancelan automáticamente. Se avisan en un reporte X horas antes del inicio y el encargado decide |
| **D4** | **Margen de preparación:** configurable, con **60 min** por defecto, aplicado antes y después de cada reserva (§3.2, C1) |
| **D5** | **Tolerancia de devolución:** **15 min**. Pasado ese tiempo, cada hora iniciada se cobra al `precio_hora` congelado |
| **D6** | **Devolución de garantía:** vendedor, encargado y administrador pueden devolverla **íntegra** sin autorización previa (`rentals.deposits.return`). Retenerla, total o parcialmente, requiere encargado o administrador (`rentals.deposits.retain`) |
| **D7** | **Cancelación:** no hay penalidad automática en esta etapa. Si hay reembolso, sigue el circuito de reembolsos actual |
| **D8** | **Daño:** crea siempre un **bloqueo de unidades** (`maquinaria_bloqueos`). Crear un mantenimiento es opcional, porque `mantenimientos` sigue ligado a `producto` |

---

## 5. Módulos reutilizables

| Pieza | Uso |
|---|---|
| `PagoService::registrarPresencial` | Anticipo, saldo y garantía en el POS (`snapshot.tipo = reserva`, con su `concepto`) |
| `PagoService::iniciarEnLinea($reservaId)` y `PagoEnLineaController` | Pagar la reserva desde el portal con PayPal (solo el alquiler) |
| `AplicadorReserva` | Se reutiliza con un filtro por `concepto` y el total `total + total_cargos` |
| `ReembolsoService` y `ReembolsoController` | Cancelaciones con reembolso y devolución de garantías |
| `CajaService`, `ArqueoService`, `ReporteFinancieroService` | Los cobros entran por `caja_id`. Se añaden las líneas de garantías y los reportes de reservas |
| `VentaController::buscarCliente` y `registrarCliente` | Búsqueda y alta rápida de clientes en el POS de reservas |
| `Dinero`, `MetodoPago`, `Bitacora`, DomPDF | Importes, métodos, auditoría, tickets y comprobantes |
| Patrón `*_eventos` y `clave_idempotencia` | `reserva_eventos` y protección contra el doble envío desde el POS |
| `config/access.php` y `PermisoSeeder` | Permisos nuevos |

---

## 6. Funcionalidades faltantes

1. **Paquetes en el ERP:** alta, edición y baja lógica; composición (`paquete_items`); horas mínimas y máximas; catálogo público.
2. **Consulta de disponibilidad** por paquete y rango horario, con margen y con el detalle de lo que falta.
3. **Creación de reservas** desde el ERP, el POS y el portal, con el precio congelado.
4. **Confirmación automática**, rechazo con motivo, cancelación, vencimiento programado y aviso de atrasos.
5. **Entrega:** checklist por equipo y unidades, responsable y hora real; la reserva pasa a `en_curso`.
6. **Devolución:** unidades y su estado (bien, dañado o faltante), horas extra y paso a la liquidación.
7. **Garantías:** en dinero o con documento; cobro, devolución y retención, separadas del alquiler (C2).
8. **Incidencias:** daño, faltante, retraso u otro tipo; generan un bloqueo y, de forma opcional, un mantenimiento y un cargo.
9. **Cobros:** anticipo, saldo, pago en línea y saldo pendiente visible.
10. **Tickets y comprobantes:** reserva, entrega, devolución y liquidación.
11. **Portal del cliente:** «Mis reservas», pagar y cancelar.
12. **Reportes:** ocupación por equipo, confirmadas sin pago (D3), atrasos, incidencias abiertas y garantías (recibidas, devueltas, retenidas y pendientes).
13. **Transición del alquiler heredado** (D1).

---

## 7. Migraciones nuevas (8; total, 50)

Todas son **nuevas**; ninguna de las 42 existentes se modifica.

| # | Ubicación | Migración | Contenido |
|---|---|---|---|
| 1 | Rentals | `add_operacion_to_reservas_table` | `canal` (erp, pos o portal), `total_cargos` (0 por defecto), `entregada_at`, `devuelta_at`, `liquidada_at`, `lugar_entrega` (nulo) y `clave_idempotencia` (única y nula) |
| 2 | Rentals | `create_reserva_eventos_table` | Historial de solo inserción: tipo, estado anterior y nuevo, responsable, datos JSON e IP |
| 3 | Rentals | `create_reserva_entregas_tables` | `reserva_entregas` (entrega o devolución, fecha, responsable, observaciones y clave única) y `reserva_entrega_detalles` (maquinaria, cantidad y estado: bien, dañado o faltante) |
| 4 | Rentals | `create_reserva_garantias_table` | Tipo (dinero o documento), monto, descripción, `pago_id`, `reembolso_id`, `monto_retenido`, estado e `incidencia_id` |
| 5 | Rentals | `create_reserva_incidencias_table` | Tipo, maquinaria, cantidad, descripción, costo, estado y `mantenimiento_id` (nulo) |
| 6 | Rentals | `create_reserva_cargos_table` | Concepto (horas extra, daño, faltante u otro), horas, monto, `incidencia_id` y clave única |
| 7 | Rentals | `create_maquinaria_bloqueos_table` | Maquinaria, cantidad, `desde`, `hasta` (nulo = indefinido), motivo, `mantenimiento_id`, `incidencia_id` e índice de disponibilidad. **`mantenimientos` no se toca** |
| 8 | Finance | `add_concepto_to_pagos_table` | `pagos.concepto` ENUM(alquiler, garantia), con `alquiler` por defecto, e índice (`reserva_id`, `concepto`). Los pagos existentes quedan como `alquiler` **sin cambiar ningún importe** |

- En MySQL se añaden CHECK: cantidades > 0, montos ≥ 0, `hasta > desde` y `monto_retenido ≤ monto`.
- Cada `down()` usa `dropForeign(['columna'])`, que se puede revertir en los dos motores.
- Antes de cambiar nada, cada migración comprueba la coherencia de los datos y se detiene si encuentra algún problema.

---

## 8. Archivos afectados

**Nuevos (Rentals)**
- `app/Enums/EstadoReserva.php` (con el mapa de transiciones)
- `app/Services/{DisponibilidadService, CotizadorReserva, ReservaService, EntregaService, GarantiaService, IncidenciaService}.php`
- `app/Exceptions/{SinDisponibilidad, TransicionReservaInvalida}.php`
- `app/Models/{ReservaEvento, ReservaEntrega, ReservaEntregaDetalle, ReservaGarantia, ReservaIncidencia, ReservaCargo, MaquinariaBloqueo}.php`
- `app/Console/{ExpirarReservas, MarcarAtrasos}.php`, programadas en `RentalsServiceProvider`
- `app/Http/Controllers/{Admin/PaqueteController, Admin/ReservaController, Pos/MostradorReservaController, Cliente/ReservaController, CatalogoPaqueteController}.php`
- Vistas: `resources/views/{paquetes, reservas, pos, cliente}/…` y los PDF
- `config/rentals.php` (archivo nuevo del módulo: margen de 60 min y tolerancia de 15 min)
- Las 7 migraciones de Rentals (§7)
- Pruebas: `tests/{Feature, Unit}`, `tests/Support/EscenarioReservas.php` y el ejecutor de concurrencia `tests/Support/concurrencia/confirmar-reserva.php` (§10.2)

**Nuevos (Finance y herramientas)**
- La migración `add_concepto_to_pagos_table`
- `.tools/validacion-mysql-etapa4.ps1` y `.tools/resultados/e4-*`, más `huellas-migraciones-etapa4.txt`

**Modificados**
- `Modules/Rentals/routes/web.php`: rutas nuevas; `alquileres.create` y `alquileres.store` quedan congeladas, con redirección y aviso (D1)
- `Modules/Rentals/app/Models/{Reserva, Paquete, Maquinaria}.php`: relaciones y alcances de consulta
- `Modules/Finance/app/Aplicadores/AplicadorReserva.php`: filtro por `concepto` y uso de `total_cargos`
- `Modules/Finance/app/Models/Pago.php`: constantes de `concepto`
- `Modules/Finance/app/Services/PagoService.php`: acepta `concepto`
- `Modules/Finance/app/Services/ReembolsoService.php`: devolución rápida de garantías (D6)
- `Modules/Finance/app/Services/ArqueoService.php` y `app/Support/ResultadoArqueo.php`: líneas de garantías aparte (C2)
- `Modules/Finance/app/Services/ReporteFinancieroService.php` y su vista y CSV: sección de garantías y reportes de reservas
- `Modules/Finance/resources/views/caja/{show, corte-pdf, partials/arqueo}.blade.php`: garantías aparte
- `Modules/Finance/app/Http/Controllers/PagoEnLineaController.php`: destino de tipo reserva
- `Modules/Rentals/app/Http/Controllers/MantenimientoController.php`: bloqueo opcional por maquinaria
- `config/access.php`, `database/seeders/PermisoSeeder.php` y `resources/views/components/admin-sidebar.blade.php`
- `Modules/Audit/app/Services/ReadOnlySqlGuard.php` y `IaReportController.php`
- `tests/Feature/Instalacion/{EsquemaInstalacionTest, SeedersInstalacionTest}.php`: tablas nuevas

**Sin cambios:** `composer.json`, `composer.lock`, las 42 migraciones existentes, el esquema de `alquileres` y `alquiler_detalles`, y `.env`.

**Permisos nuevos:**

| Permiso | Roles |
|---|---|
| `rentals.packages.manage` | admin, encargado |
| `rentals.reservations.create`, `rentals.reservations.cancel` | admin, encargado, vendedor |
| `rentals.deliver`, `rentals.return` | admin, encargado, vendedor, almacenero, técnico |
| `rentals.deposits.return` | admin, encargado, vendedor (D6) |
| `rentals.deposits.retain` | admin, encargado (D6) |
| `rentals.incidents.manage` | admin, encargado, técnico |
| `rentals.reports.view` | admin, encargado |

El cliente accede solo a lo suyo, por propiedad (`ci_cliente`); no necesita permiso.

---

## 9. Riesgos

| Riesgo | Mitigación |
|---|---|
| **R1.** Sobreventa por concurrencia. SQLite ignora `FOR UPDATE` porque serializa toda la base | Bloqueo ordenado y recálculo dentro de la transacción. **Prueba real con dos conexiones simultáneas en MySQL** (§10.2, C3) |
| **R2.** Convivencia con el alquiler heredado (`maquinarias.estado = alquilado`) | Los alquileres activos cuentan como bloqueo; se congela el alta (D1) |
| **R3.** La garantía podría mezclarse con `estado_pago`, el arqueo o los ingresos | `pagos.concepto`, líneas propias en caja y reportes, y pruebas específicas (C2) |
| **R4.** Reservas confirmadas sin pago | Reporte de confirmadas sin pago (D3) |
| **R5.** Una devolución tardía choca con la reserva siguiente (con margen) | La devolución detecta el conflicto, crea una incidencia y avisa |
| **R6.** Los cambios en `AplicadorReserva` y `ArqueoService` pueden romper Finanzas | Regresión de las 144 pruebas de Finanzas en los dos motores en cada paso que toque Finanzas |
| **R7.** El DDL de MySQL es lento en este disco | Migraciones agrupadas; la prueba de migraciones revierte solo las 8 de esta etapa |
| **R8.** Zona horaria y horas parciales | `America/La_Paz`; tolerancia de 15 min y cobro por hora iniciada (D5) |
| **R9.** Ejecutar pruebas contra una base equivocada | Destino verificado antes de cada ejecución; el script aborta si no coincide (§10.1, C4) |
| **R10.** Una prueba de concurrencia inestable (temporización) | Sincronización explícita con `GET_LOCK`, repetición N veces y tiempo límite por proceso (§10.2) |

---

## 10. Plan de implementación y pruebas

### 10.1 Bases de datos de prueba (C4)

- **SQLite:** solo `:memory:`, como ya define `phpunit.xml`.
- **MySQL:** **solo** `sound_management_test`, en `127.0.0.1:3310`, con el usuario `sound_test`, que solo ve esa base.
- `.tools/validacion-mysql-etapa4.ps1` (copia de `validacion-mysql-etapa3.ps1`) **verifica el servidor, el puerto, la base y el usuario antes de cada ejecución y aborta si alguno difiere**. Al terminar, detiene MySQL.
- Las operaciones destructivas (`migrate:fresh`, `migrate:rollback`, `RefreshDatabase`, `DROP`) **se ejecutan solo en esa base**. Nunca hay conexión con `sound_management_db` ni con otro servidor o base.
- Los procesos hijos de la prueba de concurrencia heredan el mismo destino y lo vuelven a verificar antes de conectarse.
- Las salidas se guardan en `.tools/resultados/` (disco E:). No se escribe deliberadamente en C:.

### 10.2 Prueba real de concurrencia en MySQL (C3)

**Objetivo:** demostrar que dos confirmaciones **simultáneas** de las mismas unidades no producen sobreventa.

**Diseño** (`Modules/Rentals/tests/Feature/ConcurrenciaReservasMySqlTest.php`):
1. **Preparación:** una maquinaria con `cantidad_total = 2`, un paquete que la usa con `cantidad = 2` y dos reservas `pendiente` en el mismo intervalo. Los datos se confirman (*commit*) en la base de prueba para que los vean otras conexiones.
2. **Dos procesos PHP independientes** (`proc_open`), cada uno con **su propia conexión** MySQL, ejecutan `tests/Support/concurrencia/confirmar-reserva.php`, que arranca la aplicación y llama a `ReservaService::confirmar`.
3. **Barrera de inicio:** los dos esperan una señal común (`GET_LOCK` de MySQL que libera la prueba). Así entran a la vez en la transacción.
4. **Resultado esperado con unidades insuficientes:**
   - exactamente **una** reserva queda `confirmada` y la otra `rechazada` (D2);
   - `Σ reserva_equipos.cantidad «reservado»` ≤ `cantidad_total`;
   - sin errores de interbloqueo y sin excepciones no controladas;
   - cada proceso registra su evento en `reserva_eventos`.
5. **Variante con unidades suficientes** (`cantidad_total = 4`): **las dos** se confirman.
6. **Variante con margen:** reservas consecutivas separadas por menos de 60 min compiten por la única unidad; solo una se confirma.
7. **Repetición:** cada escenario se ejecuta **20 veces**, con un tiempo límite por proceso, para descartar un acierto por azar.
8. **Evidencia:** se registran los tiempos de espera del bloqueo para demostrar que la segunda transacción **esperó** a la primera.

**En SQLite** esta prueba no se puede reproducir: el motor no tiene bloqueo de filas. Se marca como omitida con un motivo explícito y el informe lo declara. En SQLite se prueba la secuencia equivalente: la segunda confirmación, después de la primera, se rechaza.

### 10.3 Pasos

| Paso | Contenido | Pruebas principales (SQLite y MySQL) |
|---|---|---|
| 1 | Las 8 migraciones y sus modelos | `MigracionReservasTest`: `rollback --step=8` y `migrate`; los pagos existentes quedan con `concepto = alquiler` sin cambiar importes; CHECK solo en MySQL; **42 hashes intactos y 50 migraciones** |
| 2 | `DisponibilidadService` y `CotizadorReserva` | Límites `[a, b)`; **margen de 60 min antes y después** (los cuatro casos de §3.2); bloqueos; alquileres heredados; `cantidad_total > 1`; horas mínimas y máximas; sin tarifa por día |
| 3 | `ReservaService`: crear, confirmar, rechazar, cancelar y vencer; eventos; tareas programadas | Confirmación sin ningún pago; un pago no crea `reserva_equipos`; la cancelación libera; idempotencia; **concurrencia real en MySQL (§10.2)** |
| 4 | Finanzas: `concepto`, `AplicadorReserva`, pago en línea y presencial de la reserva | Anticipo → `parcial`; saldo → `pagado`; **la garantía no cambia `estado_pago`**; la garantía aparece **aparte** en el arqueo, la foto del cierre, el PDF y los reportes; regresión de las 144 pruebas de Finanzas |
| 5 | Entrega, devolución, horas extra y cargos | Checklist; devolución tardía con conflicto (y margen); tolerancia de 15 min; total con cargos |
| 6 | Garantías e incidencias | Devolución íntegra por el vendedor (D6) que resta como «Garantías devueltas»; retención solo por encargado o administrador; bloqueo por daño (D8); una doble confirmación no tiene doble efecto |
| 7 | Canales: ERP, POS, portal y catálogo | Permisos por rol (botones y rutas); propiedad del cliente; alta heredada congelada (D1) |
| 8 | Reportes y tickets | PDF y CSV; confirmadas sin pago (D3); atrasos; sección de garantías |
| 9 | Validación final con `.tools\ejecutar-pruebas.ps1` (SQLite) y `.tools\validacion-mysql-etapa4.ps1` (MySQL en `sound_management_test:3310`) | Rentals, Finanzas e Instalación en los dos motores; nueva línea base de 50 migraciones; `composer.json` y `composer.lock` con el mismo hash; informe `INFORME_ETAPA_4_VALIDACION.md` |

**Fuera de alcance:** las 21 pruebas Breeze heredadas que fallan (Etapa 5), mover los permisos a `rol_permiso` y la prueba manual con PayPal *sandbox*.

---

## 11. Incorporación de las correcciones

| Corrección | Dónde se incorporó |
|---|---|
| C1. Margen de 60 min antes y después de cada reserva | §3.2 (fórmula, ejemplos), D4, §10.2 (variante 6), §10.3 (pasos 2 y 5) |
| C2. Garantías separadas del alquiler en `estado_pago`, caja, arqueo y reportes | §3.4, P6, migración 8, §8, R3, §10.3 (pasos 4 y 6) |
| C3. Prueba real con dos conexiones simultáneas en MySQL | §3.3, §10.2, R1, R10, §10.3 (paso 3) |
| C4. Pruebas solo en `sound_management_test:3310`, sin operaciones destructivas en otras bases | §10.1, R9, §10.3 (paso 9) |
| C5. Línea base 37 + 5 = 42 (hashes verificados) + 8 = 50 | §2, §7, §10.3 (pasos 1 y 9) |

---

**Pendiente:** autorización expresa para comenzar la implementación de la Etapa 4, empezando por el paso 1.
