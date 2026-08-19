<?php

namespace Pterodactyl\Extensions\ServerSplitter\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Extensions\ServerSplitter\Exceptions\SplitterException;
use Pterodactyl\Extensions\ServerSplitter\Models\ServerSplit;
use Pterodactyl\Extensions\ServerSplitter\Services\SplitterService;

class SplitterController extends Controller
{
    public function __construct(protected SplitterService $splitter)
    {
    }

    /**
     * Lista los servidores del usuario que pueden dividirse.
     */
    public function index(): View
    {
        $user = $this->user();

        // Dividir es exclusivo del propietario: los administradores gestionan
        // las divisiones desde /admin/serversplitter, no desde el area cliente.
        $query = Server::query()->where('owner_id', $user->id)->orderBy('name');

        $servers = $query->limit(200)->get()->reject(fn (Server $s) => $this->splitter->isChild($s))->values();

        return view('serversplitter::client.list', [
            'servers' => $servers,
            'settings' => $this->splitter->settings(),
            'splitter' => $this->splitter,
        ]);
    }

    public function show(string $server): View
    {
        $parent = $this->resolveServer($server);
        [$view, $data] = $this->pageFor($parent);

        return view($view, $data);
    }

    /**
     * Igual que show(), pero devuelve solo el HTML del contenido, sin la
     * pagina completa (sin <html>/cabecera/pie). La usa el script inyectado
     * en el panel de cliente (serversplitter-inject.js) para sustituir el
     * area de contenido de la SPA de React sin recargar la pagina: el
     * sidebar y el fondo del tema no se tocan, solo esta porcion.
     *
     * No es una API estable pensada para consumo externo: cambia junto a la
     * plantilla y no lleva versionado propio.
     */
    public function fragment(string $server): Response
    {
        $parent = $this->resolveServer($server);
        [$view, $data] = $this->pageFor($parent);
        $contentView = $view . '-content';

        $html = '<div class="ss-fragment ss-container">' . view($contentView, $data)->render() . '</div>';

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Vista y datos para un servidor: la pagina de gestion si es un padre,
     * o la de "esto es una division" si es un hijo. Compartido entre show()
     * y fragment() para no duplicar la logica de las dos.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function pageFor(Server $parent): array
    {
        // Una division no se gestiona desde ella misma: se deshace desde el
        // servidor principal del que salio o desde el panel de administracion.
        if ($this->splitter->isChild($parent)) {
            $split = ServerSplit::query()->with('parent')->where('server_id', $parent->id)->first();
            $origin = $split?->parent;

            return ['serversplitter::client.child', [
                'server' => $parent,
                'origin' => $origin,
                'ownsOrigin' => $origin !== null && (int) $origin->owner_id === (int) $this->user()->id,
            ]];
        }

        return ['serversplitter::client.index', [
            'server' => $parent,
            'state' => $this->splitter->state($parent),
            'settings' => $this->splitter->settings(),
            'eggs' => $this->splitter->availableEggsFor($parent),
            'canChooseEgg' => $this->splitter->canChooseEgg($parent),
        ]];
    }

    /**
     * Consulta ligera que usa el script inyectado en el panel de cliente para
     * decidir si muestra la pestana "Divisiones" en un servidor concreto.
     *
     * Devuelve siempre 200 con available=false en lugar de 403/404 para no
     * llenar la consola del navegador de errores en servidores ajenos.
     */
    public function availability(string $server): JsonResponse
    {
        $model = Server::query()
            ->where('uuidShort', $server)
            ->orWhere('uuid', $server)
            ->first();

        $user = $this->user();
        $available = false;

        if ($model !== null
            && (int) $model->owner_id === (int) $user->id
            && !$this->splitter->isChild($model)
        ) {
            // Si la extension esta desactivada, el propietario sigue viendo la
            // pestana mientras tenga divisiones vivas que poder eliminar.
            $available = (bool) $this->splitter->settings()['enabled']
                || $this->splitter->children($model)->isNotEmpty();
        }

        return new JsonResponse([
            'available' => $available,
            'url' => $available && $model !== null
                ? route('serversplitter.show', $model->uuidShort)
                : null,
        ]);
    }

    public function store(Request $request, string $server): RedirectResponse
    {
        $parent = $this->resolveServer($server);
        $eggRule = $this->splitter->canChooseEgg($parent) ? 'required|integer' : 'nullable|integer';

        $data = $request->validate([
            'egg_id' => $eggRule,
            'name' => 'nullable|string|max:120',
            'memory' => 'nullable|integer|min:0|max:1048576',
            'disk' => 'nullable|integer|min:0|max:10485760',
            'cpu' => 'nullable|integer|min:0|max:100000',
        ]);

        try {
            $child = $this->splitter->createSplit($parent, $this->user(), $data);
        } catch (SplitterException $e) {
            return $this->back($parent)->with('serversplitter:error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->back($parent)->with('serversplitter:error', 'No se pudo crear la division: ' . $e->getMessage());
        }

        return $this->back($parent)->with(
            'serversplitter:success',
            sprintf('Division "%s" creada. Se esta instalando en el nodo.', $child->name)
        );
    }

    public function destroy(string $server, int $split): RedirectResponse
    {
        $parent = $this->resolveServer($server);

        $model = ServerSplit::query()
            ->with(['server', 'parent'])
            ->where('parent_id', $parent->id)
            ->whereKey($split)
            ->first();

        if ($model === null) {
            return $this->back($parent)->with('serversplitter:error', 'Esa division no pertenece a este servidor.');
        }

        try {
            $this->splitter->deleteSplit($model, $this->user());
        } catch (SplitterException $e) {
            return $this->back($parent)->with('serversplitter:error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->back($parent)->with('serversplitter:error', 'No se pudo eliminar la division: ' . $e->getMessage());
        }

        return $this->back($parent)->with('serversplitter:success', 'Division eliminada y recursos devueltos.');
    }

    protected function back(Server $server): RedirectResponse
    {
        return redirect()->route('serversplitter.show', ['server' => $server->uuidShort]);
    }

    protected function user(): User
    {
        /** @var \Pterodactyl\Models\User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * Resuelve el servidor y comprueba que el usuario puede gestionarlo.
     */
    protected function resolveServer(string $value): Server
    {
        $server = Server::query()
            ->where('uuidShort', $value)
            ->orWhere('uuid', $value)
            ->first();

        abort_if($server === null, 404);

        // Solo el propietario. Un subusuario con acceso al servidor en el panel
        // no puede dividirlo ni deshacer divisiones.
        $user = $this->user();
        abort_unless((int) $server->owner_id === (int) $user->id, 403);

        return $server;
    }
}