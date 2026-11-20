<?php

/*
|--------------------------------------------------------------------------
| Control de acceso (Fase 1 — estabilización)
|--------------------------------------------------------------------------
|
| Matriz central de roles y habilidades. Sustituye a los Gates con nombres
| de rol escritos a mano en AppServiceProvider. No requiere paquetes nuevos.
|
| 'roles'     : rol interno => nombres aceptados en la tabla `rol` (se
|               comparan sin mayúsculas ni tildes). Solo cuentan las
|               asignaciones con estado 'Activo' en `estadoRol` de usuarios
|               con tipoPersona = 'E'.
| 'abilities' : habilidad (Gate) => roles internos que la tienen.
|               El rol 'cliente' se deriva de tipoPersona = 'C'.
|
| Para dar acceso a un rol personalizado creado desde "Trabajos", añade su
| nombre a la lista del rol interno correspondiente.
|
*/

return [

    'roles' => [
        'admin'      => ['Administrador'],
        'encargado'  => ['Encargado'],
        'tecnico'    => ['Técnico', 'Tecnico'],
        // Roles heredados de la ferretería (se mantienen por compatibilidad)
        'almacenero' => ['Almacenero'],
        'vendedor'   => ['Vendedor', 'Cajero', 'Secretaria'],
    ],

    'abilities' => [
        // Gates históricos usados en vistas y controladores
        'admin'              => ['admin'],
        'almacenero'         => ['almacenero', 'encargado'],
        'cliente'            => ['cliente'],

        // Personal interno (cualquier rol de empleado activo)
        'staff'              => ['admin', 'encargado', 'tecnico', 'almacenero', 'vendedor'],

        // Inventario / catálogo
        'inventory.manage'   => ['admin', 'encargado', 'almacenero'],

        // Ventas en tienda y datos de clientes
        'sales.operate'      => ['admin', 'encargado', 'vendedor'],
        'customers.lookup'   => ['admin', 'encargado', 'vendedor'],

        // Equipos y alquileres
        'equipment.view'     => ['admin', 'encargado', 'tecnico', 'almacenero', 'vendedor'],
        'rentals.view'       => ['admin', 'encargado', 'tecnico', 'almacenero', 'vendedor'],
        'rentals.operate'    => ['admin', 'encargado', 'vendedor'],

        // Mantenimiento
        'maintenance.manage' => ['admin', 'encargado', 'tecnico'],

        // Reportes con IA (consultas de solo lectura)
        'reports.ai'         => ['admin'],

        // Finanzas (Fase 2 — Etapa 2). Técnico, almacenero y cliente: ninguno por defecto.
        'finance.payments.view_all'  => ['admin', 'encargado'],
        'finance.payments.view_own'  => ['admin', 'encargado', 'vendedor'],   // pagos de caja registrados por él
        'finance.payments.register'  => ['admin', 'encargado', 'vendedor'],   // cobro presencial en caja
        'finance.payments.verify'    => ['admin', 'encargado'],               // transferencias y QR
        'finance.payments.reconcile' => ['admin', 'encargado'],               // reintentar / aplicar manualmente
        'finance.refunds.request'    => ['admin', 'encargado'],               // solicitar y confirmar entrega en caja
        'finance.refunds.execute'    => ['admin'],                            // autorizar y ejecutar reembolsos
        'finance.exchange.manage'    => ['admin'],                            // tipos de cambio
        'finance.audit.view'         => ['admin'],                            // historial, webhooks
    ],

];
