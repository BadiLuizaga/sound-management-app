# FASE 2 — ETAPA 2: SISTEMA DE PAGOS — DISEÑO TÉCNICO FINAL

**Estado:** 📐 **Diseño final pendiente de aprobación explícita.** Todavía no se ha creado ni modificado ningún archivo de código, migraciones ni configuración. Solo se ha actualizado este documento.
**Fecha:** 2026-11-20
**Pruebas:** solo `127.0.0.1:3310/sound_management_test` (MySQL 8.4.13 portátil) y SQLite en memoria. PayPal siempre simulado con `Http::fake()`, sin credenciales reales ni cobros.

---

## 0. Decisiones aprobadas y cómo las aplica el diseño

| # | Decisión | Aplicación en el diseño |
|---|---|---|
| 1 | Migraciones nuevas; no modificar las ejecutadas; no eliminar `pagos_externos` sin transición segura | 6 migraciones **nuevas** en el módulo Finance (§3). `pagos_externos` **se conserva** como histórico de solo lectura y sus filas se **copian** a `pagos` con referencia al original (§3.6). No se modifica ni elimina ninguna migración. |
| 2 | Tipo de cambio: solo el administrador; vigencia diaria con fecha explícita; 2 decimales; sin margen; nunca una tasa por defecto | `tipos_cambio` con una fila por `fecha_aplicacion` (zona `America/La_Paz`), única por día. Sin tasa del día → **el pago en línea no se ofrece**. Las tasas ya usadas por un pago no se editan (§2.5). |
| 3 | Transferencia y QR pendientes hasta que un administrador o encargado verifique el ingreso; referencia obligatoria y única | Estado `pendiente_verificacion`; solo pasa a `recibido` con `finance.payments.verify`. UNIQUE `(metodo_pago_id, referencia_externa)`. |
| 4 | Reembolsos de PayPal totales y parciales, con autorización, auditoría e idempotencia | Tabla `reembolsos` con clave de idempotencia, `PayPal-Request-Id` y doble paso (solicitar → autorizar y ejecutar). |
| 5 | Devoluciones de ventas cobradas → registro de devolución + reembolso vinculado; **nunca** modificar ni borrar líneas de la nota | `DevolucionController@aprobar` deja de tocar `NotaVenta` y `detalleNotaVenta`; guarda importes en la devolución y crea el `reembolso` (§2.7). |
| 6 | Alquileres antiguos como históricos, sin cobros retroactivos; los cobros nuevos van a reservas | `alquileres` no se toca. `pagos.reserva_id` para los cobros nuevos. |
| 7 | Expiración a los 30 minutos; **nunca** expirar `verificando` ni capturas inciertas | Solo `iniciado` y `orden_creada`, y **después** de consultar a PayPal (§2.6). |
| 8 | Módulo independiente `Finance` | `Modules/Finance` con su propio `composer.json`. El plugin de fusión ya incluye `Modules/*/composer.json`, así que **no se edita el `composer.json` raíz** (§1.2). |
| 9 | Permisos: admin todo; encargado operativo + verificación; vendedor solo registrar y ver sus pagos de caja; nada para técnico, almacenero y cliente | Matriz de §4. Los permisos personalizados no se pisan (`insertOrIgnore`). |
| 10 | Sin credenciales *sandbox* por ahora | Todo con `Http::fake()`; la prueba manual con *sandbox* queda pendiente. |

---

## 1. Arquitectura

### 1.1 Visión general

```
Sales (carrito, POS)   Rentals (reservas)   Procurement (devoluciones)
        │                     │                      │
        └──────────── Finance\Services ──────────────┘
                 PagoService · ReembolsoService · TipoCambioService · ConciliacionService
                 Aplicadores: VentaEnLinea · VentaPresencial · Reserva
                 Gateways\PaymentGateway ← Gateways\PayPal\PayPalGateway (+ PayPalClient)
                 Webhooks\PayPalWebhookProcessor
                        │
   tipos_cambio · tipo_cambio_revisiones · pagos · pago_eventos · reembolsos · webhook_eventos
```

**Principios (condiciones obligatorias):**
1. **Estado del dinero ≠ estado de aplicación.** `pagos.estado` frente a `pagos.estado_aplicacion`.
2. **Un pago recibido se confirma (*commit*) antes de aplicarlo.** Ningún fallo posterior lo borra. Las filas de `pagos` y `reembolsos` **nunca se eliminan** (FKs `restrict`; sin borrado en cascada).
3. **El navegador nunca da un pago por recibido.** Solo lo hacen la captura en el servidor (respuesta de PayPal verificada), un webhook con firma verificada o la verificación de una persona autorizada.
4. **Importes en centavos enteros** (`Finance\Support\Dinero`), BOB como moneda operativa. Nada de `float` en las comparaciones.
5. **Idempotencia** en crear, capturar, aplicar, webhooks, reembolsos y registros presenciales (§2.4).
6. **Bloqueos de fila** (`SELECT … FOR UPDATE` por la PK del pago), sin *gap locks*. Reintentos ante *deadlock*.

