# FASE 2 — ETAPA 2: PLAN DEL SISTEMA DE PAGOS

**Estado:** 📋 **Diagnóstico y propuesta. Pendiente de aprobación.** No se ha modificado código, migraciones ni configuración.
**Fecha:** 2026-11-20
**Base de pruebas prevista:** `127.0.0.1:3310/sound_management_test` (MySQL 8.4.13 portátil) + SQLite en memoria. Sin credenciales reales ni cobros reales: PayPal siempre simulado con `Http::fake()`.

---

## PARTE 2.1 — DIAGNÓSTICO (código real)

### 1. Modelos y tablas de pagos

| Tabla / modelo | Qué guarda hoy | Carencias |
|---|---|---|
| `pagos_externos` / `Modules\Sales\Models\PagoExterno` (migración `2026_11_20_000001`) | `proveedor`, `referencia_externa` (UNIQUE con proveedor), `referencia_captura`, `monto`, `moneda`, `estado` (texto libre: `aplicado` \| `requiere_reembolso`), `ci_usuario` NOT NULL, `nro_factura` | Sin FKs; sin estados intermedios (no existe "orden creada", "recibido" ni "verificando"); sin *snapshot* del carrito; sin vínculo con métodos, reservas ni alquileres; sin historial; `ci_usuario` NOT NULL impide registrar eventos de webhook sin sesión. **Solo lo usa el checkout en línea.** |
| `metodoPago` / `MetodoPago` | `id`, `codigo` (UNIQUE), `nombre`, `activo`; EFECTIVO, TARJETA, TRANSFERENCIA, QR, PAYPAL | Correcto desde la Etapa 1. |
| `NotaVenta` / `FacturaVenta` | `nro`, `fecha`, `total`, `ci_cliente`, `ci_empleado`, `id_pago` (FK metodoPago) | **No registra el pago en sí**: ni importe recibido, ni referencia, ni moneda, ni estado. Un solo método por nota. |
| `alquileres` / `Alquiler` | `metodo_pago_id` (FK), `monto_garantia`, `total_estimado`, `total_real` | **Nunca registra un cobro**: ni anticipo, ni saldo, ni recepción o devolución de la garantía. |
| `reservas` / `Reserva` (Etapa 1) | `estado_pago` (`pendiente`\|`parcial`\|`pagado`\|`reembolsado`), `total`, `moneda` = BOB | Ninguna tabla de pagos la referencia todavía. |
| `cajas` / `Caja` | apertura, cierre, diferencia | No tiene movimientos; el saldo esperado se calcula sumando `NotaVenta.total` (ver §4). |

### 2. Controladores y rutas con dinero

| Ruta | Controlador | Qué hace |
|---|---|---|
| `POST /carrito/checkout` (`auth`, `throttle:10,1`) | `CartController@checkout` | Verifica la orden de PayPal ya **capturada por el navegador** y crea `PagoExterno` + `NotaVenta`. |
| `POST /ventas` (`can:sales.operate`) | `VentaController@store` | POS: crea `NotaVenta` con `id_pago` presencial. **No crea ningún registro de pago.** |
| `POST /alquileres`, `POST /alquileres/{id}/devolucion` (`can:rentals.operate`) | `AlquilerController` | Calcula totales y guarda `metodo_pago_id`. **No registra cobros.** |
| `/caja/*` (`admin`) | `CajaController` | Apertura, corte y reporte. |
| `POST /devoluciones/{id}/aprobar` (`admin`) | `DevolucionController@aprobar` | **Reduce `NotaVenta.total` y borra o modifica líneas de `detalleNotaVenta` sin registrar ningún reembolso.** |
| — | — | **No existen** rutas de administración de pagos, reembolsos, conciliación ni webhooks. |

### 3. Flujo actual de PayPal

```
Navegador                                   Servidor
─────────                                   ────────
actions.order.create({value: totalBOB})     (no interviene)
   └─ moneda del SDK = PAYPAL_CURRENCY (USD)
actions.order.capture()   ← COBRO REAL AQUÍ
POST /carrito/checkout {paypal_order_id} →  1. isConfigured() + método PAYPAL
                                            2. ¿orden ya usada? (pagos_externos)
                                            3. GET /v2/checkout/orders/{id}: COMPLETED, moneda, monto
                                            4. Transacción: PagoExterno 'aplicado' + NotaVenta + stock
                                            5. Si falla 4 → PagoExterno 'requiere_reembolso'
```

