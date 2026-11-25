<?php

return [
    'name' => 'Rentals',

    /*
    | Margen de preparación entre reservas consecutivas (D4, C1), en minutos.
    | Se aplica ANTES y DESPUÉS de cada reserva al calcular la disponibilidad:
    | una reserva [s, e) choca con una solicitud [a, b) si s − M < b y e + M > a.
    | No se aplica a los bloqueos por mantenimiento o daño. Un cambio afecta
    | solo a las confirmaciones nuevas, nunca a las ya confirmadas.
    */
    'margen_preparacion_minutos' => 60,

    /*
    | Plazo de seguridad de una reserva 'pendiente' (D2), en minutos. La
    | confirmación es automática al crearla; si por cualquier motivo no llega a
    | confirmarse ni rechazarse, la tarea `reservas:expirar` la marca 'expirada'.
    */
    'expiracion_pendiente_minutos' => 15,
];
