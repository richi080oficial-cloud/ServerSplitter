#!/usr/bin/env bash
#
# ServerSplitter — gestor (instalar / actualizar / desinstalar / estado)
# Autor: Waise Team · Licencia: MIT
#
#   bash <(curl -sSL .../install.sh) install
#   sudo serversplitter update
#   sudo serversplitter apikey
#   sudo serversplitter uninstall --drop-data
#
set -Eeuo pipefail

REPO_URL="${REPO_URL:-${SERVERSPLITTER_REPO:-https://github.com/waise-team/ServerSplitter.git}}"
REPO_BRANCH="${REPO_BRANCH:-main}"
SRC_DIR="${SRC_DIR:-/opt/serversplitter}"
INSTALLER_REL="scripts/addon-install.sh"
STATE_DIR="/usr/local/share/serversplitter"
STATE_FILE="${STATE_DIR}/state.env"
CLI_PATH="/usr/local/bin/serversplitter"
CRON_MARKER="serversplitter-autoupdate"
LOG_FILE="/var/log/serversplitter-update.log"
TARGET_REL="app/Extensions/ServerSplitter"

PANEL_DIR="${PANEL_DIR:-}"
WEB_USER="${WEB_USER:-}"
FORCE="${FORCE:-0}"
DROP_DATA="${DROP_DATA:-0}"
API_KEY=""

if [ -t 1 ]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'
    BLUE=$'\033[0;36m'; NC=$'\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; NC=''
fi

info() { echo -e "${BLUE}[serversplitter]${NC} $*"; }
ok()   { echo -e "${GREEN}[serversplitter]${NC} $*"; }
warn() { echo -e "${YELLOW}[serversplitter]${NC} $*" >&2; }
fail() { echo -e "${RED}[serversplitter]${NC} $*" >&2; exit 1; }

trap 'fail "El gestor se detuvo en la línea $LINENO."' ERR

[[ $EUID -eq 0 ]] || fail "Ejecútalo como root: añade sudo delante del comando."

