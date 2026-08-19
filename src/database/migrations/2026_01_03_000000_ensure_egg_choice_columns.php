<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Migracion de reparacion: en paneles donde ya se habia ejecutado
 * 2026_01_02_000000_add_egg_choice_to_serversplitter_limits (registrada en la
 * tabla migrations) con un contenido anterior de ese mismo archivo, Laravel
 * la considera "ya aplicada" por nombre de fichero y no vuelve a ejecutarla
 * aunque su contenido cambiase despues (SQLSTATE 42S22 "Unknown column
 * egg_choice_mode"). Este archivo, al ser nuevo, si se ejecuta siempre y deja
 * las columnas correctas independientemente de por donde se quedara cada
 * instalacion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('serversplitter_limits')) {
            return;
        }

        Schema::table('serversplitter_limits', function (Blueprint $table) {
            if (!Schema::hasColumn('serversplitter_limits', 'egg_choice_mode')) {
                $table->string('egg_choice_mode', 16)->nullable()->after('max_cpu');
            }

            if (!Schema::hasColumn('serversplitter_limits', 'allowed_egg_ids')) {
                $table->text('allowed_egg_ids')->nullable()->after('egg_choice_mode');
            }
        });

        // Resto heredado de una version anterior de esta funcionalidad, ya
        // sustituida por egg_choice_mode / allowed_egg_ids. Se limpia si
        // llego a crearse.
        if (Schema::hasColumn('serversplitter_limits', 'allow_egg_choice')) {
            Schema::table('serversplitter_limits', function (Blueprint $table) {
                $table->dropColumn('allow_egg_choice');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('serversplitter_limits')) {
            return;
        }

        Schema::table('serversplitter_limits', function (Blueprint $table) {
            if (Schema::hasColumn('serversplitter_limits', 'allowed_egg_ids')) {
                $table->dropColumn('allowed_egg_ids');
            }

            if (Schema::hasColumn('serversplitter_limits', 'egg_choice_mode')) {
                $table->dropColumn('egg_choice_mode');
            }
        });
    }
};
