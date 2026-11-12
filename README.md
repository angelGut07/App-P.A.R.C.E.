# P.A.R.C.E

**Plataforma de Asistencia Rápida Para Conductores en Emergencia.** Conecta a conductores varados con mecánicos cercanos: el cliente pide asistencia desde el mapa, un mecánico disponible la acepta, la atiende registrando repuestos y evidencias, y el cliente paga, recibe su comprobante y califica el servicio. Un panel de administración gestiona usuarios, mecánicos, inventario, pagos, calificaciones y PQR.

| Capa | Tecnología |
|---|---|
| Backend (API REST) | PHP 8.4 en producción (compatible con 8.2+), sin framework, arquitectura MVC propia, PDO |
| Base de datos | MySQL 8 / MariaDB 10.4+ (esquema en 3FN, ver [docs/BASE_DE_DATOS.md](docs/BASE_DE_DATOS.md)) |
| Frontend | React 18 + TypeScript + Vite + Tailwind CSS + Leaflet (OpenStreetMap) |
| Servicios externos | Resend (correos), Groq (asistente de diagnóstico), Cloudflare Turnstile (CAPTCHA) — todos opcionales |

## Funcionalidades por rol

- **Cliente:** registro (con vehículo y licencia), catálogo de servicios con precio base, asistente de diagnóstico, solicitud con ubicación en el mapa o GPS, seguimiento en vivo, contacto con el mecánico (llamada / WhatsApp), pago (efectivo, tarjeta o PSE — pasarela de pruebas), comprobante imprimible, calificación del mecánico, encuesta de satisfacción, PQR, historial, gestión de vehículos con SOAT y tecnomecánica, y solicitud para convertirse en mecánico.
- **Mecánico:** servicios aprobados por tipo (solo ve y acepta solicitudes de esos servicios; puede pedir más desde su perfil), disponibilidad, solicitudes cercanas ordenadas por prioridad y distancia, aceptar / iniciar / completar, repuestos tomados del inventario (descuentan stock), fotos de evidencia, confirmación de pagos en efectivo, calificación del cliente, estadísticas y PQR. Quien es mecánico y cliente cambia de vista desde el menú.
- **Administrador:** dashboard con indicadores, usuarios (suspender / reactivar), revisión de solicitudes de mecánico aprobando cada servicio por separado, servicios, vehículos, pagos, calificaciones y encuestas, desempeño por mecánico, inventario, ingreso de mercancía por proveedor y factura, y PQR de clientes y mecánicos.

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

Requisitos: PHP 8.4, la versión de producción (funciona desde 8.2), con las extensiones `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo` y `sodium`; Composer, Node.js 20+ y MySQL o MariaDB (el de XAMPP sirve). En Windows, `winget install PHP.PHP.NTS.8.4` instala la misma versión que Render; luego copia `php.ini-development` como `php.ini` en su carpeta y activa esas extensiones.

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

El mecánico de demostración tiene aprobados todos los servicios menos la grúa, para mostrar la aprobación por tipo de servicio.

> Son solo para desarrollo y pruebas: no ejecutes `seed` en producción. Su contraseña es anterior a la política actual y es pública: antes de producción real pon `SEED_DEMO=false` y borra o desactiva estas cuentas.

## Consola

