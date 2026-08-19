<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Permite al admin decidir, por servidor, que egg puede usar el propietario
 * al crear una division:
 *
 *   none    (por defecto, siempre que no haya fila o el campo este vacio)
 *           La division hereda el mismo egg que el servidor padre. El
 *           propietario no ve ningun selector.
 *   all     El propietario elige entre todos los eggs permitidos por la
 *           configuracion global ("Reglas de eggs").
 *   defined El propietario elige solo entre los eggs que el admin ha
 *           marcado para ESTE servidor (columna allowed_egg_ids).
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
