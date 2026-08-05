<?php

declare(strict_types=1);

/**
 * Integra ServerSplitter en la interfaz del panel sin romper nada.
 *
 * Uso:
 *   php patch-panel.php patch    /var/www/pterodactyl
 *   php patch-panel.php unpatch  /var/www/pterodactyl
 *   php patch-panel.php status   /var/www/pterodactyl
 *
 * Toca dos vistas del panel porque Pterodactyl no expone ningun hook para
 * ellas:
 *
 *   resources/views/layouts/admin.blade.php     -> entrada en el menu lateral
 *   resources/views/templates/wrapper.blade.php -> <script> que inyecta la
 *                                                  pestana "Divisiones" en la
 *                                                  SPA de cliente
 *
 * Todo lo insertado queda entre los marcadores serversplitter:begin/end, asi
 * que aplicar el parche dos veces no duplica nada y 'unpatch' lo deja como
 * estaba. Antes de escribir se crea <archivo>.serversplitter.bak.
 *
 * Codigos de salida: 0 correcto, 1 error de uso/escritura, 2 aplicado
 * parcialmente (algun ancla no encontrada; el resto si se aplico).
 */

const MARK_BEGIN = '{{-- serversplitter:begin --}}';
const MARK_END = '{{-- serversplitter:end --}}';

$mode = (string) ($argv[1] ?? '');
$panel = rtrim((string) ($argv[2] ?? ''), '/');

if (!in_array($mode, ['patch', 'unpatch', 'status'], true) || $panel === '') {
    fwrite(STDERR, "Uso: php patch-panel.php patch|unpatch|status /ruta/al/panel\n");
    exit(1);
}

if (!is_file($panel . '/artisan')) {
    fwrite(STDERR, "No parece un panel de Pterodactyl: $panel\n");
    exit(1);
}

$targets = [
    'menu de administracion' => [
        'file' => $panel . '/resources/views/layouts/admin.blade.php',
        'insert' => 'insertSidebarEntry',
    ],
    'panel de cliente' => [
        'file' => $panel . '/resources/views/templates/wrapper.blade.php',
        'insert' => 'insertClientScript',
    ],
];

$failures = 0;

foreach ($targets as $label => $target) {
    $file = $target['file'];

    if (!is_file($file)) {
        fwrite(STDERR, "[$label] No se encontro " . relative($panel, $file) . "\n");
        $failures++;
        continue;
    }

    $contents = (string) file_get_contents($file);
    $patched = str_contains($contents, MARK_BEGIN);

    if ($mode === 'status') {
        printf("%-24s %s (%s)\n", $label, $patched ? 'parcheado' : 'sin parchear', relative($panel, $file));
        continue;
    }

    if ($mode === 'unpatch') {
        if (!$patched) {
            echo "[$label] No habia parche que quitar.\n";
            continue;
        }

        $updated = removeBlocks($contents);

        if ($updated === $contents) {
            fwrite(STDERR, "[$label] No se pudo limpiar el bloque; revisa " . relative($panel, $file) . " a mano.\n");
            $failures++;
            continue;
        }

        writeFile($file, $updated);
        echo "[$label] Parche eliminado.\n";
        continue;
    }

    // mode === patch
    if ($patched) {
        echo "[$label] Ya estaba parcheado.\n";
        continue;
    }

    $updated = ($target['insert'])($contents);

    if ($updated === null) {
        fwrite(STDERR, "[$label] No se localizo el punto de insercion en " . relative($panel, $file) . ".\n");
        $failures++;
        continue;
    }

    writeFile($file, $updated);
    echo "[$label] Parche aplicado.\n";
}

exit($failures > 0 ? ($mode === 'status' ? 1 : 2) : 0);

// ---------------------------------------------------------------------------
// Inserciones
// ---------------------------------------------------------------------------

/**
 * Anade la entrada de ServerSplitter al final de <ul class="sidebar-menu">.
 */
