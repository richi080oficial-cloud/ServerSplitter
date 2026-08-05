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
 * Rutas de cliente: /serversplitter
 */
Route::middleware(['web', 'auth'])
    ->prefix('serversplitter')
    ->name('serversplitter.')
    ->group(function () {
        Route::get('/', [SplitterController::class, 'index'])->name('index');
        Route::get('/{server}', [SplitterController::class, 'show'])->name('show');
        Route::post('/{server}/splits', [SplitterController::class, 'store'])->name('store');
        Route::delete('/{server}/splits/{split}', [SplitterController::class, 'destroy'])->name('destroy');
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