<?php

namespace Modules\Finance\Tests\Support;

use Database\Factories\UsuarioFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Access\Models\Usuario;
use Modules\Finance\Models\TipoCambio;
use Modules\Finance\Services\TipoCambioService;
use Modules\Inventory\Models\Producto;
use Modules\Sales\Models\Caja;
use Modules\Sales\Models\Carrito;

/**
 * Datos ficticios para las pruebas financieras (solo en la base de pruebas).
 */
trait EscenarioFinanzas
{
    protected Usuario $admin;

    protected function prepararBase(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->admin = UsuarioFactory::new()->conRol('Administrador')->create();
        DB::table('marca')->insert(['id' => 1, 'nombre' => 'Marca de prueba']);
        DB::table('categoria')->insert(['idcategoria' => 1, 'nombre' => 'Categoría de prueba']);
    }

    protected function usuarioConRol(string $rol): Usuario
    {
        return UsuarioFactory::new()->conRol($rol)->create();
    }

    protected function cliente(): Usuario
    {
        return UsuarioFactory::new()->cliente()->create();
    }

    protected function producto(int $id, string $precio, int $stock): Producto
    {
        return Producto::create([
            'idproducto' => $id,
            'nombre' => "Producto {$id}",
            'precio' => $precio,
            'cantidad' => $stock,
            'id_marca' => 1,
            'id_categoria' => 1,
        ]);
    }

    protected function alCarrito(Usuario $u, Producto $p, int $cantidad): void
    {
        Carrito::create(['ci_usuario' => $u->ci, 'idproducto' => $p->idproducto, 'cantidad' => $cantidad]);
    }

    /** Tasa BOB por 1 USD con fecha de aplicación HOY (La Paz). */
    protected function tasaHoy(string $tasa = '6.960000'): TipoCambio
    {
        return TipoCambio::create([
            'moneda_base' => 'USD',
            'moneda_cotizada' => 'BOB',
            'tasa' => $tasa,
            'fecha_aplicacion' => app(TipoCambioService::class)->hoy()->toDateString(),
            'ci_registrado_por' => $this->admin->ci,
        ]);
    }

    /** Caja abierta del usuario (reutiliza la que ya tenga: una sola abierta por usuario). */
    protected function abrirCaja(Usuario $u, string $monto = '100.00'): Caja
    {
        return Caja::where('user_id', $u->ci)->where('estado', 'abierta')->first()
            ?? Caja::create([
                'user_id' => $u->ci,
                'monto_apertura' => $monto,
                'estado' => 'abierta',
                'fecha_apertura' => now(),
                'version_arqueo' => 2,
            ]);
    }
}
