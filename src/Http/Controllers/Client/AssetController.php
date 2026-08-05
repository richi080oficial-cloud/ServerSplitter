<?php

namespace Pterodactyl\Extensions\ServerSplitter\Http\Controllers\Client;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sirve el CSS/JS de la extension directamente desde su carpeta.
 *
 * Asi no hace falta copiar nada a public/ (ni volver a copiarlo despues de
 * cada actualizacion del panel). Es un controlador y no un closure para que
 * `php artisan route:cache` siga funcionando.
 */
class AssetController extends Controller
{
    /** @var array<string, string> */
    protected const ALLOWED = [
        'serversplitter.css' => 'text/css; charset=utf-8',
        'serversplitter.js' => 'application/javascript; charset=utf-8',
    ];

    public function show(string $asset): BinaryFileResponse
    {
        abort_unless(array_key_exists($asset, self::ALLOWED), 404);

        $path = realpath(__DIR__ . '/../../../resources/assets/' . $asset);
        abort_if($path === false || !is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => self::ALLOWED[$asset],
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}