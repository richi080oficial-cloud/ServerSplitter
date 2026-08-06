<?php

namespace Pterodactyl\Extensions\ServerSplitter\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Services\Servers\BuildModificationService;
use Pterodactyl\Services\Servers\ServerCreationService;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Pterodactyl\Extensions\ServerSplitter\Exceptions\SplitterException;
use Pterodactyl\Extensions\ServerSplitter\Models\ServerSplit;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterEggRule;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterLimit;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterSetting;

/**
 * Nucleo de ServerSplitter: crea, elimina y recalcula divisiones de forma atomica.
 */
class SplitterService
{
    /** Estados en los que NO se permite tocar un servidor. */
    protected const BLOCKED_STATUSES = ['suspended', 'installing', 'install_failed', 'restoring_backup'];

    public function __construct(
        protected ServerCreationService $creationService,
        protected BuildModificationService $buildService,
        protected ServerDeletionService $deletionService,
        protected DaemonPowerRepository $powerRepository,
        protected ResourceCalculator $calculator,
        protected LockManager $locks
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return SplitterSetting::snapshot();
    }

    public function calculator(): ResourceCalculator
    {
        return $this->calculator;
    }

    public function locks(): LockManager
    {
        return $this->locks;
    }

    /**
     * Divisiones de un servidor padre.
     */
    public function children(Server $parent): Collection
    {
        return ServerSplit::query()
            ->with('server')
            ->where('parent_id', $parent->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (ServerSplit $split) => $split->server !== null)
            ->values();
    }

    public function isChild(Server $server): bool
    {
        return ServerSplit::query()->where('server_id', $server->id)->exists();
    }

    public function limitFor(Server $parent): ?SplitterLimit
    {
        return SplitterLimit::query()->where('server_id', $parent->id)->first();
    }

    /**
     * Numero maximo de divisiones permitidas para un servidor padre.
     *
     * El valor 0 significa "ilimitado" (misma convencion que Pterodactyl usa
     * para memory/disk/cpu). Un limite por servidor a null indica que no hay
     * limite propio y se usa el valor global.
     */
    public function maxSplits(Server $parent): int
    {
        $limit = $this->limitFor($parent);

        if ($limit && $limit->max_splits !== null) {
            return max(0, (int) $limit->max_splits);
        }

        return max(0, (int) $this->settings()['max_splits']);
    }

    /**
     * True si el servidor no tiene tope de divisiones.
     */
    public function hasUnlimitedSplits(Server $parent): bool
    {
        return $this->maxSplits($parent) === 0;
    }

    /**
     * Eggs que el usuario puede elegir para una division.
     */
    public function availableEggs(): Collection
    {
        $settings = $this->settings();
        $rules = SplitterEggRule::query()->get()->keyBy('egg_id');

        return Egg::query()
            ->with('nest')
            ->orderBy('name')
            ->get()
            ->filter(function (Egg $egg) use ($rules, $settings) {
                $rule = $rules->get($egg->id);

                if ($rule === null) {
                    return (bool) $settings['allow_unlisted_eggs'];
                }

                return (bool) $rule->allowed;
            })
            ->values();
    }