### 1.2 Módulo `Finance`: registro sin tocar `composer.json`
- **Comprobado en el código:** `composer.json` raíz → `extra.merge-plugin.include = ["Modules/*/composer.json"]`. El `composer.json` propio del módulo (con su `autoload.psr-4`) se fusiona en `composer dump-autoload`.
- Se ejecutará `composer dump-autoload --optimize --no-scripts` con el Composer portátil de `.tools`, comprobando que el SHA-256 de `composer.lock` y de `composer.json` **no cambia**.
- **Si el plugin no registrara el módulo sin editar el `composer.json` raíz, me detendré y te lo informaré antes de hacer nada más.**
- Se crea a mano, sin usar `php artisan module:make`, que lanzaría `composer` como proceso del sistema:
  - `Modules/Finance/module.json`
  - `Modules/Finance/composer.json`
  - `Modules/Finance/app/Providers/{FinanceServiceProvider, RouteServiceProvider, EventServiceProvider}.php`
  - `Modules/Finance/routes/{web.php, api.php}`
  - `Modules/Finance/config/config.php`
- `modules_statuses.json` añade `"Finance": true`.
- El `ModuleServiceProvider` de nwidart v13 (comprobado) carga automáticamente las migraciones del módulo, los comandos (`$commands`), las tareas (`configureSchedules`), la configuración y las vistas.
- **Dependencias entre módulos:** Finance usa `MetodoPago`, `FacturaVenta` (Sales), `Reserva` (Rentals), `Devolucion` (Procurement) y `Caja` (Sales). Sales, Rentals y Procurement llaman a Finance **solo a través de sus servicios**.

### 1.3 Componentes

| Componente | Responsabilidad |
|---|---|
| `Finance\Support\Dinero` | Valor inmutable en centavos + moneda; suma, resta, comparación, formato. |
| `Finance\Enums\{EstadoPago, EstadoAplicacion, EstadoReembolso, CanalPago}` | Enums PHP con `transicionesPermitidas()`. |
| `Finance\Services\TipoCambioService` | `vigenteParaHoy()`, `registrar()` (solo admin), `convertirBobAUsd()` (HALF_UP, 2 decimales). |
| `Finance\Services\PagoService` | Ciclo completo del pago: crear el intento, la orden, la captura, marcar recibido, aplicar, registrar presencial, verificar o rechazar, expirar. Valida cada transición y escribe `pago_eventos`. |
| `Finance\Services\ReembolsoService` | Solicitar, autorizar y ejecutar; reembolso parcial o total; recalcula el estado del pago. |
| `Finance\Services\ConciliacionService` | Lo usa `finanzas:conciliar` (§2.6). |
| `Finance\Aplicadores\{AplicadorVentaEnLinea, AplicadorVentaPresencial, AplicadorReserva}` | Generan la `NotaVenta` a partir del *snapshot* o recalculan `reservas.estado_pago`. Interfaz `AplicadorPago`. |
| `Finance\Gateways\PaymentGateway` | `crearOrden`, `capturarOrden`, `consultarOrden`, `reembolsarCaptura`, `verificarFirmaWebhook`. |
| `Finance\Gateways\PayPal\PayPalClient` | HTTP (Orders v2 y Payments v2), OAuth con el *token* en caché (Laravel Cache), *timeouts* y cabecera `PayPal-Request-Id`. |
| `Finance\Gateways\PayPal\PayPalGateway` | Traduce las respuestas de PayPal a resultados tipados; nunca expone el secreto. |
| `Finance\Webhooks\PayPalWebhookProcessor` | Procesa eventos ya verificados y guardados. |
| `Finance\Console\ConciliarPagos` | `php artisan finanzas:conciliar`, programado cada 5 minutos (perfil `workers` de Docker). |

---

## 2. Flujos

### 2.1 Estados

**`pagos.estado` (dinero):**

```
iniciado ─► orden_creada ─► verificando ─► recibido ─► reembolsado_parcial ─► reembolsado
   │             │               │            │                └──────────────► reembolsado
   │             │               └─► fallido   └─► revertido (contracargo)
   ├─► fallido   ├─► fallido / cancelado / expirado
   └─► expirado
pendiente_verificacion ─► recibido        (transferencia / QR, al verificarlo una persona autorizada)
                       └─► rechazado
```
- Tarjeta y efectivo en caja entran directamente como `recibido`, registrados por personal autorizado y con la caja abierta.
- `verificando` **nunca** expira automáticamente.
- Estados finales sin dinero: `fallido`, `expirado`, `cancelado`, `rechazado`.

**`pagos.estado_aplicacion`:** `pendiente` → `aplicado` | `requiere_revision` → `aplicado_manual`. `no_aplica` para registros históricos sin destino.

**`reembolsos.estado`:** `solicitado` → `autorizado` → `procesando` → `completado` | `fallido` | `incierto` (*timeout*; nunca se descarta automáticamente, lo resuelve la conciliación o el webhook). `rechazado` si el administrador no lo autoriza.

### 2.2 Pago en línea con PayPal (servidor)

