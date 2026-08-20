#!/usr/bin/env bash
#
# ServerSplitter para Pterodactyl - instalador / actualizador / desinstalador
#
# Normalmente lo invoca el gestor (serversplitter.sh). Uso directo:
#
#   bash scripts/addon-install.sh install
#   bash scripts/addon-install.sh update
#   bash scripts/addon-install.sh uninstall [--force] [--drop-data]
#   bash scripts/addon-install.sh status
#   bash scripts/addon-install.sh apikey [--key=CLAVE]
#   bash scripts/addon-install.sh info
#   bash scripts/addon-install.sh prune
#
set -Eeuo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ADDON_VERSION="$(tr -d '[:space:]' < "${SOURCE_DIR}/VERSION" 2>/dev/null || echo 'unknown')"

STATE_DIR="/usr/local/share/serversplitter"
STATE_FILE="${STATE_DIR}/state.env"
BACKUP_DIR="${STATE_DIR}/backups"
CLI_PATH="/usr/local/bin/serversplitter"
MANAGER_SRC_DIR="${SRC_DIR:-/opt/serversplitter}"

TARGET_REL="app/Extensions/ServerSplitter"
PROVIDER_CLASS='Pterodactyl\Extensions\ServerSplitter\Providers\ServerSplitterServiceProvider'
ARTISAN_NAMESPACE="serversplitter:manage"
MIN_PANEL_VERSION="1.11.0"

PANEL_DIR="${PANEL_DIR:-}"
WEB_USER="${WEB_USER:-}"
REPO_URL="${SERVERSPLITTER_REPO:-https://github.com/waise-team/ServerSplitter.git}"
ASSUME_YES="no"
DROP_DATA="no"
API_KEY=""

if [ -t 1 ]; then
    C_RESET=$'\033[0m'; C_RED=$'\033[31m'; C_GREEN=$'\033[32m'
    C_YELLOW=$'\033[33m'; C_BLUE=$'\033[36m'; C_BOLD=$'\033[1m'
else
    C_RESET=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''; C_BOLD=''
fi

info()  { printf '%s[serversplitter]%s %s\n' "$C_BLUE" "$C_RESET" "$*"; }
ok()    { printf '%s[serversplitter]%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
warn()  { printf '%s[serversplitter]%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
error() { printf '%s[serversplitter]%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; }
die()   { error "$*"; exit 1; }

trap 'error "El script se ha detenido en la línea ${LINENO}."' ERR

# ---------------------------------------------------------------------------
# Utilidades
# ---------------------------------------------------------------------------

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        die "Este script debe ejecutarse como root (usa: sudo bash scripts/addon-install.sh $1)."
    fi
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "Falta el comando requerido: $1"
}

load_state() {
    # shellcheck disable=SC1090
    [ -f "$STATE_FILE" ] && . "$STATE_FILE" || true
}

save_state() {
    mkdir -p "$STATE_DIR"
    cat > "$STATE_FILE" <<EOF
# Generado por ServerSplitter - no editar a mano
PANEL_DIR="${PANEL_DIR}"
WEB_USER="${WEB_USER}"
REPO_URL="${REPO_URL}"
ADDON_INSTALLED_VERSION="${ADDON_VERSION}"
ADDON_INSTALLED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
EOF
    chmod 600 "$STATE_FILE"
}

detect_panel() {
    if [ -n "$PANEL_DIR" ]; then
        PANEL_DIR="${PANEL_DIR%/}"
        [ -f "${PANEL_DIR}/artisan" ] || die "No se encontró 'artisan' en ${PANEL_DIR}."
        return
    fi

    load_state
    local candidates=("${PANEL_DIR:-}" "/var/www/pterodactyl" "/var/www/panel" "/var/www/html/pterodactyl" "/srv/pterodactyl")
    local dir
    for dir in "${candidates[@]}"; do
        if [ -n "$dir" ] && [ -f "${dir}/artisan" ] && [ -f "${dir}/config/app.php" ]; then
            PANEL_DIR="${dir%/}"
            return
        fi
    done

    die "No se pudo localizar el panel. Ejecuta: sudo PANEL_DIR=/ruta/al/panel bash scripts/addon-install.sh install"
}

# Igual que detect_panel pero sin abortar: deja PANEL_DIR vacío y devuelve 1
# si no encuentra el panel. Se usa en 'status', que debe informar sin morir.
detect_panel_soft() {
    load_state

    if [ -n "$PANEL_DIR" ] && [ -f "${PANEL_DIR%/}/artisan" ]; then
        PANEL_DIR="${PANEL_DIR%/}"
        return 0
    fi

    local dir
    for dir in "/var/www/pterodactyl" "/var/www/panel" "/var/www/html/pterodactyl" "/srv/pterodactyl"; do
        if [ -f "${dir}/artisan" ] && [ -f "${dir}/config/app.php" ]; then
            PANEL_DIR="$dir"
            return 0
        fi
    done

    PANEL_DIR=""
    return 1
}

detect_web_user() {
    if [ -n "$WEB_USER" ]; then return; fi
    local owner
    owner="$(stat -c '%U' "${PANEL_DIR}/public" 2>/dev/null || echo '')"
    if [ -n "$owner" ] && [ "$owner" != "root" ] && [ "$owner" != "UNKNOWN" ]; then
        WEB_USER="$owner"
    elif id -u www-data >/dev/null 2>&1; then
        WEB_USER="www-data"
    elif id -u nginx >/dev/null 2>&1; then
        WEB_USER="nginx"
    elif id -u apache >/dev/null 2>&1; then
        WEB_USER="apache"
    else
        WEB_USER="root"
    fi
}

panel_version() {
    local raw
    raw="$(grep -m1 -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" "${PANEL_DIR}/config/app.php" 2>/dev/null || true)"
    raw="$(printf '%s' "$raw" | sed -E "s/.*'([^']+)'[[:space:]]*\$/\1/")"
    printf '%s' "${raw:-unknown}"
}