    /**
     * Crea una division del servidor padre.
     *
     * @param array{egg_id:int, name?:string|null, memory?:int|null, disk?:int|null, cpu?:int|null} $data
     *
     * @throws SplitterException
     */
    public function createSplit(Server $parent, User $actor, array $data): Server
    {
        return $this->locks->run($parent->id, function () use ($parent, $actor, $data) {
            $settings = $this->settings();
            $this->assertEnabled($settings);
            $this->assertUsable($parent);

            if ($this->isChild($parent)) {
                throw new SplitterException('Este servidor ya es una division, no puede volver a dividirse.');
            }

            $children = $this->children($parent);
            $max = $this->maxSplits($parent);

            // 0 = ilimitado: no se aplica ningun tope.
            if ($max > 0 && $children->count() >= $max) {
                throw new SplitterException(sprintf('Has alcanzado el limite de divisiones para este servidor (%d).', $max));
            }

            $egg = Egg::query()->with('variables')->find((int) ($data['egg_id'] ?? 0));
            if ($egg === null) {
                throw new SplitterException('El egg seleccionado no existe.');
            }

            $rule = SplitterEggRule::query()->where('egg_id', $egg->id)->first();
            if ($rule === null && !$settings['allow_unlisted_eggs']) {
                throw new SplitterException('Ese egg no esta habilitado para divisiones.');
            }
            if ($rule !== null && !$rule->allowed) {
                throw new SplitterException('Ese egg no esta habilitado para divisiones.');
            }

            $parentLimits = $this->calculator->limitsOf($parent);
            $childrenLimits = $children->map(fn (ServerSplit $s) => $this->calculator->limitsOf($s->server))->all();
            $mode = (string) $settings['resize_mode'];

            if ($mode === ResourceCalculator::MODE_DISTRIBUTE) {
                $pool = $this->calculator->pool($parentLimits, $childrenLimits);
                $this->calculator->assertDistributable($pool);

                $result = $this->calculator->distribute($pool, $children->count() + 1);
                $childLimits = $result['share'];
                $parentNew = $result['parent'];
                $siblingLimits = $result['share'];
            } else {
                $childLimits = $this->normaliseRequest($data, $settings, $rule);
                $parentNew = $this->calculator->subtract($parentLimits, $childLimits);
                $siblingLimits = null;
            }

            $this->assertMinimums($childLimits, $settings, $rule);
            $this->assertParentReserve($parentLimits, $childLimits, $parentNew, $settings, $mode);
            $this->assertWithinPurchasedLimits($parent, $childLimits, $children);

            $allocation = $this->freeAllocation($parent);
            $child = $this->createChildServer($parent, $egg, $childLimits, $allocation, $data['name'] ?? null);

            try {
                $this->applyBuild($parent, $parentNew);

                if ($siblingLimits !== null) {
                    foreach ($children as $split) {
                        $this->applyBuild($split->server, $siblingLimits);
                    }
                }

                ServerSplit::query()->create([
                    'server_id' => $child->id,
                    'parent_id' => $parent->id,
                    'user_id' => $actor->id,
                    'memory' => $childLimits['memory'],
                    'disk' => $childLimits['disk'],
                    'cpu' => $childLimits['cpu'],
                    'mode' => $mode,
                ]);
            } catch (\Throwable $e) {
                // Si el padre no pudo redimensionarse, no dejamos el hijo huerfano.
                $this->safeDelete($child);

                throw new SplitterException(
                    'No se pudo redimensionar el servidor padre, la division se ha revertido: ' . $e->getMessage(),
                    500
                );
            }

            $this->runPowerAction($parent, $settings);

            return $child->refresh();
        });
    }

    /**
     * Elimina una division y devuelve los recursos al padre.
     *
     * @throws SplitterException
     */
    public function deleteSplit(ServerSplit $split, User $actor, bool $adminOverride = false): void
    {
        $parent = $split->parent;

        if ($parent === null) {
            $split->delete();

            return;
        }

        $this->locks->run($parent->id, function () use ($split, $parent, $adminOverride) {
            $settings = $this->settings();

            if (!$adminOverride) {
                // El propietario siempre puede eliminar sus propias divisiones.
                $this->assertEnabled($settings);
                $this->assertUsable($parent);
            }

            $child = $split->server;
            $childLimits = [
                'memory' => (int) $split->memory,
                'disk' => (int) $split->disk,
                'cpu' => (int) $split->cpu,
            ];

            if ($child !== null) {
                $childLimits = $this->calculator->limitsOf($child);
                $this->safeDelete($child);
            }

            $split->delete();

            $remaining = $this->children($parent->refresh());
            $parentLimits = $this->calculator->limitsOf($parent);
            $mode = (string) $settings['resize_mode'];

            if ($mode === ResourceCalculator::MODE_DISTRIBUTE) {
                $pool = $this->calculator->pool(
                    $this->calculator->add($parentLimits, $childLimits),
                    $remaining->map(fn (ServerSplit $s) => $this->calculator->limitsOf($s->server))->all()
                );

                $result = $this->calculator->distribute($pool, $remaining->count());
                $this->applyBuild($parent, $result['parent']);

                foreach ($remaining as $sibling) {
                    $this->applyBuild($sibling->server, $result['share']);
                }
            } else {
                $this->applyBuild($parent, $this->calculator->add($parentLimits, $childLimits));
            }

            $this->runPowerAction($parent, $settings);
        });
    }