```
1. POST /pagos/paypal/ordenes          (auth; cliente; throttle 10/min)
   a. Requisitos: client_id + secret + webhook_id configurados; método PAYPAL activo;
      tasa BOB→USD del día. Si falta algo → 422 sin llamar a PayPal ni crear el pago.
   b. Recalcula el carrito en el servidor (BOB), congela el snapshot y calcula USD = round(BOB / tasa, 2) HALF_UP.
   c. INSERT pagos (estado 'iniciado', uuid, monto BOB, monto USD, tipo_cambio_id, tasa, expira_at = +30 min) → COMMIT
   d. PayPal POST /v2/checkout/orders  (intent CAPTURE; custom_id = invoice_id = uuid;
      PayPal-Request-Id = "orden-{uuid}")
   e. estado 'orden_creada' + referencia_externa = order_id → COMMIT;  evento 'orden_creada'
   f. Responde {uuid, order_id}.   ** No se reserva stock ni equipos. **

2. El cliente aprueba en PayPal (navegador). Esto NO cambia ningún estado.

3. POST /pagos/{uuid}/captura          (auth; solo el dueño del pago)
   a. SELECT … FOR UPDATE del pago (PK).
      - Si ya está 'recibido' o en un estado posterior → respuesta idempotente (no se vuelve a capturar).
      - Si no está en 'orden_creada' → 409.
   b. estado 'verificando' → COMMIT (se marca ANTES de llamar a PayPal)
   c. PayPal POST /v2/checkout/orders/{id}/capture   (PayPal-Request-Id = "captura-{uuid}")
      · timeout / 5xx / respuesta ilegible → se queda en 'verificando' (incierto) → conciliación o webhook
      · 4xx definitivo (rechazado, no aprobado) → 'fallido'
   d. Verificación estricta: order.id = referencia_externa, custom_id = uuid,
      captura COMPLETED, moneda USD y centavos exactos = monto_proveedor guardado.
      · Si PayPal cobró pero algo no cuadra → 'recibido' + 'requiere_revision' (el dinero QUEDA registrado)
   e. estado 'recibido', referencia_captura, recibido_at, respuesta saneada → COMMIT  ◀ PAGO ASEGURADO

4. Aplicación (transacción aparte; DB::transaction(..., 3) ante deadlock)
   AplicadorVentaEnLinea: bloquea los productos, comprueba stock y precios contra el snapshot,
   crea NotaVenta (id_pago = PAYPAL por código) + detalles, descuenta stock, vacía el carrito.
   → estado_aplicacion 'aplicado', nro_factura                                    → COMMIT
   Error tras los reintentos → 'requiere_revision' + motivo (stock intacto).
```

### 2.3 Pagos presenciales (POS y, más adelante, reservas en mostrador)

| Método | Requisitos | Estado inicial | Aplicación |
|---|---|---|---|
| Efectivo | `finance.payments.register` + caja abierta propia; importe recibido ≥ total; se registra el cambio | `recibido` | Inmediata (`NotaVenta` en la misma transacción) |
| Tarjeta | Ídem + nº de autorización o *voucher* **obligatorio y único** | `recibido` | Inmediata |
| Transferencia / QR | Ídem + **referencia bancaria obligatoria y única** | **`pendiente_verificacion`** | **Diferida** (C1): la venta se crea al verificar. Si se rechaza, no hay venta. |

Las verifica un usuario con `finance.payments.verify` (admin y encargado), desde `/finanzas/pagos`, comparando con el extracto bancario. Queda constancia de `ci_verificado_por` y `verificado_at`. Si se rechaza: `rechazado` + motivo.

### 2.4 Idempotencia y no duplicación

| Riesgo | Mecanismo |
|---|---|
| Crear dos órdenes por doble clic | Clave de idempotencia del cliente (`Idempotency-Key`, UUID generado por la vista) → UNIQUE `pagos.clave_idempotencia`; la segunda petición devuelve el mismo pago |
| PayPal recibe la misma creación o captura dos veces | `PayPal-Request-Id` estable (`orden-{uuid}`, `captura-{uuid}`, `reembolso-{uuid}`) |
| Capturar dos veces | Bloqueo de fila + estado; la segunda petición recibe la respuesta idempotente |
| Reutilizar una orden, captura o referencia bancaria | UNIQUE `(metodo_pago_id, referencia_externa)`, UNIQUE `(proveedor, referencia_captura)`, `custom_id` = uuid |
| Dos ventas por un pago | `estado_aplicacion` comprobado bajo bloqueo; `nro_factura` se asigna una sola vez |
| Webhook repetido | UNIQUE `(proveedor, evento_id)` |
| Reembolso repetido | UNIQUE `reembolsos.clave_idempotencia` + `PayPal-Request-Id`; Σ reembolsos completados + en curso ≤ recibido (comprobado bajo bloqueo del pago) |
| POS enviado dos veces | `clave_idempotencia` del formulario |

### 2.5 Tipo de cambio

| Regla | Implementación |
|---|---|
| Quién | Solo `finance.exchange.manage` (administrador) |
| Definición | `tasa` = **BOB por 1 USD**, DECIMAL(12,6) > 0 |
| Vigencia | Una fila por `fecha_aplicacion` (fecha de La Paz); UNIQUE `(moneda_base, moneda_cotizada, fecha_aplicacion)`. El pago usa la tasa de la fecha en que se crea el intento. **No se hereda la tasa del día anterior.** |
| Conversión | `usd = round(bob / tasa, 2)` HALF_UP; BOB también a 2 decimales. Se guardan en el pago `monto`, `monto_proveedor`, `tipo_cambio_id` y `tasa_aplicada`. |
| Sin tasa del día | El botón de PayPal no se muestra y el endpoint responde 422. **Nunca hay una tasa por defecto.** |
| Modificar | Se permite **solo si ningún pago la usó**. Cada cambio deja una fila en `tipo_cambio_revisiones` (tasa anterior y nueva, quién, motivo) y en la bitácora. Si ya se usó, solo se puede crear la de otro día. |
| Transparencia | El carrito muestra "Total Bs. X → se cobrará USD Y (tasa Z del dd/mm/aaaa)". |
| Reembolsos | El USD se calcula con la **`tasa_aplicada` original del pago**, nunca con la de hoy. En el último reembolso se usa el USD restante exacto, para que no haya deriva por redondeo. |

