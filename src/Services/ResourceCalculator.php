<?php

namespace Pterodactyl\Extensions\ServerSplitter\Services;

use Pterodactyl\Models\Server;
use Pterodactyl\Extensions\ServerSplitter\Exceptions\SplitterException;

/**
 * Aritmetica de recursos. Trabaja siempre con arrays ['memory' => int, 'disk' => int, 'cpu' => int].
 *
 * Convencion de Pterodactyl: el valor 0 significa "ilimitado". Por eso nunca se
 * resta ni se reparte una clave cuyo valor de origen sea 0.
 */
class ResourceCalculator
{
    public const MODE_DIFFERENCE = 'difference';
    public const MODE_DISTRIBUTE = 'distribute';

    public const KEYS = ['memory', 'disk', 'cpu'];

    /**
     * @return array<string, int>
     */
    public function limitsOf(Server $server): array
    {
        return [
            'memory' => (int) $server->memory,
            'disk' => (int) $server->disk,
            'cpu' => (int) $server->cpu,
        ];
    }

    /**
     * Suma total de recursos gestionados: padre + hijos.
     *
     * @param array<string, int> $parent
     * @param array<int, array<string, int>> $children
     *
     * @return array<string, int>
     */
    public function pool(array $parent, array $children): array
    {
        $pool = [];

        foreach (self::KEYS as $key) {
            $pool[$key] = (int) ($parent[$key] ?? 0);

            foreach ($children as $child) {
                $pool[$key] += (int) ($child[$key] ?? 0);
            }
        }

        return $pool;
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     *
     * @return array<string, int>
     */
    public function add(array $a, array $b): array
    {
        $out = [];

        foreach (self::KEYS as $key) {
            $base = (int) ($a[$key] ?? 0);
            // 0 = ilimitado: se mantiene ilimitado.
            $out[$key] = $base === 0 ? 0 : $base + (int) ($b[$key] ?? 0);
        }

        return $out;
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     *
     * @return array<string, int>
     */
    public function subtract(array $a, array $b): array
    {
        $out = [];

        foreach (self::KEYS as $key) {
            $base = (int) ($a[$key] ?? 0);
            $out[$key] = $base === 0 ? 0 : max(0, $base - (int) ($b[$key] ?? 0));
        }

        return $out;
    }

    /**
     * Parte igualitaria por servidor (division entera hacia abajo).
     *
     * @param array<string, int> $pool
     *
     * @return array<string, int>
     */
    public function share(array $pool, int $participants): array
    {
        if ($participants < 1) {
            throw new SplitterException('Numero de participantes invalido al repartir recursos.', 500);
        }

        $out = [];

        foreach (self::KEYS as $key) {
            $total = (int) ($pool[$key] ?? 0);
            $out[$key] = $total === 0 ? 0 : intdiv($total, $participants);
        }

        return $out;
    }

    /**
     * Reparto equitativo: devuelve la parte de cada hijo y el resto para el padre.
     *
     * @param array<string, int> $pool
     *
     * @return array{share: array<string, int>, parent: array<string, int>}
     */
    public function distribute(array $pool, int $childCount): array
    {
        $participants = $childCount + 1;
        $share = $this->share($pool, $participants);

        $parent = [];
        foreach (self::KEYS as $key) {
            $total = (int) ($pool[$key] ?? 0);
            $parent[$key] = $total === 0 ? 0 : $total - ($share[$key] * $childCount);
        }

        return ['share' => $share, 'parent' => $parent];
    }

    /**
     * Comprueba que el modo distribute es aplicable (no hay recursos ilimitados en juego).
     *
     * @param array<string, int> $pool
     */
    public function assertDistributable(array $pool): void
    {
        foreach (['memory', 'disk'] as $key) {
            if ((int) ($pool[$key] ?? 0) === 0) {
                throw new SplitterException(
                    'El reparto equitativo necesita que el servidor padre tenga limites definidos de memoria y disco (no ilimitados).',
                    422
                );
            }
        }
    }
}