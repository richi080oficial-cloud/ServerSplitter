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
 * Listado de servidores divisibles del usuario (no cuelga de ningun servidor
 * concreto, asi que se queda en /serversplitter).
 */
Route::middleware(['web', 'auth'])
    ->get('serversplitter', [SplitterController::class, 'index'])
    ->name('serversplitter.index');

/*
 * Rutas de cliente para un servidor concreto: /server/{server}/serversplitter
 *
 * Cuelgan de la misma ruta base que usa el panel de cliente (/server/{id}/...)
 * para que la URL sea coherente con el resto de pestanas del servidor. Sigue
 * siendo una pagina Blade aparte (recarga completa al navegar): sin acceso al
 * codigo del tema de React no hay forma de montar un componente de verdad
 * dentro de la SPA.
 */
Route::middleware(['web', 'auth'])
    ->prefix('server/{server}/serversplitter')
    ->name('serversplitter.')
    ->group(function () {
        Route::get('/availability', [SplitterController::class, 'availability'])
            ->middleware('throttle:60,1')
            ->name('availability');
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
 * Rutas de administracion: /admin/serversplitter
 */
Route::middleware(['web', 'auth', AdminAuthenticate::class])
    ->prefix('admin/serversplitter')
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