    /**
     * Aplica cambios provenientes de WHMCS / Paymenter (o del admin).
     *
     * $payload admite:
     *   action     => add | set        (add suma la diferencia, set fija el total del pool)
     *   memory/disk/cpu => int
     *   max_splits => int
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> estado resultante
     */
    public function applyIntegration(Server $parent, array $payload): array
    {
        return $this->locks->run($parent->id, function () use ($parent, $payload) {
            $settings = $this->settings();
            $this->assertUsable($parent);

            if (array_key_exists('max_splits', $payload) && $payload['max_splits'] !== null) {
                SplitterLimit::query()->updateOrCreate(
                    ['server_id' => $parent->id],
                    ['max_splits' => max(0, (int) $payload['max_splits'])]
                );
            }

            foreach (['max_memory', 'max_disk', 'max_cpu'] as $key) {
                if (array_key_exists($key, $payload) && $payload[$key] !== null) {
                    SplitterLimit::query()->updateOrCreate(
                        ['server_id' => $parent->id],
                        [$key => max(0, (int) $payload[$key])]
                    );
                }
            }

            $delta = [
                'memory' => (int) ($payload['memory'] ?? 0),
                'disk' => (int) ($payload['disk'] ?? 0),
                'cpu' => (int) ($payload['cpu'] ?? 0),
            ];

            $hasResourceChange = $delta['memory'] !== 0 || $delta['disk'] !== 0 || $delta['cpu'] !== 0;

            if ($hasResourceChange) {
                $action = (string) ($payload['action'] ?? 'add');
                $children = $this->children($parent);
                $childrenLimits = $children->map(fn (ServerSplit $s) => $this->calculator->limitsOf($s->server))->all();
                $parentLimits = $this->calculator->limitsOf($parent);
                $mode = (string) $settings['resize_mode'];

                if ($action === 'set') {
                    $pool = [
                        'memory' => max(0, $delta['memory']),
                        'disk' => max(0, $delta['disk']),
                        'cpu' => max(0, $delta['cpu']),
                    ];
                } else {
                    $pool = $this->calculator->pool($parentLimits, $childrenLimits);
                    foreach (ResourceCalculator::KEYS as $key) {
                        $pool[$key] = max(0, $pool[$key] + $delta[$key]);
                    }
                }

                if ($mode === ResourceCalculator::MODE_DISTRIBUTE && $children->count() > 0) {
                    $this->calculator->assertDistributable($pool);
                    $result = $this->calculator->distribute($pool, $children->count());

                    $this->applyBuild($parent, $result['parent']);
                    foreach ($children as $split) {
                        $this->applyBuild($split->server, $result['share']);
                    }
                } else {
                    // Modo diferencia: los hijos no se tocan, el padre absorbe el cambio.
                    $used = $this->calculator->pool(['memory' => 0, 'disk' => 0, 'cpu' => 0], $childrenLimits);
                    $parentNew = [];
                    foreach (ResourceCalculator::KEYS as $key) {
                        $parentNew[$key] = max(0, $pool[$key] - $used[$key]);
                    }

                    $this->applyBuild($parent, $parentNew);
                }

                $this->runPowerAction($parent, $settings);
            }

            return $this->state($parent->refresh());
        });
    }