### 2.6 Conciliación (`php artisan finanzas:conciliar`, cada 5 minutos)
1. **Expiración** (solo `iniciado` y `orden_creada` con `expira_at` vencido):
   - `iniciado` → `expirado`.
   - `orden_creada` → **primero se consulta la orden en PayPal**:
     - `COMPLETED` → `recibido` (y se aplica);
     - `APPROVED` → se captura en el servidor (paso 3 de §2.2);
     - no aprobada, anulada o inexistente → `expirado`;
     - error de red o API → **no se toca** (se reintenta en la siguiente ejecución).
2. **`verificando`** → consulta la orden y la captura → `recibido` o `fallido`. **Nunca `expirado`.** Si sigue incierto, se marca para revisión a partir de 24 h, sin cambiar su estado de dinero.
3. **`recibido` + `pendiente`** (en línea) → reintenta la aplicación.
4. **Reembolsos `incierto` o `procesando`** → consulta el reembolso en PayPal.
5. **Webhooks guardados pero no procesados** → se reprocesan.
6. Cada acción escribe `pago_eventos` (o un evento de reembolso).

**Pantalla `/finanzas/pagos`:** filtros por estado y método; detalle con *snapshot*, eventos y reembolsos; acciones según el permiso: verificar o rechazar, reintentar la aplicación, aplicar a mano con motivo, solicitar o autorizar un reembolso.

### 2.7 Devoluciones de ventas cobradas

`DevolucionController@aprobar` pasa a hacer esto:
1. Valida las cantidades: `devuelto acumulado + esta devolución ≤ vendido`, por línea.
2. **No modifica `NotaVenta` ni `detalleNotaVenta`.** El historial original se conserva.
3. Guarda en `devolucion_detalles` el `precio_unitario` neto (con el descuento de la línea) y el `subtotal`; en `devoluciones`, `monto_total`, `ci_aprobado_por` y `aprobado_at`.
4. Repone el stock (como hoy).
5. Si es de tipo "Devolución" y la venta tiene pagos `recibido`:
   - **pagada por PayPal** → crea un `reembolso` `solicitado`, vinculado a la devolución, que debe autorizar un administrador (§2.8);
   - **pagada en caja** → crea un `reembolso` presencial que se completa cuando el personal confirma que devolvió el dinero (C3).

   Si es "Garantía" → sin reembolso.
6. El neto de una venta se calcula como `NotaVenta.total − Σ reembolsos completados`, en las vistas y reportes.

### 2.8 Reembolsos (PayPal y presenciales)

```
Solicitar (finance.refunds.request: admin, encargado)  → reembolsos 'solicitado' (motivo, monto BOB ≤ disponible)
Autorizar y ejecutar (finance.refunds.execute: admin)  → 'autorizado' → 'procesando' → COMMIT
   PayPal POST /v2/payments/captures/{captura}/refund  (amount USD con la tasa original;
   PayPal-Request-Id = "reembolso-{uuid}")
   · COMPLETED → 'completado' + referencia_externa (refund id); el pago pasa a 'reembolsado_parcial' o 'reembolsado'
   · PENDING   → 'procesando' (lo cierra la conciliación o el webhook)
   · timeout/5xx → 'incierto' (nunca se descarta; la conciliación consulta)
   · 4xx       → 'fallido' + motivo
Presencial: 'autorizado' → 'completado' al confirmar la entrega del dinero (en efectivo, con la caja abierta).
```
Un administrador puede hacer los dos pasos a la vez. Toda acción queda en `pago_eventos` y en la bitácora (quién, cuándo, cuánto, motivo).

### 2.9 Webhooks firmados
- `POST /webhooks/paypal`: sin sesión; con excepción de CSRF **solo** para esa ruta (`bootstrap/app.php`); `throttle:120,1`.
- **Verificación:** `POST /v1/notifications/verify-webhook-signature` con las cabeceras `PAYPAL-TRANSMISSION-ID/-TIME/-SIG`, `PAYPAL-CERT-URL`, `PAYPAL-AUTH-ALGO` y `PAYPAL_WEBHOOK_ID`.
- Si es inválida → **400**, se registra con `firma_valida = false` y no se procesa.
- Si es válida → se guarda (idempotente por `evento_id`) y responde **200**. El procesamiento va aparte; si falla, la conciliación lo reintenta.

| Evento | Efecto |
|---|---|
| `PAYMENT.CAPTURE.COMPLETED` | `orden_creada`/`verificando` → `recibido` (comprobando `custom_id`, monto y moneda) → aplicar |
| `PAYMENT.CAPTURE.DENIED` | → `fallido` |
| `PAYMENT.CAPTURE.PENDING` | se mantiene `verificando` (incierto) |
| `PAYMENT.CAPTURE.REFUNDED` | completa el reembolso correspondiente, o crea uno **registrado externamente** si se hizo en el panel de PayPal, y recalcula el estado del pago |
| `PAYMENT.CAPTURE.REVERSED` | → `revertido` + `requiere_revision` |
| `CHECKOUT.ORDER.APPROVED` | si la orden sigue en `orden_creada`, el servidor captura (navegador cerrado a mitad) |

