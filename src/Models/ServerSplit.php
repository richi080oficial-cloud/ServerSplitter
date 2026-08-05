<?php

namespace Pterodactyl\Extensions\ServerSplitter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * Relacion entre un servidor hijo y su servidor padre.
 */
class ServerSplit extends Model
{
    protected $table = 'serversplitter_splits';

    protected $fillable = [
        'server_id',
        'parent_id',
        'user_id',
        'memory',
        'disk',
        'cpu',
        'mode',
    ];

    protected $casts = [
        'server_id' => 'int',
        'parent_id' => 'int',
        'user_id' => 'int',
        'memory' => 'int',
        'disk' => 'int',
        'cpu' => 'int',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'parent_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}