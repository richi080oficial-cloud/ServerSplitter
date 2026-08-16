<?php

namespace Pterodactyl\Extensions\ServerSplitter\Providers;

use Illuminate\Support\ServiceProvider;
use Pterodactyl\Extensions\ServerSplitter\Console\Commands\SplitterCommand;
use Pterodactyl\Extensions\ServerSplitter\Http\Middleware\AdminServerLimits;
use Pterodactyl\Extensions\ServerSplitter\Http\Middleware\AdminSidebarLink;
use Pterodactyl\Extensions\ServerSplitter\Http\Middleware\ClientPanelScript;
use Pterodactyl\Extensions\ServerSplitter\Services\LockManager;
use Pterodactyl\Extensions\ServerSplitter\Services\ResourceCalculator;

/**
 * Punto de entrada de la extension. Todo (vistas, rutas, migraciones y config)
 * se carga desde este directorio: no hace falta publicar nada en el panel,
 * salvo los assets estaticos del panel de cliente.
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

        // Publica assets estaticos a public/extensions/serversplitter para que
        // se sirvan directamente sin pasar por Laravel (evita problemas de MIME type).
        $this->publishes([
            __DIR__ . '/../resources/assets' => public_path('extensions/serversplitter'),
        ], 'serversplitter-assets');

        $router = $this->app->make('router');

        // Recoge los limites de division enviados desde las pantallas de
        // administracion de servidores del panel (ver AdminServerLimits).
        $router->pushMiddlewareToGroup('web', AdminServerLimits::class);

        // Garantiza el enlace del menu lateral del admin aunque el parche de
        // la vista layouts/admin.blade.php no este aplicado (temas de
        // terceros, actualizaciones del panel...). Ver AdminSidebarLink.
        $router->pushMiddlewareToGroup('web', AdminSidebarLink::class);

        // Carga el script que anade la pestana "Divisiones" a la navegacion del
        // servidor en el panel de cliente (SPA de React). Ver ClientPanelScript.
        $router->pushMiddlewareToGroup('web', ClientPanelScript::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SplitterCommand::class]);
        }
    }
}