# FASE 0 — PREPARACIÓN DEL PROYECTO SOUND MANAGEMENT

**Fecha:** 2026-11-20
**Origen:** `E:\ferreteria` (rama `main`, commit `d39fe9c473021f43f2a4712ad828f1071c64cde3`, árbol de trabajo limpio)
**Destino:** `E:\BadiStudio\sound-management-app`
**Auditoría previa:** `E:\BadiStudio\sound-management\AUDITORIA_SISTEMA_SONIDO.md` (sin cambios)

No se instalaron dependencias, no se actualizó Laravel, no se ejecutaron migraciones ni pruebas, no se inició Docker, no se modificó código funcional y no se hizo ningún commit ni push.

---

## 1. Copia realizada

- Método: `tar` con exclusiones, desde `E:\ferreteria` hacia una carpeta nueva que no existía (no se sobrescribió nada).
- Archivos en el origen (sin `.git`): **469**. Archivos copiados: **468** (= 469 − `.env`).
- **Integridad:** se compararon los SHA-1 de todos los archivos. La copia es **idéntica byte a byte** al original, excepto `.env` (excluido a propósito) y los dos archivos que se ajustaron después por seguridad (§4).
- Contenido conservado: `composer.json`, `composer.lock`, `Modules/` (6), `app/`, `bootstrap/`, `config/` (12 archivos, incluido `modules.php`), `database/` (28 migraciones, seeders, factory), `resources/views` (82 vistas), `routes/` (`web.php`, `console.php`), `tests/` (10 archivos), `public/`, `stubs/nwidart-stubs`, `Dockerfile`, `docker-compose.yml`, `.dockerignore`, `phpunit.xml`, `README.md`, `.editorconfig`, `.gitattributes`, `.gitignore`, `modules_statuses.json`, `storage/` (solo la estructura con sus `.gitignore`).

> Corrección a la auditoría: allí se indicaron 30 migraciones; el recuento real es **28**.

## 2. Archivos excluidos

| Excluido | Motivo | ¿Existía en el origen? |
|---|---|---|
| `.git/` | Historial heredado (contiene credenciales filtradas) | Sí, excluido |
| `.env` | Secretos reales del entorno local | Sí, excluido |
| `vendor/` | Regenerable con `composer install` | No existía |
| `node_modules/` | Regenerable | No existía |
| `storage/logs/*.log` | Temporales | No había logs |
| `bootstrap/cache/*.php` | Caché regenerable | No había |
| `.phpunit.result.cache`, `.phpunit.cache` | Caché de pruebas | No existían |

Antes de copiar se buscaron en el origen `*.sql`, `*.key`, `*.pem`, `*.p12`, `*.pfx`, `auth.json`, `*credential*`, `*.sqlite`, `*.log`, `.DS_Store` y `Thumbs.db`: **no había ninguno** (el volcado `ferreteria.sql` está en `E:\ferreter-a(1)` y no se usó).

## 3. Módulos conservados

| Módulo | `module.json` | `routes/web.php` | Controladores | Modelos | Activo |
|---|---|---|---|---|---|
| Access | ✅ | ✅ | 3 (+9 de Auth) | 6 | ✅ |
| Inventory | ✅ | ✅ | 4 | 6 | ✅ |
| Sales | ✅ | ✅ | 4 | 8 | ✅ |
| Rentals | ✅ | ✅ | 3 | 4 | ✅ |
| Procurement | ✅ | ✅ | 4 | 7 | ✅ |
| Audit | ✅ | ✅ | 2 | 1 | ✅ |

`modules_statuses.json`: los seis módulos en `true`.

## 4. Revisiones de seguridad

Se escaneó toda la copia buscando contraseñas, tokens, API keys, claves AWS/Stripe/Groq, claves privadas, URLs de Railway y claves `base64:`. **No se imprimió ningún valor**: solo archivo y línea.

| Hallazgo | Archivo | Acción |
|---|---|---|
| `APP_KEY` real (`base64:...`) | `.env.example:3` | **Reemplazado** por `APP_KEY=` vacío (se genera con `php artisan key:generate` en cada entorno). |
| Credencial de Railway | — | **No está en la copia** (solo estaba en el `.env.example` de la carpeta antigua `sound-management` y en el historial git del repo original, que no se copió). |
| Client-ID de PayPal escrito en el código | `resources/views/carrito/index.blade.php:162` | **No se modificó** (es código funcional y un client-ID de PayPal es público por diseño, no un secreto). Se retirará con la integración de PayPal (pendiente). |
| Scripts de Botpress (chatbot de la ferretería) | `resources/views/layouts/ferreteria.blade.php`, `welcome.blade.php` | No es un secreto. No se modificó; está pendiente retirarlo. |
| Contraseñas triviales de desarrollo (`password`) | `docker-compose.yml` | Son valores por defecto de desarrollo, no credenciales reales. No se modificaron; está pendiente parametrizarlas. |
| Credenciales de demostración del admin | `README.md`, `database/seeders/AdminUserSeeder.php` | No son de un servicio externo. No se modificaron; hay que cambiarlas antes de cualquier despliegue. |
| `DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD` | `.env.example` | Vacíos o `null`. Correcto. |