| # | Problema | Ubicación | Consecuencia |
|---|---|---|---|
| F1 | **Se envía el total en BOB como si fuera USD.** El SDK usa `currency=USD` y `value = $totalConDescuento`, que está en bolivianos. | `resources/views/carrito/index.blade.php` (createOrder) + `config/services.php` (`paypal.currency`) | Se cobra unas 7 veces más. **Incumple la regla "sin conversiones implícitas".** |
| F2 | La orden y la captura se hacen en el navegador | vista del carrito | El dinero se cobra **antes** de que el servidor sepa nada. |
| F3 | La orden no queda vinculada al usuario ni al intento de pago (sin `custom_id` ni `invoice_id`) | `PayPalOrderVerifier` | Cualquier orden `COMPLETED` del mismo importe se acepta una vez. |
| F4 | Si la verificación falla **después** del cobro (API caída, *timeout*, carrito cambiado), solo queda una línea en la bitácora | `CartController@checkout` (`catch RuntimeException`) | **Pago recibido sin registro en la tabla de pagos.** Incumple la regla de conservar todo pago recibido. |
| F5 | *Deadlock* o *lock timeout* al aplicar la venta, sin reintento | ídem | Un pago válido queda como `requiere_reembolso`. |
| F6 | Sin reembolso, conciliación ni webhook | — | Los reembolsos y contracargos no se conocen; los pagos en revisión dependen de una consulta manual. |
| F7 | El *token* OAuth se pide en cada verificación | `PayPalOrderVerifier::accessToken()` | Latencia y riesgo de límite de peticiones (menor). |

### 4. Integración con ventas y alquileres
- **Venta en línea:** 1 pago → 1 `NotaVenta` (`id_pago` = PAYPAL, ya resuelto por código).
- **POS:** `NotaVenta` con `id_pago` presencial.
  - Tarjeta, transferencia y QR **sin referencia** (nº de *voucher* u operación), así que **la misma transacción podría registrarse dos veces**.
  - En efectivo no se registra lo entregado ni el cambio.
- **Caja:** el saldo esperado suma **todas** las ventas del cajero, sea cual sea el método. Una venta con tarjeta o QR aumenta el "efectivo esperado". *(La lógica de caja es de la Etapa 3, pero necesita el registro de pagos de esta etapa.)*
- **Alquileres heredados:** sin cobros; `total_real` se calcula al devolver, pero nadie registra que se cobró.
- **Reservas (Etapa 1):** solo el esquema. `estado_pago` no se calcula a partir de nada.
- **Devoluciones:** cambian el total de la nota ya cobrada, sin reembolso ni rastro contable (ver §2).

### 5. Relaciones con los métodos de pago
- `NotaVenta.id_pago`, `NotaCompra.id_pago` y `alquileres.metodo_pago_id` tienen FK a `metodoPago(id)`.
- `pagos_externos` no tiene FK hacia nada.
- La resolución del método por código y la regla "presencial" (`MetodoPago::reglaPresencial()`) están bien desde la Etapa 1.

### 6. Estados actuales
| Entidad | Estados |
|---|---|
| `pagos_externos.estado` | `aplicado`, `requiere_reembolso` (texto libre, sin máquina de estados) |
| `reservas.estado_pago` | `pendiente`, `parcial`, `pagado`, `reembolsado` (enum; nadie lo actualiza) |
| `NotaVenta` | ninguno (se crea ya "pagada") |
| `alquileres.estado` | `activo`, `completado`, `cancelado`, `atrasado` (no tiene relación con el pago) |

### 7. Auditoría y trazabilidad
- Solo existe `Bitacora::registrar()`, con texto libre (`PAGO_RECHAZADO`, `PAGO_SIN_VENTA`, `INSERTAR notaventa`).
- No hay historial estructurado de transiciones por pago, ni registro de las respuestas del proveedor, ni de eventos recibidos.