check_panel_version() {
    local version numeric lowest
    version="$(panel_version)"
    numeric="$(printf '%s' "$version" | grep -oE '^[0-9]+(\.[0-9]+){0,2}' || true)"

    if [ -z "$numeric" ]; then
        warn "No se pudo determinar la versión del panel (valor: '${version}'). Continuando."
        return
    fi

    lowest="$(printf '%s\n%s\n' "$numeric" "$MIN_PANEL_VERSION" | sort -V | head -n1)"
    if [ "$lowest" = "$MIN_PANEL_VERSION" ]; then
        info "Panel detectado: v${version} (compatible)."
    else
        die "Se requiere Pterodactyl >= ${MIN_PANEL_VERSION}. Detectado: v${version}."
    fi
}

# Ejecuta artisan como el usuario web cuando es posible, para no dejar
# archivos de caché propiedad de root en storage/ y bootstrap/cache.
artisan() {
    if [ "$(id -u)" -eq 0 ] && [ -n "$WEB_USER" ] && [ "$WEB_USER" != "root" ] \
       && command -v runuser >/dev/null 2>&1; then
        ( cd "$PANEL_DIR" && runuser -u "$WEB_USER" -- php artisan "$@" )
    else
        ( cd "$PANEL_DIR" && php artisan "$@" )
    fi
}

backup_file() {
    local file="$1" stamp
    [ -f "$file" ] || return 0
    stamp="$(date -u '+%Y%m%d%H%M%S')"
    mkdir -p "$BACKUP_DIR"
    cp -p "$file" "${BACKUP_DIR}/$(basename "$file").${stamp}.bak"
}

fix_permissions() {
    local path
    for path in "${PANEL_DIR}/${TARGET_REL}" \
                "${PANEL_DIR}/storage" "${PANEL_DIR}/bootstrap/cache"; do
        [ -e "$path" ] && chown -R "${WEB_USER}:${WEB_USER}" "$path" 2>/dev/null || true
    done
    find "${PANEL_DIR}/${TARGET_REL}" -type d -exec chmod 755 {} + 2>/dev/null || true
    find "${PANEL_DIR}/${TARGET_REL}" -type f -exec chmod 644 {} + 2>/dev/null || true
}

clear_caches() {
    info "Limpiando cachés del panel…"
    artisan config:clear >/dev/null 2>&1 || warn "config:clear falló."
    artisan route:clear  >/dev/null 2>&1 || warn "route:clear falló."
    artisan view:clear   >/dev/null 2>&1 || warn "view:clear falló."
    artisan cache:clear  >/dev/null 2>&1 || true
}