### 2.10 Reservas
- Pagos con `reserva_id`. `AplicadorReserva` recalcula `reservas.estado_pago`: Σ recibidos − Σ reembolsos completados frente a `total` → `pendiente`, `parcial`, `pagado` o `reembolsado`.
- **Nunca cambia `reservas.estado`.** Confirmar sigue siendo independiente del pago, y pagar no bloquea equipos.
- Las pantallas de reservas corresponden a su propia etapa; aquí solo se implementan el servicio y sus pruebas.

---

## 3. Migraciones propuestas (todas nuevas, en `Modules/Finance/database/migrations/`)

Se ejecutan después de las 31 actuales: el orden es global, por nombre de archivo.

### 3.1 `2026_11_22_000001_create_tipos_cambio_tables.php`
| Tabla | Columnas | Restricciones |
|---|---|---|
| `tipos_cambio` | `id`; `moneda_base` CHAR(3) = 'USD'; `moneda_cotizada` CHAR(3) = 'BOB'; `tasa` DECIMAL(12,6); `fecha_aplicacion` DATE; `fuente` VARCHAR(150) null; `ci_registrado_por` INT; timestamps | UNIQUE (base, cotizada, fecha); FK `ci_registrado_por` → usuario (restrict); CHECK `tasa > 0` |
| `tipo_cambio_revisiones` | `id`; `tipo_cambio_id`; `tasa_anterior`, `tasa_nueva` DECIMAL(12,6); `motivo` VARCHAR(255); `ci_usuario` INT; `created_at` | FK → tipos_cambio (restrict), usuario (restrict) |

### 3.2 `2026_11_22_000002_create_pagos_tables.php`
| Tabla | Columnas | Restricciones e índices |
|---|---|---|
| `pagos` | `id`; `uuid` CHAR(36); `clave_idempotencia` VARCHAR(80) null; `canal` ENUM(en_linea, presencial); `proveedor` VARCHAR(30) (`paypal` \| `manual`); `metodo_pago_id` INT; **`estado`** ENUM(iniciado, orden_creada, verificando, pendiente_verificacion, recibido, fallido, expirado, cancelado, rechazado, reembolsado_parcial, reembolsado, revertido); **`estado_aplicacion`** ENUM(pendiente, aplicado, requiere_revision, aplicado_manual, no_aplica); **`monto`** DECIMAL(12,2) + **`moneda`** CHAR(3) = 'BOB'; `monto_recibido_efectivo` DECIMAL(12,2) null; `cambio_entregado` DECIMAL(12,2) null; `monto_proveedor` DECIMAL(12,2) null + `moneda_proveedor` CHAR(3) null; `tipo_cambio_id` null; `tasa_aplicada` DECIMAL(12,6) null; `referencia_externa` VARCHAR(100) null (orden PayPal o referencia bancaria/voucher); `referencia_captura` VARCHAR(100) null; `ci_usuario` INT null (pagador); `ci_registrado_por` INT null; `ci_verificado_por` INT null; `caja_id` BIGINT null; `nro_factura` INT null; `reserva_id` BIGINT null; `snapshot` JSON null; `respuesta_proveedor` JSON null (saneada); `motivo_revision` TEXT null; `motivo_rechazo` VARCHAR(255) null; `legacy_pago_externo_id` BIGINT null; `expira_at`, `recibido_at`, `verificado_at`, `aplicado_at` DATETIME null; timestamps | UNIQUE `uuid`; UNIQUE `clave_idempotencia`; **UNIQUE (metodo_pago_id, referencia_externa)**; **UNIQUE (proveedor, referencia_captura)**; UNIQUE `legacy_pago_externo_id`; FKs **restrict** → metodoPago, tipos_cambio, usuario ×3, cajas, NotaVenta(nro), reservas, pagos_externos; índices (estado, expira_at), (estado_aplicacion), (ci_usuario), (caja_id), (reserva_id), (nro_factura); CHECK `monto > 0`, `monto_proveedor IS NULL OR monto_proveedor > 0` |
| `pago_eventos` | `id`; `pago_id`; `tipo` VARCHAR(50); `estado_anterior`, `estado_nuevo` VARCHAR(30) null; `estado_aplicacion_anterior`, `estado_aplicacion_nuevo` VARCHAR(30) null; `datos` JSON null; `ci_usuario` INT null; `ip` VARCHAR(45) null; `created_at` | FK pago (restrict); índice (pago_id, created_at). **Solo inserción** (el modelo no permite update ni delete). |

### 3.3 `2026_11_22_000003_create_reembolsos_table.php`
| Columnas | Restricciones |
|---|---|
| `id`; `uuid`; `clave_idempotencia`; `pago_id`; `devolucion_id` BIGINT null; `estado` ENUM(solicitado, autorizado, procesando, completado, fallido, incierto, rechazado); `monto` DECIMAL(12,2) BOB; `monto_proveedor` DECIMAL(12,2) null + `moneda_proveedor` CHAR(3) null; `tasa_aplicada` DECIMAL(12,6) null (copiada del pago); `origen` ENUM(aplicacion, externo_webhook); `referencia_externa` VARCHAR(100) null (refund id); `motivo` VARCHAR(255); `ci_solicitado_por`, `ci_autorizado_por` INT null; `respuesta_proveedor` JSON null; `solicitado_at`, `autorizado_at`, `completado_at` DATETIME null; timestamps | UNIQUE `uuid`, `clave_idempotencia`, `referencia_externa`; FKs restrict → pagos, devoluciones, usuario; índice (estado); CHECK `monto > 0` |

Los eventos de reembolso se registran en `pago_eventos` del pago correspondiente (tipo `reembolso_*`).