function insertSidebarEntry(string $contents): ?string
{
    $close = findSidebarClose($contents);

    if ($close === null) {
        return null;
    }

    $indent = indentOfLineAt($contents, $close);
    $inner = $indent . '    ';

    $block = wrap($indent, [
        $inner . '<li class="header">SERVERSPLITTER</li>',
        $inner . '<li class="{{ \Illuminate\Support\Str::startsWith((string) \Illuminate\Support\Facades\Route::currentRouteName(), \'admin.serversplitter\') ? \'active\' : \'\' }}">',
        $inner . '    <a href="{{ route(\'admin.serversplitter.index\') }}">',
        $inner . '        <i class="fa fa-clone"></i> <span>ServerSplitter</span>',
        $inner . '    </a>',
        $inner . '</li>',
    ]);

    $lineStart = lineStartAt($contents, $close);

    return substr($contents, 0, $lineStart) . $block . substr($contents, $lineStart);
}

/**
 * Carga el script que inyecta el enlace "Divisiones" en la SPA de cliente.
 */
function insertClientScript(string $contents): ?string
{
    $close = strripos($contents, '</body>');

    if ($close === false) {
        return null;
    }

    $indent = indentOfLineAt($contents, $close);
    $inner = $indent . '    ';

    $block = wrap($indent, [
        $inner . '<script src="{{ url(\'extensions/serversplitter/serversplitter-inject.js\') }}" defer></script>',
    ]);

    $lineStart = lineStartAt($contents, $close);

    return substr($contents, 0, $lineStart) . $block . substr($contents, $lineStart);
}

/**
 * @param array<int, string> $lines
 */
function wrap(string $indent, array $lines): string
{
    $out = $indent . MARK_BEGIN . "\n";
    foreach ($lines as $line) {
        $out .= $line . "\n";
    }

    return $out . $indent . MARK_END . "\n";
}

function removeBlocks(string $contents): string
{
    $pattern = '/[ \t]*' . preg_quote(MARK_BEGIN, '/') . '.*?' . preg_quote(MARK_END, '/') . '[ \t]*\r?\n?/s';
    $updated = preg_replace($pattern, '', $contents);

    return is_string($updated) ? $updated : $contents;
}

// ---------------------------------------------------------------------------
// Utilidades de texto
// ---------------------------------------------------------------------------

/** Posicion del </ul> que cierra el menu lateral del admin. */
function findSidebarClose(string $contents): ?int
{
    $at = strpos($contents, 'sidebar-menu');

    if ($at === false) {
        return null;
    }

    $open = strrpos(substr($contents, 0, $at), '<ul');

    if ($open === false) {
        return null;
    }

    $depth = 0;
    $length = strlen($contents);

    for ($i = $open; $i < $length; $i++) {
        if ($contents[$i] !== '<') {
            continue;
        }

        $head = strtolower(substr($contents, $i, 5));

        if ($head === '<ul>' . "\n" || str_starts_with($head, '<ul>') || str_starts_with($head, '<ul ')) {
            $depth++;
            $i += 2;
            continue;
        }

        if ($head === '</ul>') {
            $depth--;

            if ($depth <= 0) {
                return $i;
            }

            $i += 4;
        }
    }

    return null;
}

function lineStartAt(string $contents, int $offset): int
{
    $break = strrpos(substr($contents, 0, $offset), "\n");

    return $break === false ? 0 : $break + 1;
}

function indentOfLineAt(string $contents, int $offset): string
{
    $start = lineStartAt($contents, $offset);
    $line = substr($contents, $start, $offset - $start);

    return preg_match('/^[ \t]*/', $line, $m) === 1 ? $m[0] : '';
}

function relative(string $panel, string $file): string
{
    return str_starts_with($file, $panel . '/') ? substr($file, strlen($panel) + 1) : $file;
}

function writeFile(string $file, string $contents): void
{
    $backup = $file . '.serversplitter.bak';

    if (!is_file($backup) && !copy($file, $backup)) {
        fwrite(STDERR, "No se pudo crear la copia de seguridad $backup\n");
        exit(1);
    }

    if (file_put_contents($file, $contents) === false) {
        fwrite(STDERR, "No se pudo escribir $file (permisos?)\n");
        exit(1);
    }
}