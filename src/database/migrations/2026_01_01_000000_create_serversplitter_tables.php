<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('serversplitter_settings')) {
            Schema::create('serversplitter_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('serversplitter_splits')) {
            Schema::create('serversplitter_splits', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('server_id');
                $table->unsignedInteger('parent_id');
                $table->unsignedInteger('user_id')->nullable();
                $table->unsignedInteger('memory')->default(0);
                $table->unsignedInteger('disk')->default(0);
                $table->unsignedInteger('cpu')->default(0);
                $table->string('mode')->default('difference');
                $table->timestamps();

                $table->unique('server_id');
                $table->index('parent_id');

                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
                $table->foreign('parent_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('serversplitter_egg_rules')) {
            Schema::create('serversplitter_egg_rules', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('egg_id');
                $table->boolean('allowed')->default(true);
                $table->unsignedInteger('min_memory')->nullable();
                $table->unsignedInteger('min_disk')->nullable();
                $table->unsignedInteger('min_cpu')->nullable();
                $table->unsignedInteger('max_memory')->nullable();
                $table->unsignedInteger('max_disk')->nullable();
                $table->unsignedInteger('max_cpu')->nullable();
                $table->timestamps();

                $table->unique('egg_id');
                $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('serversplitter_limits')) {
            Schema::create('serversplitter_limits', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('server_id');
                $table->unsignedInteger('max_splits')->nullable();
                $table->unsignedInteger('max_memory')->nullable();
                $table->unsignedInteger('max_disk')->nullable();
                $table->unsignedInteger('max_cpu')->nullable();
                $table->timestamps();

                $table->unique('server_id');
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('serversplitter_locks')) {
            Schema::create('serversplitter_locks', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('server_id');
                $table->string('token', 64);
                $table->timestamp('expires_at');
                $table->timestamp('created_at')->nullable();

                $table->unique('server_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('serversplitter_locks');
        Schema::dropIfExists('serversplitter_limits');
        Schema::dropIfExists('serversplitter_egg_rules');
        Schema::dropIfExists('serversplitter_splits');
        Schema::dropIfExists('serversplitter_settings');
    }
};