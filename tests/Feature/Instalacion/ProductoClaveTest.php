<?php

namespace Tests\Feature\Instalacion;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Producto;
use Modules\Sales\Models\Carrito;
use Tests\TestCase;

/**
 * Regresión: producto.idproducto es una clave asignada por la aplicación (sin
 * AUTO_INCREMENT). En MySQL, con $incrementing = true, Eloquent la reemplazaba por
 * lastInsertId() = 0 después de create(). En SQLite el fallo no se manifestaba
 * (lastInsertId devuelve el rowid), por eso esta prueba debe ejecutarse en MySQL.
 */
class ProductoClaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        DB::table('marca')->insertOrIgnore(['id' => 1, 'nombre' => 'Marca de prueba']);
        DB::table('categoria')->insertOrIgnore(['idcategoria' => 1, 'nombre' => 'Categoría de prueba']);
    }

    private function crear(int $id, ?string $nombre = null): Producto
    {
        return Producto::create([
            'idproducto' => $id, 'nombre' => $nombre ?? "Producto {$id}", 'precio' => '10.00',
            'cantidad' => 5, 'id_marca' => 1, 'id_categoria' => 1,
        ]);
    }

    public function test_el_modelo_declara_una_clave_no_incremental(): void
    {
        $p = new Producto();
        $this->assertFalse($p->getIncrementing());
        $this->assertSame('idproducto', $p->getKeyName());
    }

    public function test_create_conserva_el_idproducto_asignado(): void
    {
        $p = $this->crear(101);

        $this->assertSame(101, $p->idproducto, 'El modelo en memoria debe conservar el id asignado (en MySQL quedaba en 0)');
        $this->assertSame(101, $p->getKey());
        $this->assertTrue(DB::table('producto')->where('idproducto', 101)->exists());
        $this->assertFalse(DB::table('producto')->where('idproducto', 0)->exists());
    }

    public function test_el_id_del_modelo_recien_creado_sirve_para_claves_foraneas(): void
    {
        $cliente = \Database\Factories\UsuarioFactory::new()->cliente()->create();
        $p = $this->crear(202);

        Carrito::create(['ci_usuario' => $cliente->ci, 'idproducto' => $p->idproducto, 'cantidad' => 1]);

        $this->assertTrue(DB::table('carritos')->where('idproducto', 202)->exists());
    }

    public function test_crear_y_modificar_no_altera_otros_productos_existentes(): void
    {
        $this->crear(1, 'Existente A');
        $this->crear(2, 'Existente B');
        $antes = DB::table('producto')->orderBy('idproducto')->get()->toArray();

        // Alta de un producto nuevo: los existentes quedan idénticos
        $nuevo = $this->crear(3, 'Nuevo');
        $this->assertEquals($antes, DB::table('producto')->whereIn('idproducto', [1, 2])->orderBy('idproducto')->get()->toArray());

        // Modificar el recién creado afecta solo a su fila
        $nuevo->update(['nombre' => 'Nuevo modificado', 'cantidad' => 9]);
        $this->assertEquals($antes, DB::table('producto')->whereIn('idproducto', [1, 2])->orderBy('idproducto')->get()->toArray());
        $this->assertSame('Nuevo modificado', DB::table('producto')->where('idproducto', 3)->value('nombre'));

        // Baja lógica del recién creado: solo su fila recibe deleted_at
        $nuevo->delete();
        $this->assertNotNull(DB::table('producto')->where('idproducto', 3)->value('deleted_at'));
        $this->assertEquals($antes, DB::table('producto')->whereIn('idproducto', [1, 2])->orderBy('idproducto')->get()->toArray());
        $this->assertSame(3, DB::table('producto')->count());
    }

    public function test_find_y_lock_por_clave_siguen_funcionando(): void
    {
        $this->crear(7);

        $this->assertSame('Producto 7', Producto::find(7)?->nombre);
        DB::transaction(fn () => $this->assertSame(7, Producto::whereKey(7)->lockForUpdate()->first()?->idproducto));
    }
}
