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
 *   resources/views/admin/servers/new.blade.php -> limites de division al
 *                                                  crear un servidor
 *   resources/views/admin/servers/view/build.blade.php
 *                                               -> los mismos limites al
 *                                                  editar el build
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
    'crear servidor' => [
        'file' => $panel . '/resources/views/admin/servers/new.blade.php',
        'insert' => 'insertServerCreateFields',
    ],
    'editar servidor (build)' => [
        'file' => $panel . '/resources/views/admin/servers/view/build.blade.php',
        'insert' => 'insertServerBuildFields',
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

    // Solo clases de AdminLTE: la entrada hereda el skin activo (claro, oscuro
    // o tema de terceros) sin colores propios que puedan quedar ilegibles.
    // El href usa Route::has() para no romper /admin con una
    // RouteNotFoundException si el provider aun no esta registrado.
    $block = wrap($indent, [
        $inner . '<li class="header">SERVERSPLITTER</li>',
        $inner . '<li class="{{ request()->is(\'admin/extensions/serversplitter\', \'admin/extensions/serversplitter/*\') ? \'active\' : \'\' }}">',
        $inner . '    <a href="{{ \Illuminate\Support\Facades\Route::has(\'admin.extensions.serversplitter.index\') ? route(\'admin.extensions.serversplitter.index\') : url(\'/admin/extensions/serversplitter\') }}">',
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
 * Campos de limites en el formulario de creacion de servidores (no hay
 * servidor todavia, asi que el partial se incluye sin modelo).
 */
function insertServerCreateFields(string $contents): ?string
{
    return insertLimitFields($contents, 'null');
}

/**
 * Campos de limites en la pestana "Build Configuration" de un servidor.
 */
function insertServerBuildFields(string $contents): ?string
{
    $result = insertLimitFields($contents, '$server');
    
    if ($result === null) {
        return null;
    }
    
    // Insertar también el panel integrado de ServerSplitter
    return insertSplitterPanel($result);
}

/**
 * Panel integrado de ServerSplitter en la vista del servidor.
 *
 * Se inserta DESPUES del </form> que ya existe en la vista: el partial trae
 * su propio <form> (botones de eliminar division) y un <form> dentro de otro
 * <form> es HTML invalido. Los navegadores "reparan" ese error moviendo los
 * campos del formulario anidado al formulario exterior, lo que provocaba que
 * el boton "Eliminar division" enviase en realidad el formulario de Build
 * Configuration en vez de borrar la division.
 */
function insertSplitterPanel(string $contents): ?string
{
    $tag = strripos($contents, '</form>');

    if ($tag === false) {
        return null;
    }

    $lineEnd = strpos($contents, "\n", $tag);
    $insertAt = $lineEnd === false ? strlen($contents) : $lineEnd + 1;
    $indent = indentOfLineAt($contents, $tag);

    $block = wrap($indent, [
        $indent . "@include('serversplitter::admin.partials.server-splitter', ['ssServer' => \$server])",
    ]);

    return substr($contents, 0, $insertAt) . $block . substr($contents, $insertAt);
}

/**
 * Inserta el @include del partial dentro del formulario existente, justo
 * antes del pie del ultimo box (donde esta el boton de guardar) para que los
 * campos viajen en el mismo submit.
 */
function insertLimitFields(string $contents, string $server): ?string
{
    $close = strripos($contents, '<div class="box-footer">');

    if ($close === false) {
        $close = strripos($contents, '</form>');
    }

    if ($close === false) {
        return null;
    }

    $indent = indentOfLineAt($contents, $close);

    $block = wrap($indent, [
        $indent . "@include('serversplitter::admin.partials.server-limits', ['ssServer' => " . $server . '])',
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

/**
 * Posicion del </ul> que cierra el menu lateral del admin.
 *
 * La apertura se localiza por el atributo class (sidebar-menu) y el cierre
 * contando aperturas/cierres de <ul> con una sola pasada de regex. Asi tolera
 * submenus (treeview-menu), atributos extra, etiquetas partidas en varias
 * lineas y temas que mencionen "sidebar-menu" antes en el layout (CSS o JS).
 */
function findSidebarClose(string $contents): ?int
{
    $after = null;

    if (preg_match('/<ul\b[^>]*\bsidebar-menu\b[^>]*>/is', $contents, $m, PREG_OFFSET_CAPTURE) === 1) {
        $after = (int) $m[0][1] + strlen((string) $m[0][0]);
    } elseif (preg_match('/<section\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bsidebar\b[^"\']*\1[^>]*>/is', $contents, $s, PREG_OFFSET_CAPTURE) === 1) {
        // Plan B: temas que renombran la clase del <ul>. Se usa el primer
        // <ul> que aparece dentro de <section class="sidebar">.
        $sectionEnd = (int) $s[0][1] + strlen((string) $s[0][0]);

        if (preg_match('/<ul\b[^>]*>/is', $contents, $u, PREG_OFFSET_CAPTURE, $sectionEnd) === 1) {
            $after = (int) $u[0][1] + strlen((string) $u[0][0]);
        }
    }

    if ($after === null) {
        return null;
    }

    $found = preg_match_all(
        '/<\/?ul\b[^>]*>/is',
        $contents,
        $tags,
        PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE,
        $after
    );

    if ($found === false || $found === 0) {
        return null;
    }

    $depth = 1;

    foreach ($tags[0] as $tag) {
        if (str_starts_with((string) $tag[0], '</')) {
            $depth--;

            if ($depth === 0) {
                return (int) $tag[1];
            }

            continue;
        }

        $depth++;
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