    /**
     * Estado completo de un servidor padre (usado por la API y las vistas).
     *
     * @return array<string, mixed>
     */
    public function state(Server $parent): array
    {
        $children = $this->children($parent);
        $limit = $this->limitFor($parent);
        $settings = $this->settings();
        $max = $this->maxSplits($parent);

        return [
            'server' => [
                'id' => $parent->id,
                'uuid' => $parent->uuid,
                'identifier' => $parent->uuidShort,
                'name' => $parent->name,
                'memory' => (int) $parent->memory,
                'disk' => (int) $parent->disk,
                'cpu' => (int) $parent->cpu,
            ],
            'mode' => $settings['resize_mode'],
            'is_child' => $this->isChild($parent),
            'locked' => $this->locks->isLocked($parent->id),
            'splits_used' => $children->count(),
            'splits_max' => $max,
            'splits_unlimited' => $max === 0,
            'splits_available' => $max === 0 ? null : max(0, $max - $children->count()),
            'purchased_limits' => $limit ? [
                'max_splits' => $limit->max_splits,
                'max_memory' => $limit->max_memory,
                'max_disk' => $limit->max_disk,
                'max_cpu' => $limit->max_cpu,
            ] : null,
            'pool' => $this->calculator->pool(
                $this->calculator->limitsOf($parent),
                $children->map(fn (ServerSplit $s) => $this->calculator->limitsOf($s->server))->all()
            ),
            'splits' => $children->map(function (ServerSplit $split) {
                return [
                    'id' => $split->id,
                    'server_id' => $split->server_id,
                    'identifier' => $split->server->uuidShort,
                    'name' => $split->server->name,
                    'memory' => (int) $split->server->memory,
                    'disk' => (int) $split->server->disk,
                    'cpu' => (int) $split->server->cpu,
                    'created_at' => $split->created_at?->toIso8601String(),
                ];
            })->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $settings
     */
    protected function assertEnabled(array $settings): void
    {
        if (!$settings['enabled']) {
            throw new SplitterException('El sistema de divisiones esta desactivado por el administrador.', 403);
        }
    }

    protected function assertUsable(Server $server): void
    {
        $status = (string) ($server->status ?? '');

        if ($status !== '' && in_array($status, self::BLOCKED_STATUSES, true)) {
            throw new SplitterException(
                sprintf('El servidor no esta disponible ahora mismo (estado: %s).', $status),
                409
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $settings
     *
     * @return array<string, int>
     */
    protected function normaliseRequest(array $data, array $settings, ?SplitterEggRule $rule): array
    {
        $memory = (int) ($data['memory'] ?? 0);

        // El cliente siempre puede elegir disco y CPU; si no envia valor se usa
        // el minimo de la regla del egg y, en su defecto, el minimo global.
        $disk = isset($data['disk']) && $data['disk'] !== ''
            ? (int) $data['disk']
            : (int) ($rule?->min_disk ?: $settings['min_disk']);

        $cpu = isset($data['cpu']) && $data['cpu'] !== ''
            ? (int) $data['cpu']
            : (int) ($rule?->min_cpu ?: $settings['min_cpu']);

        return ['memory' => $memory, 'disk' => $disk, 'cpu' => $cpu];
    }

    /**
     * @param array<string, int> $limits
     * @param array<string, mixed> $settings
     */
    protected function assertMinimums(array $limits, array $settings, ?SplitterEggRule $rule): void
    {
        $labels = ['memory' => 'memoria (MiB)', 'disk' => 'disco (MiB)', 'cpu' => 'CPU (%)'];

        foreach (ResourceCalculator::KEYS as $key) {
            $min = (int) ($rule?->{'min_' . $key} ?: $settings['min_' . $key]);
            $max = (int) ($rule?->{'max_' . $key} ?: 0);

            if ($limits[$key] < $min) {
                throw new SplitterException(sprintf('El minimo de %s para una division es %d.', $labels[$key], $min));
            }

            if ($max > 0 && $limits[$key] > $max) {
                throw new SplitterException(sprintf('El maximo de %s para este egg es %d.', $labels[$key], $max));
            }
        }
    }

    /**
     * @param array<string, int> $parentBefore
     * @param array<string, int> $requested
     * @param array<string, int> $parentAfter
     * @param array<string, mixed> $settings
     */
    protected function assertParentReserve(
        array $parentBefore,
        array $requested,
        array $parentAfter,
        array $settings,
        string $mode
    ): void {
        $labels = ['memory' => 'memoria', 'disk' => 'disco', 'cpu' => 'CPU'];

        foreach (ResourceCalculator::KEYS as $key) {
            if ($parentBefore[$key] === 0) {
                continue; // ilimitado en el padre
            }

            if ($mode === ResourceCalculator::MODE_DIFFERENCE && $requested[$key] > $parentBefore[$key]) {
                throw new SplitterException(sprintf(
                    'El servidor padre no tiene suficiente %s (disponible: %d).',
                    $labels[$key],
                    $parentBefore[$key]
                ));
            }

            $reserve = (int) $settings['reserve_' . $key];

            if ($parentAfter[$key] < $reserve) {
                throw new SplitterException(sprintf(
                    'El servidor padre debe conservar al menos %d de %s (quedaria %d).',
                    $reserve,
                    $labels[$key],
                    $parentAfter[$key]
                ));
            }
        }
    }

    /**
     * Limites "comprados" (WHMCS/Paymenter) sobre el total repartido en hijos.
     *
     * @param array<string, int> $requested
     */
    protected function assertWithinPurchasedLimits(Server $parent, array $requested, Collection $children): void
    {
        $limit = $this->limitFor($parent);

        if ($limit === null) {
            return;
        }

        $labels = ['memory' => 'memoria', 'disk' => 'disco', 'cpu' => 'CPU'];

        foreach (ResourceCalculator::KEYS as $key) {
            $max = (int) ($limit->{'max_' . $key} ?? 0);

            if ($max <= 0) {
                continue;
            }

            $used = $requested[$key];
            foreach ($children as $split) {
                $used += (int) $split->server->{$key};
            }

            if ($used > $max) {
                throw new SplitterException(sprintf(
                    'Superas el maximo de %s contratado para divisiones (%d de %d).',
                    $labels[$key],
                    $used,
                    $max
                ));
            }
        }
    }

    protected function freeAllocation(Server $parent): Allocation
    {
        $allocation = Allocation::query()
            ->where('node_id', $parent->node_id)
            ->whereNull('server_id')
            ->orderBy('port')
            ->first();

        if ($allocation === null) {
            throw new SplitterException(
                'No hay puertos libres en el nodo del servidor padre. Avisa al administrador.',
                503
            );
        }

        return $allocation;
    }

    /**
     * @param array<string, int> $limits
     */
    protected function createChildServer(
        Server $parent,
        Egg $egg,
        array $limits,
        Allocation $allocation,
        ?string $name
    ): Server {
        $settings = $this->settings();
        $prefix = (string) $settings['child_name_prefix'];

        $finalName = trim((string) $name);
        if ($finalName === '') {
            $finalName = sprintf('%s-%s-%s', $prefix, $parent->uuidShort, Str::lower(Str::random(4)));
        }

        $environment = [];
        foreach ($egg->variables as $variable) {
            $environment[$variable->env_variable] = $variable->default_value;
        }

        $images = is_array($egg->docker_images) ? array_values($egg->docker_images) : [];
        $image = $images[0] ?? (string) $egg->docker_image;

        $payload = [
            'name' => Str::limit($finalName, 191, ''),
            'description' => sprintf('Division de %s (#%d)', $parent->name, $parent->id),
            'owner_id' => $parent->owner_id,
            'node_id' => $parent->node_id,
            'allocation_id' => $allocation->id,
            'allocation_additional' => [],
            'nest_id' => $egg->nest_id,
            'egg_id' => $egg->id,
            'startup' => $egg->startup,
            'image' => $image,
            'skip_scripts' => false,
            'environment' => $environment,
            'memory' => $limits['memory'],
            'swap' => 0,
            'disk' => $limits['disk'],
            'io' => 500,
            'cpu' => $limits['cpu'],
            'threads' => null,
            'oom_disabled' => (bool) $parent->oom_disabled,
            'database_limit' => 0,
            'allocation_limit' => 0,
            'backup_limit' => 0,
            'start_on_completion' => false,
        ];

        return $this->creationService->handle($payload);
    }

    /**
     * @param array<string, int> $limits
     */
    protected function applyBuild(Server $server, array $limits): void
    {
        $this->buildService->handle($server, [
            'allocation_id' => $server->allocation_id,
            'add_allocations' => [],
            'remove_allocations' => [],
            'memory' => (int) $limits['memory'],
            'swap' => (int) $server->swap,
            'io' => (int) $server->io,
            'cpu' => (int) $limits['cpu'],
            'threads' => $server->threads,
            'disk' => (int) $limits['disk'],
            'database_limit' => $server->database_limit,
            'allocation_limit' => $server->allocation_limit,
            'backup_limit' => $server->backup_limit,
            'oom_disabled' => (bool) $server->oom_disabled,
        ]);
    }

    protected function safeDelete(Server $server): void
    {
        $service = $this->deletionService;

        if (method_exists($service, 'withForce')) {
            $service = $service->withForce(true);
        }

        $service->handle($server);
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function runPowerAction(Server $parent, array $settings): void
    {
        $action = (string) $settings['power_action'];

        if (!in_array($action, ['restart', 'stop', 'kill'], true)) {
            return;
        }

        try {
            $this->powerRepository->setServer($parent)->send($action);
        } catch (\Throwable $e) {
            // Un fallo de wings no debe invalidar la division ya aplicada.
            Log::warning('[ServerSplitter] No se pudo enviar la accion de energia al servidor padre.', [
                'server_id' => $parent->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}