sync_source_copy() {
    mkdir -p "$STATE_DIR"
    if [ "$SOURCE_DIR" != "${STATE_DIR}/source" ]; then
        rm -rf "${STATE_DIR}/source.tmp"
        mkdir -p "${STATE_DIR}/source.tmp"
        ( cd "$SOURCE_DIR" && tar --exclude='./.git' -cf - . ) | ( cd "${STATE_DIR}/source.tmp" && tar -xf - )
        rm -rf "${STATE_DIR}/source"
        mv "${STATE_DIR}/source.tmp" "${STATE_DIR}/source"
    fi
    chmod 755 "${STATE_DIR}/source/scripts/addon-install.sh" 2>/dev/null || true
    chmod 755 "${STATE_DIR}/source/serversplitter.sh" 2>/dev/null || true
    chmod 755 "${STATE_DIR}/source/install.sh" 2>/dev/null || true
    chmod 755 "${STATE_DIR}/source/bin/serversplitter" 2>/dev/null || true
}

app_url() {
    grep -m1 -E '^APP_URL=' "${PANEL_DIR}/.env" 2>/dev/null \
        | sed -E 's/^APP_URL=//; s/^"//; s/"$//; s#/$##' || true
}

provider_registered() {
    grep -rq 'ServerSplitterServiceProvider' \
        "${PANEL_DIR}/config/app.php" "${PANEL_DIR}/bootstrap/providers.php" 2>/dev/null
}

# Los parches de interfaz (menú de admin + panel de cliente) se marcan con
# comentarios Blade, así que basta con buscarlos.
panel_patched() {
    grep -rq 'serversplitter:begin' \
        "${PANEL_DIR}/resources/views/layouts/admin.blade.php" \
        "${PANEL_DIR}/resources/views/templates/wrapper.blade.php" 2>/dev/null
}

# ---------------------------------------------------------------------------
# Instalación
# ---------------------------------------------------------------------------

