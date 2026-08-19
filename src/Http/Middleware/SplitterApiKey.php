<?php

namespace Pterodactyl\Extensions\ServerSplitter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\IpUtils;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterSetting;

/**
 * Autentica las llamadas de WHMCS / Paymenter mediante la clave de la extension.
 *
 * La clave se envia en la cabecera X-Splitter-Key (o como Bearer token) y se
 * compara contra el hash guardado en la base de datos. Sin clave configurada,
 * la API queda cerrada por defecto.
 *
 * Capa extra opcional: si el administrador configura una lista blanca de IPs
 * (ajuste api_allowed_ips), la peticion tambien debe venir de una de esas
 * direcciones/CIDR, aunque la clave sea correcta. Vacio = sin restriccion.
 */
class SplitterApiKey
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (!$this->ipAllowed($request)) {
            return new JsonResponse(['error' => 'Tu direccion IP no esta autorizada para usar esta API.'], 403);
        }

        $provided = (string) ($request->header('X-Splitter-Key') ?? $request->bearerToken() ?? '');
        $hash = (string) (SplitterSetting::read('api_key_hash') ?? '');

        if ($hash === '') {
            return new JsonResponse([
                'error' => 'La API de ServerSplitter no esta configurada. Ejecuta: serversplitter apikey',
            ], 503);
        }

        if ($provided === '' || !password_verify($provided, $hash)) {
            return new JsonResponse(['error' => 'Clave de API invalida.'], 401);
        }

        return $next($request);
    }

    protected function ipAllowed(Request $request): bool
    {
        $raw = trim((string) SplitterSetting::read('api_allowed_ips', ''));

        if ($raw === '') {
            return true;
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $raw))));

        if ($allowed === []) {
            return true;
        }

        return IpUtils::checkIp((string) $request->ip(), $allowed);
    }
}