<?php

namespace Pterodactyl\Extensions\ServerSplitter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carga serversplitter-inject.js en el panel de cliente de Pterodactyl.
 *
 * El area de cliente es una SPA de React servida siempre desde la misma
 * plantilla wrapper, asi que no hay ningun Blade propio donde anadir el
 * <script>. En lugar de parchear vistas del panel (que se pierden en cada
 * actualizacion) se anade la etiqueta al HTML ya renderizado, justo antes de
 * </body>. El script inyectado es el que localiza la barra de navegacion del
 * servidor y agrega la pestana "Divisiones".
 *
 * Reglas de seguridad antes de tocar la respuesta:
 *   - solo peticiones GET no-AJAX de un usuario autenticado,
 *   - solo respuestas 200 de tipo text/html (nunca JSON, descargas ni streams),
 *   - nunca en /admin*, /api*, /serversplitter* ni en la ruta de assets: esas
 *     paginas no son la SPA de cliente,
 *   - solo si el HTML es realmente el wrapper del panel (contenedor #app o
 *     #modal-portal),
 *   - si la marca ya esta presente, no se duplica.
 *
 * El script decide por si mismo si hay algo que mostrar consultando
 * /server/<id>/serversplitter/availability, asi que inyectarlo en todas las
 * paginas de cliente no expone nada: en servidores ajenos o en divisiones no
 * anade enlace.
 */
class ClientPanelScript
{
    /** Marca en HTML (no Blade) para reconocer nuestra propia insercion. */
    private const MARKER = '<!-- serversplitter:client -->';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->applies($request, $response)) {
            return $response;
        }

        $html = $response->getContent();

        if (!is_string($html) || $html === '' || str_contains($html, self::MARKER)) {
            return $response;
        }

        if (!$this->looksLikeClientPanel($html)) {
            return $response;
        }

        // strripos: si el HTML menciona "</body>" dentro de un script o de un
        // string, la ultima aparicion sigue siendo el cierre real del documento.
        $position = strripos($html, '</body>');

        if ($position === false) {
            return $response;
        }

        $updated = substr($html, 0, $position) . $this->tag() . substr($html, $position);

        $response->setContent($updated);

        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen($updated));
        }

        return $response;
    }

    /**
     * Determina si la respuesta es una pagina del panel de cliente en HTML que
     * podemos reescribir sin riesgo.
     */
    protected function applies(Request $request, Response $response): bool
    {
        // Descarta StreamedResponse, BinaryFileResponse y JsonResponse.
        if (!$response instanceof HttpResponse) {
            return false;
        }

        if ($request->method() !== 'GET' || $request->ajax() || $request->wantsJson()) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if (!str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return false;
        }

        if ($response->headers->has('Content-Disposition')) {
            return false;
        }

        if ($request->is(
            'admin', 'admin/*',
            'api/*',
            'serversplitter', 'serversplitter/*',
            'server/*/serversplitter', 'server/*/serversplitter/*',
            'extensions/*'
        )) {
            return false;
        }

        return $request->user() !== null;
    }

    /**
     * Comprueba que el documento es el wrapper de la SPA. Evita inyectar el
     * script en paginas HTML que no son el panel (login, errores, vistas de
     * terceros...), donde no existe ninguna barra de navegacion de servidor.
     */
    protected function looksLikeClientPanel(string $html): bool
    {
        return preg_match('/id\s*=\s*(["\'])(app|modal-portal|root)\1/i', $html) === 1;
    }

    /** Etiqueta <script> con defer: no bloquea el arranque de React. */
    protected function tag(): string
    {
        $src = asset('extensions/serversplitter/serversplitter-inject.js');

        return "\n" . self::MARKER . "\n"
            . '<script src="' . e($src) . '" defer></script>' . "\n";
    }
}