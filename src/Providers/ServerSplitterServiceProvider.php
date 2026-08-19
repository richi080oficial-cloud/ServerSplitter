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

        // IMPORTANTE: las rutas se cargan aqui, en register(), y no en boot().
        //
        // El panel de cliente de Pterodactyl es una SPA de React servida por
        // una ruta "catch-all" del propio nucleo (algo como Route::get('/{react}', ...))
        // registrada en el boot() de su propio RouteServiceProvider. Laravel
        // resuelve cada peticion con la PRIMERA ruta que coincide, en el
        // orden en que se registraron: si esa ruta catch-all se registra
        // antes que las nuestras, se traga peticiones a /serversplitter y a
        // /extensions/serversplitter/* devolviendo el HTML de la SPA en vez
        // de nuestras vistas o nuestro JS/CSS (lo que a su vez impide que el
        // <script> inyectado en el panel de cliente llegue a ejecutarse
        // nunca, porque el navegador recibe HTML donde esperaba JavaScript).
        //
        // El register() de TODOS los providers se ejecuta antes que el
        // boot() de CUALQUIER provider, sin importar el orden en el array
        // "providers" de config/app.php o bootstrap/providers.php. Cargando
        // aqui nuestras rutas nos garantizamos quedar registrados antes que
        // el catch-all del panel, sin depender de en que posicion quedo
        // nuestro ServiceProvider al registrarse.
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'serversplitter');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

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