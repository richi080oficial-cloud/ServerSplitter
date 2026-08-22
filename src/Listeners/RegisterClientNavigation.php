<?php

declare(strict_types=1);

namespace ServerSplitter\Listeners;

use Pterodactyl\Events\Server\ServerViewed;

class RegisterClientNavigation
{
    /**
     * Registra el enlace de ServerSplitter en la navegación del cliente.
     */
    public function handle(ServerViewed $event): void
    {
        // Este listener se ejecuta cuando se visualiza un servidor
        // El enlace se inyecta vía JavaScript en serversplitter-inject.js
    }
}