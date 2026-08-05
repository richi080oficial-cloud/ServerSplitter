<?php

namespace Pterodactyl\Extensions\ServerSplitter\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Extensions\ServerSplitter\Exceptions\SplitterException;
use Pterodactyl\Extensions\ServerSplitter\Models\ServerSplit;
use Pterodactyl\Extensions\ServerSplitter\Services\SplitterService;

/**
 * API para WHMCS / Paymenter (u otro automatismo).
 *
 * Autenticada por la cabecera X-Splitter-Key (middleware SplitterApiKey).
 */
class IntegrationController extends Controller
{
    public function __construct(protected SplitterService $splitter)
    {
    }

    public function show(string $server): JsonResponse
    {
        $model = $this->resolve($server);

        return new JsonResponse(['data' => $this->splitter->state($model)]);
    }

    /**
     * Aumenta / reduce recursos y limites de divisiones.
     */
    public function update(Request $request, string $server): JsonResponse
    {
        $model = $this->resolve($server);

        $data = $request->validate([
            'action' => 'nullable|in:add,set',
            'memory' => 'nullable|integer',
            'disk' => 'nullable|integer',
            'cpu' => 'nullable|integer',
            'max_splits' => 'nullable|integer|min:0|max:1000',
            'max_memory' => 'nullable|integer|min:0',
            'max_disk' => 'nullable|integer|min:0',
            'max_cpu' => 'nullable|integer|min:0',
        ]);

        try {
            $state = $this->splitter->applyIntegration($model, $data);
        } catch (SplitterException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->getStatus());
        }

        return new JsonResponse(['data' => $state]);
    }

    /**
     * Elimina todas las divisiones de un servidor (por ejemplo al cancelar el servicio).
     */
    public function purge(string $server): JsonResponse
    {
        $model = $this->resolve($server);
        $removed = 0;

        foreach ($this->splitter->children($model) as $split) {
            try {
                $this->splitter->deleteSplit($split, $model->user, true);
                $removed++;
            } catch (SplitterException $e) {
                return new JsonResponse([
                    'error' => $e->getMessage(),
                    'removed' => $removed,
                ], $e->getStatus());
            }
        }

        return new JsonResponse([
            'data' => [
                'removed' => $removed,
                'state' => $this->splitter->state($model->refresh()),
            ],
        ]);
    }

    public function destroySplit(string $server, int $split): JsonResponse
    {
        $model = $this->resolve($server);

        $record = ServerSplit::query()
            ->with(['server', 'parent'])
            ->where('parent_id', $model->id)
            ->whereKey($split)
            ->first();

        if ($record === null) {
            return new JsonResponse(['error' => 'Division no encontrada para ese servidor.'], 404);
        }

        try {
            $this->splitter->deleteSplit($record, $model->user, true);
        } catch (SplitterException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->getStatus());
        }

        return new JsonResponse(['data' => $this->splitter->state($model->refresh())]);
    }

    protected function resolve(string $value): Server
    {
        $server = Server::query()
            ->where('uuidShort', $value)
            ->orWhere('uuid', $value)
            ->orWhere('external_id', $value)
            ->when(ctype_digit($value), fn ($q) => $q->orWhere('id', (int) $value))
            ->first();

        abort_if($server === null, 404, 'Servidor no encontrado.');

        return $server;
    }
}