do_install() {
    require_root install
    require_cmd php
    require_cmd tar
    detect_panel
    detect_web_user
    check_panel_version

    [ -d "${SOURCE_DIR}/src" ] || die "No se encuentra ${SOURCE_DIR}/src"
    [ -f "${SOURCE_DIR}/tools/register-provider.php" ] || die "Falta tools/register-provider.php en el repositorio."
    [ -f "${SOURCE_DIR}/tools/patch-panel.php" ] || die "Falta tools/patch-panel.php en el repositorio."

    info "Panel:          ${PANEL_DIR}"
    info "Usuario web:    ${WEB_USER}"
    info "Versión addon:  ${ADDON_VERSION}"

    sync_source_copy
    save_state

    info "Copiando la extensión a ${TARGET_REL}…"
    rm -rf "${PANEL_DIR}/${TARGET_REL}"
    mkdir -p "${PANEL_DIR}/${TARGET_REL}"
    ( cd "${STATE_DIR}/source/src" && tar -cf - . ) \
        | ( cd "${PANEL_DIR}/${TARGET_REL}" && tar -xf - )
    printf '%s\n' "$ADDON_VERSION" > "${PANEL_DIR}/${TARGET_REL}/VERSION"

    # El namespace Pterodactyl\Extensions\* ya lo cubre el autoload PSR-4 del
    # panel (app/ => Pterodactyl\), así que no hay que tocar composer.json.
    info "Registrando el service provider…"
    backup_file "${PANEL_DIR}/config/app.php"
    backup_file "${PANEL_DIR}/bootstrap/providers.php"
    php "${STATE_DIR}/source/tools/register-provider.php" register "$PANEL_DIR" \
        || die "No se pudo registrar el provider. Añádelo manualmente: ${PROVIDER_CLASS}::class,"

    info "Integrando los enlaces en la interfaz del panel…"
    backup_file "${PANEL_DIR}/resources/views/layouts/admin.blade.php"
    backup_file "${PANEL_DIR}/resources/views/templates/wrapper.blade.php"
    backup_file "${PANEL_DIR}/resources/views/admin/servers/new.blade.php"
    backup_file "${PANEL_DIR}/resources/views/admin/servers/view/build.blade.php"

    # patch-panel.php reconoce sus propios bloques por el marcador
    # "serversplitter:begin" y, si ya esta presente, no vuelve a tocar el
    # archivo. Eso es correcto para no duplicar nada, pero tambien significa
    # que un panel ya parcheado con una version antigua de la extension NUNCA
    # recibiria un parche corregido en updates posteriores. Por eso, en cada
    # instalacion/actualizacion se retira primero el parche existente (si lo
    # hay) y se vuelve a aplicar entero, para que las correcciones lleguen
    # siempre a paneles ya instalados.
    php "${STATE_DIR}/source/tools/patch-panel.php" unpatch "$PANEL_DIR" >/dev/null 2>&1 || true
    php "${STATE_DIR}/source/tools/patch-panel.php" patch "$PANEL_DIR" \
        || warn "Algún parche de interfaz no se pudo aplicar; la extensión funciona, pero tendrás que entrar por URL."

    clear_caches

    info "Ejecutando migraciones…"
    artisan migrate --force || die "Las migraciones fallaron. Revisa la conexión a la base de datos."

    # Genera la clave de la API de integración automáticamente si todavía no
    # existe (primera instalación); en actualizaciones posteriores ya hay una
    # y --if-missing la deja tal cual, así nunca se invalida la integración
    # con WHMCS/Paymenter por accidente.
    info "Comprobando la clave de la API de integración…"
    artisan "$ARTISAN_NAMESPACE" apikey --if-missing || warn "No se pudo generar la clave de la API automáticamente; ejecuta 'sudo serversplitter apikey' a mano."

    install_cli
    fix_permissions
    clear_caches

    local url; url="$(app_url)"
    ok "ServerSplitter v${ADDON_VERSION} instalado correctamente."
    printf '\n  %sAdministración:%s  %s/admin/extensions/serversplitter\n' "$C_BOLD" "$C_RESET" "${url:-https://tu-panel}"
    printf '  %sCliente:%s         pestaña «Divisiones» en cada servidor (menú lateral)\n' "$C_BOLD" "$C_RESET"
    printf '  %sAPI:%s             %s/api/serversplitter/servers/{server}\n' "$C_BOLD" "$C_RESET" "${url:-https://tu-panel}"
    printf '\n  La clave de la API se ha generado automáticamente (mira el mensaje de arriba si es la\n'
    printf '  primera instalación). Para verla otra vez o generar una nueva: %ssudo serversplitter apikey%s\n\n' "$C_BOLD" "$C_RESET"
}

install_cli() {
    info "Instalando el comando 'serversplitter'…"
    if [ -f "${STATE_DIR}/source/bin/serversplitter" ]; then
        install -m 755 "${STATE_DIR}/source/bin/serversplitter" "$CLI_PATH"
    elif [ -f "${STATE_DIR}/source/serversplitter.sh" ]; then
        install -m 755 "${STATE_DIR}/source/serversplitter.sh" "$CLI_PATH"
    else
        warn "No se encontró el ejecutable del CLI; se omite."
    fi
}

# ---------------------------------------------------------------------------
# Actualización
# ---------------------------------------------------------------------------

do_update() {
    require_root update
    require_cmd git
    load_state
    detect_panel
    detect_web_user

    # Si el gestor está presente, es él quien mantiene el código fuente.
    if [ -f "${MANAGER_SRC_DIR}/serversplitter.sh" ]; then
        info "Delegando la actualización en el gestor (${MANAGER_SRC_DIR}/serversplitter.sh)…"
        PANEL_DIR="$PANEL_DIR" WEB_USER="$WEB_USER" \
            exec bash "${MANAGER_SRC_DIR}/serversplitter.sh" update
    fi

    [ -n "$REPO_URL" ] || die "No se conoce la URL del repositorio. Usa: sudo SERVERSPLITTER_REPO=<url> serversplitter update"

    local tmp
    tmp="$(mktemp -d /tmp/serversplitter-update.XXXXXX)"
    info "Descargando la última versión desde ${REPO_URL}…"
    if ! git clone --depth 1 "$REPO_URL" "${tmp}/repo" >/dev/null 2>&1; then
        rm -rf "$tmp"
        die "No se pudo clonar ${REPO_URL}. Comprueba la URL y la conectividad."
    fi

    local new_version
    new_version="$(tr -d '[:space:]' < "${tmp}/repo/VERSION" 2>/dev/null || echo 'unknown')"
    info "Versión instalada: ${ADDON_INSTALLED_VERSION:-desconocida} → nueva: ${new_version}"

    # Se ejecuta el instalador de la copia nueva para evitar auto-modificación.
    if ! PANEL_DIR="$PANEL_DIR" WEB_USER="$WEB_USER" SERVERSPLITTER_REPO="$REPO_URL" \
            bash "${tmp}/repo/scripts/addon-install.sh" install; then
        rm -rf "$tmp"
        die "La actualización falló."
    fi
    rm -rf "$tmp"
    ok "Actualización completada."
}

