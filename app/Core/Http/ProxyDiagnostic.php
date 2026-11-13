<?php

namespace App\Core\Http;

use App\Core\Request;

/**
 * TEMPORAL: diagnóstico de cómo llega la IP del cliente a través del proxy de
 * Render. Sirve para decidir en qué proxies confiar; se borra al corregir
 * IPValidator.
 *
 * Nunca muestra una dirección. Cada IP se reemplaza por su tipo (rango
 * privado, Cloudflare, pública o de documentación) y una huella corta:
 * HMAC-SHA256 con una clave aleatoria que se guarda en storage/ y nunca sale
 * del servidor. Dos peticiones del mismo cliente dan la misma huella y dos
 * clientes distintos, huellas distintas, sin que la huella revele la IP.
 *
 * Lo muestra GET /api/health/system (checks.proxy) y queda en el log "proxy"
 * (pestaña Logs de Render).
 */
final class ProxyDiagnostic
{
    /** Entradas de X-Forwarded-For que se describen como máximo (las de la derecha) */
    private const MAX_ENTRIES = 10;

    /**
     * Rangos con nombre, en orden de prioridad. Los de documentación (RFC 5737
     * y RFC 3849) nunca son clientes reales: sirven para reconocer una entrada
     * falsa enviada a propósito en una prueba. Cloudflare publica sus rangos en
     * https://www.cloudflare.com/ips (consultados el 2026-11-13).
     */
    private const RANGES = [
        'documentacion'          => ['192.0.2.0/24', '198.51.100.0/24', '203.0.113.0/24', '2001:db8::/32'],
        'loopback'               => ['127.0.0.0/8', '::1/128'],
        'privada:10.0.0.0/8'     => ['10.0.0.0/8'],
        'privada:172.16.0.0/12'  => ['172.16.0.0/12'],
        'privada:192.168.0.0/16' => ['192.168.0.0/16'],
        'privada:fc00::/7'       => ['fc00::/7'],
        'cgnat:100.64.0.0/10'    => ['100.64.0.0/10'],
        'enlace-local'           => ['169.254.0.0/16', 'fe80::/10'],
        'cloudflare'             => [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ],
    ];

    /**
     * Describe la petición: REMOTE_ADDR, cada entrada de X-Forwarded-For (de
     * izquierda a derecha), los headers de IP que agregan Cloudflare y otros
     * proxies, y la clave que usa hoy el límite de intentos.
     *
     * @return array<string, mixed>
     */
    public static function describe(Request $request): array
    {
        $chain = [];
        $forwardedFor = $request->header('X-Forwarded-For');
        if (is_string($forwardedFor) && trim($forwardedFor) !== '') {
            $chain = array_map('trim', explode(',', $forwardedFor));
        }

        return [
            'remoteAddr'         => self::describeIp($request->ip()),
            'xForwardedFor'      => array_map([self::class, 'describeIp'], array_slice($chain, -self::MAX_ENTRIES)),
            'xForwardedForTotal' => count($chain),
            'cfConnectingIp'     => self::describeIp($request->header('CF-Connecting-IP')),
            'trueClientIp'       => self::describeIp($request->header('True-Client-IP')),
            'xRealIp'            => self::describeIp($request->header('X-Real-IP')),
            'forwarded'          => $request->header('Forwarded') !== null ? 'presente' : 'ausente',
            'claveActual'        => self::describeIp(IPValidator::getClientIP($request)),
        ];
    }

    /**
     * Tipo de una dirección: el nombre de su rango, "publica" o "invalida".
     */
    public static function classify(string $ip): string
    {
        $packed = self::pack($ip);
        if ($packed === null) {
            return 'invalida';
        }

        foreach (self::RANGES as $name => $ranges) {
            foreach ($ranges as $cidr) {
                if (self::inRange($packed, $cidr)) {
                    return $name;
                }
            }
        }

        return 'publica';
    }

    /**
     * "tipo#huella" de una IP; "ausente" si el header no vino.
     */
    private static function describeIp(mixed $value): string
    {
        if (!is_string($value)) {
            return 'ausente';
        }

        $type = self::classify($value);
        $packed = self::pack($value);
        $key = self::key();

        if ($packed === null || $key === null) {
            return $type;
        }

        return $type . '#' . substr(hash_hmac('sha256', $packed, $key), 0, 8);
    }

    /**
     * Forma binaria de una IP válida (IPv4 de 4 bytes, IPv6 de 16); una IPv4
     * escrita como IPv6 (::ffff:a.b.c.d) se trata como la IPv4.
     */
    private static function pack(string $ip): ?string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    /**
     * Si una IP (en forma binaria) pertenece a un rango CIDR IPv4 o IPv6.
     */
    private static function inRange(string $packed, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $packedSubnet = inet_pton($subnet);
        if ($packedSubnet === false || strlen($packedSubnet) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if (substr($packed, 0, $fullBytes) !== substr($packedSubnet, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($packedSubnet[$fullBytes]) & $mask);
    }

    /**
     * Clave aleatoria de las huellas, compartida por todos los procesos del
     * servidor mientras dure el contenedor. null si no se puede guardar: en
     * ese caso se muestran los tipos sin huella.
     */
    private static function key(): ?string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }

        $file = dirname(__DIR__, 3) . '/storage/cache/proxy-diagnostic.key';
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }

        // 'x' crea el archivo solo si no existe: dos procesos que llegan a la
        // vez no se pisan la clave, el segundo lee la del primero
        $handle = @fopen($file, 'x');
        if ($handle !== false) {
            $new = random_bytes(32);
            fwrite($handle, $new);
            fclose($handle);
            return $key = $new;
        }

        $existing = @file_get_contents($file);

        return (is_string($existing) && strlen($existing) === 32) ? $key = $existing : null;
    }
}
