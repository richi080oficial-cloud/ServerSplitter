<?php

namespace Pterodactyl\Extensions\ServerSplitter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterLimit;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda los limites de ServerSplitter enviados desde las pantallas de
 * administracion de servidores del panel (crear servidor y editar build).
 *
 * Pterodactyl no expone ningun hook para esos formularios y su validacion
 * ignora los campos que no conoce, asi que la extension los recoge aqui:
 * el formulario envia serversplitter[max_splits], serversplitter[max_memory],
 * etc. y este middleware los persiste DESPUES de que el panel haya hecho su
 * trabajo, solo si la peticion termino en una redireccion correcta.
 *
 * Semantica de los valores (identica a la del panel de la extension):
 *   campo vacio -> se usa el valor global
 *   0           -> ilimitado
 */
class AdminServerLimits
{
    /** Campos aceptados, en el orden en el que se muestran. */
    private const FIELDS = ['max_splits', 'max_memory', 'max_disk', 'max_cpu'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->applies($request)) {
            return $response;
        }

        $payload = $this->payload($request);

        if ($payload === null) {
            // El formulario no traia campos de la extension: no tocamos nada.
            return $response;
        }

        if (!$this->succeeded($request, $response)) {
            return $response;
        }

        $serverId = $this->serverId($request, $response);

        if ($serverId === null) {
            return $response;
        }

        $eggMode = $this->eggChoiceMode($request);
        $allowedEggIds = $this->allowedEggIds($request);

        $isDefault = $payload === []
            && $eggMode === SplitterLimit::EGG_MODE_NONE
            && $allowedEggIds === [];

        if ($isDefault) {
            SplitterLimit::query()->where('server_id', $serverId)->delete();

            return $response;
        }

        $limit = SplitterLimit::query()->firstOrNew(['server_id' => $serverId]);
        $limit->server_id = $serverId;

        foreach (self::FIELDS as $field) {
            $limit->{$field} = $payload[$field] ?? null;
        }

        $limit->egg_choice_mode = $eggMode;
        $limit->allowed_egg_ids = $eggMode === SplitterLimit::EGG_MODE_DEFINED && $allowedEggIds !== []
            ? implode(',', $allowedEggIds)
            : null;

        $limit->save();

        return $response;
    }

    /**
     * Solo actuamos en los dos formularios de admin que parcheamos y siempre
     * con un administrador autenticado detras.
     */
    protected function applies(Request $request): bool
    {
        if ($request->isMethodSafe()) {
            return false;
        }

        if (!$request->is('admin/servers/new', 'admin/servers/view/*/build')) {
            return false;
        }

        $user = $request->user();

        return $user !== null && (bool) $user->root_admin;
    }

    /**
     * Normaliza los campos enviados.
     *
     * @return array<string, int>|null null = no venia el bloque de la extension
     */
    protected function payload(Request $request): ?array
    {
        $raw = $request->input('serversplitter');

        if (!is_array($raw)) {
            return null;
        }

        $data = [];

        foreach (self::FIELDS as $field) {
            $value = $raw[$field] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '' || !is_numeric($value)) {
                continue;
            }

            $number = (int) $value;

            if ($number < 0) {
                continue;
            }

            $data[$field] = $number;
        }

        return $data;
    }

    /**
     * Lee serversplitter[egg_choice_mode]. Cualquier valor que no sea 'all' o
     * 'defined' se trata como 'none' (el predeterminado: la division hereda
     * el egg del padre).
     */
    protected function eggChoiceMode(Request $request): string
    {
        $raw = (string) $request->input('serversplitter.egg_choice_mode', '');

        return in_array($raw, [SplitterLimit::EGG_MODE_ALL, SplitterLimit::EGG_MODE_DEFINED], true)
            ? $raw
            : SplitterLimit::EGG_MODE_NONE;
    }

    /**
     * IDs de egg marcados en el multi-select serversplitter[allowed_egg_ids][].
     *
     * @return array<int, int>
     */
    protected function allowedEggIds(Request $request): array
    {
        $raw = $request->input('serversplitter.allowed_egg_ids', []);

        if (!is_array($raw)) {
            return [];
        }

        $ids = array_map('intval', $raw);

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }

    /**
     * El panel redirige tanto al guardar bien como al fallar la validacion;
     * en el segundo caso hay errores en sesion y no debemos guardar nada.
     */
    protected function succeeded(Request $request, Response $response): bool
    {
        if (!$response->isRedirect()) {
            return false;
        }

        if ($request->hasSession() && $request->session()->get('errors') !== null) {
            return false;
        }

        return true;
    }

    /**
     * Al editar, el id viene en la ruta; al crear, en la redireccion final
     * hacia /admin/servers/view/{id}.
     */
    protected function serverId(Request $request, Response $response): ?int
    {
        $parameter = $request->route('server');

        if ($parameter instanceof Server) {
            return (int) $parameter->id;
        }

        if (is_numeric($parameter)) {
            return (int) $parameter;
        }

        $location = (string) $response->headers->get('Location', '');

        if (preg_match('#/admin/servers/view/(\d+)#', $location, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}