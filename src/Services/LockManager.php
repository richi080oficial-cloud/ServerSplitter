<?php

namespace Pterodactyl\Extensions\ServerSplitter\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Extensions\ServerSplitter\Exceptions\SplitterException;

/**
 * Bloqueo pesimista por servidor padre, respaldado en base de datos.
 *
 * Evita que dos peticiones simultaneas (panel + WHMCS, doble clic, etc.)
 * recalculen los recursos del padre al mismo tiempo y dejen numeros incoherentes.
 */
class LockManager
{
    protected const TABLE = 'serversplitter_locks';

    /**
     * Ejecuta el callback con el servidor bloqueado y libera el bloqueo siempre.
     */
    public function run(int $serverId, Closure $callback, int $ttl = 120): mixed
    {
        $token = $this->acquire($serverId, $ttl);

        try {
            return $callback();
        } finally {
            $this->release($serverId, $token);
        }
    }

    /**
     * @throws SplitterException si el servidor ya esta bloqueado.
     */
    public function acquire(int $serverId, int $ttl = 120): string
    {
        $token = Str::random(40);
        $now = Carbon::now();

        try {
            DB::transaction(function () use ($serverId, $token, $ttl, $now) {
                $existing = DB::table(self::TABLE)
                    ->where('server_id', $serverId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if (Carbon::parse($existing->expires_at)->greaterThan($now)) {
                        throw new SplitterException(
                            'Ya hay una operacion en curso sobre este servidor. Espera unos segundos y vuelve a intentarlo.',
                            409
                        );
                    }

                    DB::table(self::TABLE)->where('server_id', $serverId)->delete();
                }

                DB::table(self::TABLE)->insert([
                    'server_id' => $serverId,
                    'token' => $token,
                    'expires_at' => $now->copy()->addSeconds($ttl),
                    'created_at' => $now,
                ]);
            });
        } catch (QueryException $e) {
            // Dos peticiones concurrentes pasaron el SELECT ... FOR UPDATE casi
            // a la vez (por ejemplo panel + WHMCS en el mismo instante) y
            // chocan contra el unique(server_id) al insertar. Se traduce a un
            // error de negocio en vez de dejar escapar la excepcion de SQL.
            throw new SplitterException(
                'Ya hay una operacion en curso sobre este servidor. Espera unos segundos y vuelve a intentarlo.',
                409
            );
        }

        return $token;
    }

    public function release(int $serverId, string $token): void
    {
        DB::table(self::TABLE)
            ->where('server_id', $serverId)
            ->where('token', $token)
            ->delete();
    }

    public function isLocked(int $serverId): bool
    {
        $row = DB::table(self::TABLE)->where('server_id', $serverId)->first();

        return $row !== null && Carbon::parse($row->expires_at)->isFuture();
    }

    /**
     * Limpia bloqueos caducados (por si un proceso murio a medias).
     */
    public function pruneExpired(): int
    {
        return DB::table(self::TABLE)->where('expires_at', '<', Carbon::now())->delete();
    }
}