### 3.4 `2026_11_22_000004_create_webhook_eventos_table.php`
| Columnas | Restricciones |
|---|---|
| `id`; `proveedor` VARCHAR(30); `evento_id` VARCHAR(100); `tipo` VARCHAR(100); `firma_valida` BOOLEAN; `payload` JSON; `cabeceras` JSON (sin datos sensibles); `pago_id` null; `procesado_at` null; `intentos` INT default 0; `resultado` VARCHAR(30) null; `error` TEXT null; timestamps | UNIQUE (proveedor, evento_id); FK pago (restrict, null); índice (procesado_at) |

### 3.5 `2026_11_22_000005_add_importes_to_devoluciones_tables.php` (solo añade columnas)
| Tabla | Columnas nuevas |
|---|---|
| `devoluciones` | `monto_total` DECIMAL(12,2) null; `ci_aprobado_por` INT null (FK usuario); `aprobado_at` DATETIME null |
| `devolucion_detalles` | `precio_unitario` DECIMAL(12,2) null; `subtotal` DECIMAL(12,2) null |

### 3.6 `2026_11_22_000006_transferir_pagos_externos_a_pagos.php`: transición segura
- **No elimina `pagos_externos`, ni sus filas, ni su migración.** Queda como histórico de solo lectura; el código deja de escribir en ella.
- **Copia** cada fila a `pagos`, de forma idempotente por `legacy_pago_externo_id` UNIQUE:

| `pagos_externos.estado` | → `pagos.estado` / `estado_aplicacion` |
|---|---|
| `aplicado` | `recibido` / `aplicado` (con su `nro_factura`) |
| `requiere_reembolso` | `recibido` / `requiere_revision` (motivo: "Migrado desde pagos_externos") |
| otro | `recibido` / `requiere_revision` |

- Método PAYPAL resuelto por código. Si falta, la migración **se detiene sin copiar nada**.
- Cada copia genera un `pago_eventos` de tipo `migrado_desde_pagos_externos`.
- `down()`: borra **solo las copias** (`legacy_pago_externo_id IS NOT NULL`) si no tienen reembolsos ni eventos posteriores a la migración; si los tienen, se niega (excepción) para no perder historial.
- En `sound_management_test` y en `sound_management_db` la tabla está vacía; la transición se prueba insertando filas ficticias en la base de pruebas.

**No se modifica ninguna migración existente.**

---

## 4. Permisos (`config/access.php` + `PermisoSeeder`, con `insertOrIgnore`)

| Permiso | Admin | Encargado | Vendedor | Técnico, Almacenero, Cliente |
|---|:-:|:-:|:-:|:-:|
| `finance.payments.view_all` (todos los pagos) | ✅ | ✅ | — | — |
| `finance.payments.view_own` (los registrados por él en su caja) | ✅ | ✅ | ✅ | — |
| `finance.payments.register` (cobro presencial) | ✅ | ✅ | ✅ | — |
| `finance.payments.verify` (transferencia/QR) | ✅ | ✅ | — | — |
| `finance.payments.reconcile` (reintentar, aplicar a mano, conciliación) | ✅ | ✅ | — | — |
| `finance.refunds.request` | ✅ | ✅ | — | — |
| `finance.refunds.execute` (autorizar y ejecutar) | ✅ | — | — | — |
| `finance.exchange.manage` (tipos de cambio) | ✅ | — | — | — |
| `finance.audit.view` (eventos, webhooks, histórico) | ✅ | — | — | — |

- El cliente solo ve y captura **sus propios** pagos en línea. Es una regla de propiedad (`ci_usuario`), no un permiso financiero.
- `PermisoSeeder` añade los permisos y sus asignaciones por defecto con `insertOrIgnore`: **no quita ni cambia** asignaciones personalizadas existentes, y los roles personalizados **no reciben** permisos financieros por defecto.
- **Nota:** hoy los Gates se evalúan desde `config/access.php`; pasar a leer `rol_permiso` es de la Etapa 3. Hasta entonces, un rol personalizado no tiene permisos financieros salvo que se añada a la matriz. Es coherente con "no conceder por defecto".

---

## 5. Archivos

### 5.1 Nuevos (módulo `Modules/Finance`)
| Ruta | Contenido |
|---|---|
| `module.json`, `composer.json`, `config/config.php` | Registro del módulo y configuración (`moneda_operativa` BOB, `expiracion_minutos` 30, `reintentos_aplicacion` 3, `verificando_alerta_horas` 24) |
| `app/Providers/{FinanceServiceProvider, RouteServiceProvider, EventServiceProvider}.php` | Comandos, tarea programada y *binding* `PaymentGateway → PayPalGateway` |
| `routes/web.php` | Rutas de §5.3 |
| `database/migrations/` (6) | §3 |
| `app/Models/{Pago, PagoEvento, Reembolso, TipoCambio, TipoCambioRevision, WebhookEvento}.php` | Modelos (`PagoEvento` solo inserción) |
| `app/Support/Dinero.php`, `app/Enums/*.php` | Valor monetario y estados |
| `app/Services/{PagoService, ReembolsoService, TipoCambioService, ConciliacionService}.php` | Lógica |
| `app/Aplicadores/{AplicadorPago, AplicadorVentaEnLinea, AplicadorVentaPresencial, AplicadorReserva}.php` | Aplicación a venta o reserva |
| `app/Gateways/PaymentGateway.php`, `app/Gateways/PayPal/{PayPalClient, PayPalGateway}.php` | Proveedor |
| `app/Webhooks/PayPalWebhookProcessor.php` | Procesamiento de eventos |
| `app/Http/Controllers/{PagoEnLineaController, PayPalWebhookController}.php` | En línea y webhook |
| `app/Http/Controllers/Admin/{PagoController, ReembolsoController, TipoCambioController}.php` | Administración |
| `app/Console/ConciliarPagos.php` | `finanzas:conciliar` |
| `resources/views/{pagos/index, pagos/show, tipos-cambio/index}.blade.php` | Pantallas (con `@can` coherentes con las rutas) |
| `tests/Feature/*.php`, `tests/Unit/*.php` | §6 (se añade `Modules/*/tests` a `phpunit.xml`) |

