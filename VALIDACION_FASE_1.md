# VALIDACIÓN FASE 1 — DIAGNÓSTICO Y PLAN DE CORRECCIONES

**Fecha:** 2026-11-20
**Referencia:** `REVISION_CAMBIOS_FASE_1.md`
**Proyecto:** `E:\BadiStudio\sound-management-app`

**Alcance:** solo diagnóstico. No se modificó, revirtió ni corrigió ningún archivo; este informe es el único archivo nuevo. Tampoco se instalaron dependencias, se ejecutaron migraciones, se usó Docker, se ejecutó Git, se escribió en el disco C ni se accedió a `E:\ferreteria`.

**Método:** lectura de los archivos reales del proyecto (rutas, controladores, vistas, migraciones, configuración y Docker). Además, se hizo un análisis en memoria de las 82 vistas, que cruza cada enlace o formulario hacia rutas protegidas con el bloque `@can` que lo rodea.

---

## 1. Resumen ejecutivo

**Conclusión general:** las correcciones de seguridad de la Fase 1 van en la dirección correcta y cierran las vulnerabilidades críticas (SQL de IA abierto, checkout sin pago, IDOR en comprobantes, datos de clientes expuestos, CRUD sin autorización). **Ninguna debe revertirse.** Pero el sistema todavía **no es operable** por cuatro bloqueos:

1. **La cadena de migraciones está rota (problema anterior a la Fase 1).** `migrate` se detiene en la migración #21, así que en una BD limpia no se crean `pagos_externos`, `mantenimientos` (completa), `devoluciones`, `promociones` ni `cotizaciones`. Por la misma razón, **ninguna prueba con `RefreshDatabase` puede ejecutarse**.
2. **El checkout puede perder la trazabilidad de pagos cobrados**:
   - si la verificación falla después de la captura;
   - si falta el secreto de PayPal pero hay client-ID;
   - y, **en una BD limpia, siempre**: el checkout inserta `id_pago = 2`, nadie siembra `metodoPago` y la FK falla después del cobro (**hallazgo nuevo**, PP-07).
3. **Hay permisos concedidos que no se pueden usar:** vendedor y encargado tienen `sales.operate` y `rentals.operate`, pero vender y alquilar exigen una **caja abierta**, y solo el administrador puede abrirla (R-01).
4. **Con Docker, las imágenes subidas no se ven:** Nginx no puede leer `storage/app/public` (D-01).

**Errores propios de la Fase 1 confirmados:**
- El *fallback* de la conexión de reportes no funciona (D-03).
- El validador SQL rechaza las multiplicaciones (S-01).
- Hay botones visibles que dan 403 (R-03, R-04).
- Los bloqueos `FOR UPDATE` no tienen reintento (S-05).

**Balance de los 30 archivos de código y configuración tocados en la Fase 1 (21 modificados y 9 nuevos, sin contar los informes):**
- 15 se conservan tal cual.
- 11 necesitan corrección (10 filas en §6.2).
- 4 dependen de aprobación funcional: `config/access.php`, las rutas de Rentals, `PagoExterno.php` y la migración `pagos_externos`. Las 8 decisiones de §6.3 también afectan a archivos de los grupos anteriores.
- 0 deben revertirse.

---

## 2. Estado de las migraciones

### 2.1 Las 28 migraciones originales + 1 nueva

