<?php

return [
    'name' => 'Finance',

    // Moneda operativa de todos los importes (pagos, ventas, reservas)
    'moneda_operativa' => 'BOB',

    // Minutos tras los que un intento en línea sin aprobar puede expirar.
    // Nunca se aplica a pagos 'verificando' ni a capturas inciertas.
    'expiracion_minutos' => (int) env('PAGOS_EXPIRACION_MINUTOS', 30),

    // Reintentos de la aplicación (venta/reserva) ante bloqueos de base de datos
    'reintentos_aplicacion' => 3,

    // Horas tras las que un pago 'verificando' se marca para revisión (sin cambiar su estado)
    'verificando_alerta_horas' => 24,

    // Zona horaria para la fecha de aplicación del tipo de cambio
    'zona_horaria' => 'America/La_Paz',
];
