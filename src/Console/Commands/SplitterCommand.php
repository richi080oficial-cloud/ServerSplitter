<?php

namespace Pterodactyl\Extensions\ServerSplitter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Extensions\ServerSplitter\Models\ServerSplit;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterEggRule;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterLimit;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterSetting;
use Pterodactyl\Extensions\ServerSplitter\Services\LockManager;

class SplitterCommand extends Command
{
    protected $signature = 'serversplitter:manage
                            {action=info : info | apikey | purge | prune}
                            {--key= : Clave concreta a usar con la accion apikey}
                            {--force : No pedir confirmacion (necesario para purge)}';

    protected $description = 'Utilidades internas de ServerSplitter (usadas por el CLI serversplitter).';

    protected const TABLES = [
        'serversplitter_locks',
        'serversplitter_limits',
        'serversplitter_egg_rules',
        'serversplitter_splits',
        'serversplitter_settings',
    ];

    public function handle(LockManager $locks): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'info' => $this->info_(),
            'apikey' => $this->apiKey(),
            'purge' => $this->purge(),
            'prune' => $this->prune($locks),
            default => $this->unknown($action),
        };
    }

    protected function info_(): int
    {
        if (!Schema::hasTable('serversplitter_settings')) {
            $this->error('Las tablas de ServerSplitter no existen. Ejecuta: serversplitter install');

            return self::FAILURE;
        }

        $settings = SplitterSetting::snapshot();

        $this->line('<info>ServerSplitter</info> - estado actual');
        $this->newLine();

        $rows = [];
        foreach ($settings as $key => $value) {
            if ($key === 'api_key_hash') {
                continue;
            }
            $rows[] = [$key, is_bool($value) ? ($value ? 'si' : 'no') : (string) $value];
        }
        $this->table(['Ajuste', 'Valor'], $rows);

        $this->table(['Metrica', 'Total'], [
            ['Divisiones activas', (string) ServerSplit::query()->count()],
            ['Reglas de eggs', (string) SplitterEggRule::query()->count()],
            ['Servidores con limites propios', (string) SplitterLimit::query()->count()],
            ['Clave de API configurada', (string) (SplitterSetting::read('api_key_hash') ? 'si' : 'no')],
        ]);

        return self::SUCCESS;
    }

    protected function apiKey(): int
    {
        $key = (string) ($this->option('key') ?: Str::random(48));

        SplitterSetting::write(['api_key_hash' => password_hash($key, PASSWORD_DEFAULT)]);

        $this->newLine();
        $this->line('<info>Clave de API de ServerSplitter generada.</info>');
        $this->line('Guardala ahora, no se puede volver a mostrar:');
        $this->newLine();
        $this->line("  <comment>$key</comment>");
        $this->newLine();
        $this->line('Uso: cabecera  X-Splitter-Key: <clave>  contra /api/serversplitter/...');

        return self::SUCCESS;
    }

    protected function purge(): int
    {
        if (!$this->option('force') && !$this->confirm('Se eliminaran TODAS las tablas de ServerSplitter. Los servidores hijos NO se borran. Continuar?', false)) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
                $this->line("Tabla eliminada: $table");
            }
        }

        SplitterSetting::flush();
        $this->info('Datos de ServerSplitter eliminados.');

        return self::SUCCESS;
    }

    protected function prune(LockManager $locks): int
    {
        $removed = $locks->pruneExpired();
        SplitterSetting::flush();

        $this->info("Bloqueos caducados eliminados: $removed");

        return self::SUCCESS;
    }

    protected function unknown(string $action): int
    {
        $this->error("Accion desconocida: $action (usa info, apikey, purge o prune)");

        return self::FAILURE;
    }
}