# Sound Management — Sistema de gestión de alquiler de equipos de sonido

Aplicación Laravel 11 modular (`nwidart/laravel-modules`) en proceso de transformación desde un sistema de ferretería. El estado y las decisiones de cada fase están en los documentos `FASE_*.md` de esta carpeta.

## Instalación limpia

La base de datos se construye **desde cero** con migraciones y seeders. No contiene datos de ningún negocio anterior: el cliente introduce sus propios datos desde la aplicación.

### 1. Dependencias
Requiere PHP **8.3 o superior** (el `composer.lock` lo exige) con las extensiones pdo_mysql, mbstring, intl, gd, zip, fileinfo y openssl.
```bash
composer install
```

### 2. Entorno
```bash
cp .env.example .env
php artisan key:generate
```
Edita `.env` con una base de datos **propia y vacía** (`DB_DATABASE=sound_management_db`) y credenciales únicas. No reutilices credenciales de otros sistemas.

### 3. Estructura y datos técnicos mínimos
```bash
php artisan migrate
php artisan db:seed
```
`db:seed` solo crea la configuración indispensable y es idempotente (puede repetirse sin duplicar):
- **Métodos de pago:** Efectivo, Tarjeta, Transferencia bancaria, QR y PayPal. Se identifican por código; el sistema nunca depende de IDs fijos.
- **Roles de personal:** Administrador, Encargado, Técnico, Almacenero y Vendedor. El cliente no es un rol: se registra él mismo desde el portal.
- **Permisos** del sistema y su asignación por defecto a cada rol.

No se crean usuarios, clientes, productos, equipos, ventas, compras ni reservas.

### 4. Primer administrador (procedimiento seguro)
```bash
php artisan sound:crear-administrador
```
- Es interactivo: la contraseña se escribe sin mostrarse y se confirma. Mínimo 12 caracteres, con mayúsculas, minúsculas, números y símbolos.
- No acepta contraseñas por argumentos ni por variables de entorno.
- Se niega a ejecutarse si ya existe un administrador activo. Los siguientes administradores se crean desde la aplicación.

No existen cuentas ni contraseñas predeterminadas.

### 5. Clientes
Cada cliente crea su propia cuenta desde el portal web (`/register`) y solo accede a su información.

## Docker
Ver `docker-compose.yml` (PHP-FPM 8.4 + Nginx + MySQL 8.4). Las credenciales se leen de `.env`, son obligatorias y no hay valores por defecto. Las migraciones se ejecutan siempre a mano.

## Pruebas
```bash
php vendor/phpunit/phpunit/phpunit
```
Las pruebas destructivas solo se permiten sobre SQLite en memoria o sobre la base aislada `127.0.0.1:3310/sound_management_test`. `tests/TestCase.php` aborta la ejecución si la conexión apunta a cualquier otro destino.
