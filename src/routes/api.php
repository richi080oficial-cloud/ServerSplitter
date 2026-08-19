<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Extensions\ServerSplitter\Http\Middleware\SplitterApiKey;
use Pterodactyl\Extensions\ServerSplitter\Http\Controllers\Api\IntegrationController;

/*
 * API de integracion (WHMCS / Paymenter).
 * Prefijo propio para no interferir con la Application API de Pterodactyl.
 */
Route::middleware(['api', 'throttle:120,1', SplitterApiKey::class])
    ->prefix('api/serversplitter')
    ->name('serversplitter.api.')
    ->group(function () {
        Route::get('/servers/{server}', [IntegrationController::class, 'show'])->name('show');
        Route::patch('/servers/{server}', [IntegrationController::class, 'update'])->name('update');
        Route::post('/servers/{server}/purge', [IntegrationController::class, 'purge'])->name('purge');
        Route::delete('/servers/{server}/splits/{split}', [IntegrationController::class, 'destroySplit'])->name('splits.destroy');
    });