<?php

namespace Pterodactyl\Extensions\ServerSplitter\Providers;

use Illuminate\Support\ServiceProvider;
use Pterodactyl\Extensions\ServerSplitter\Console\Commands\SplitterCommand;
use Pterodactyl\Extensions\ServerSplitter\Http\Middleware\AdminServerLimits;
use Pterodactyl\Extensions\ServerSplitter\Services\LockManager;
use Pterodactyl\Extensions\ServerSplitter\Services\ResourceCalculator;

/**
 * Punto de entrada de la extension. Todo (vistas, rutas, migraciones y config)
 * se carga desde este directorio: no hace falta publicar nada en el panel.
 */
class ServerSplitterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/serversplitter.php', 'serversplitter');

        $this->app->singleton(LockManager::class);
        $this->app->singleton(ResourceCalculator::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'serversplitter');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Recoge los limites de division enviados desde las pantallas de
        // administracion de servidores del panel (ver AdminServerLimits).
        $this->app->make('router')->pushMiddlewareToGroup('web', AdminServerLimits::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SplitterCommand::class]);
        }
    }
}