### 8. Configuración de moneda
- La moneda operativa (BOB) **no está configurada en ninguna parte**: aparece solo en textos ("BOB", "Bs.") y en `reservas.moneda`.
- `services.paypal.currency` = `USD` (por defecto).
- **No existe ningún mecanismo de conversión**: el único "puente" es el error F1.
- **PayPal no admite BOB** como moneda de cobro, así que la conversión a USD es necesaria, y debe ser explícita.

### 9. Webhooks
**No existe ninguno**: ni ruta, ni controlador, ni verificación de firma, ni tabla de eventos. `bootstrap/app.php` no tiene excepciones de CSRF.

### 10. Pruebas existentes
- `tests/Feature/Instalacion/MetodoPagoTest::test_checkout_no_registra_venta_si_el_metodo_paypal_no_existe`: la única prueba del checkout.
- **No hay ninguna prueba del flujo de PayPal** (no se usa `Http::fake` en el proyecto).
- No hay pruebas del POS, de la caja ni de las devoluciones.

---

## PARTE 2.2 — PROPUESTA TÉCNICA

### A. Arquitectura recomendada

```
                       ┌──────────────── Modules/Sales/app/Payments ────────────────┐
 Controladores         │  PaymentService (orquesta; transiciones válidas)           │
 PagoEnLinea ─────────▶│  ConversionService (BOB → moneda del proveedor, explícita) │
 PayPalWebhook ───────▶│  Aplicadores: VentaEnLinea, PagoReserva                    │
 Admin\Pago ──────────▶│  PaymentGateway (interfaz) ← PayPalGateway                 │
 POS (VentaController) │                               (PayPalClient + token)       │
                       └──────────────┬─────────────────────────────────────────────┘
                                      ▼
            pagos  ──< pago_eventos   webhook_eventos   tipos_cambio
             │ FK: metodoPago, NotaVenta, reservas, cajas, usuario
```

**Principios:**
1. **Libro único de pagos** (`pagos`): todo dinero recibido o devuelto, sea cual sea el método.
2. **Dos estados independientes:**
   - `estado`: qué pasó con el dinero.
   - `estado_aplicacion`: si ya se generó la venta o la reserva.

   Así **un pago recibido nunca desaparece**: su registro se confirma (*commit*) **antes** de intentar aplicarlo.
3. **El servidor crea y captura la orden.** El navegador solo aprueba en PayPal.
4. **Importes siempre en BOB.** Cuando el proveedor cobra en otra moneda, se guardan los dos importes y el tipo de cambio usado.
5. **Idempotencia en todos los puntos de entrada:** crear, capturar, webhook, reembolso y registro presencial.
6. **Módulo:** se implementa dentro de `Modules/Sales`. Crear un módulo nuevo (`Finance`) obligaría a editar el autoload de `composer.json`; ver la decisión D8.

### B. Estados

**`pagos.estado` (dinero):**

| Estado | Significado | Hacia |
|---|---|---|
| `iniciado` | Intento creado en el servidor, sin orden en el proveedor | `orden_creada`, `fallido`, `expirado` |
| `orden_creada` | Orden creada en PayPal, esperando la aprobación del cliente | `verificando`, `recibido`, `fallido`, `expirado`, `cancelado` |
| `verificando` | Se pidió la captura pero el resultado es desconocido (*timeout* o error de red) | `recibido`, `fallido` (lo resuelve la conciliación o el webhook) |
| `recibido` | **Dinero confirmado** por el proveedor o por el personal (presencial) | `reembolsado_parcial`, `reembolsado`, `revertido` |
| `fallido` / `expirado` / `cancelado` | Sin dinero recibido | — (finales) |
| `reembolsado_parcial` / `reembolsado` | Devolución total o parcial (con su fila de reembolso enlazada) | `reembolsado` |
| `revertido` | Contracargo o reverso del proveedor | — (pasa a revisión) |

**`pagos.estado_aplicacion`:** `pendiente` → `aplicado` | `requiere_revision` → `aplicado_manual` | `no_aplica` (para reembolsos).

Cada transición se valida en `PaymentService` (las no permitidas lanzan una excepción) y se registra en **`pago_eventos`**, que es solo de inserción.

