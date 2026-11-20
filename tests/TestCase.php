<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Salvaguarda antes de cualquier prueba que borre la base.
     *
     * Se ejecuta en setUpTraits() (método de la clase base, que Laravel llama
     * ANTES de RefreshDatabase/DatabaseMigrations/DatabaseTruncation). No se
     * usa beforeRefreshingDatabase() porque el trait RefreshDatabase define
     * su propia versión vacía y, al ser un trait, anularía esta.
     *
     * Solo se permite:
     *  - SQLite en memoria, o
     *  - MySQL exactamente en 127.0.0.1:3310, base `sound_management_test`
     *    (servidor portátil aislado de .tools).
     * Cualquier otro destino aborta la ejecución SIN conectarse a la base.
     */
    protected function setUpTraits()
    {
        $traits = class_uses_recursive(static::class);

        if (isset($traits[RefreshDatabase::class])
            || isset($traits[DatabaseMigrations::class])
            || isset($traits[DatabaseTruncation::class])) {
            $this->asegurarBaseDePruebas();
        }

        return parent::setUpTraits();
    }

    private function asegurarBaseDePruebas(): void
    {
        $conexion = config('database.default');
        $cfg = config("database.connections.{$conexion}");
        $driver = $cfg['driver'] ?? null;

        if ($driver === 'sqlite' && ($cfg['database'] ?? null) === ':memory:') {
            return;
        }

        $esBaseDePruebas = $driver === 'mysql'
            && ($cfg['host'] ?? null) === '127.0.0.1'
            && (string) ($cfg['port'] ?? '') === '3310'
            && ($cfg['database'] ?? null) === 'sound_management_test';

        if (!$esBaseDePruebas) {
            fwrite(STDERR, sprintf(
                "\nABORTADO: las pruebas destructivas solo pueden usar SQLite en memoria o 127.0.0.1:3310/sound_management_test (destino actual: %s %s:%s/%s)\n",
                $driver ?? '?',
                $cfg['host'] ?? '?',
                $cfg['port'] ?? '?',
                $cfg['database'] ?? '?'
            ));
            exit(1);
        }
    }
}