### 5.2 Modificados
| Archivo | Cambio |
|---|---|
| `modules_statuses.json` | `"Finance": true` |
| `bootstrap/app.php` | Excepción de CSRF **solo** para `webhooks/paypal` |
| `Modules/Sales/routes/web.php` | `carrito.checkout` (antiguo) ya no registra ventas: redirige al carrito con un aviso |
| `Modules/Sales/app/Http/Controllers/CartController.php` | Se retira la lógica de cobro de `checkout()`; `index()` pasa a la vista la disponibilidad del pago, el total en USD y la tasa |
| `resources/views/carrito/index.blade.php` | El SDK usa `createOrder` y `onApprove` contra `/pagos/paypal/…`; se muestran BOB, USD y la tasa; `Idempotency-Key` |
| `Modules/Sales/app/Http/Controllers/VentaController.php` + `resources/views/ventas/create.blade.php` | El POS registra el pago presencial mediante `PagoService` (referencia, efectivo recibido y cambio, clave de idempotencia) |
| `Modules/Procurement/app/Http/Controllers/DevolucionController.php` | §2.7 (sin modificar notas) |
| `resources/views/components/admin-sidebar.blade.php` | Enlaces "Pagos" y "Tipos de cambio" con `@can` |
| `config/services.php`, `.env.example` | `PAYPAL_WEBHOOK_ID`; `PAYPAL_CURRENCY` pasa a ser solo la moneda del proveedor |
| `config/access.php`, `database/seeders/PermisoSeeder.php` | Permisos de §4 |
| `Modules/Audit/app/Services/ReadOnlySqlGuard.php` | Prohíbe `pagos`, `pago_eventos`, `reembolsos` y `webhook_eventos` en los reportes con IA |
| `phpunit.xml` | Incluir `Modules/*/tests` |
| `tests/Feature/Instalacion/{EsquemaInstalacionTest, SeedersInstalacionTest, MetodoPagoTest}.php` | Tablas y permisos nuevos; el checkout antiguo ya no registra ventas |

### 5.3 Rutas
| Método y ruta | Acceso |
|---|---|
| `POST /pagos/paypal/ordenes` | Autenticado (dueño), `throttle:10,1` |
| `POST /pagos/{uuid}/captura` | Autenticado, **solo el dueño**, `throttle:10,1` |
| `GET /pagos/{uuid}/estado` | Autenticado, solo el dueño |
| `POST /webhooks/paypal` | Público, firma verificada, sin CSRF, `throttle:120,1` |
| `GET /finanzas/pagos`, `GET /finanzas/pagos/{uuid}` | `view_all` o `view_own` (filtrado) |
| `POST /finanzas/pagos/{uuid}/verificar` · `/rechazar` | `finance.payments.verify` |
| `POST /finanzas/pagos/{uuid}/reintentar` · `/aplicar-manual` | `finance.payments.reconcile` |
| `POST /finanzas/pagos/{uuid}/reembolsos` | `finance.refunds.request` |
| `POST /finanzas/reembolsos/{uuid}/autorizar` · `/rechazar` · `/confirmar-entrega` | `finance.refunds.execute` |
| `GET/POST /finanzas/tipos-cambio`, `PUT /finanzas/tipos-cambio/{id}` | `finance.exchange.manage` |

### 5.4 Se conservan sin borrar
- `Modules/Sales/app/Models/PagoExterno.php` y la tabla `pagos_externos`: histórico de solo lectura.
- `Modules/Sales/app/Services/PayPalOrderVerifier.php`: se deja de usar y se marca obsoleto. Se eliminará cuando lo autorices.

### 5.5 No cambia
`composer.json`, `composer.lock`, Laravel, las dependencias, las migraciones existentes, los alquileres heredados ni `reservas.estado`.

---

## 6. Pruebas automatizadas (PayPal siempre con `Http::fake()`)

