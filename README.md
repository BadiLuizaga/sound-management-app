<div align="center">

#  Sound Managemen

**Sistema web para la gestión integral de alquiler de equipos de sonido**

![Laravel](https://img.shields.io/badge/Laravel_11-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP_8.3-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Blade](https://img.shields.io/badge/Blade-F05340?style=for-the-badge&logo=laravel&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Composer](https://img.shields.io/badge/Composer-885630?style=for-the-badge&logo=composer&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-2496ED?style=for-the-badge&logo=docker&logoColor=white)
![Nginx](https://img.shields.io/badge/Nginx-009639?style=for-the-badge&logo=nginx&logoColor=white)

</div>

---

##  Stack

| Backend | Frontend | Base de datos | Reportes | Infraestructura |
|:---:|:---:|:---:|:---:|:---:|
| Laravel 11 + PHP 8.3 (modular) | Blade | MySQL 8.4 | DomPDF | Docker + Nginx |

---

##  Descripción

Sound Management administra el negocio de alquiler de equipos de sonido: inventario de equipos, reservas y alquileres, ventas, compras a proveedores, roles y permisos del personal, auditoría y un portal donde cada cliente se registra y consulta su información.

---

##  Requisitos

| Herramienta | Versión |
|---|---|
|  PHP | 8.3 o superior (extensiones `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `fileinfo`, `openssl`) |
|  Composer | 2.x |
|  MySQL | 8.x |
|  Git | Cualquier versión reciente |

> Crea una base de datos vacía `sound_management_db`, copia `.env.example` a `.env` y coloca tus credenciales de MySQL.

---

##  Levantar el sistema

```bash
composer setup && php artisan db:seed && php artisan sound:crear-administrador
```

```bash
composer dev
```

| Servicio | URL |
|---|---|
|  Web | http://localhost:8000 |
