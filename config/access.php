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
    ],

];