**`reservas.estado_pago`** se **calcula** a partir de los pagos `recibido` y `aplicado` (cobros − reembolsos frente a `total`). **Nunca modifica `reservas.estado`**: la confirmación sigue siendo independiente del pago.

### C. Esquema propuesto (migración nueva y aditiva)

Para respetar "no modificar migraciones sin autorización", se propone **una migración nueva** (`2026_11_21_000001_create_pagos_tables`) en lugar de editar `2026_11_20_000001`. Elimina `pagos_externos`, que hoy está vacía y es solo de pruebas, y crea las tablas siguientes (ver D1).

| Tabla | Columnas principales | Restricciones |
|---|---|---|
| `pagos` | `id`, `uuid` (UNIQUE, referencia pública y `custom_id`/`invoice_id` en PayPal), `tipo` (`cobro`\|`reembolso`), `pago_origen_id` (self, para reembolsos), `metodo_pago_id`, `proveedor` (`paypal`\|`manual`), `estado`, `estado_aplicacion`, **`monto` + `moneda` (BOB)**, `monto_proveedor` + `moneda_proveedor` + `tipo_cambio_id` + `tasa_aplicada`, `referencia_externa` (orden u operación), `referencia_captura`, `ci_usuario` (null), `ci_registrado_por` (null), `caja_id` (null), `nro_factura` (null), `reserva_id` (null), `snapshot` JSON (carrito o concepto, congelado), `respuesta_proveedor` JSON (saneada), `motivo_revision`, `expira_at`, `recibido_at`, `aplicado_at`, timestamps | FKs → metodoPago, NotaVenta, reservas, cajas, usuario, pagos; **UNIQUE (metodo_pago_id, referencia_externa)** y **UNIQUE (proveedor, referencia_captura)**: impiden reutilizar transacciones externas; índices (estado, expira_at), (estado_aplicacion), (ci_usuario); CHECK monto > 0 |
| `pago_eventos` | `pago_id`, `tipo` (creado, orden_creada, captura_solicitada, recibido, aplicado, error_aplicacion, webhook, conciliado, reembolso…), `estado_anterior`, `estado_nuevo`, `datos` JSON, `ci_usuario`, `ip`, `created_at` | FK pago (restrict); solo inserción |
| `webhook_eventos` | `proveedor`, `evento_id` (UNIQUE con proveedor), `tipo`, `firma_valida`, `payload` JSON, `procesado_at`, `resultado`, `error`, timestamps | Idempotencia de webhooks |
| `tipos_cambio` | `moneda_origen` (BOB), `moneda_destino` (USD), `tasa` DECIMAL(12,6) (BOB por 1 USD), `vigente_desde`, `vigente_hasta` (null), `fuente`, `ci_registrado_por`, timestamps | CHECK tasa > 0; índice (origen, destino, vigente_desde) |

`ReadOnlySqlGuard` y el prompt de reportes con IA se actualizarán: `pagos`, `pago_eventos` y `webhook_eventos` quedan **prohibidas** (contienen respuestas del proveedor), igual que `pagos_externos` hoy.

### D. Flujo de creación y captura (PayPal, Orders API v2)

```
1. Cliente pulsa "Pagar"  →  POST /pagos/paypal/orden            (auth, throttle)
   Servidor:
   a. Comprueba: PayPal configurado (client_id + secret + webhook_id), método PAYPAL activo,
      tipo de cambio vigente. Si algo falta → no se ofrece ni se cobra nada.
   b. Recalcula el carrito en el servidor (BOB) y congela el snapshot.
   c. INSERT pagos (estado 'iniciado', uuid, monto BOB, monto USD, tasa)  → COMMIT
   d. POST /v2/checkout/orders  (PayPal-Request-Id = uuid; amount USD; custom_id = invoice_id = uuid)
   e. UPDATE pagos → 'orden_creada', referencia_externa = order_id        → COMMIT
   f. Devuelve order_id al SDK.  ** No se reserva stock ni equipos. **

2. Cliente aprueba en PayPal  →  onApprove  →  POST /pagos/{uuid}/capturar   (auth; dueño del pago)
   Servidor:
   a. SELECT … FOR UPDATE del pago; si ya está 'recibido' → respuesta idempotente.
   b. POST /v2/checkout/orders/{id}/capture  (PayPal-Request-Id = uuid + '-captura')
      · timeout / 5xx → 'verificando' → COMMIT (la conciliación lo resuelve; nunca se pierde)
      · rechazo       → 'fallido'
   c. Verificación: status COMPLETED, captura COMPLETED, custom_id = uuid,
      order_id = referencia_externa, moneda y monto USD exactos.
      · Si PayPal cobró pero algo no cuadra → 'recibido' + 'requiere_revision' (el dinero queda registrado)
   d. UPDATE pagos → 'recibido', referencia_captura, respuesta → COMMIT   ◀ PAGO ASEGURADO

3. Aplicación (transacción aparte, hasta 3 reintentos ante deadlock):
   bloquear productos → recalcular contra el snapshot → NotaVenta + detalles + stock
   → pagos.estado_aplicacion = 'aplicado', nro_factura                  → COMMIT
   Si falla: 'requiere_revision' + motivo + aviso al administrador. El stock no se toca.
```