| # | Migración | Comprueba si existe | FKs hacia | Estado en BD limpia |
|---|---|---|---|---|
| 1 | `0001_01_01_000000_create_users_table` | No | — | ✅ |
| 2 | `0001_01_01_000001_create_cache_table` | No | — | ✅ |
| 3 | `0001_01_01_000002_create_jobs_table` | No | — | ✅ |
| 4 | `2023_01_01_000000_create_base_tables` | Sí | categoria, color, empleado, marca, medida, rol, usuario, volumen | ✅ |
| 5 | `2026_04_13_023624_create_bitacoras_table` | No | — | ✅ |
| 6 | `2026_05_05_083122_add_auth_fields_to_usuario_table` | No | — | ✅ |
| 7 | `2026_05_05_083423_rename_correo_to_email_in_usuario_table` | No | — | ✅ |
| 8 | `2026_05_05_140934_increase_tipo_persona_length_in_usuario_table` | No | — | ✅ |
| 9 | `2026_05_09_210214_create_carritos_table` | No | producto, usuario | ✅ |
| 10 | `2026_05_10_000000_fix_jobs_tables` | Sí (columna) | — | ✅ (no hace nada: `fechaFin` ya existe) |
| 11 | `2026_05_11_000001_add_campos_marca_categoria_producto` | No | — | ✅ |
| 12 | `2026_05_11_191528_create_cajas_table` | No | **ninguna** (`user_id` sin FK) | ✅ |
| 13 | `2026_05_11_195318_add_soft_deletes_to_producto_table` | No | — | ✅ |
| 14 | `2026_05_13_000000_add_categoria_to_cliente_table` | Sí (columna) | — | ✅ |
| 15 | `2026_05_13_060426_add_deleted_at_to_producto_table` | — | — | ✅ (vacía) |
| 16 | `2026_05_13_071914_add_deleted_at_to_producto_table` | — | — | ✅ (vacía, duplicada) |
| 17 | `2026_06_01_161833_add_costo_to_producto_table` | Sí (columna) | — | ✅ |
| 18 | `2026_06_02_000001_create_maquinarias_table` | No | — | ✅ |
| 19 | `2026_06_02_000002_create_alquileres_table` | No | metodoPago, usuario | ✅ |
| 20 | `2026_06_02_000003_create_alquiler_detalles_table` | No | alquileres, maquinarias | ✅ |
| 21 | `2026_06_21_221019_add_imagen_to_promociones_table` | **No** | — | ❌ **Falla:** `promociones` no existe todavía (se crea en la #27) |
| 22 | `2026_06_21_225458_add_cantidad_to_mantenimientos_table` | **No** | — | ❌ `mantenimientos` no existe (se crea en la #25) |
| 23 | `2026_06_21_230100_change_maquinaria_id_to_idproducto_in_mantenimientos_table` | **No** | maquinarias, producto | ❌ mismo motivo |
| 24 | `2026_06_22_000001_create_devoluciones_table` | Sí | **NotaVenta**, devoluciones, producto, usuario | ❌ `NotaVenta` no tiene migración |
| 25 | `2026_06_22_000002_create_mantenimientos_table` | Sí | maquinarias, usuario | ✅ (pero con la forma antigua, `maquinaria_id`) |
| 26 | `2026_06_22_000003_create_pedidos_reabastecimiento_table` | Sí | pedidos_reabastecimiento, producto, usuario | ✅ |
| 27 | `2026_06_22_000004_create_promociones_table` | Sí | producto, promociones | ✅ (sin `imagen`) |
| 28 | `2026_06_23_000001_create_cotizaciones_tables` | No | cotizaciones, producto, usuario | ✅ |
| **29** | **`2026_11_20_000001_create_pagos_externos_table` (Fase 1)** | No | **ninguna** | ✅ en sí misma; ⛔ **nunca se alcanza** |

### 2.2 Migración nueva `pagos_externos`

| Aspecto | Evaluación |
|---|---|
| Contenido | `id`, `proveedor`(30), `referencia_externa`(100), `referencia_captura`(100, nullable), `monto` decimal(12,2), `moneda` char(3), `estado`(20), `ci_usuario` int, `nro_factura` int nullable, timestamps. Clave única `(proveedor, referencia_externa)`; índices en `ci_usuario` y `nro_factura`. `down()` correcto. |
| Relaciones y FKs | Ninguna, **a propósito**: `NotaVenta` no tiene migración y la FK hacia `usuario.ci` impediría registrar un cobro si el usuario se borrara. Para un registro contable es correcto no depender en cascada. |
| Dependencias | Ninguna tabla. **Se puede ejecutar sola en una BD limpia**, pero **depende del orden**: `migrate` aborta en la #21, así que nunca llega a la #29. |
| Defectos menores | `estado` es texto libre (sin enum ni catálogo); falta un índice por `estado` (para conciliar); no guarda el *snapshot* del carrito ni el payload de PayPal (útiles para conciliar); `ci_usuario` NOT NULL impide registrar pagos huérfanos (p. ej. de un webhook sin sesión). |

### 2.3 Problemas de migraciones

| ID | Archivo / ubicación | Evidencia | Impacto | Solución | Riesgo de la solución |
|---|---|---|---|---|---|
| M-01 | Migraciones #21, #22 y #23 | Alteran tablas que se crean después (#25, #27) y no comprueban si existen. | `migrate` aborta y la BD queda a medias. **Las pruebas no pueden ejecutarse** (todas las Feature usan `RefreshDatabase`). | Renombrar a `2026_06_22_000005/6/7_*` y añadir `hasTable`/`hasColumn`. | Bajo: no hay ninguna instalación de esta app con migraciones registradas. |
| M-02 | #24 `create_devoluciones_table` | FK `nro_factura → NotaVenta(nro)`; `NotaVenta` solo existe en `ferreteria.sql`. | Falla la #24. | Migración nueva `2026_06_01_000000_create_legacy_sales_purchase_tables` (proveedor, NotaVenta, detalleNotaVenta, NotaCompra, detalleNotaCompra), copiada del volcado y con `hasTable`. | Bajo. Los tipos deben coincidir exactamente (INT con signo) para que las FKs encajen. |
| M-03 | #29 `pagos_externos` | §2.2 | No llega a crearse hasta corregir M-01 y M-02. | Mantener; añadir columnas de conciliación en la corrección de PayPal (§3.4). | Bajo. |
| M-04 | Seeders | No existe ningún seeder de `metodoPago`; `CartController.php:410` inserta `id_pago => 2`; `NotaVenta.id_pago` tiene FK a `metodoPago` (volcado). | **En una BD limpia todo checkout cobrado falla y queda como `requiere_reembolso`** (PP-07). El POS y los alquileres no tienen métodos que elegir. | `MetodoPagoSeeder` (1 Efectivo, 2 Tarjeta/PayPal). | Bajo. |
| M-05 | `AdminUserSeeder` | Fija `rol.id = 3` como "Cliente"; en el volcado ese id es "Almacenero". | En una BD importada renombraría un rol real. | Sembrar por nombre y no por id. | Bajo. |
| M-06 | #15 y #16 | Vacías y duplicadas. | Ninguno funcional. | Retirar. | Ninguno. |
| M-07 | #12 `cajas` | `user_id` sin FK ni índice compuesto. | Sin integridad; `lockForUpdate` sobre `cajas` recorre la tabla entera (S-04). | FK `user_id → usuario.ci` + índice `(user_id, estado)`. | Comprobar antes que no haya huérfanos. |

---

## 3. Análisis del flujo PayPal

### 3.1 Flujo actual, paso a paso (código real)

| Paso | Dónde ocurre | Qué pasa | Valoración |
|---|---|---|---|
| 1. Mostrar el botón | `carrito/index.blade.php:114`, `:166` | Se muestra si hay `PAYPAL_CLIENT_ID` (sin mirar el secreto). | ⚠️ PP-04 |
| 2. Crear la orden | Navegador, `actions.order.create()` (`:171-181`) | El monto sale del HTML renderizado (`$totalConDescuento`); sin `custom_id`/`invoice_id` ni `currency_code` explícito. | ⚠️ PP-01, PP-02 |
| 3. Aprobar y **capturar** | Navegador, `actions.order.capture()` | **El dinero se cobra aquí, antes de que el servidor intervenga.** | ⚠️ raíz de PP-03 |
| 4. Enviar al servidor | Formulario oculto con `paypal_order_id` | — | ✅ |
| 5. Credenciales | `CartController.php:341` `isConfigured()` | Sin secreto, responde "no disponible" **sin registrar nada**, aunque el cobro ya ocurrió. | ❌ PP-04 |
| 6. Duplicados (previo) | `:357` | Si la orden existe en `pagos_externos`, se rechaza. | ✅ |
| 7. Verificar | `PayPalOrderVerifier::verify` | OAuth + `GET /v2/checkout/orders/{id}`; `COMPLETED`, captura `COMPLETED`, moneda y monto en centavos. | ✅ en lo técnico. ⚠️ no comprueba a quién pertenece la orden (PP-01) |
| 8. Fallo de verificación | `:364-367` | Solo una línea en la bitácora; **nada en `pagos_externos`**. | ❌ PP-03 |
| 9. Registrar la venta | `:369-449` (transacción) | Bloquea productos, recalcula, `PagoExterno` 'aplicado', `NotaVenta`, detalles, stock y bitácora. | ✅ |
| 10. Duplicado concurrente | Clave única + `:455-461` | El segundo envío termina con "ya fue procesado". | ✅ |
| 11. Fallo tras el cobro | `:463-486` | `PagoExterno` 'requiere_reembolso' fuera de la transacción + bitácora; si eso también falla, `Log::critical`. | ✅ parcial; ⚠️ PP-05, PP-07 |
| 12. Reembolso y conciliación | — | **No existe**: no hay pantalla, ni llamada de reembolso, ni webhook, ni tarea de conciliación. | ❌ PP-06 |

### 3.2 Problemas de PayPal

| ID | Archivo / ubicación | Evidencia | Impacto | Solución | Riesgo |
|---|---|---|---|---|---|
| PP-01 | `carrito/index.blade.php:171-181`; `PayPalOrderVerifier.php` | La orden se crea en el navegador; el verificador no comprueba `custom_id`, `invoice_id` ni el pagador. | Cualquier orden `COMPLETED` con el mismo monto sirve una vez para cualquier carrito y usuario. Riesgo bajo de explotación, pero sin vínculo contable. | Crear la orden **en el servidor** con `custom_id` = ID interno del intento de pago y verificarlo al capturar. | Medio: cambia el JS del botón. |
| PP-02 | `carrito/index.blade.php:178`, `.env` `PAYPAL_CURRENCY=USD` | Se envía el total en BOB como valor USD. | **Se cobra unas 7 veces más** (problema anterior, que ahora el verificador "valida"). | Decisión de negocio: conversión con tipo de cambio guardado en el intento, o pasarela que acepte BOB. | Alto si se implementa mal (montos). |
| PP-03 | `CartController.php:362-367` | `catch (\RuntimeException)` solo llama a `Bitacora::registrar`. | Un cobro con API caída, *timeout* o carrito modificado entre la carga y el pago **no queda en `pagos_externos`**. | Registrar **siempre** el intento antes de capturar y el resultado de la captura (§3.4). | Bajo. |
| PP-04 | `carrito/index.blade.php:114,166`; `CartController.php:341` | La vista mira `client_id`; el servidor exige `client_id` y `secret`. | Con configuración incompleta: el cliente paga, la venta se rechaza y **no queda ningún rastro** (ni siquiera en la bitácora). | La vista debe usar `PayPalOrderVerifier::isConfigured()`; con la captura en el servidor (§3.4) el problema desaparece. | Bajo. |
| PP-05 | `CartController.php:388` y `:415` | `lockForUpdate()->max('nro')` sin reintento; cualquier `Throwable` termina en 'requiere_reembolso'. | Un *deadlock* o un *lock wait timeout* bajo concurrencia **marca un pago válido como reembolso**. | Reintentar la transacción de aplicación (`DB::transaction(..., 3)`) y separar "pago confirmado" de "venta aplicada". | Bajo. |
| PP-06 | — | No hay pantalla, comando ni webhook para 'requiere_reembolso'. | Los reembolsos dependen de que alguien revise la BD a mano; los contracargos y reversos de PayPal no se conocen. | Pantalla de conciliación + reembolso por API + webhook firmado + tarea programada (§3.4). | Medio. |
| PP-07 | `CartController.php:410`; seeders | `id_pago => 2` con FK a `metodoPago`, y nadie lo siembra. | **En una BD limpia todo checkout cobrado falla.** | `MetodoPagoSeeder` + leer el ID de método desde la configuración o por nombre. | Bajo. |
| PP-08 | `CartController.php:413-433` | El stock no se reserva antes del pago. | Si otro cliente compra el último artículo durante el pago → 'requiere_reembolso'. | Reserva temporal al crear la orden, o asumirlo con una conciliación ágil. | Medio (reservas con vencimiento). |
| PP-09 | `PagoExterno`, migración #29 | Sin *snapshot* del carrito, sin payload, sin `estado` indexado; `ci_usuario` NOT NULL. | Conciliación limitada; no admite eventos de webhook sin usuario. | Añadir columnas (§3.4). | Bajo. |

### 3.3 Lo que está bien (se conserva)
- La verificación del lado del servidor por API, con secreto solo en el servidor.
- Montos en centavos.
- La idempotencia con clave única.
- El bloqueo de productos.
- El registro de 'requiere_reembolso' cuando la venta falla después del cobro.
- La desactivación del checkout sin credenciales.

### 3.4 Flujo propuesto (sin perder nunca la trazabilidad de un pago)

```
Estados de pagos_externos:
 creada → aprobada → capturada → aplicada
                         └→ requiere_revision → reembolsada | aplicada_manual
 creada/aprobada → expirada (tarea programada)
```

1. **Crear la orden en el servidor:** `POST /carrito/pago/orden`.
   - El servidor calcula el total, guarda en `pagos_externos` un registro **`creada`** (monto, moneda, *snapshot* del carrito, `ci_usuario` y `custom_id` = UUID del registro) **y lo confirma (commit)**.
   - Luego crea la orden en PayPal (`POST /v2/checkout/orders`, con `PayPal-Request-Id` = UUID para que sea idempotente) y guarda `referencia_externa`.
2. **Aprobar en el navegador:** el SDK usa `createOrder` → ID devuelto por el servidor. **El navegador ya no captura.**
3. **Capturar en el servidor:** `POST /carrito/pago/capturar`.
   - El servidor llama a `POST /v2/checkout/orders/{id}/capture` (también con `PayPal-Request-Id`).
   - Verifica `custom_id`, monto y moneda, y **en una transacción corta y propia** pasa el registro a **`capturada`** (con `referencia_captura` y payload).
   - *A partir de aquí el pago ya está registrado, falle lo que falle después.*
4. **Aplicar la venta:** en otra transacción, con reintentos ante *deadlock* (NotaVenta, detalles, stock), el registro pasa a **`aplicada`** con `nro_factura`. Si falla, pasa a **`requiere_revision`**, con el motivo, y se avisa al administrador.
5. **Webhook** `POST /webhooks/paypal`: firma verificada con `verify-webhook-signature`.
   - Eventos `PAYMENT.CAPTURE.COMPLETED`, `DENIED`, `REFUNDED` y `REVERSED`.
   - Si llega la captura de un registro todavía `creada` o `aprobada`, el sistema la procesa como en el paso 3 (cubre el caso de que el navegador se cierre a mitad).
6. **Tarea programada** (`schedule:work`; el perfil `workers` ya existe en Docker):
   - pasa a `expirada` los registros `creada` de más de N minutos;
   - consulta a PayPal los `aprobada` o `capturada` que no avanzan;
   - alerta de los `requiere_revision`.
7. **Pantalla de conciliación** (admin y finanzas): lista por estado, detalle con el *snapshot*, acciones "Aplicar manualmente" y "Reembolsar" (`POST /v2/payments/captures/{id}/refund`), todo registrado en la bitácora.

Esto resuelve PP-01, PP-03, PP-04, PP-05, PP-06 y PP-09. PP-02 (moneda) y PP-08 (reserva de stock) son **decisiones de negocio**. Como la pasarela definitiva está pendiente, el flujo debería implementarse sobre una interfaz `PaymentGateway`, con PayPal como uno de sus drivers.

---

## 4. Matriz de permisos por rol

Fuentes: rutas de los 6 módulos, comprobaciones dentro de los controladores, `config/access.php`, `AdminMiddleware` y el análisis de las vistas. **No hay Policies en el proyecto.** La barra lateral se muestra a **cualquier usuario autenticado que no sea cliente** (`layouts/ferreteria.blade.php:16`), aunque no tenga ningún rol.

### 4.1 Operaciones permitidas por el backend

| Operación (ruta) | Admin | Encargado | Técnico | Almacenero | Vendedor | Cliente |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Catálogo público, carrito, cotizaciones propias | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Checkout en línea | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Crear/editar/borrar productos, `/api/producto` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| Ajuste de stock `/inventario` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Marcas y categorías (admin) | ✅ | ❌ | ❌ | ❌¹ | ❌ | ❌ |
| POS `ventas.*` | ✅ | ✅² | ❌ | ❌ | ✅² | ❌ |
| API de clientes (buscar y alta rápida) | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ |
| Comprobante de venta | ✅ | ✅ | ❌ | ❌ | ✅ | solo el suyo |
| Alquileres: listar y ver | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Alquileres: crear y devolver | ✅ | ✅² | ❌ | ❌ | ✅² | ❌ |
| Comprobante de alquiler | ✅ | ✅ | ✅ | ✅ | ✅ | solo el suyo |
| Maquinarias: listar | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Maquinarias: crear, editar, borrar | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Mantenimientos | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Caja (abrir, cortar, reportes) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Usuarios y asignación de roles | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Proveedores, compras, devoluciones, pedidos, promociones | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Bitácora y reportes IA | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `/trabajos` (ver sus propias asignaciones) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (vacío) |

¹ `CategoriaController::autorizarStaff()` sí admite al almacenero, pero la ruta exige `admin` (incoherencia anterior a la Fase 1).
² **Permitido pero imposible de completar:** `VentaController@store` y `AlquilerController@store` exigen una caja abierta a nombre del usuario, y solo el admin puede abrirla (R-01).

### 4.2 Botones y enlaces visibles en la interfaz

| Elemento | Admin | Encargado | Técnico | Almacenero | Vendedor | Cliente |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Barra lateral: Dashboard, Carrito, Catálogo, Marcas, Categorías, Trabajos | ✅ | ✅ | ✅ | ✅ | ✅ | — (sin barra) |
| Barra lateral: Ventas | ✅ | ✅ | — | — | ✅ | — |
| Barra lateral: Alquileres, Maquinarias | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| Barra lateral: Mantenimientos | ✅ | ✅ | ✅ | — | — | — |
| Barra lateral: Reportes IA y bloque admin | ✅ | — | — | — | — | — |
| Botón "Editar producto" en el catálogo (`categoria-recursiva:44`) | ✅ | **—** | — | **—** | — | — |
| "Nuevo producto" (dashboard y vista admin) | ✅ | **—** | — | **—** | — | — |
| "Nuevo alquiler" (`alquileres/index.blade.php:13`) | ✅ | ✅ | **✅ (403)** | **✅ (403)** | ✅ | — |
| "Registrar devolución" (`alquileres/show.blade.php:145`) | ✅ | ✅ | **✅ (403)** | **✅ (403)** | ✅ | — |
| "Alquilar" en el catálogo público (`maquinarias/catalogo.blade.php:28`) | ✅ | ✅ | **✅ (403)** | **✅ (403)** | ✅ | **✅ (403)**; invitado → login |
| "Nueva venta" (`ventas/index.blade.php:13`) | ✅ | ✅ | — | — | ✅ | — |
| Crear/editar/borrar maquinaria | ✅ | — | — | — | — | — |
| Panel de catálogo del layout (`ferreteria.blade.php:121-150`) | — | — | — | — | — | — (**código muerto**: `@if(can('admin'))` dentro de `@cannot('admin')`) |
| "Mis compras" y "Mis alquileres" (para llegar a los comprobantes propios) | — | — | — | — | — | **no existe** |

### 4.3 Diferencias entre backend y frontend

| ID | Archivo / ubicación | Evidencia | Impacto | Solución | Riesgo |
|---|---|---|---|---|---|
| R-01 | `Sales/routes/web.php:57-61` (caja solo admin) frente a `VentaController@store` y `AlquilerController@store` (exigen caja abierta) | La caja va detrás del middleware `admin`; las altas buscan `Caja::where('user_id', Auth::id())->where('estado','abierta')`. | Vendedor y encargado ven el POS y el alta de alquiler, pero **siempre reciben 422** "Debes realizar la apertura de la caja". | **Decisión funcional:** permitir que `sales.operate` abra y cierre **su propia** caja (habilidad nueva `cash.own`), o que el admin abra cajas a nombre de un cajero. | Medio (finanzas). |
| R-02 | `inventario/categoria-recursiva.blade.php:44`, `dashboard/index.blade.php:186`, `inventario/roles/almacenero.blade.php` | Los botones de producto usan `@can('admin')`; la ruta exige `can:inventory.manage`. | Encargado y almacenero tienen el permiso, pero **no tienen ningún botón** para ejercerlo. | Cambiar a `@can('inventory.manage')` en los botones de producto. | Bajo. |
| R-03 | `alquileres/index.blade.php:13`, `alquileres/show.blade.php:145` | Sin `@can`. | Técnico y almacenero ven acciones que dan 403. | `@can('rentals.operate')`. | Bajo. |
| R-04 | `maquinarias/catalogo.blade.php:28` | "Alquilar" → `alquileres.create` en una página pública. | Clientes, técnico y almacenero → 403 (para clientes ya pasaba antes). | Mostrarlo solo con `@can('rentals.operate')`, o cambiarlo por "Solicitar cotización" para clientes (cuadra con el futuro portal). | Bajo. |
| R-05 | `layouts/ferreteria.blade.php:16` | La barra lateral se muestra si `tipoPersona !== 'C'`. | Un empleado sin roles ve la barra (solo con enlaces públicos y Trabajos). | `@can('staff')`. | Bajo. |
| R-06 | `Inventory/routes/web.php:56-57` | `/inventario` (ajuste de stock) solo admin, cuando `inventory.manage` incluye al encargado y al almacenero. | Incoherencia de diseño. | **Decisión funcional:** pasarlo a `inventory.manage` o dejarlo solo para admin. | Medio (afecta al stock). |
| R-07 | `Inventory/routes/web.php:47-53` + `CategoriaController::autorizarStaff` | Ruta `admin` frente a un controlador que admite al almacenero. | Código del controlador inalcanzable. | Unificar con `inventory.manage` o quitar la comprobación del controlador. | Bajo. |
| R-08 | `VentaController.php:27-32` | Solo el admin ve todas las ventas. | El encargado solo ve las suyas. | Decisión funcional: habilidad `sales.view_all`. | Bajo. |
| R-09 | `AdminMiddleware` | Siempre responde con una redirección. | Las peticiones AJAX de un usuario sin permisos reciben HTML (p. ej. `/reportes-ia/consultar`). | Responder 403 JSON si `expectsJson()`. | Bajo. |
| R-10 | Rutas de mantenimientos | De `admin` (redirección) a `can:` (403). | Cambio de comportamiento para quien no es admin. | Aceptarlo o personalizar la página 403. | Ninguno. |
| R-11 | — | No hay "Mis compras" ni "Mis alquileres". | El cliente solo llega a sus comprobantes si conoce la URL. | Portal de clientes (fase posterior). | — |
| R-12 | Seeders | Los roles "Encargado" y "Técnico" no existen en la BD. | La matriz no se puede usar hasta crearlos. | Seeder de roles. | Bajo. |
| R-13 | `AlquilerController.php:25,52,64,174,184`, `MaquinariaController.php:17` | Quedan las comprobaciones antiguas `Gate::denies('admin') && tipoPersona === 'C'`. | Duplican lo que ya hace la ruta. Inofensivas, pero confunden. | Retirarlas al introducir Policies. | Bajo. |

---

## 5. Auditoría de Docker (archivos reales)

### 5.1 Verificación por aspecto

| Aspecto | Estado | Detalle |
|---|---|---|
| Build de desarrollo | ✅ coherente | `base` → `dev` (`CMD php-fpm`); el código entra por *bind mount*; `vendor` en un volumen con nombre. Hace falta `docker compose run --rm app composer install` antes del primer uso (documentado). |
| Build de producción | ⚠️ incompleto | `base` → `prod`: `COPY . .` + `composer install --no-dev` + `USER www-data`. No tiene servidor HTTP propio (necesita Nginx), ni `HEALTHCHECK`, ni `EXPOSE`, ni cachés de config, rutas y vistas. Copia `*.md`, `tests`, `stubs` y `docker/`. |
| PHP-FPM | ✅ | `php:8.4-fpm-bookworm`; escucha en 9000 (configuración por defecto de la imagen), sin publicar. Extensiones: pdo_mysql, mbstring, exif, pcntl, bcmath, gd, zip, intl, opcache. Cubre Laravel 11, dompdf y nwidart, y cumple el `php ^8.3` del lock. |
| `app.ini` | ✅ | `opcache.validate_timestamps=${PHP_OPCACHE_VALIDATE_TIMESTAMPS}` (1 en dev, 0 en prod); zona `America/La_Paz`. Límites de subida (10M/12M) coherentes con Nginx (`client_max_body_size 12M`). |
| Nginx | ✅ con una carencia | `fastcgi_pass app:9000` + `SCRIPT_FILENAME /var/www/public/index.php` coincide con el montaje del contenedor `app`. El orden de las regex es correcto. Se deniegan los archivos ocultos y cualquier `.php` que no sea `index.php`. **No sirve `/storage`** (D-01). |
| MySQL | ✅ | 8.4, utf8mb4, `lower_case_table_names=1` (irreversible para el volumen, D-06), healthcheck y `depends_on: service_healthy`. |
| Persistencia | ✅ | Volúmenes `sound_management_mysql` (datos) y `vendor`. No reutiliza nada de la ferretería. |
| `storage` y archivos públicos | ❌ | D-01. |
| Variables de entorno | ⚠️ | Contraseñas obligatorias (`${VAR:?}`); `DB_HOST`/`DB_PORT` se sobrescriben dentro de los contenedores (correcto). Fallan `DB_REPORTS_*` vacíos (D-03) y `APP_URL` (D-04). |
| Puertos | ✅ | `127.0.0.1:8080→80` (web) y `127.0.0.1:3307→3306` (db). No chocan con el 3306 de XAMPP. FPM no se publica. |
| Volúmenes | ✅ | Sin duplicados. |
| Compatibilidad entre servicios | ⚠️ | web → app:9000 ✅; app → db:3306 ✅. `web` no tiene acceso a `storage` ❌. `queue` y `scheduler` repiten `build`/`image` mediante el ancla (D-05). |
| `.dockerignore` | ⚠️ | No excluye `*.sql`, `storage/app/public/*`, `storage/app/private/*`, `tests`, `*.md` ni `docker/mysql` (D-02). |
| `.env.example` | ⚠️ | D-03, D-04; `DB_USERNAME=sound_app` no existe en XAMPP. |

### 5.2 Problemas de Docker

| ID | Archivo / ubicación | Evidencia | Impacto | Solución | Riesgo |
|---|---|---|---|---|---|
| D-01 | `docker-compose.yml` (servicio `web`), `docker/nginx/default.conf` | `CategoriaController.php:103` guarda en el disco `public`; 8 vistas usan `asset('storage/...')`; `web` solo monta `./public`; `public/storage` no existe. | **404 en todas las imágenes subidas** (logos, categorías, productos). Además, `storage:link` crea un enlace simbólico absoluto que puede fallar sobre un *bind mount* de Windows. | Montar `./storage/app/public:/var/www/storage/app/public:ro` en `web` y en Nginx usar `location /storage/ { alias /var/www/storage/app/public/; }`, sin depender del enlace. | Bajo. |
| D-02 | `Dockerfile` (target `prod`), `.dockerignore` | Sin servidor HTTP; `COPY . .` sin exclusiones de datos. | La imagen de producción no se puede desplegar sola y podría incluir volcados o archivos subidos. | Definir la estrategia de despliegue (Nginx + FPM en dos contenedores, o una imagen única con supervisor). Ampliar `.dockerignore`. Añadir `HEALTHCHECK`. | Medio. |
| D-03 | `config/database.php:77-78`, `.env.example:40-41` | `env('DB_REPORTS_USERNAME', env('DB_USERNAME'))` con la variable definida y **vacía** devuelve `''`. | Al activar los reportes IA sin el usuario de solo lectura, la conexión falla (usuario vacío). | `env('DB_REPORTS_USERNAME') ?: env('DB_USERNAME', 'root')` (y lo mismo para password, host y port), o comentar esas líneas en `.env.example`. | Bajo. |
| D-04 | `.env.example:5` | `APP_URL=http://localhost` cuando el puerto es 8080. | URLs absolutas incorrectas en correos, colas y CLI. | `APP_URL=http://localhost:8080`. | Ninguno. |
| D-05 | `docker-compose.yml` ancla `x-app` | `queue` y `scheduler` heredan `build` + `image`. | Construcciones redundantes. | Dejar `build` solo en `app`; en los demás, solo `image`. | Ninguno. |
| D-06 | `docker-compose.yml` (`--lower-case-table-names=1`) | Solo se puede fijar al inicializar el volumen. | Si después se quita, MySQL no arranca con ese volumen. | Documentarlo junto al servicio y no quitarlo nunca sin recrear el volumen. | — |
| D-07 | `Dockerfile` (target `dev`) | El maestro de FPM corre como root y `composer install` también; `vendor` queda como root. | En un host Linux, `storage/` montado podría no ser escribible por `www-data`. En Windows no afecta. | Argumento UID/GID y usuario no root en dev. | Bajo. |
| D-08 | `docker-compose.yml` healthcheck | `-p"$MYSQL_ROOT_PASSWORD"` en la línea de comandos. | La contraseña de root es visible en la lista de procesos del contenedor. | `MYSQL_PWD` o un archivo de opciones. | Ninguno. |

---

## 6. Archivos modificados y nuevos en la Fase 1, y su clasificación

### 6.1 Cambios correctos que conviene conservar (15)
| Archivo | Motivo |
|---|---|
| `app/Support/AccessControl.php` | Resuelve los roles una vez por petición; mantiene la semántica de los Gates históricos. |
| `app/Providers/AppServiceProvider.php` | Gates desde la configuración; para invitados devuelven `false`, como antes. |
| `Modules/Inventory/routes/web.php` | Cierra el CRUD de productos y la API con costos; elimina el error 500 de `show`. |
| `Modules/Sales/routes/web.php` | Cierra el POS y la API de clientes; añade `throttle`. |
| `Modules/Audit/routes/web.php` | Reportes IA solo para admin. |
| `Modules/Sales/app/Http/Controllers/VentaController.php` | Cierra el IDOR del comprobante; bloquea el correlativo. |
| `Modules/Rentals/app/Http/Controllers/AlquilerController.php` | Comprobante para el titular o el personal. |
| `Modules/Procurement/app/Http/Controllers/CompraController.php` | `withTrashed()` corrige IDs duplicados; bloqueo. |
| `Modules/Access/app/Http/Controllers/TrabajoController.php` | Transacción y corrección de NOT NULL. |
| `resources/views/bitacora/ia-reports.blade.php` | Corrige el XSS almacenado. |
| `resources/views/components/admin-sidebar.blade.php` | Coincide con el backend (comprobado por el análisis de vistas). |
| `config/services.php` | Secretos fuera del código; IA desactivada por defecto. |
| `docker/php/app.ini` | Correcto. |
| `docker/mysql/reports-user.sql.example` | Correcto (se usa después de migrar). |
| `.gitignore` | Correcto (dos reglas redundantes e inofensivas). |

### 6.2 Cambios que necesitan corrección (10)
| Archivo | Problemas |
|---|---|
| `Modules/Audit/app/Services/ReadOnlySqlGuard.php` | S-01, S-02 |
| `Modules/Audit/app/Http/Controllers/IaReportController.php` | Depende de D-03; por lo demás, correcto |
| `config/database.php` | D-03 |
| `.env.example` | D-03, D-04 |
| `Modules/Sales/app/Http/Controllers/CartController.php` | PP-03, PP-05, PP-07 |
| `Modules/Sales/app/Services/PayPalOrderVerifier.php` | PP-01 (rediseño §3.4) |
| `resources/views/carrito/index.blade.php` | PP-04, PP-01 |
| `Modules/Sales/app/Http/Controllers/CajaController.php` | S-04 |
| `docker-compose.yml` + `docker/nginx/default.conf` | D-01, D-05, D-08 |
| `Dockerfile` (+ `.dockerignore`, sin tocar en la Fase 1) | D-02, D-07 |

Fuera de la Fase 1, pero necesarias para que sus cambios funcionen: vistas de alquileres y productos (R-02, R-03, R-04), migraciones y seeders (M-01, M-02, M-04).

### 6.3 Cambios que requieren aprobación funcional (8)
| Archivo / decisión | Qué hay que aprobar |
|---|---|
| `config/access.php` | La matriz de roles. Que los roles personalizados pierdan Ventas y Alquileres. Que el Gate `almacenero` incluya al encargado. Que el técnico gestione mantenimientos. Los nombres `Cajero` y `Secretaria` como "vendedor". |
| `Modules/Rentals/routes/web.php` | El reparto `rentals.view` / `rentals.operate`; mantenimientos fuera de `admin`. |
| Checkout desactivado sin credenciales | Hoy la tienda en línea **no vende** hasta configurar PayPal. |
| Reportes IA desactivados y solo para admin | Pérdida de la función para el resto. |
| Comprobante de alquiler para el titular | Amplía el acceso de los clientes. |
| `CajaController` (una caja abierta por usuario) | Regla de negocio nueva. |
| `docker-compose.yml` (`lower_case_table_names=1`, MySQL 8.4) | Decisión de plataforma irreversible para el volumen. |
| Migración `pagos_externos` + `PagoExterno.php` | Aceptar la tabla nueva y su evolución (§3.4). |

### 6.4 Cambios que deben revertirse
**Ninguno.** Todos los cambios reducen riesgos frente al estado original; los que tienen defectos se corrigen mejor hacia adelante. Revertir el checkout o el validador **reabriría vulnerabilidades críticas** (ventas sin pago, SQL abierto).

---

## 7. Riesgos detectados

### 7.1 Código (errores de la Fase 1)
| ID | Archivo / ubicación | Evidencia | Impacto | Solución | Riesgo |
|---|---|---|---|---|---|
| S-01 | `ReadOnlySqlGuard.php` (comprobación de `*`) | Rechaza cualquier `*` salvo `COUNT(*)`. | Rechaza `SUM(precio_unitario * cantidad)`, el reporte de ventas más común. | Prohibir solo el comodín: `select\s+\*`, `,\s*\*`, `\w+\.\*`. | Bajo, si se cubre con pruebas. |
| S-02 | `ReadOnlySqlGuard.php` (regex de hex/binario) | `\bb'` y `\bx'` se evalúan sobre la consulta sin limpiar. | Falso rechazo de literales como `'b'` o `'x'`. | Evaluar sobre la consulta sin literales. | Bajo. |
| S-03 | `ReadOnlySqlGuard.php` (lista de tablas) | `FROM` dentro de funciones distintas de `EXTRACT`, `TRIM` y `SUBSTRING`. | Falso rechazo poco frecuente. | Ampliar la lista o usar un parser SQL. | Bajo. |
| S-04 | `CajaController.php` (`apertura`) | `lockForUpdate()->exists()` sin índice `(user_id, estado)`. | Recorre y bloquea toda la tabla `cajas`; con la tabla vacía, dos aperturas simultáneas pueden acabar en *deadlock* (error 500). | Índice + reintento. | Bajo. |
| S-05 | `VentaController`, `CartController`, `CompraController`, `TrabajoController` (`lockForUpdate()->max()`) | *Gap locks* compatibles entre sí cuando la tabla está vacía. | *Deadlock* sin reintento (en el checkout, un falso 'requiere_reembolso'). La eficacia sobre un agregado optimizado **no está verificada** en MySQL 8.4. | Tabla `sequences` con bloqueo de fila, o `AUTO_INCREMENT`; mientras tanto, `DB::transaction(..., 3)`. | Medio (toca los correlativos). |

### 7.2 Riesgos transversales
- **No existe ninguna prueba que respalde la Fase 1** y hoy no es posible escribirlas y ejecutarlas sin corregir M-01, M-02 y M-04.
- La moneda del checkout (PP-02) es un riesgo económico real en cuanto se configure PayPal.
- La matriz de roles se aplica sobre nombres de rol que todavía no existen en la BD (R-12).

---

## 8. Correcciones recomendadas por prioridad

| Prioridad | IDs | Corrección | Archivos |
|---|---|---|---|
| **P0: bloqueantes** | M-01, M-02, M-04, PP-07 | Reparar la cadena de migraciones y sembrar `metodoPago` y los roles base. | `database/migrations/*` (renombrar 3, 1 nueva), `database/seeders/*` |
| **P0** | — | Infraestructura de pruebas: alinear `User`/`Usuario` en los tests de Breeze y la factory; incluir `Modules/*/tests` en `phpunit.xml`. | `tests/*`, `database/factories/UserFactory.php`, `phpunit.xml` |
| **P1: dinero** | PP-03, PP-04, PP-05, PP-01, PP-06, PP-09 | Flujo de pago del §3.4 (orden y captura en el servidor, estados, webhook, conciliación). | `CartController`, `PayPalOrderVerifier`, `PagoExterno`, migración de columnas nueva, `carrito/index.blade.php`, rutas, comando programado, vista de conciliación |
| **P1** | R-01 | Decidir y aplicar la regla de la caja para vendedor y encargado. | `config/access.php`, `Sales/routes/web.php`, `CajaController` |
| **P1** | D-01 | Servir `storage` público en Docker. | `docker-compose.yml`, `docker/nginx/default.conf` |
| **P2: correcciones de la Fase 1** | D-03, S-01, S-02 | *Fallback* de reportes y validador SQL. | `config/database.php`, `.env.example`, `ReadOnlySqlGuard.php` |
| **P2** | R-02, R-03, R-04, R-05, R-09 | Alinear la interfaz con los permisos; 403 JSON. | `categoria-recursiva`, `dashboard/index`, `alquileres/index`, `alquileres/show`, `maquinarias/catalogo`, `layouts/ferreteria`, `AdminMiddleware` |
| **P2** | S-04, S-05, M-07 | Índice y FK de `cajas`; reintentos o secuencias. | Migración nueva, controladores afectados |
| **P3** | D-02, D-04, D-05, D-07, D-08, R-06, R-07, R-08, R-13, M-05, M-06 | Endurecimiento de Docker y limpieza. | `Dockerfile`, `.dockerignore`, `docker-compose.yml`, `.env.example`, rutas de Inventory, seeders |
| **Decisión de negocio** | PP-02, PP-08, R-06, R-08 | Moneda, reserva de stock, ajuste de stock y visibilidad de ventas. | — |

---

## 9. Orden de implementación

1. **Migraciones y seeders** (M-01, M-02, M-04, M-05, M-06). Sin esto no se puede probar nada.
2. **Infraestructura de pruebas:** tests de Breeze, factory y `phpunit.xml`. Ejecutar la suite en Docker (`docker compose run --rm app php artisan test`) y no en el PHP 8.2 local.
3. **Pruebas de caracterización** de lo hecho en la Fase 1 (matriz de rutas por rol, comprobantes, API de clientes, validador SQL), **antes** de cambiar nada más.
4. **Correcciones pequeñas de la Fase 1:** D-03, S-01, S-02, D-04.
5. **Docker:** D-01 (storage), D-05, D-08.
6. **Decisión e implementación de la caja (R-01)** y alineación de la interfaz (R-02 a R-05, R-09).
7. **Rediseño del flujo de pago (§3.4)** sobre una interfaz `PaymentGateway`, con su migración de columnas, el webhook, la tarea programada y la pantalla de conciliación.
8. **Concurrencia:** S-04, S-05, M-07.
9. **Producción:** D-02, D-07.
10. **Documentación:** corregir `FASE_1_ESTABILIZACION.md` (*fallback* y "sin ventas sin pago").

---

## 10. Pruebas necesarias para validar cada corrección

Todas en la suite del proyecto (PHPUnit sobre SQLite en memoria o MySQL en Docker). PayPal **siempre simulado** con `Http::fake()`: sin credenciales reales y sin cobros.

| Corrección | Pruebas |
|---|---|
| M-01, M-02, M-04 | `migrate:fresh --seed` en una BD desechable sin errores; existen `pagos_externos`, `NotaVenta`, `mantenimientos.idproducto`, `promociones.imagen` y `metodoPago` 1 y 2; `migrate:rollback` completo. |
| Infraestructura de pruebas | Los tests de Breeze en verde con `Usuario`; login, registro y perfil. |
| Matriz de roles (caracterización) | Prueba paramétrica: para cada uno de los 6 perfiles × cada ruta protegida (unas 45), código esperado (200/302/403) según §4.1. Comprobar que los invitados reciben `false` en los Gates. |
| Comprobantes (IDOR) | Venta: dueño 200, otro cliente 403, vendedor 200, técnico 403. Alquiler: titular 200, otro cliente 403, técnico 200. |
| API de clientes | Cliente 403; vendedor 200; `throttle` (61 peticiones → 429). |
| D-03 | Con `DB_REPORTS_USERNAME` vacío, `config('database.connections.mysql_reports.username') === config('database.connections.mysql.username')`. |
| S-01, S-02 | Pruebas unitarias del validador: los 26 casos existentes + `SUM(a*b)`, `precio * 1.13`, `WHERE x = 'b'`, `SELECT *`, `SELECT t.*`, `SELECT a, * FROM`, `UNION SELECT password`. |
| IA en ejecución | Con `Http::fake` de Groq: SQL válido → 200 con datos; SQL de escritura → 422; desactivado → 503; no admin → redirección o 403; se registra en la bitácora; truncado a `max_rows`. |
| XSS en reportes | Prueba de navegador o *snapshot* del JS: un valor `<img onerror>` se muestra escapado. |
| Flujo PayPal (§3.4) | Con `Http::fake`: (1) orden creada → registro `creada` con *snapshot*; (2) captura correcta → `capturada` → `aplicada` + NotaVenta + stock; (3) monto o moneda distinta → `requiere_revision` y sin venta; (4) `custom_id` ajeno → rechazo; (5) API caída en la captura → el registro existe y el webhook lo completa después; (6) stock insuficiente tras la captura → `requiere_revision` y stock intacto; (7) doble envío → una sola venta; (8) webhook con firma inválida → 400; (9) `REFUNDED` → `reembolsada`; (10) la tarea programada expira los `creada` antiguos; (11) credenciales incompletas → sin botón y sin cobro. |
| PP-07 | Checkout con la BD sembrada: NotaVenta con el `id_pago` correcto. |
| R-01 | Vendedor abre su propia caja y registra una venta; no puede cortar la caja de otro. |
| R-02 a R-04 | Pruebas de vista (`assertSee` / `assertDontSee`) por rol para "Editar producto", "Nuevo alquiler", "Registrar devolución" y "Alquilar". |
| R-09 | Petición JSON de un no admin a una ruta `admin` → 403 JSON. |
| S-04, S-05 | Prueba de concurrencia en MySQL (dos procesos): dos aperturas de caja simultáneas → una abierta, sin 500; N ventas paralelas → números únicos y consecutivos, sin `requiere_reembolso` espurio. |
| D-01 | (Manual, en Docker, cuando se autorice) subir un logo y obtener 200 en `/storage/...` a través de Nginx. |
| D-02 | (Cuando se autorice) construir `prod` y comprobar que no contiene `.env`, `*.sql`, `storage/app/public/*` ni `tests`. |
