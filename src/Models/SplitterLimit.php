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
    protected $table = 'serversplitter_limits';

    protected $fillable = [
        'server_id',
        'max_splits',
        'max_memory',
        'max_disk',
        'max_cpu',
        'allow_egg_choice',
    ];

    protected $casts = [
        'server_id' => 'int',
        'max_splits' => 'int',
        'max_memory' => 'int',
        'max_disk' => 'int',
        'max_cpu' => 'int',
        // bool con null: null = usar el comportamiento global (permitido).
        'allow_egg_choice' => 'bool',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}