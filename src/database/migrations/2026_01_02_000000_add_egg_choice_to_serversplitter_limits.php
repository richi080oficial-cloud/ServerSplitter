<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Permite al admin decidir, por servidor, si el propietario puede elegir el
 * egg de sus divisiones o si estas heredan siempre el egg del padre.
 *
 * null = usar el comportamiento global (el propietario puede elegir, dentro
 *        de los eggs permitidos por las reglas de "Reglas de eggs").
 * 1    = el propietario puede elegir egg (igual que el comportamiento global).
 * 0    = el propietario NO puede elegir: la division usa siempre el mismo
 *        egg que el servidor padre.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('serversplitter_limits') && !Schema::hasColumn('serversplitter_limits', 'allow_egg_choice')) {
            Schema::table('serversplitter_limits', function (Blueprint $table) {
                $table->boolean('allow_egg_choice')->nullable()->after('max_cpu');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('serversplitter_limits') && Schema::hasColumn('serversplitter_limits', 'allow_egg_choice')) {
            Schema::table('serversplitter_limits', function (Blueprint $table) {
                $table->dropColumn('allow_egg_choice');
            });
        }
    }
};