### E. Idempotencia

| Punto | Mecanismo |
|---|---|
| Crear la orden | `PayPal-Request-Id` = uuid del pago (PayPal devuelve la misma orden si se repite) |
| Capturar | Bloqueo de fila + "si ya está recibido, devolver el resultado" + `PayPal-Request-Id` propio |
| Reutilizar una orden o captura | UNIQUE `(metodo_pago_id, referencia_externa)` y `(proveedor, referencia_captura)` + `custom_id` = uuid |
| Doble clic o doble envío | Bloqueo de fila + estado; una sola `NotaVenta` |
| Aplicar | `estado_aplicacion` comprobado bajo bloqueo |
| Webhook | UNIQUE `(proveedor, evento_id)` |
| Reembolso | `PayPal-Request-Id` = uuid del reembolso; suma de reembolsos ≤ importe recibido |
| Pago presencial | UNIQUE `(metodo_pago_id, referencia_externa)`: el mismo *voucher*, transferencia o QR no se registra dos veces |

### F. Manejo de errores

| Momento | ¿Hay dinero? | Resultado |
|---|---|---|
| Configuración incompleta, sin tipo de cambio o método inactivo | No | No se muestra el botón; los endpoints responden sin llamar a PayPal |
| Error al crear la orden | No | `fallido`; mensaje genérico |
| Cliente cancela o no aprueba | No | `orden_creada` → `expirado` (conciliación) |
| *Timeout* o 5xx en la captura | Desconocido | `verificando` → conciliación o webhook |
| Captura rechazada | No | `fallido` |
| Captura correcta pero datos que no cuadran | **Sí** | `recibido` + `requiere_revision` |
| Fallo al crear la venta (stock, *deadlock* tras reintentos) | **Sí** | `recibido` + `requiere_revision`; stock intacto |

En todos los casos: se registra un `pago_eventos`, un log técnico sin secretos y un mensaje genérico al usuario. Nunca se devuelve la respuesta cruda del proveedor.

### G. Conciliación
Comando `php artisan pagos:conciliar`. En Docker se programa con el perfil `workers`; en local se ejecuta a mano.
1. `iniciado` u `orden_creada` con `expira_at` vencido → consulta la orden; si no está capturada → `expirado`.
2. `verificando` → `GET` de la orden o captura → `recibido` o `fallido`.
3. `recibido` + `pendiente` → reintenta la aplicación.
4. `requiere_revision` → lista e informe.

**Pantalla de administración** (`/admin/pagos`): filtros por estado, detalle con el *snapshot* y el historial de eventos, y las acciones "Reintentar aplicación", "Marcar aplicado manualmente" (con motivo) y "Reembolsar". Requiere los permisos `payments.view`, `payments.refund` y `payments.reconcile` (permisos nuevos; ver D9).

### H. Webhooks firmados
- `POST /webhooks/paypal`: sin sesión ni CSRF (excepción en `bootstrap/app.php`), con `throttle`.
- La firma se verifica con `POST /v1/notifications/verify-webhook-signature`, usando las cabeceras `PAYPAL-TRANSMISSION-*` y `PAYPAL_WEBHOOK_ID`. Si es inválida → **400**, se registra en `webhook_eventos` con `firma_valida=false` y no se procesa.
- Si es válida: se guarda en `webhook_eventos` (idempotente por `evento_id`) y se responde **200** aunque el procesamiento falle; la conciliación lo reintenta.

