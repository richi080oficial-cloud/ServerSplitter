# ServerSplitter para Pterodactyl

Autor: **Waise Team**

Permite que un cliente **divida su servidor** en servidores hijos: se crea un servidor nuevo
con parte de los recursos del principal y el padre se redimensiona automaticamente.
Al eliminar una division, los recursos vuelven al padre.

- Panel de administracion propio (`/admin/extensions/serversplitter`) con configuracion, reglas por egg y limites por servidor.
- Pestana "Divisiones" integrada en el panel de cliente de cada servidor, sin recargar la pagina.
- API con clave propia para **WHMCS / Paymenter** (ampliar recursos, fijar limites, purgar divisiones). La clave se genera sola al instalar.
- Operaciones **atomicas**: bloqueo pesimista por servidor y rollback si el padre no puede redimensionarse.

## Requisitos

- Pterodactyl Panel 1.11.x (Laravel 10) o equivalente con el namespace `Pterodactyl\`.
- PHP 8.1+ y acceso por consola al servidor del panel como root.
- `curl` y `git` (el gestor instala `git` automaticamente si falta).
- Nodos con **allocations libres**: cada division consume un puerto del mismo nodo que el padre.

## Instalacion

Un solo comando, como root:

    bash <(curl -sSL https://raw.githubusercontent.com/waise-team/ServerSplitter/main/install.sh) install

Con eso ya queda listo: la extension se registra, las migraciones se ejecutan y la clave de la
API de integracion se genera automaticamente (se muestra al final de la instalacion; guardala,
no se puede volver a mostrar). No hace falta ningun paso manual adicional.

Que hace el instalador:

1. `install.sh` descarga el gestor `serversplitter.sh` desde el repositorio.
2. El gestor clona el codigo en `/opt/serversplitter` y ejecuta `scripts/addon-install.sh`.
3. El instalador copia `src/` a `app/Extensions/ServerSplitter`, registra el ServiceProvider
   (en `bootstrap/providers.php` o en el array `providers` de `config/app.php`, con copia de
   seguridad previa), integra los enlaces en la interfaz, limpia caches y ejecuta las migraciones.
4. Genera la clave de la API de integracion si todavia no existe (nunca la toca si ya hay una:
   una actualizacion posterior no invalida la integracion con WHMCS/Paymenter).
5. Guarda el estado en `/usr/local/share/serversplitter` (panel, usuario web, repositorio,
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
| `sudo serversplitter apikey` | Genera una clave de API nueva (`--key=CLAVE` para fijar una); normalmente no hace falta, ver Instalacion |
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

## Eleccion de egg por servidor

Ademas de las reglas globales de "Reglas de eggs" (que eggs existen en todo el panel y con que
minimos/maximos), cada servidor padre admite su propio modo de eleccion de egg para sus
divisiones. Se edita en `/admin/servers/view/{id}` &rarr; pestana *Build Configuration*, en el
bloque ServerSplitter ("Eleccion de egg para las divisiones"):

| Modo | Comportamiento |
| --- | --- |
| **No seleccionar** (predeterminado) | El propietario **no ve ningun selector**: cada division que cree usa siempre, de forma forzada, el mismo egg que ya tiene el servidor padre. Es el comportamiento por defecto de todo servidor sin configurar. |
| **Todos los eggs permitidos globalmente** | El propietario elige entre los eggs marcados como "permitido" en la pestana "Reglas de eggs" (o cualquier egg, si el ajuste global "Permitir eggs sin regla definida" esta activo). |
| **Eggs concretos** | El admin elige, servidor por servidor, una lista concreta de eggs (por ejemplo: para este servidor, solo puede cambiar a `webhosting` o `minecraft`, aunque el panel tenga tambien `rust` configurado). El propietario solo ve esos eggs en el desplegable. |

En modo "Eggs concretos" la lista de "Reglas de eggs" global no filtra nada mas: la lista que
eligio el admin para ese servidor es la autoridad final. En modo "No seleccionar" tampoco se
consulta ninguna lista: la division simplemente copia el egg que el padre ya tenia instalado.

## Integracion en el panel de cliente

La pestana "Divisiones" aparece en el menu lateral del servidor y se abre **sin recargar la
pagina**, sustituyendo solo el area de contenido de la SPA de React. No es una integracion nativa
de React (eso solo es posible con Blueprint, que ServerSplitter no usa a proposito): es una
simulacion cuidadosa por JavaScript (`serversplitter-inject.js`) pensada para no romper el resto
del panel:

- Los nodos que React tenia en el area de contenido **no se destruyen** al sustituirlos: se mueven
  (sin recrearlos) a memoria y se devuelven intactos si el usuario navega a otra pestana o pulsa
  "atras"/"adelante", para que React los siga reconociendo como suyos.
- Un F5, un marcador o una pestana nueva no pueden "continuar" dentro de React: arrancan la SPA de
  cero como siempre, y en cuanto termina de montar (esperando a que el contenido se estabilice,
  para no interrumpir la carga inicial de datos del servidor) el script sustituye el contenido
  igual que con un clic, sin pasar nunca por una pagina con un diseno distinto.
- Si el script no logra localizar el area de contenido de la SPA (selector no reconocido en tu
  tema), se cae a una navegacion normal en vez de quedarse a medias.

## Protecciones incluidas

- Reserva minima garantizada al padre (`reserve_memory`, `reserve_disk`, `reserve_cpu`).
- Minimos y maximos globales y por egg, mas eggs permitidos/bloqueados.
- Un servidor hijo no puede volver a dividirse.
- Servidores suspendidos, instalando o restaurando un backup quedan bloqueados.
- Bloqueo en base de datos por servidor: evita que panel y WHMCS recalculen a la vez.
- Si falla el redimensionado del padre, el hijo recien creado se elimina (sin recursos huerfanos).
- Limites "comprados" por servidor (`max_splits`, `max_memory`, `max_disk`, `max_cpu`).
- Limite de peticiones (`throttle`) en crear/eliminar divisiones desde el panel de cliente y en toda
  la API de integracion, para frenar abuso o scripts descontrolados.
- La clave de la API se compara con `password_verify` (a tiempo constante) y se guarda hasheada;
  nunca se registra ni se puede volver a mostrar.

## API de integracion (WHMCS / Paymenter)

Autenticacion por cabecera `X-Splitter-Key` (o `Authorization: Bearer <clave>`). La clave se
genera sola durante la instalacion (ver arriba) y se guarda **hasheada**; si por lo que sea no hay
ninguna configurada, la API responde `503`.

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
    scripts/addon-install.sh           Instalador interno (copia, provider, migraciones, clave de API)
    bin/serversplitter                 Comando corto: delega en el gestor
    tools/register-provider.php        Registro del ServiceProvider en el panel
    tools/patch-panel.php              Enlaces en el menu de admin y en el panel de cliente
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
