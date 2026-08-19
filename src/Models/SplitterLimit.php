<?php

namespace Pterodactyl\Extensions\ServerSplitter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Server;

/**
 * Limites especificos de un servidor padre (los rellenan WHMCS/Paymenter o el admin).
 */
class SplitterLimit extends Model
{
    /** Modos de eleccion de egg para las divisiones de este servidor. */
    public const EGG_MODE_NONE = 'none';
    public const EGG_MODE_ALL = 'all';
    public const EGG_MODE_DEFINED = 'defined';

    protected $table = 'serversplitter_limits';

    protected $fillable = [
        'server_id',
        'max_splits',
        'max_memory',
        'max_disk',
        'max_cpu',
        'egg_choice_mode',
        'allowed_egg_ids',
    ];

    protected $casts = [
        'server_id' => 'int',
        'max_splits' => 'int',
        'max_memory' => 'int',
        'max_disk' => 'int',
        'max_cpu' => 'int',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    /**
     * Modo de eleccion de egg, normalizado. Sin fila o campo vacio => 'none'
     * (la division hereda siempre el egg del padre): es el comportamiento
     * predeterminado.
     */
    public function eggChoiceMode(): string
    {
        $mode = (string) ($this->egg_choice_mode ?? '');

        return in_array($mode, [self::EGG_MODE_ALL, self::EGG_MODE_DEFINED], true) ? $mode : self::EGG_MODE_NONE;
    }

    /**
     * IDs de egg permitidos explicitamente para este servidor (modo 'defined').
     *
     * @return array<int, int>
     */
    public function allowedEggIds(): array
    {
        $raw = (string) ($this->allowed_egg_ids ?? '');

        if ($raw === '') {
            return [];
        }

        $ids = array_map('intval', explode(',', $raw));

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }
}