```bash
php bin/parce migrate                 # aplica migraciones pendientes
php bin/parce status                  # estado de las migraciones
php bin/parce rollback                # revierte el último lote
php bin/parce fresh                   # borra todo, migra y siembra (bloqueado en producción)
php bin/parce expire-requests 30      # expira solicitudes sin atender hace más de 30 min
php bin/parce cleanup-sessions        # borra sesiones vencidas y registros viejos de intentos fallidos
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

Suite de punta a punta (E2E): usa la API por HTTP igual que el frontend. Cubre el flujo de un servicio (solicitud, aceptación, repuesto, evidencia, pago y calificaciones), el registro, la aprobación de mecánicos por servicio, el inventario, las PQR y las reglas de acceso. Recrea sola una base aparte llamada `parce_e2e` y levanta su propio servidor, así que solo necesita MySQL encendido:

```bash
vendor/bin/phpunit -c phpunit.e2e.xml
```

Si un paso falla, el mensaje dice qué se intentaba y qué respondió la API, y el detalle del servidor, con la traza del error, queda en `storage/logs/e2e-server.log`.

El CI de GitHub Actions (`.github/workflows/ci.yml`) corre en cada push:
- la suite unitaria con PHP 8.2 y 8.4, que falla ante cualquier aviso de código obsoleto;
- contra MySQL 8, las migraciones, los datos demo y la reversión, la suite de integración y la E2E;
- el build del frontend.

## Seguridad

- **Contraseñas:** hash Argon2id. Al crear o cambiar una contraseña se exigen 8 a 128 caracteres, mayúscula, minúscula, número y carácter especial, y se rechazan las contraseñas comunes ("Password123!", "Colombia2024*"…) y las que contienen el nombre, el correo o la palabra "parce" (`app/Models/Auth/PasswordPolicy.php`). El inicio de sesión no aplica estas reglas, para que las cuentas antiguas sigan entrando.
- **Intentos fallidos:** límite por IP (5 intentos cada 15 minutos) y bloqueo por cuenta: tras 3 fallos se pide el CAPTCHA y tras 5 la cuenta se bloquea 15 minutos, el doble en cada bloqueo nuevo (hasta 24 horas). El dueño recibe un correo de alerta y restablecer la contraseña desbloquea la cuenta (`LoginThrottleService`).
- **CAPTCHA:** Cloudflare Turnstile en el registro, la recuperación de contraseña y el inicio de sesión tras varios fallos. Se activa con `TURNSTILE_SITE_KEY` y `TURNSTILE_SECRET_KEY` (`CaptchaService`).
- **Sesiones:** cookie HttpOnly (el id nunca viaja en el JSON), vencen por inactividad y por tiempo total, y se regeneran cada 10 minutos.
- **Roles:** cada petición pasa por autenticación y RBAC en el servidor; los mecánicos solo atienden los tipos de servicio que un administrador les aprobó.
- `GET /api/health/system` indica si el chatbot, el correo y el CAPTCHA tienen clave configurada, sin mostrarla.

## Protección de datos personales (Ley 1581 de 2012)

- **Páginas públicas:** `/terminos` (Términos y condiciones) y `/privacidad` (Política de tratamiento de datos), enlazadas desde el pie de página.
- **Autorización:** el registro exige aceptarlas (`accept_terms`). Cada aceptación se guarda como prueba en `user_consents`, con la versión, la fecha, la IP y el navegador (`ConsentService`).
- **Cuentas existentes:** si un cliente o mecánico no ha aceptado la versión vigente, al entrar ve una pantalla que se lo pide antes de seguir (`ConsentGate`, `POST /api/auth/consent`).
- **Cambiar los textos:** se sube la versión en `ConsentService::CURRENT_VERSION` y en `POLICY_VERSION` (`frontend/src/views/legal.ts`); todos deben aceptarla de nuevo.
- **Antes de operar comercialmente:** completar en la política los datos del responsable (razón social o nombre, NIT, dirección y teléfono) y revisarla con asesoría legal.

## Logs y monitoreo

- **En local** los logs quedan en `storage/logs/`, un archivo por tipo y por día: `error-AAAA-MM-DD.log` (errores con su traza), `database-…` (conexiones y consultas fallidas) y `requests-…` (una línea JSON por petición).
- **En producción** van a la salida de errores (stderr) y se leen en la pestaña *Logs* del servicio en Render: los archivos del contenedor se borran en cada despliegue. Lo controla `LOG_CHANNEL` (`stderr` o `file`); sin definirla, se usa `stderr` cuando `APP_ENV=production`.
- **Cómo encontrar un error:** cada respuesta trae el header `X-Request-Id`, y ese mismo id aparece en la línea del error en los logs.
- **Salud del servicio:**
  - `GET /api/health` confirma que la app responde y es el que usa Render para sus chequeos.
  - `GET /api/health/database` responde 503 si la base de datos no contesta. Es la URL para un monitor externo, por ejemplo UptimeRobot cada 5 minutos.
  - `GET /api/health/system` da el detalle por componente y también responde 503 sin base de datos.

## Despliegue en Render

La aplicación se despliega como **un solo servicio web Docker**: Apache sirve el frontend compilado y redirige `/api` al backend PHP, en el mismo dominio. Así la cookie de sesión es de primera parte y no hace falta configurar CORS.

1. **Base de datos.** Render solo ofrece PostgreSQL gestionado, así que crea una base MySQL externa, por ejemplo [Aiven for MySQL](https://aiven.io/mysql) (plan gratuito), TiDB Cloud o Railway. Anota host, puerto, base, usuario y contraseña. Si el proveedor exige TLS con su propia CA (Aiven lo hace), pega el contenido de su `ca.pem` en la variable `DB_SSL_CA_PEM`.
2. **Servicio.** En Render: *New → Blueprint*, elige este repositorio y Render leerá `render.yaml`.
3. **Variables.** Completa en el panel las marcadas como secretas: `APP_URL` (la URL `https://...onrender.com` que asigne Render), `DB_*`, y opcionalmente `RESEND_API_KEY`, `MAIL_FROM`, `GROQ_API_KEY`, `TURNSTILE_SITE_KEY` y `TURNSTILE_SECRET_KEY` (se crean gratis en el panel de Cloudflare → Turnstile, con el dominio del sitio). Para un entorno de pruebas puedes poner `SEED_DEMO=true` en el primer despliegue.
4. Cada despliegue aplica las migraciones pendientes al arrancar (`RUN_MIGRATIONS=true`).

Limitaciones del plan gratuito de Render: el servicio se suspende tras ~15 minutos sin tráfico (la primera petición tarda unos segundos en despertar) y su disco es efímero, así que las fotos subidas se pierden en cada despliegue. Para producción real: usa un plan pago con disco persistente montado en `/var/www/html/public/uploads` (o un almacenamiento de objetos) y programa las tareas de mantenimiento como Cron Jobs. Al ser una imagen Docker estándar, la misma configuración funciona en cualquier VPS o nube.

## Configuración

Todas las variables están documentadas en [.env.example](.env.example). Las claves (`RESEND_API_KEY`, `GROQ_API_KEY`, `TURNSTILE_SECRET_KEY`, credenciales de BD) nunca van en el repositorio. Para cargar otro archivo en vez de `.env`, define la variable `ENV_FILE` con su ruta (así lo hace la suite E2E).
