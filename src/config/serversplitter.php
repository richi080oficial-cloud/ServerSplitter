<?php

/*
 * Valores por defecto de ServerSplitter.
 *
 * Todos estos valores pueden sobreescribirse desde el panel de administracion
 * (/admin/serversplitter). Lo que se guarda en la tabla serversplitter_settings
 * tiene prioridad sobre este archivo; este archivo solo define el fallback.
 */

return [
    'defaults' => [
        // Interruptor general de la extension.
        'enabled' => true,

        // Numero maximo de divisiones por servidor padre (puede sobreescribirse por servidor).
        // 0 = ilimitado, siguiendo la misma convencion que Pterodactyl usa para memory/disk/cpu.
        'max_splits' => 3,

        // difference  -> los recursos del hijo se restan del padre (y se devuelven al borrarlo)
        // distribute  -> el total del padre se reparte equitativamente entre padre e hijos
        'resize_mode' => 'difference',

        // Accion sobre el servidor padre al aplicar cambios: none | restart | stop | kill
        'power_action' => 'restart',

        // Permitir que el usuario elija disco / cpu para el hijo.
        'allow_disk' => true,
        'allow_cpu' => true,

        // Minimos que debe recibir un servidor hijo.
        'min_memory' => 512,   // MiB
        'min_disk' => 1024,    // MiB
        'min_cpu' => 25,       // %

        // Recursos que SIEMPRE deben quedar libres en el servidor padre.
        'reserve_memory' => 512,
        'reserve_disk' => 1024,
        'reserve_cpu' => 25,

        // Si es false, solo se pueden usar eggs con una regla explicita "permitido".
        'allow_unlisted_eggs' => false,

        // Permitir que el propietario elimine sus propias divisiones.
        'allow_child_deletion' => true,

        // Prefijo del nombre generado para los servidores hijos.
        'child_name_prefix' => 'split',
    ],
];