**`.gitignore`:** ya ignoraba `.env`, `.env.backup`, `.env.production`, `auth.json`, `/vendor`, `/node_modules`, `*.log`, `/storage/*.key`, `*.sqlite`. Se **añadió** un bloque:

```
.env.*  (con excepción !.env.example)
*.pem  *.key  *.p12  *.pfx  *.crt
*.sql  *.sql.gz  *.dump
credentials*.json  service-account*.json
/storage/app/private/*  (conservando su .gitignore)
```

Comprobado con `git check-ignore`: `.env`, `.env.local`, `*.sql`, `storage/logs/laravel.log` y `vendor/` quedan ignorados; **`.env.example` no** (se versiona).

**`.dockerignore`:** excluye `.git`, `.env`, `.env.*`, `vendor`, `node_modules` y logs. Correcto para no meter secretos en la imagen.

## 5. Estado de Git

- Repositorio **nuevo** inicializado solo en `E:\BadiStudio\sound-management-app` (`git init -b main`).
- Rama: `main`. **Sin commits** (0 objetos). **Sin remotos.** No se hizo ningún push.
- No hay ningún `.git` heredado (aparte del recién creado en la raíz).
- `git status`: 25 entradas sin seguimiento en la raíz; no se ha añadido nada al índice.
- Git 2.53.0.

## 6. Problemas encontrados

1. **Credencial de Railway expuesta:** sigue en el historial público de `github.com/DenilCruz/ferreter-a` (commits `70c30f7`, `fcaf4df`) y en `E:\BadiStudio\sound-management\.env.example`. **Hay que rotarla desde el panel de Railway.** Desde aquí no se intentó.
2. **`APP_KEY` real en el `.env.example` del original y en su historial:** cualquier entorno que la haya usado debe generar una clave nueva.
3. **Repositorio git huérfano en `E:\`** (`E:\.git`, sin commits, propiedad de SYSTEM): cualquier carpeta de `E:` sin repo propio "cae" dentro de él. No se tocó. Conviene revisarlo y eliminarlo a mano si no se usa.
4. **`.env.example` apunta a `DB_DATABASE=ferreteria_guisella`:** si se usa tal cual, la app nueva trabajaría sobre la BD de la ferretería. No se cambió (no es un secreto); hay que crear una BD propia.
5. **Pendientes ya conocidos de la auditoría** (no se corrigen en esta fase porque son código funcional): migraciones fuera de orden y tablas sin migración, `/reportes-ia` abierto a cualquier usuario autenticado, checkout sin verificación en el servidor, CRUD de productos sin autorización, pruebas de auth probablemente rotas.
6. `README.md` y `APP_NAME` siguen siendo los de "Ferretería Guisella".

## 7. Acciones pendientes

**Del responsable (fuera del código):**
- [ ] Rotar la contraseña de la BD de Railway en el proveedor (o eliminar esa BD si ya no se usa).
- [ ] Decidir si se elimina `E:\.git`.
- [ ] Revisar este informe y el contenido preparado antes del primer commit.

**Siguiente paso técnico (con aprobación):**
- [ ] Primer commit en `main` (`git add` revisado archivo por archivo; confirmar con `git status` que no entra ningún secreto).
- [ ] Crear `.env` local a partir de `.env.example` con una BD nueva (p. ej. `sound_management`), `APP_KEY` nueva y `APP_LOCALE=es`.
- [ ] `composer install` y unificar la versión de PHP entre local y Docker.
- [ ] Parches de seguridad urgentes: cerrar `/reportes-ia`, autorizar `ProductoController`, proteger `/api/clientes/*`, desactivar el checkout de PayPal.
- [ ] Reordenar migraciones y crear las que faltan (o diseñar el esquema nuevo de la Fase 1).
- [ ] Hacer pasar la suite de pruebas existente.
- [ ] Parametrizar las credenciales de `docker-compose.yml` y cambiar las del admin de demostración.
- [ ] Actualizar `README.md` y la marca.
