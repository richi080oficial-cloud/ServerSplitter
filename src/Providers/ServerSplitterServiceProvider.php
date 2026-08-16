<<<<<<< SEARCH
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'serversplitter');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

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
=======
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
>>>>>>> REPLACE