| # | Grupo | Casos |
|---|---|---|
| 1 | Dinero y conversión | Centavos; HALF_UP; `round(bob / tasa, 2)`; sin tasa del día → no disponible; la tasa del día anterior no se usa |
| 2 | Tipos de cambio | Solo el admin registra (encargado y vendedor → 403); uno por día; editar una tasa usada → rechazado; editar una no usada → revisión registrada |
| 3 | Crear la orden | Petición a PayPal con USD, `custom_id` = uuid y `PayPal-Request-Id`; pago `orden_creada`; stock y equipos sin cambios; misma `Idempotency-Key` → mismo pago |
| 4 | Captura correcta | `verificando` → `recibido` → `aplicado`; `NotaVenta` (PAYPAL por código); stock; carrito; eventos en orden |
| 5 | El navegador "dice aprobado" | Sin llamar a la captura, el pago **sigue en `orden_creada`**; ninguna ruta acepta un estado enviado por el cliente |
| 6 | Doble captura, doble clic, concurrencia | Una sola captura en PayPal (`Http::assertSentCount`), una `NotaVenta` |
| 7 | Orden ajena o reutilizada | `custom_id` o dueño distintos → rechazada; referencia repetida → UNIQUE |
| 8 | Captura que no cuadra (monto, moneda, `custom_id`) | `recibido` + `requiere_revision`; sin venta |
| 9 | *Timeout* o 5xx en la captura | Se queda en `verificando`; la conciliación con la orden `COMPLETED` → `recibido` → `aplicado`; **con la orden en error, no expira** |
| 10 | Fallo posterior (stock, excepción simulada en el aplicador) | Reintentos → `requiere_revision`; **el pago `recibido` no se borra ni cambia de estado de dinero** |
| 11 | Expiración | `iniciado` y `orden_creada` sin aprobar > 30 min → `expirado`; `orden_creada` `APPROVED` → se captura; `verificando` → **nunca** expira |
| 12 | Webhooks | Firma inválida → 400 sin procesar; válido `CAPTURE.COMPLETED` → `recibido`; `evento_id` repetido → ignorado; `REFUNDED` externo → reembolso `externo_webhook`; `REVERSED` → `revertido` + revisión |
| 13 | Reembolsos | Solicitar (encargado) y ejecutar (solo admin); parcial y total; Σ ≤ recibido; idempotencia (misma clave → un reembolso y una llamada); *timeout* → `incierto`, que no se descarta; USD con la tasa original; último parcial sin deriva |
| 14 | Presencial | Efectivo y tarjeta → `recibido` con caja abierta; tarjeta sin referencia → 422; transferencia y QR → `pendiente_verificacion` **sin venta**; verificar (encargado) → `recibido` + venta; rechazar → sin venta; referencia repetida → rechazada; doble envío del POS → un pago |
| 15 | Permisos | Matriz §4 por rol; el vendedor solo ve sus pagos; técnico, almacenero y cliente → 403 en `/finanzas/*`; un rol personalizado sin permisos → 403; el sembrado no quita permisos personalizados |
| 16 | Devoluciones | Aprobar **no modifica** `NotaVenta` ni sus detalles (comparación antes y después); guarda importes; crea el reembolso vinculado; las cantidades acumuladas no superan lo vendido; una garantía no genera reembolso |
| 17 | Reservas | El pago actualiza `estado_pago` (parcial o pagado) y **no** `estado`; no crea `reserva_equipos` |
| 18 | Transición de `pagos_externos` | Filas ficticias → copias con el mapeo de §3.6; la migración repetida no duplica; `pagos_externos` intacta; `down()` se niega si hay historial posterior |
| 19 | Configuración incompleta | Sin `secret` o `webhook_id` → sin botón; los endpoints no llaman a PayPal |
| 20 | Regresión | Las 29 pruebas de la Etapa 1 siguen en verde (adaptadas a las tablas nuevas) |

Se ejecutan con `.tools\ejecutar-pruebas.ps1` (SQLite) y `-Motor mysql` (`127.0.0.1:3310/sound_management_test`, mostrando antes la conexión). La salvaguarda de `tests/TestCase.php` sigue activa.

---

## 7. Riesgos y mitigación
| Riesgo | Mitigación |
|---|---|
| El plugin de fusión no registra `Finance` sin editar `composer.json` | Se comprueba primero; si falla, **me detengo y te informo** |
| Diferencias entre la API real de PayPal y la simulación | Las simulaciones siguen la documentación de Orders v2, Payments v2 y Webhooks; la prueba manual con *sandbox* queda pendiente (decisión 10) |
| Cambios en el POS y las devoluciones afectan a la operación | Pruebas 14 y 16; el comportamiento de la caja (saldo por método) se ajusta en la Etapa 3 |
| Bloqueos en concurrencia | Bloqueo por PK, transacciones cortas y reintentos; prueba 6 |
| Llamadas de red dentro de transacciones | Ninguna: se confirma (*commit*) antes y después de cada llamada a PayPal |

---

## 8. Confirmaciones pendientes (detalles que derivan de tus decisiones)

| # | Punto | Propuesta del diseño |
|---|---|---|
| **C1** | En el POS, con transferencia o QR, la venta (nota y descuento de stock) **solo se crea al verificar el pago**. Mientras tanto, el pago está `pendiente_verificacion` y no hay venta. | Aplicar así, por coherencia con "no registrar como recibido sin verificar" |
| **C2** | Reembolsos: el encargado **solicita** y solo el administrador **autoriza y ejecuta** | Así (decisión 9: el encargado es operativo; el reembolso es financiero) |
| **C3** | Reembolso de una venta cobrada en caja: se completa cuando el personal confirma que devolvió el dinero. Su efecto sobre el arqueo de caja se integra en la Etapa 3 | Así |
| **C4** | Tasa = **BOB por 1 USD**; el pago usa la tasa de la fecha (La Paz) en que se **crea** el intento | Así |

Cuando apruebes este diseño (y C1–C4), implementaré la Etapa 2 en este orden:
1. Módulo Finance y su registro.
2. Migraciones y modelos.
3. Dinero, tipo de cambio y servicios.
4. PayPal (orden, captura, conciliación, webhooks).
5. Presencial y POS.
6. Reembolsos y devoluciones.
7. Permisos y vistas.
8. Pruebas.

Iré ejecutando las pruebas en cada paso.