| Evento | Acción |
|---|---|
| `PAYMENT.CAPTURE.COMPLETED` | Pago `verificando`/`orden_creada` → `recibido` (y aplicación) |
| `PAYMENT.CAPTURE.DENIED` / `DECLINED` | → `fallido` |
| `PAYMENT.CAPTURE.REFUNDED` | Crea o confirma la fila de reembolso → `reembolsado(_parcial)` |
| `PAYMENT.CAPTURE.REVERSED` | → `revertido` + `requiere_revision` |
| `CHECKOUT.ORDER.APPROVED` | Si el navegador se cerró antes de capturar, el servidor captura (paso 2) |

### I. Conversión BOB → USD (necesaria, porque PayPal no admite BOB)
- **Fuente explícita:** tabla `tipos_cambio`, registrada por un administrador. **No hay tasa por defecto en el código.** Sin una tasa vigente, el pago en línea queda desactivado.
- **Definición:** `tasa` = BOB por 1 USD. `monto_usd = round(monto_bob / tasa, 2)` con redondeo `HALF_UP` (ver D2).
- **Verificable:** cada pago guarda `monto` (BOB), `monto_proveedor` (USD), `tipo_cambio_id` y `tasa_aplicada`. La captura se compara contra el USD **guardado**, no contra uno recalculado.
- **Transparencia:** el carrito muestra "Total Bs. X — se cobrará USD Y (tipo de cambio Z al dd/mm/aaaa)".
- `NotaVenta.total` sigue en BOB.

### J. Pagos presenciales (Efectivo, Tarjeta, Transferencia, QR)
- El POS crea, **en la misma transacción** que la `NotaVenta`, un `pago` con estado `recibido` y `aplicado`, `ci_registrado_por` y `caja_id` (caja abierta del usuario).
- Tarjeta, Transferencia y QR **exigen referencia** (nº de autorización u operación), que es única por método.
- Efectivo: importe recibido y cambio.
- La "verificación" es la del personal autorizado (ver D3 para transferencia y QR).

### K. Reembolsos
- Fila `pagos` con `tipo = reembolso` y `pago_origen_id`; total o parcial, con la suma acumulada ≤ importe recibido.
- PayPal: `POST /v2/payments/captures/{id}/refund` con `PayPal-Request-Id`. Presencial: solo registro.
- Todo reembolso queda en `pago_eventos` y en la bitácora.
- **Devoluciones de ventas (D5):** en lugar de modificar la nota en silencio, generarían un reembolso enlazado.

---

## L. Archivos

### Nuevos
| Archivo | Propósito |
|---|---|
| `database/migrations/2026_11_21_000001_create_pagos_tables.php` | `pagos`, `pago_eventos`, `webhook_eventos`, `tipos_cambio`; elimina `pagos_externos` (D1) |
| `config/pagos.php` | Moneda operativa `BOB`, expiración de intentos, reintentos, proveedores habilitados |
| `Modules/Sales/app/Models/{Pago, PagoEvento, WebhookEvento, TipoCambio}.php` | Modelos |
| `Modules/Sales/app/Payments/EstadoPago.php`, `EstadoAplicacion.php` | Enums PHP de estados + transiciones válidas |
| `Modules/Sales/app/Payments/Contracts/PaymentGateway.php` | `crearOrden`, `capturar`, `consultarOrden`, `reembolsar`, `verificarWebhook` |
| `Modules/Sales/app/Payments/PayPal/{PayPalClient, PayPalGateway}.php` | HTTP, OAuth con token en caché, *timeouts*, cabeceras de idempotencia |
| `Modules/Sales/app/Payments/{PaymentService, ConversionService}.php` | Orquestación y conversión explícita |
| `Modules/Sales/app/Payments/Aplicadores/{VentaEnLinea, PagoReserva}.php` | Generar la venta y recalcular `reservas.estado_pago` |
| `Modules/Sales/app/Http/Controllers/{PagoEnLineaController, PayPalWebhookController}.php` | Crear y capturar; webhook |
| `Modules/Sales/app/Http/Controllers/Admin/{PagoController, TipoCambioController}.php` | Conciliación, reembolsos, tipos de cambio |
| `Modules/Sales/app/Console/ConciliarPagos.php` | `pagos:conciliar` |
| `resources/views/admin/pagos/{index, show}.blade.php`, `resources/views/admin/tipos-cambio/index.blade.php` | Administración |
| `tests/Feature/Pagos/*.php`, `tests/Unit/Pagos/*.php` | Pruebas (§M) |