# ── Detección del panel ───────────────────────────────────────────────────────
detect_panel() {
    if [[ -n "$PANEL_DIR" ]]; then echo "${PANEL_DIR%/}"; return; fi
    if [[ -f "$STATE_FILE" ]]; then
        local saved
        saved="$(grep -m1 -E '^PANEL_DIR=' "$STATE_FILE" 2>/dev/null | sed -E 's/^PANEL_DIR="?([^"]*)"?$/\1/')"
        if [[ -n "$saved" && -f "$saved/artisan" ]]; then echo "$saved"; return; fi
    fi
    local c
    for c in /var/www/pterodactyl /var/www/panel /var/www/html/pterodactyl /srv/pterodactyl; do
        [[ -f "$c/artisan" && -f "$c/config/app.php" ]] && { echo "$c"; return; }
    done
    echo ""
}

require_panel() {
    PANEL="$(detect_panel)"
    [[ -n "$PANEL" ]] || fail "No se encontró Pterodactyl. Reintenta con: sudo serversplitter $ACTION --panel=/ruta/al/panel"
    ok "Panel detectado en: $PANEL"
}

# ── Dependencias ──────────────────────────────────────────────────────────────
pkg_install() {
    local pkg="$1"
    if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get update -qq && \
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$pkg"
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y -q "$pkg"
    elif command -v yum >/dev/null 2>&1; then
        yum install -y -q "$pkg"
    else
        fail "No se pudo instalar '$pkg' automáticamente. Instálalo y reintenta."
    fi
}

require_cmd() {
    local cmd="$1" pkg="${2:-$1}"
    command -v "$cmd" >/dev/null 2>&1 && return
    info "Instalando $pkg…"
    pkg_install "$pkg"
    command -v "$cmd" >/dev/null 2>&1 || fail "'$cmd' sigue sin estar disponible."
    ok "$pkg instalado."
}

# ── Descargar / actualizar el código fuente ───────────────────────────────────
sync_sources() {
    require_cmd git
    if [[ -d "$SRC_DIR/.git" ]]; then
        info "Actualizando el código en $SRC_DIR…"
        git -C "$SRC_DIR" remote set-url origin "$REPO_URL"
        git -C "$SRC_DIR" fetch --quiet origin "$REPO_BRANCH"
        git -C "$SRC_DIR" reset --hard --quiet "origin/$REPO_BRANCH"
        git -C "$SRC_DIR" clean -fd --quiet
    else
        info "Descargando el repositorio en $SRC_DIR…"
        rm -rf "$SRC_DIR"
        git clone --quiet --branch "$REPO_BRANCH" --depth 1 "$REPO_URL" "$SRC_DIR"
    fi

    # Normalizar saltos de línea por si el repo se editó desde Windows.
    find "$SRC_DIR" -name '*.sh' -print0 | xargs -0 -r sed -i 's/\r$//'
    if [[ -f "$SRC_DIR/bin/serversplitter" ]]; then
        sed -i 's/\r$//' "$SRC_DIR/bin/serversplitter"
    fi
    chmod +x "$SRC_DIR"/*.sh 2>/dev/null || true
    chmod +x "$SRC_DIR/scripts"/*.sh 2>/dev/null || true
    chmod +x "$SRC_DIR/bin/serversplitter" 2>/dev/null || true

    [[ -f "$SRC_DIR/$INSTALLER_REL" ]] || fail "Falta $INSTALLER_REL en el repositorio."

    ok "Código sincronizado (commit $(git -C "$SRC_DIR" rev-parse --short HEAD))."
}

remote_has_changes() {
    git -C "$SRC_DIR" fetch --quiet origin "$REPO_BRANCH"
    [[ "$(git -C "$SRC_DIR" rev-parse HEAD)" != "$(git -C "$SRC_DIR" rev-parse "origin/$REPO_BRANCH")" ]]
}

# ── Estado ────────────────────────────────────────────────────────────────────
is_installed() {
    [[ -f "$STATE_FILE" ]] && return 0
    local panel; panel="$(detect_panel)"
    [[ -n "$panel" && -d "$panel/$TARGET_REL" ]]
}

install_cli() {
    if [[ -f "$SRC_DIR/bin/serversplitter" ]]; then
        install -m 0755 "$SRC_DIR/bin/serversplitter" "$CLI_PATH"
    else
        install -m 0755 "$SRC_DIR/serversplitter.sh" "$CLI_PATH"
    fi
    ok "Comando corto disponible: serversplitter"
}

run_installer() {
    [[ -f "$SRC_DIR/$INSTALLER_REL" ]] || sync_sources
    PANEL_DIR="$PANEL" WEB_USER="$WEB_USER" SERVERSPLITTER_REPO="$REPO_URL" SRC_DIR="$SRC_DIR" \
        bash "$SRC_DIR/$INSTALLER_REL" "$@"
}

# ── Instalación ───────────────────────────────────────────────────────────────
do_install() {
    require_panel
    sync_sources

    info "Instalando la extensión…"
    run_installer install
    install_cli
    ok "Instalado. Genera la clave de la API con: sudo serversplitter apikey"
}

do_update() {
    require_panel

    if ! is_installed; then
        warn "No se detectó ninguna instalación previa; se hará una instalación limpia."
        do_install
        return
    fi

    if [[ "$FORCE" != "1" && -d "$SRC_DIR/.git" ]] && ! remote_has_changes; then
        ok "Ya estás en la última versión. Nada que hacer."
        return
    fi

    info "Actualizando la extensión…"
    sync_sources
    run_installer install
    install_cli
    ok "Extensión actualizada a la última versión."
}

# ── Desinstalación ────────────────────────────────────────────────────────────
do_uninstall() {
    # Este script puede vivir dentro de SRC_DIR y vamos a borrar esa carpeta:
    # nos movemos a una copia temporal para que bash pueda seguir leyéndose.
    if [[ "${SERVERSPLITTER_DETACHED:-0}" != "1" ]]; then
        local tmp; tmp="$(mktemp /tmp/serversplitter-XXXXXX.sh)"
        cat "${BASH_SOURCE[0]}" > "$tmp"
        chmod +x "$tmp"
        SERVERSPLITTER_DETACHED=1 FORCE="$FORCE" DROP_DATA="$DROP_DATA" \
            PANEL_DIR="$PANEL_DIR" WEB_USER="$WEB_USER" exec bash "$tmp" uninstall
    fi

    require_panel
    is_installed || fail "No se detectó ninguna instalación de la extensión."

    warn "Se eliminará TODO: archivos, provider registrado, cron, comando y el código en $SRC_DIR."
    if [[ "$DROP_DATA" == "1" ]]; then
        warn "Además se BORRARÁN las tablas de ServerSplitter (ajustes, reglas, divisiones y límites)."
    else
        info "Las tablas de la base de datos se conservan (añade --drop-data para borrarlas)."
    fi
    warn "Los servidores hijos ya creados NO se eliminan."
    if [[ "$FORCE" != "1" && -t 0 ]]; then
        read -r -p "¿Continuar? [s/N] " answer
        [[ "$answer" =~ ^([sS]|[yY]|si|SI|sí|SÍ)$ ]] || { info "Cancelado."; return; }
    fi

    info "Desinstalando…"
    local args=(uninstall --force)
    [[ "$DROP_DATA" == "1" ]] && args+=(--drop-data)
    run_installer "${args[@]}" || warn "El desinstalador terminó con errores; se continúa con la limpieza."

    autoupdate_off quiet
    rm -rf "$SRC_DIR"
    rm -f "$CLI_PATH"
    ok "Extensión desinstalada por completo. No queda nada."
}

# ── Auto-update ───────────────────────────────────────────────────────────────
autoupdate_on() {
    [[ -f "$SRC_DIR/serversplitter.sh" ]] || sync_sources
    local line="0 4 * * * /usr/bin/env bash $SRC_DIR/serversplitter.sh update >> $LOG_FILE 2>&1 # $CRON_MARKER"
    ( crontab -l 2>/dev/null | grep -v "$CRON_MARKER" || true; echo "$line" ) | crontab -
    ok "Actualización automática activada (cada día a las 04:00)."
    info "Registro: $LOG_FILE"
}

autoupdate_off() {
    if crontab -l 2>/dev/null | grep -q "$CRON_MARKER"; then
        crontab -l 2>/dev/null | grep -v "$CRON_MARKER" | crontab -
        [[ "${1:-}" == "quiet" ]] || ok "Actualización automática desactivada."
    else
        [[ "${1:-}" == "quiet" ]] || info "La actualización automática no estaba activada."
    fi
}

# ── Estado / utilidades del panel ─────────────────────────────────────────────
do_status() {
    PANEL="$(detect_panel)"
    if [[ -f "$SRC_DIR/$INSTALLER_REL" && -n "$PANEL" ]]; then
        run_installer status
    else
        echo "ServerSplitter"
        echo "  Instalación : $(is_installed && echo presente || echo ausente)"
        echo "  Panel       : ${PANEL:-no detectado}"
        echo "  Código      : $([[ -d "$SRC_DIR" ]] && echo "$SRC_DIR" || echo 'no descargado')"
        echo "  CLI         : $([[ -x "$CLI_PATH" ]] && echo "$CLI_PATH" || echo 'no instalado')"
    fi
    echo "  Auto-update : $(crontab -l 2>/dev/null | grep -q "$CRON_MARKER" && echo activo || echo inactivo)"
}

do_apikey() {
    require_panel
    if [[ -n "$API_KEY" ]]; then
        run_installer apikey --key="$API_KEY"
    else
        run_installer apikey
    fi
}

do_info()  { require_panel; run_installer info; }
do_prune() { require_panel; run_installer prune; }

do_version() {
    if [[ -f "$SRC_DIR/VERSION" ]]; then
        tr -d '[:space:]' < "$SRC_DIR/VERSION"; echo
    else
        echo "desconocida (el código fuente no está descargado)"
    fi
}

# ── Ayuda ─────────────────────────────────────────────────────────────────────
usage() {
    cat <<EOF
ServerSplitter — gestor

  install             Instala la extensión en Pterodactyl
  update              Actualiza a la última versión
  uninstall           Desinstala TODO (archivos, provider, cron y comando)
  status              Muestra el estado de la instalación
  version             Muestra la versión descargada
  apikey [--key=X]    Genera (o fija) la clave de la API de integración
  info                Muestra la configuración y estadísticas actuales
  prune               Limpia caché y bloqueos caducados
  autoupdate-on       Programa la actualización automática diaria (04:00)
  autoupdate-off      Cancela la actualización automática

Opciones:
  --force, -y         No pedir confirmación (uninstall) y forzar la actualización
  --drop-data         En uninstall, borra también las tablas de la extensión
  --panel=RUTA        Ruta del panel (por defecto se detecta automáticamente)
  --user=USUARIO      Usuario del servidor web (por defecto se detecta)
  --key=CLAVE         Clave concreta para 'apikey'

Variables de entorno:
  PANEL_DIR           Equivalente a --panel
  WEB_USER            Equivalente a --user
  REPO_URL            Repositorio git (por defecto el oficial)
  REPO_BRANCH         Rama a usar (por defecto main)
  SRC_DIR             Dónde se guarda el código fuente (por defecto /opt/serversplitter)

Ejemplos:
  sudo serversplitter update
  sudo serversplitter update --force
  sudo serversplitter install --panel=/var/www/pterodactyl --user=www-data
  sudo serversplitter uninstall -y --drop-data
EOF
}

ACTION="${1:-help}"
shift || true
for arg in "$@"; do
    case "$arg" in
        --force|-y|--yes) FORCE=1 ;;
        --drop-data)      DROP_DATA=1 ;;
        --key=*)          API_KEY="${arg#*=}" ;;
        --panel=*)        PANEL_DIR="${arg#*=}" ;;
        --user=*)         WEB_USER="${arg#*=}" ;;
        *) warn "Opción desconocida ignorada: $arg" ;;
    esac
done

case "$ACTION" in
    install)                do_install ;;
    update|upgrade)         do_update ;;
    uninstall|remove)       do_uninstall ;;
    status)                 do_status ;;
    version|--version)      do_version ;;
    apikey)                 do_apikey ;;
    info)                   do_info ;;
    prune)                  do_prune ;;
    autoupdate-on)          autoupdate_on ;;
    autoupdate-off)         autoupdate_off ;;
    ''|help|-h|--help)      usage ;;
    *) echo "Acción desconocida: $ACTION" >&2; echo; usage; exit 1 ;;
esac