# ---------------------------------------------------------------------------
# Desinstalación
# ---------------------------------------------------------------------------

do_uninstall() {
    require_root uninstall
    load_state
    detect_panel
    detect_web_user

    # Si nos ejecutamos desde el propio STATE_DIR, trabajamos desde una copia.
    case "$SOURCE_DIR" in
        "${STATE_DIR}"*)
            local tmp
            tmp="$(mktemp -d /tmp/serversplitter-uninstall.XXXXXX)"
            ( cd "${STATE_DIR}/source" && tar -cf - . ) | ( cd "$tmp" && tar -xf - )
            local extra=()
            [ "$ASSUME_YES" = "yes" ] && extra+=(--force)
            [ "$DROP_DATA" = "yes" ] && extra+=(--drop-data)
            PANEL_DIR="$PANEL_DIR" WEB_USER="$WEB_USER" SERVERSPLITTER_SELFCOPY=1 \
                exec bash "${tmp}/scripts/addon-install.sh" uninstall "${extra[@]}"
            ;;
    esac

    if [ "$ASSUME_YES" != "yes" ]; then
        if [ "$DROP_DATA" = "yes" ]; then
            printf '%s' "Esto eliminará la extensión Y sus tablas con todos sus datos. Escribe 'si' para continuar: "
        else
            printf '%s' "Esto eliminará los archivos de la extensión (las tablas se conservan). Escribe 'si' para continuar: "
        fi
        local answer=''
        read -r answer || true
        case "$answer" in
            si|SI|Si|sí|SÍ|yes|y) ;;
            *) die "Desinstalación cancelada." ;;
        esac
    fi

    if [ "$DROP_DATA" = "yes" ]; then
        if [ -d "${PANEL_DIR}/${TARGET_REL}" ]; then
            info "Eliminando las tablas de ServerSplitter…"
            artisan "$ARTISAN_NAMESPACE" purge --force \
                || warn "La purga devolvió un error; se continuará con los archivos."
        else
            warn "La extensión no está en el panel; no se puede purgar la base de datos."
        fi
    fi

    info "Revirtiendo los parches de la interfaz…"
    php "${SOURCE_DIR}/tools/patch-panel.php" unpatch "$PANEL_DIR" \
        || warn "No se pudieron revertir todos los parches; revisa los bloques 'serversplitter:begin' en resources/views."

    info "Eliminando el service provider…"
    php "${SOURCE_DIR}/tools/register-provider.php" unregister "$PANEL_DIR" \
        || warn "No se pudo desregistrar el provider; revísalo a mano."

    info "Eliminando ${PANEL_DIR}/${TARGET_REL}…"
    rm -rf "${PANEL_DIR}/${TARGET_REL}"

    clear_caches

    info "Eliminando el comando y los datos del instalador…"
    rm -f "$CLI_PATH"
    rm -rf "$STATE_DIR"

    ok "ServerSplitter eliminado por completo. El panel queda en su estado original."
    info "Los servidores hijos ya creados siguen existiendo; gestiónalos desde el panel."
}

# ---------------------------------------------------------------------------
# Estado y utilidades
# ---------------------------------------------------------------------------