### Modificados
| Archivo | Cambio |
|---|---|
| `Modules/Sales/routes/web.php` | Rutas nuevas; `carrito.checkout` antiguo redirige al flujo nuevo |
| `bootstrap/app.php` | Excepción CSRF solo para `webhooks/paypal` |
| `Modules/Sales/app/Http/Controllers/CartController.php` | Se retira la lógica de `checkout()` (pasa a `PaymentService`); el carrito no cambia |
| `resources/views/carrito/index.blade.php` | El SDK usa `createOrder` y `onApprove` del servidor; se muestran BOB, USD y la tasa |
| `Modules/Sales/app/Http/Controllers/VentaController.php` | El POS registra un `pago` presencial con referencia (J) |
| `Modules/Procurement/app/Http/Controllers/DevolucionController.php` | Reembolso enlazado (solo si se aprueba D5) |
| `Modules/Sales/app/Providers/SalesServiceProvider.php` | Registrar el comando y el *binding* de `PaymentGateway` |
| `config/services.php`, `.env.example` | `PAYPAL_WEBHOOK_ID`; la moneda del proveedor deja de usarse para importes en BOB |
| `config/access.php`, `database/seeders/PermisoSeeder.php` | Permisos `payments.view`, `payments.refund`, `payments.reconcile`, `exchange.manage` (D9) |
| `Modules/Audit/app/Services/ReadOnlySqlGuard.php` | Prohibir las tablas nuevas en los reportes con IA |
| `tests/Feature/Instalacion/{MetodoPagoTest, EsquemaInstalacionTest, SeedersInstalacionTest}.php` | Adaptar a las tablas y rutas nuevas |
| `routes/console.php` | Programar `pagos:conciliar` |

### Eliminados (justificación)
| Archivo | Motivo |
|---|---|
| `Modules/Sales/app/Services/PayPalOrderVerifier.php` | Lo sustituye `PayPalGateway` (crear y capturar en el servidor) |
| `Modules/Sales/app/Models/PagoExterno.php` | Lo sustituye `Pago`; la tabla `pagos_externos` está vacía y nunca se usó en producción |

**No cambia:** `composer.json`, `composer.lock`, Laravel ni las dependencias. Se usa solo `Http` de Laravel (Guzzle ya está instalado).

---

## M. Pruebas automatizadas (todas con `Http::fake`, sin red ni credenciales reales)

Se ejecutan con `.tools\ejecutar-pruebas.ps1`, en SQLite y en `127.0.0.1:3310/sound_management_test`.

