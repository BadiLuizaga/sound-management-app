<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 — Etapa 3: las cajas YA CERRADAS se calcularon con el arqueo heredado.
 * Se marcan explícitamente con version_arqueo = 1 y NO se recalculan: sus
 * importes (monto_cierre, diferencia) quedan exactamente como estaban.
 * Las cajas abiertas al migrar se cerrarán con el arqueo nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cajas')->where('estado', 'cerrada')->update(['version_arqueo' => 1]);
    }

    public function down(): void
    {
        // Nada que revertir: la columna desaparece con la migración 000001.
    }
};
