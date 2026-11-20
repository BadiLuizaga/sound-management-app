<?php

namespace Tests\Feature\Instalacion;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fase 2 — Etapa 1: la base se construye desde cero con las migraciones.
 */
class EsquemaInstalacionTest extends TestCase
{
    use RefreshDatabase;

    public const TABLAS = [
        // Laravel
        'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        // Migración base
        'marca', 'color', 'medida', 'volumen', 'metodoPago', 'categoria', 'usuario', 'producto',
        'empleado', 'cliente', 'rol', 'estadoRol', 'bitacora',
        // Migraciones posteriores
        'bitacoras', 'carritos', 'cajas', 'maquinarias', 'alquileres', 'alquiler_detalles',
        'proveedor', 'NotaVenta', 'detalleNotaVenta', 'NotaCompra', 'detalleNotaCompra',
        'devoluciones', 'devolucion_detalles', 'mantenimientos',
        'pedidos_reabastecimiento', 'pedido_reabastecimiento_detalles',
        'promociones', 'promocion_productos', 'cotizaciones', 'cotizacion_detalles',
        'pagos_externos',
        // Reservas (estructura)
        'paquetes', 'paquete_items', 'reservas', 'reserva_paquetes', 'reserva_equipos',
        // Permisos
        'permisos', 'rol_permiso',
    ];

    public function test_todas_las_tablas_existen(): void
    {
        $faltan = array_values(array_filter(self::TABLAS, fn ($t) => !Schema::hasTable($t)));

        $this->assertSame([], $faltan, 'Tablas que no se crearon: ' . implode(', ', $faltan));
        $this->assertCount(49, self::TABLAS);
    }

    public function test_tablas_que_antes_solo_estaban_en_sql_externo_tienen_sus_claves(): void
    {
        $this->assertTrue(Schema::hasColumns('NotaVenta', ['nro', 'fecha', 'total', 'ci_cliente', 'ci_empleado', 'id_pago']));
        $this->assertTrue(Schema::hasColumns('detalleNotaVenta', ['nro_factura', 'id_producto', 'precio_unitario', 'cantidad', 'descuento']));
        $this->assertTrue(Schema::hasColumns('NotaCompra', ['nro', 'fecha', 'total', 'ci_proveedor', 'id_pago']));
        $this->assertTrue(Schema::hasColumns('proveedor', ['ci', 'nombre', 'descripcion', 'telefono', 'correo', 'direccion']));

        $this->assertForeignKey('NotaVenta', 'ci_cliente', 'cliente');
        $this->assertForeignKey('NotaVenta', 'ci_empleado', 'empleado');
        $this->assertForeignKey('NotaVenta', 'id_pago', 'metodopago');
        $this->assertForeignKey('detalleNotaVenta', 'nro_factura', 'notaventa');
        $this->assertForeignKey('NotaCompra', 'ci_proveedor', 'proveedor');
        $this->assertForeignKey('devoluciones', 'nro_factura', 'notaventa');
    }

    public function test_migraciones_reordenadas_dejan_la_forma_final(): void
    {
        $this->assertTrue(Schema::hasColumn('promociones', 'imagen'));
        $this->assertTrue(Schema::hasColumns('mantenimientos', ['idproducto', 'cantidad']));
        $this->assertFalse(Schema::hasColumn('mantenimientos', 'maquinaria_id'));
        $this->assertForeignKey('mantenimientos', 'idproducto', 'producto');
    }

    public function test_claves_foraneas_e_indices_nuevos(): void
    {
        $this->assertForeignKey('cajas', 'user_id', 'usuario');
        $this->assertForeignKey('reservas', 'ci_cliente', 'usuario');
        $this->assertForeignKey('reserva_paquetes', 'reserva_id', 'reservas');
        $this->assertForeignKey('reserva_paquetes', 'paquete_id', 'paquetes');
        $this->assertForeignKey('reserva_equipos', 'maquinaria_id', 'maquinarias');
        $this->assertForeignKey('paquete_items', 'maquinaria_id', 'maquinarias');
        $this->assertForeignKey('rol_permiso', 'id_rol', 'rol');
        $this->assertForeignKey('rol_permiso', 'permiso_id', 'permisos');

        $indices = collect(Schema::getIndexes('reserva_equipos'))->pluck('name')->map(fn ($n) => strtolower($n));
        $this->assertTrue($indices->contains('reserva_equipos_disponibilidad_index'));

        $indicesCajas = collect(Schema::getIndexes('cajas'))->pluck('name')->map(fn ($n) => strtolower($n));
        $this->assertTrue($indicesCajas->contains('cajas_user_id_estado_index'));

        $this->assertTrue(Schema::hasColumns('metodoPago', ['codigo', 'activo']));
        $this->assertTrue(Schema::hasColumn('maquinarias', 'cantidad_total'));
    }

    public function test_longitudes_coherentes_con_las_validaciones(): void
    {
        DB::table('usuario')->insert([
            'ci' => 5550001,
            'nombre' => str_repeat('N', 100),
            'apellido' => str_repeat('A', 100),
            'email' => str_repeat('e', 240) . '@prueba.bo',
            'domicilio' => str_repeat('D', 255),
            'telefono' => '+591 70000000',
            'tipoPersona' => 'C',
        ]);
        $this->assertSame(1, DB::table('usuario')->count());

        DB::table('marca')->insert(['id' => 1, 'nombre' => 'Marca']);
        DB::table('categoria')->insert(['idcategoria' => 1, 'nombre' => 'Categoría sin descripción']);
        DB::table('producto')->insert([
            'idproducto' => 1,
            'nombre' => str_repeat('P', 255),
            'imagen' => 'https://example.test/' . str_repeat('i', 2000),
            'precio' => 1,
            'cantidad' => 0,
            'id_marca' => 1,
            'id_categoria' => 1,
        ]);
        $this->assertSame(1, DB::table('producto')->count());
    }

    public function test_unicidad_en_base_de_datos(): void
    {
        DB::table('usuario')->insert(['ci' => 1, 'nombre' => 'A', 'apellido' => 'B', 'email' => 'repetido@prueba.bo', 'tipoPersona' => 'C']);

        $this->expectException(QueryException::class);
        DB::table('usuario')->insert(['ci' => 2, 'nombre' => 'C', 'apellido' => 'D', 'email' => 'repetido@prueba.bo', 'tipoPersona' => 'C']);
    }

    public function test_rol_con_nombre_repetido_es_rechazado_por_la_base(): void
    {
        DB::table('rol')->insert(['id' => 90, 'nombre' => 'Duplicado']);

        $this->expectException(QueryException::class);
        DB::table('rol')->insert(['id' => 91, 'nombre' => 'Duplicado']);
    }

    private function assertForeignKey(string $tabla, string $columna, string $tablaDestino): void
    {
        $existe = collect(Schema::getForeignKeys($tabla))->contains(function ($fk) use ($columna, $tablaDestino) {
            return in_array($columna, $fk['columns'], true)
                && strtolower($fk['foreign_table']) === strtolower($tablaDestino);
        });

        $this->assertTrue($existe, "Falta la clave foránea {$tabla}.{$columna} → {$tablaDestino}");
    }
}