do_status() {
    load_state
    detect_panel_soft || true
    if [ -n "$PANEL_DIR" ]; then
        detect_web_user || true
    fi

    printf '%sServerSplitter%s\n' "$C_BOLD" "$C_RESET"
    printf '  Versión del paquete : %s\n' "$ADDON_VERSION"
    printf '  Versión instalada   : %s\n' "${ADDON_INSTALLED_VERSION:-no instalada}"
    printf '  Panel               : %s (v%s)\n' "${PANEL_DIR:-no detectado}" "$(panel_version)"
    printf '  Usuario web         : %s\n' "${WEB_USER:-desconocido}"
    printf '  Extensión           : %s\n' "$([ -d "${PANEL_DIR}/${TARGET_REL}" ] && echo presente || echo ausente)"
    printf '  Provider registrado : %s\n' "$(provider_registered && echo sí || echo no)"
    printf '  Parches interfaz    : %s\n' "$(panel_patched && echo aplicados || echo 'no aplicados')"
    printf '  Repositorio         : %s\n' "${REPO_URL:-no configurado}"
    printf '  CLI                 : %s\n' "$([ -x "$CLI_PATH" ] && echo "$CLI_PATH" || echo 'no instalado')"

    if [ -d "${PANEL_DIR}/${TARGET_REL}" ]; then
        printf '\n'
        artisan "$ARTISAN_NAMESPACE" info || warn "No se pudo consultar el estado en el panel."
    fi
}

do_apikey() {
    require_root apikey
    load_state
    detect_panel
    detect_web_user
    [ -d "${PANEL_DIR}/${TARGET_REL}" ] || die "La extensión no está instalada en ${PANEL_DIR}."

    if [ -n "$API_KEY" ]; then
        artisan "$ARTISAN_NAMESPACE" apikey --key="$API_KEY"
    else
        artisan "$ARTISAN_NAMESPACE" apikey
    fi
}

do_info() {
    load_state
    detect_panel
    detect_web_user
    [ -d "${PANEL_DIR}/${TARGET_REL}" ] || die "La extensión no está instalada en ${PANEL_DIR}."
    artisan "$ARTISAN_NAMESPACE" info
}

do_prune() {
    load_state
    detect_panel
    detect_web_user
    [ -d "${PANEL_DIR}/${TARGET_REL}" ] || die "La extensión no está instalada en ${PANEL_DIR}."
    artisan "$ARTISAN_NAMESPACE" prune
}

usage() {
    cat <<EOF
${C_BOLD}ServerSplitter v${ADDON_VERSION}${C_RESET} - instalador para Pterodactyl

Uso: bash scripts/addon-install.sh <comando> [opciones]

Comandos:
  install       Instala o reinstala la extensión en el panel
  update        Descarga la última versión del repositorio y la aplica
  uninstall     Elimina la extensión (añade --drop-data para borrar las tablas)
  status        Muestra el estado de la instalación
  apikey        Genera o fija la clave de la API de integración
  info          Muestra la configuración y estadísticas actuales
  prune         Limpia caché y bloqueos caducados
  version       Muestra la versión del paquete
  help          Muestra esta ayuda

Opciones:
  --force, -y     No pedir confirmación (uninstall)
  --drop-data     Borra las tablas de la extensión durante uninstall
  --key=CLAVE     Clave concreta para 'apikey'

Variables de entorno:
  PANEL_DIR            Ruta del panel (por defecto se detecta automáticamente)
  WEB_USER             Usuario del servidor web (por defecto se detecta)
  SERVERSPLITTER_REPO  URL del repositorio git para 'update'
EOF
}

main() {
    local cmd="${1:-help}"
    shift || true
    local arg
    for arg in "$@"; do
        case "$arg" in
            --force|-y|--yes) ASSUME_YES="yes" ;;
            --drop-data)      DROP_DATA="yes" ;;
            --key=*)          API_KEY="${arg#*=}" ;;
            *) warn "Opción desconocida ignorada: ${arg}" ;;
        esac
    done

    case "$cmd" in
        install)            do_install ;;
        update|upgrade)     do_update ;;
        uninstall|remove)   do_uninstall ;;
        status)             do_status ;;
        apikey)             do_apikey ;;
        info)               do_info ;;
        prune)              do_prune ;;
        version|--version)  printf '%s\n' "$ADDON_VERSION" ;;
        help|--help|-h)     usage ;;
        *) usage; die "Comando desconocido: ${cmd}" ;;
    esac
}

main "$@"