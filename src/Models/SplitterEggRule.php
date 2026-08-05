<?php

namespace Pterodactyl\Extensions\ServerSplitter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Egg;

/**
 * Regla que define si un egg puede usarse en una division y con que limites.
 */
class SplitterEggRule extends Model
{
    protected $table = 'serversplitter_egg_rules';

    protected $fillable = [
        'egg_id',
        'allowed',
        'min_memory',
        'min_disk',
        'min_cpu',
        'max_memory',
        'max_disk',
        'max_cpu',
    ];

    protected $casts = [
        'egg_id' => 'int',
        'allowed' => 'bool',
        'min_memory' => 'int',
        'min_disk' => 'int',
        'min_cpu' => 'int',
        'max_memory' => 'int',
        'max_disk' => 'int',
        'max_cpu' => 'int',
    ];

    public function egg(): BelongsTo
    {
        return $this->belongsTo(Egg::class, 'egg_id');
    }
}