<?php

namespace Pterodactyl\Extensions\ServerSplitter\Http\Controllers\Client;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
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

        $query = Server::query()->orderBy('name');
        if (!$user->root_admin) {
            $query->where('owner_id', $user->id);
        }

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

        return view('serversplitter::client.index', [
            'server' => $parent,
            'state' => $this->splitter->state($parent),
            'settings' => $this->splitter->settings(),
            'eggs' => $this->splitter->availableEggs(),
        ]);
    }

    public function store(Request $request, string $server): RedirectResponse
    {
        $parent = $this->resolveServer($server);

        $data = $request->validate([
            'egg_id' => 'required|integer',
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

        $user = $this->user();
        abort_unless($user->root_admin || $server->owner_id === $user->id, 403);

        return $server;
    }
}