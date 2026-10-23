# P.A.R.C.E

**Plataforma de Asistencia Rápida Para Conductores en Emergencia.** Conecta a conductores varados con mecánicos cercanos: el cliente pide asistencia desde el mapa, un mecánico disponible la acepta, la atiende registrando repuestos y evidencias, y el cliente paga, recibe su comprobante y califica el servicio. Un panel de administración gestiona usuarios, mecánicos, inventario, pagos, calificaciones y PQR.

| Capa | Tecnología |
|---|---|
| Backend (API REST) | PHP 8.2 sin framework, arquitectura MVC propia, PDO |
| Base de datos | MySQL 8 / MariaDB 10.4+ (esquema en 3FN, ver [docs/BASE_DE_DATOS.md](docs/BASE_DE_DATOS.md)) |
| Frontend | React 18 + TypeScript + Vite + Tailwind CSS + Leaflet (OpenStreetMap) |
| Servicios externos | Resend (correos), Groq (asistente de diagnóstico) — ambos opcionales |

## Funcionalidades por rol

- **Cliente:** registro (con vehículo y licencia), catálogo de servicios con precio base, asistente de diagnóstico, solicitud con ubicación en el mapa o GPS, seguimiento en vivo, contacto con el mecánico (llamada / WhatsApp), pago (efectivo, tarjeta o PSE — pasarela de pruebas), comprobante imprimible, calificación del mecánico, encuesta de satisfacción, PQR, historial, gestión de vehículos con SOAT y tecnomecánica, y solicitud para convertirse en mecánico.
- **Mecánico:** disponibilidad, solicitudes cercanas ordenadas por prioridad y distancia, aceptar / iniciar / completar, repuestos tomados del inventario (descuentan stock), fotos de evidencia, confirmación de pagos en efectivo, calificación del cliente, estadísticas y PQR. Quien es mecánico y cliente cambia de vista desde el menú.
- **Administrador:** dashboard con indicadores, usuarios (suspender / reactivar), revisión de solicitudes de mecánico, servicios, vehículos, pagos, calificaciones y encuestas, desempeño por mecánico, inventario, ingreso de mercancía por proveedor y factura, y PQR de clientes y mecánicos.

## Estructura (MVC)

```
app/
  Core/           Framework: App, Router, Request, Response, Database, migraciones
    Http/         Formato de respuestas, validación de peticiones, errores, rate limit
  Controllers/    Controladores HTTP (uno por recurso)
  Models/         Modelo: reglas de negocio y acceso a datos por dominio
                  (Auth, Vehicle, ServiceRequest, Inventory, Payment, PQR, Survey, ...)
  Middleware/     CORS, cabeceras de seguridad, autenticación, roles (RBAC), log
config/routes.php Todas las rutas de la API (/api/...)
database/         Migraciones (esquema) y datos de demostración
bin/parce         Consola: migrar, sembrar datos, tareas de mantenimiento
public/           Punto de entrada web (index.php)
frontend/src/
  models/         Tipos de los datos de la API
  services/       Llamadas a la API por módulo
  controllers/    Estado global (sesión, servicio en curso, disponibilidad)
  views/          Páginas y componentes de React
tests/            PHPUnit: unitarias (sin BD) e integración (con BD de pruebas)
docker/, Dockerfile, render.yaml   Despliegue
```

## Puesta en marcha local

Requisitos: PHP 8.2 con `pdo_mysql`, Composer, Node.js 20+, MySQL o MariaDB (XAMPP sirve).

```bash
composer install
cp .env.example .env            # ajusta DB_* si tu MySQL no es root sin contraseña
mysql -u root -e "CREATE DATABASE parce CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php bin/parce migrate
php bin/parce seed              # datos de demostración (opcional)
cd frontend && npm install && cd ..
```

Levanta los dos servidores en terminales separadas:

```bash
php -S localhost:8000 -t public public/router.php
```

```bash
npm run dev --prefix frontend
```

Abre http://localhost:5173. Vite reenvía `/api` y `/uploads` al servidor PHP, así que el navegador ve un solo origen igual que en producción.

**Cuentas de demostración** (creadas por `php bin/parce seed`, contraseña `Parce2026*`):

| Rol | Correo |
|---|---|
| Administrador | admin@parce.test |
| Cliente | cliente@parce.test |
| Mecánico (también cliente) | mecanico@parce.test |

> Son solo para desarrollo y pruebas: no ejecutes `seed` en producción.

## Consola

```bash
php bin/parce migrate                 # aplica migraciones pendientes
php bin/parce status                  # estado de las migraciones
php bin/parce rollback                # revierte el último lote
php bin/parce fresh                   # borra todo, migra y siembra (bloqueado en producción)
php bin/parce expire-requests 30      # expira solicitudes sin atender hace más de 30 min
php bin/parce cleanup-sessions        # borra sesiones vencidas
php bin/parce cleanup-logs 30         # borra logs de más de 30 días
```

Las tres tareas de mantenimiento están pensadas para ejecutarse periódicamente (cron).

## Pruebas

```bash
composer test                                   # suite unitaria (no necesita base de datos)
npm run build --prefix frontend                 # verificación de tipos + build del frontend
```

Suite de integración (opcional, usa una base de datos aparte llamada `parce_test`):

```bash
mysql -u root -e "CREATE DATABASE parce_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
DB_DATABASE=parce_test php bin/parce migrate
vendor/bin/phpunit -c phpunit.integration.xml
```

El CI de GitHub Actions (`.github/workflows/ci.yml`) corre la suite unitaria y el build del frontend en cada push.

## Despliegue en Render

La aplicación se despliega como **un solo servicio web Docker**: Apache sirve el frontend compilado y redirige `/api` al backend PHP, en el mismo dominio. Así la cookie de sesión es de primera parte y no hace falta configurar CORS.

1. **Base de datos.** Render solo ofrece PostgreSQL gestionado, así que crea una base MySQL externa, por ejemplo [Aiven for MySQL](https://aiven.io/mysql) (plan gratuito), TiDB Cloud o Railway. Anota host, puerto, base, usuario y contraseña. Si el proveedor exige TLS con su propia CA (Aiven lo hace), pega el contenido de su `ca.pem` en la variable `DB_SSL_CA_PEM`.
2. **Servicio.** En Render: *New → Blueprint*, elige este repositorio y Render leerá `render.yaml`.
3. **Variables.** Completa en el panel las marcadas como secretas: `APP_URL` (la URL `https://...onrender.com` que asigne Render), `DB_*`, y opcionalmente `RESEND_API_KEY`, `MAIL_FROM` y `GROQ_API_KEY`. Para un entorno de pruebas puedes poner `SEED_DEMO=true` en el primer despliegue.
4. Cada despliegue aplica las migraciones pendientes al arrancar (`RUN_MIGRATIONS=true`).

Limitaciones del plan gratuito de Render: el servicio se suspende tras ~15 minutos sin tráfico (la primera petición tarda unos segundos en despertar) y su disco es efímero, así que las fotos subidas se pierden en cada despliegue. Para producción real: usa un plan pago con disco persistente montado en `/var/www/html/public/uploads` (o un almacenamiento de objetos) y programa las tareas de mantenimiento como Cron Jobs. Al ser una imagen Docker estándar, la misma configuración funciona en cualquier VPS o nube.

## Configuración

Todas las variables están documentadas en [.env.example](.env.example). Las claves (`RESEND_API_KEY`, `GROQ_API_KEY`, credenciales de BD) nunca van en el repositorio.
