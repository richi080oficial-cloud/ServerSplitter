<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\AdminAuthenticate;
use Pterodactyl\Extensions\ServerSplitter\Http\Controllers\Admin\AdminController;
use Pterodactyl\Extensions\ServerSplitter\Http\Controllers\Client\AssetController;
use Pterodactyl\Extensions\ServerSplitter\Http\Controllers\Client\SplitterController;

/*
 * Assets estaticos (CSS/JS) servidos desde la propia extension.
 */
Route::middleware(['web'])
    ->get('extensions/serversplitter/{asset}', [AssetController::class, 'show'])
    ->where('asset', '[A-Za-z0-9._-]+')
    ->name('serversplitter.asset');

/*
 * Rutas de cliente para un servidor concreto: /server/{server}/serversplitter
 *
 * Cuelgan de la misma ruta base que usa el panel de cliente (/server/{id}/...)
 * para que la URL sea coherente con el resto de pestanas del servidor.
 */
Route::middleware(['web', 'auth'])
    ->prefix('server/{server}/serversplitter')
    ->name('serversplitter.')
    ->group(function () {
        Route::get('/availability', [SplitterController::class, 'availability'])
            ->middleware('throttle:60,1')
            ->name('availability');

        // Version "solo contenido" (sin la pagina completa) que pide por
        // fetch() el script inyectado en el panel de cliente, para
        // sustituir el area central de la SPA sin recargar la pagina. Ver
        // SplitterController::fragment() y serversplitter-inject.js.
        Route::get('/fragment', [SplitterController::class, 'fragment'])
            ->middleware('throttle:60,1')
            ->name('fragment');

        Route::get('/', [SplitterController::class, 'show'])->name('show');

        // Limite mas estricto en las acciones que crean o destruyen servidores:
        // evita que un usuario (o un script) sature el nodo a base de clics.
        Route::post('/splits', [SplitterController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('store');
        Route::delete('/splits/{split}', [SplitterController::class, 'destroy'])
            ->middleware('throttle:20,1')
            ->name('destroy');
    });

/*
 * Rutas de administracion: /admin/extensions/serversplitter
 *
 * OJO: no uses "admin/serversplitter" (ni ningun prefijo que empiece
 * literalmente por "admin/servers"). El sidebar de algunos temas de
 * administracion resalta la seccion "Servers" con una comprobacion de
 * prefijo tipo admin/servers* (para que siga activa en
 * admin/servers/view/123, etc.), y esa cadena coincide por accidente con
 * "admin/serversplitter" (empieza igual, letra a letra: a-d-m-i-n-/-s-e-r-
 * v-e-r-s), asi que "Servers" se marcaba activo tambien en nuestra pagina.
 * Anidando bajo /admin/extensions/ se evita la colision.
 */
Route::middleware(['web', 'auth', AdminAuthenticate::class])
    ->prefix('admin/extensions/serversplitter')
    ->name('admin.serversplitter.')
    ->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::patch('/settings', [AdminController::class, 'updateSettings'])->name('settings.update');
        Route::post('/eggs', [AdminController::class, 'storeEggRule'])->name('eggs.store');
        Route::delete('/eggs/{rule}', [AdminController::class, 'destroyEggRule'])->name('eggs.destroy');
        Route::delete('/splits/{split}', [AdminController::class, 'destroySplit'])->name('splits.destroy');
        Route::post('/unlock', [AdminController::class, 'unlock'])->name('unlock');
        Route::post('/maintenance', [AdminController::class, 'rebuildInfo'])->name('maintenance');
    });