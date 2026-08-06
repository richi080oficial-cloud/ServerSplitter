# ServerSplitter para Pterodactyl

Autor: **Waise Team**

Permite que un cliente **divida su servidor** en servidores hijos: se crea un servidor nuevo
con parte de los recursos del principal y el padre se redimensiona automaticamente.
Al eliminar una division, los recursos vuelven al padre.

- Panel de administracion propio (`/admin/serversplitter`) con configuracion, reglas por egg y limites por servidor.
- Interfaz de cliente independiente (`/serversplitter`), responsive y accesible.
- API con clave propia para **WHMCS / Paymenter** (ampliar recursos, fijar limites, purgar divisiones).
- Operaciones **atomicas**: bloqueo pesimista por servidor y rollback si el padre no puede redimensionarse.

## Requisitos

- Pterodactyl Panel 1.11.x (Laravel 10) o equivalente con el namespace `Pterodactyl\`.
- PHP 8.1+ y acceso por consola al servidor del panel como root.
- `curl` y `git` (el gestor instala `git` automaticamente si falta).
- Nodos con **allocations libres**: cada division consume un puerto del mismo nodo que el padre.

## Instalacion

Un solo comando, como root:

    bash <(curl -sSL https://raw.githubusercontent.com/waise-team/ServerSplitter/main/install.sh) install

Despues genera la clave de la API de integracion (hasta entonces la API responde `503`):

    sudo serversplitter apikey

Que hace el instalador:

1. `install.sh` descarga el gestor `serversplitter.sh` desde el repositorio.
2. El gestor clona el codigo en `/opt/serversplitter` y ejecuta `scripts/addon-install.sh`.
3. El instalador copia `src/` a `app/Extensions/ServerSplitter`, registra el ServiceProvider
   (en `bootstrap/providers.php` o en el array `providers` de `config/app.php`, con copia de
   seguridad previa), limpia caches y ejecuta las migraciones.
4. Guarda el estado en `/usr/local/share/serversplitter` (panel, usuario web, repositorio,
   version y una copia del codigo) e instala el comando corto `/usr/local/bin/serversplitter`.

El namespace `Pterodactyl\Extensions\*` ya lo cubre el autoload del panel, asi que
**no se modifica `composer.json`**.

Si el panel no esta en una ruta estandar:

    sudo serversplitter install --panel=/var/www/pterodactyl --user=www-data

## Comandos

| Comando | Descripcion |
| --- | --- |
| `sudo serversplitter install` | Instala o reinstala la extension |
| `sudo serversplitter update` | Actualiza a la ultima version (no hace nada si ya esta al dia) |
| `sudo serversplitter update --force` | Reinstala aunque no haya cambios en el repositorio |
| `sudo serversplitter status` | Estado de la instalacion, provider, CLI y auto-update |
| `sudo serversplitter version` | Version descargada |
| `sudo serversplitter apikey` | Genera la clave de la API (`--key=CLAVE` para fijar una) |
| `sudo serversplitter info` | Configuracion y contadores actuales |
| `sudo serversplitter prune` | Limpia cache y bloqueos caducados |
| `sudo serversplitter autoupdate-on` | Actualizacion automatica diaria a las 04:00 |
| `sudo serversplitter autoupdate-off` | Cancela la actualizacion automatica |
| `sudo serversplitter uninstall` | Desinstala archivos y provider (conserva las tablas) |
| `sudo serversplitter uninstall --drop-data` | Desinstala y **borra las tablas** de la extension |

Opciones comunes: `--force`/`-y` (sin confirmacion), `--panel=RUTA`, `--user=USUARIO`, `--key=CLAVE`.
Variables de entorno equivalentes: `PANEL_DIR`, `WEB_USER`, `REPO_URL`, `REPO_BRANCH`, `SRC_DIR`.

El registro del auto-update se escribe en `/var/log/serversplitter-update.log`.

Al desinstalar, los servidores hijos ya creados **no** se borran: gestionalos desde el panel.

## Modos de redimensionado

| Modo | Comportamiento |
| --- | --- |
| `difference` (por defecto) | El usuario elige RAM/disco/CPU para la division y esa cantidad se **resta** al padre. Al borrar la division se le **suma** de vuelta. |
| `distribute` | El total (padre + hijos) se reparte a **partes iguales** entre el padre y todas sus divisiones. Requiere que el padre tenga limites definidos de RAM y disco. |

En Pterodactyl el valor `0` significa *ilimitado*: esas claves nunca se restan ni se reparten.
El modo `distribute` exige limites reales en memoria y disco.

## Protecciones incluidas

- Reserva minima garantizada al padre (`reserve_memory`, `reserve_disk`, `reserve_cpu`).
- Minimos y maximos globales y por egg, mas eggs permitidos/bloqueados.
- Un servidor hijo no puede volver a dividirse.
- Servidores suspendidos, instalando o restaurando un backup quedan bloqueados.
- Bloqueo en base de datos por servidor: evita que panel y WHMCS recalculen a la vez.
- Si falla el redimensionado del padre, el hijo recien creado se elimina (sin recursos huerfanos).
- Limites "comprados" por servidor (`max_splits`, `max_memory`, `max_disk`, `max_cpu`).

## API de integracion (WHMCS / Paymenter)

Autenticacion por cabecera `X-Splitter-Key` (o `Authorization: Bearer <clave>`).
La clave se guarda **hasheada**; si no hay clave configurada, la API responde `503`.

`{server}` acepta el UUID corto, el UUID completo, el `external_id` o el ID interno.

Estado actual:

    curl -s -H 'X-Splitter-Key: TU_CLAVE' \
      https://panel.example.com/api/serversplitter/servers/1a7ce997

Sumar 2 GiB de RAM y 10 GiB de disco al total gestionado, y permitir 4 divisiones:

    curl -s -X PATCH -H 'X-Splitter-Key: TU_CLAVE' -H 'Content-Type: application/json' \
      -d '{"action":"add","memory":2048,"disk":10240,"max_splits":4}' \
      https://panel.example.com/api/serversplitter/servers/1a7ce997

Fijar el total exacto del pool (upgrade / downgrade de plan):

    curl -s -X PATCH -H 'X-Splitter-Key: TU_CLAVE' -H 'Content-Type: application/json' \
      -d '{"action":"set","memory":8192,"disk":40960,"cpu":400}' \
      https://panel.example.com/api/serversplitter/servers/1a7ce997

Cancelacion del servicio: elimina todas las divisiones y devuelve los recursos al padre.

    curl -s -X POST -H 'X-Splitter-Key: TU_CLAVE' \
      https://panel.example.com/api/serversplitter/servers/1a7ce997/purge

Endpoints disponibles:

| Metodo | Ruta | Descripcion |
| --- | --- | --- |
| GET | `/api/serversplitter/servers/{server}` | Estado, pool de recursos y divisiones |
| PATCH | `/api/serversplitter/servers/{server}` | Ajusta recursos y limites (`add` / `set`) |
| POST | `/api/serversplitter/servers/{server}/purge` | Elimina todas las divisiones |
| DELETE | `/api/serversplitter/servers/{server}/splits/{split}` | Elimina una division concreta |

## Consola del panel

Equivalentes directos por si prefieres usar artisan (desde el directorio del panel):

    php artisan serversplitter:manage info     # estado y contadores
    php artisan serversplitter:manage apikey   # nueva clave de API
    php artisan serversplitter:manage prune    # limpia cache y bloqueos caducados
    php artisan serversplitter:manage purge    # elimina las tablas de la extension

## Estructura

    extension.json
    VERSION                            Version del paquete
    install.sh                         Instalador publico (descarga el gestor)
    serversplitter.sh                  Gestor: install / update / uninstall / autoupdate
    scripts/addon-install.sh           Instalador interno (copia, provider, migraciones)
    bin/serversplitter                 Comando corto: delega en el gestor
    tools/register-provider.php        Registro del ServiceProvider en el panel
    src/config/serversplitter.php      Valores por defecto
    src/database/migrations/           Tablas de la extension
    src/Models/                        Ajustes, divisiones, reglas de egg, limites
    src/Services/                      SplitterService, ResourceCalculator, LockManager
    src/Http/                          Controladores (admin, cliente, API) y middleware
    src/routes/                        web.php y api.php
    src/resources/views/               Blade de admin y de cliente
    src/resources/assets/              CSS y JS (servidos por AssetController)
    src/Console/Commands/              Comando artisan

Rutas que se crean en el sistema:

    /opt/serversplitter                Codigo fuente clonado (lo mantiene el gestor)
    /usr/local/share/serversplitter    Estado, copia del codigo y backups
    /usr/local/bin/serversplitter      Comando global

El CSS y el JS se sirven desde la propia extension en `/extensions/serversplitter/*`,
por lo que **no hay que copiar nada a `public/`** ni repetirlo tras actualizar el panel.

## Aviso

Eliminar una division borra el servidor hijo y **todos sus archivos**; es irreversible.
Prueba primero en un panel de desarrollo y haz copia de seguridad de la base de datos
antes de instalar en produccion.