| # | Escenario | Comprobación |
|---|---|---|
| 1 | Conversión | Tasa 6.96 → USD redondeado HALF_UP; sin tasa vigente → pago en línea no disponible |
| 2 | Crear la orden | La petición a PayPal lleva USD, `custom_id` = uuid y `PayPal-Request-Id`; pago `orden_creada`; **stock y equipos sin cambios** |
| 3 | Captura correcta | `recibido` → `aplicado`; `NotaVenta` con `id_pago` = PAYPAL; stock descontado; carrito vacío; eventos registrados |
| 4 | Doble captura o doble clic | Una sola `NotaVenta` y un solo `recibido` |
| 5 | Orden ajena o reutilizada | Rechazada (`custom_id` distinto o UNIQUE) |
| 6 | Monto o moneda distintos tras capturar | `recibido` + `requiere_revision`; sin venta |
| 7 | *Timeout* o 500 en la captura | `verificando`; luego `pagos:conciliar` con la orden COMPLETED → `recibido` → `aplicado` |
| 8 | Stock insuficiente tras capturar | `recibido` + `requiere_revision`; stock intacto |
| 9 | Excepción en el aplicador (simula *deadlock*) | Reintentos y después `requiere_revision`; el pago se conserva |
| 10 | Webhook con firma inválida | 400; nada procesado |
| 11 | Webhook válido `CAPTURE.COMPLETED` para un pago `verificando` | `recibido`; el mismo `evento_id` repetido se ignora |
| 12 | Webhook `REFUNDED` / `REVERSED` | Fila de reembolso / `revertido` + revisión |
| 13 | Expiración | `orden_creada` vencida → `expirado`; sin efecto en el stock |
| 14 | Reembolso total o parcial (admin) | Fila enlazada; no supera lo recibido; usuario sin permiso → 403 |
| 15 | Acceso | Un cliente no ve ni captura pagos de otro; las rutas de admin exigen permiso |
| 16 | POS presencial | Referencia obligatoria para tarjeta, transferencia y QR; referencia repetida rechazada; exige caja abierta |
| 17 | Pago de reserva | Actualiza `estado_pago` (parcial o pagado) **sin cambiar `estado`** |
| 18 | Configuración incompleta | Sin botón; los endpoints no llaman a PayPal |
| 19 | Regresión | Las 29 pruebas de la Etapa 1 siguen en verde |

---

## N. Riesgos
| Riesgo | Mitigación |
|---|---|
| Cambiar el flujo del checkout rompe la compra en línea | Hoy está desactivado sin credenciales; las pruebas 2 a 9 cubren el flujo nuevo |
| Errores de redondeo en la conversión | Centavos enteros; el USD se guarda y se compara contra lo guardado |
| Webhooks que llegan antes que la captura del navegador | Bloqueo de fila e idempotencia por estado |
| Cambios en el POS afectan a la caja | El POS solo **añade** el registro de pago; la lógica de caja se ajusta en la Etapa 3 |
| Diferencias de la API real de PayPal frente a lo simulado | Las pruebas se basan en la documentación de Orders v2; la validación real queda para *sandbox* con credenciales de prueba, **que proporcionarás tú** cuando se autorice |

---

## O. Decisiones que necesito antes de implementar

| # | Decisión | Recomendación |
|---|---|---|
| **D1** | ¿Migración nueva que elimina `pagos_externos` y crea las tablas nuevas, o editar `2026_11_20_000001`? | **Migración nueva** (aditiva; el repositorio ya se publicó con la anterior) |
| **D2** | Tipo de cambio: ¿quién lo registra, cada cuánto vence, redondeo y si se aplica un recargo o margen? | Admin y encargado lo registran; vigencia hasta que se registre otro; HALF_UP a 2 decimales; sin recargo |
| **D3** | Transferencia y QR: ¿se dan por recibidos al registrarlos el personal con su referencia, o quedan "pendientes de verificación" hasta confirmarlos en el banco? | Recibido al registrarlo un usuario con permiso, con referencia obligatoria y única |
| **D4** | Reembolsos de PayPal: ¿ejecutarlos desde la aplicación (API) o solo registrar reembolsos hechos fuera? ¿Se permiten parciales? | Desde la aplicación, con el permiso `payments.refund`; parciales permitidos |
| **D5** | Devoluciones de ventas: ¿generar un reembolso enlazado en lugar de modificar la nota en silencio? | Sí; la nota original no se modifica |
| **D6** | Alquileres heredados (`maquinarias`): ¿se dejan sin cobros, porque los sustituirán las reservas por paquetes? | Dejarlos sin cambios en esta etapa |
| **D7** | Expiración de un intento de pago en línea sin aprobar | 30 minutos (no bloquea stock ni equipos) |
| **D8** | Ubicación: `Modules/Sales` o módulo nuevo `Finance` (requiere editar el autoload de `composer.json`, sin cambiar dependencias) | `Modules/Sales` ahora |
| **D9** | Permisos nuevos (`payments.*`, `exchange.manage`) y a qué roles se asignan | Admin: todos; encargado: ver y conciliar; vendedor: registrar pagos presenciales |
| **D10** | Credenciales *sandbox* de PayPal para una prueba manual final | Opcional; las pruebas automáticas no las necesitan |
