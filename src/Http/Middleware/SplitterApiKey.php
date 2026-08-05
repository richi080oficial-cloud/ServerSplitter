<?php

namespace Pterodactyl\Extensions\ServerSplitter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterSetting;

/**
 * Autentica las llamadas de WHMCS / Paymenter mediante la clave de la extension.
 *
 * La clave se envia en la cabecera X-Splitter-Key (o como Bearer token) y se
 * compara contra el hash guardado en la base de datos. Sin clave configurada,
 * la API queda cerrada por defecto.
 */
class SplitterApiKey
{
    public function handle(Request $request, Closure $next): mixed
    {
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
}