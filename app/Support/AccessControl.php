<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Resuelve los roles internos de un usuario y evalúa habilidades según
 * config/access.php. Los roles se calculan una sola vez por usuario y
 * por petición (el servicio se registra como "scoped").
 */
class AccessControl
{
    /** @var array<string, string[]> */
    private array $cache = [];

    /**
     * @return string[] roles internos (admin, encargado, tecnico, cliente, ...)
     */
    public function rolesFor(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $key = (string) $user->getAuthIdentifier();

        return $this->cache[$key] ??= $this->resolve($user);
    }

    public function hasRole(?Authenticatable $user, string $role): bool
    {
        return in_array($role, $this->rolesFor($user), true);
    }

    public function allows(?Authenticatable $user, string $ability): bool
    {
        $allowed = (array) config("access.abilities.{$ability}", []);

        return $allowed !== [] && array_intersect($allowed, $this->rolesFor($user)) !== [];
    }

    public function forget(?Authenticatable $user = null): void
    {
        if ($user === null) {
            $this->cache = [];
            return;
        }

        unset($this->cache[(string) $user->getAuthIdentifier()]);
    }

    /**
     * @return string[]
     */
    private function resolve(Authenticatable $user): array
    {
        $roles = [];
        $tipo = (string) ($user->tipoPersona ?? '');

        if ($tipo === 'C' || strtolower($tipo) === 'cliente') {
            $roles[] = 'cliente';
        }

        if ($tipo === 'E' && method_exists($user, 'empleado')) {
            $nombresActivos = $user->empleado?->asignaciones()
                ->where('estado', 'Activo')
                ->with('rol')
                ->get()
                ->pluck('rol.nombre')
                ->filter()
                ->map(fn ($nombre) => $this->normalize($nombre))
                ->all() ?? [];

            foreach ((array) config('access.roles', []) as $interno => $nombres) {
                foreach ((array) $nombres as $nombre) {
                    if (in_array($this->normalize($nombre), $nombresActivos, true)) {
                        $roles[] = $interno;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($roles));
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }
}
