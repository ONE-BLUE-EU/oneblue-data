#!/usr/bin/env bash
# Run the oneblue-data stack. Dev and prod use the same image base, PHP config,
# database and paths; dev adds a bind mount, Xdebug and Mailpit, prod adds Caddy.
#
# This script also runs on the server, inside an installed release (a directory
# with a RELEASE file, made by release.sh). There, "prod up" never builds or
# pulls: it starts exactly the images that were built and tested locally.
set -euo pipefail

cd "$(dirname "$(readlink -f "$0")")"

usage() {
  cat <<'EOF'
Usage: ./run.sh [dev|prod] [command] [args...]

The profile defaults to dev: "./run.sh" starts dev, "./run.sh drush cr" runs in dev.

  up                  Build (pulling newer base images) and start (default).
                      In an installed release: start the shipped images only.
  down                Stop containers (data volumes are kept)
  restart             Restart containers
  build               Rebuild images
  logs [service]      Follow logs
  ps                  List containers
  sh [service]        Shell as www-data (default: web)
  drush <args>        Run drush (stdout is clean for scripts; PHP warnings go to stderr)
  exec <cmd> [args]   Run a command in the web container as www-data, e.g.
                      ./run.sh exec vendor/bin/phpunit ...
  composer <args>     Run composer (dev only)
  mysql               MariaDB client on the site database
  db-export [file]    Dump DB to backups/ (or the given .sql.gz path)
  db-import <file>    Replace the DB with a .sql or .sql.gz dump
  files-export [file] Archive public files to backups/ (or the given .tar.gz)
  files-import <file> Extract a .tar.gz of the files directory's contents
  sync-from-prod [all|db|files]
                      Mirror prod into dev (dev only). Needs HOST and SSH_KEY
                      (optional SSH_USER, default root). Files are rsynced
                      with --delete; the DB dump is kept in backups/ and imported.
  deploy-hooks        updatedb, config import (if config/sync has config), cache rebuild
  check               Smoke test: key pages/API return 200, Drupal bootstraps,
                      no pending DB updates, no new PHP errors
  is-installed        Exit 0 if Drupal is installed (DB not empty), 1 otherwise
  init-env            (prod) Create .env with random secrets if missing. Uses
                      PROXY (nginx|caddy, default nginx), DOMAIN, SERVER_IP,
                      WEB_PORT, HTTP_PORT, HTTPS_PORT, COMPOSE_PROJECT_NAME
  nginx-site          (prod, PROXY=nginx) Print the host nginx site config for
                      this install (domain and port from .env)
  site-install        Fresh DKAN install (DESTROYS the current DB)
  frontend-build      Clone (if missing) and build docroot/frontend (dev only;
                      prod builds FRONTEND_REF from the frontend repo in the image)
  compose <args>      Raw docker compose passthrough
EOF
}

die() { echo "error: $*" >&2; exit 1; }

case "${1:-}" in
  dev|prod) ENV="$1"; shift ;;
  -h|--help|help) usage; exit 0 ;;
  *) ENV=dev ;;
esac
CMD="${1:-up}"
[ $# -gt 0 ] && shift

# Shell env overrides .env, so the mode always matches the argument.
export APP_ENV="$ENV"
export HOST_UID="${HOST_UID:-$(id -u)}"
export HOST_GID="${HOST_GID:-$(id -g)}"

ensure_env() {
  [ -f .env ] && return
  [ "$CMD" = init-env ] && return
  [ "$ENV" = prod ] && die ".env is missing. deploy.sh creates it on the server; or copy .env.example and set strong secrets."
  echo "Creating .env from .env.example"
  sed "s/^HASH_SALT=.*/HASH_SALT=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')/" .env.example > .env
}

# .env.images: every image name/version. .env: secrets and settings (wins on conflict).
dc() { docker compose --env-file .env.images --env-file .env -f docker-compose.yaml -f "docker-compose.$ENV.yaml" "$@"; }

TTY=()
[ -t 0 ] && [ -t 1 ] || TTY=(-T)

as_www() { dc exec "${TTY[@]}" -u www-data -e HOME=/tmp "$@"; }
# drush with PHP warnings/deprecations on stderr, so stdout carries only drush's
# output and $(./run.sh drush ...) can be captured in scripts. (dev.ini displays
# errors, which would otherwise go to stdout.) Same as vendor/bin/drush otherwise.
# Without a terminal (scripts, pipes), drush wraps tables at 80 columns, which
# splits rows; give it a wide width there. With a terminal, its real width is used.
drush_www() {
  local cols=()
  [ ${#TTY[@]} -gt 0 ] && cols=(COLUMNS="${COLUMNS:-250}")
  as_www web env XDEBUG_MODE=off "${cols[@]}" php -d display_errors=stderr vendor/drush/drush/drush.php "$@"
}
db_sh() { dc exec -T db sh -c "$1"; }

site_installed() {
  drush_www status --field=bootstrap 2>/dev/null | grep -q Successful
}

ensure_env
[ -f .env.images ] || die ".env.images is missing."
set -a; . ./.env.images; set +a

case "$CMD" in
  up)
    if [ "$ENV" = dev ]; then
      mkdir -p private docroot/sites/default/files
      dc pull --quiet db mailpit
      dc build --pull
      dc up -d --remove-orphans
      if [ ! -f vendor/autoload.php ]; then
        echo "Installing composer dependencies..."
        as_www web composer install --no-interaction
      fi
      [ -d docroot/frontend/build/static ] || "$0" dev frontend-build
      echo "Site:    http://localhost:$(dc port web 80 | cut -d: -f2)"
      echo "Mailpit: http://localhost:$(dc port mailpit 8025 | cut -d: -f2)"
    elif [ -f RELEASE ]; then
      # Installed release: images were loaded by deploy.sh / release.sh test.
      mkdir -p private docroot/sites/default/files
      dc up -d --no-build --pull never --remove-orphans
    else
      mkdir -p private docroot/sites/default/files
      dc pull --quiet db
      grep -q '^COMPOSE_PROFILES=.*caddy' .env && dc pull --quiet caddy
      dc build --pull
      dc up -d --remove-orphans
    fi
    ;;
  down)    dc down "$@" ;;
  restart) dc restart "$@" ;;
  build)   dc build --pull "$@" ;;
  logs)    dc logs -f --tail=200 "$@" ;;
  ps)      dc ps "$@" ;;
  sh)      as_www "${1:-web}" bash ;;
  drush)   drush_www "$@" ;;
  exec)    [ $# -gt 0 ] || die "usage: ./run.sh $ENV exec <command> [args...]"
           as_www web env XDEBUG_MODE="${XDEBUG_MODE:-off}" "$@" ;;
  composer)
    [ "$ENV" = dev ] || die "composer runs in dev; commit composer.lock and redeploy."
    as_www web composer "$@"
    ;;
  mysql)
    dc exec "${TTY[@]}" db sh -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
    ;;
  db-export)
    mkdir -p backups
    out="${1:-backups/db-$(date +%Y%m%d-%H%M%S).sql.gz}"
    db_sh 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --quick --routines --triggers "$MARIADB_DATABASE"' | gzip > "$out"
    echo "Database exported to $out"
    ;;
  db-import)
    file="${1:-}"
    [ -f "$file" ] || die "usage: ./run.sh $ENV db-import <dump.sql[.gz]>"
    echo "Replacing database with $file ..."
    db_sh 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$MARIADB_DATABASE\`; CREATE DATABASE \`$MARIADB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"'
    case "$file" in
      *.gz) gunzip -c "$file" ;;
      *.zst) zstd -dc "$file" ;;
      *) cat "$file" ;;
    esac | db_sh 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
    drush_www cache:rebuild || true
    echo "Database imported."
    ;;
  files-export)
    mkdir -p backups
    out="${1:-backups/files-$(date +%Y%m%d-%H%M%S).tar.gz}"
    dc exec -T web tar -C /var/www/html/docroot/sites/default/files --exclude=./php --exclude=./css --exclude=./js -czf - . > "$out"
    echo "Files exported to $out"
    ;;
  files-import)
    file="${1:-}"
    [ -f "$file" ] || die "usage: ./run.sh $ENV files-import <files.tar.gz>"
    dc exec -T web tar -C /var/www/html/docroot/sites/default/files -xzf - < "$file"
    dc exec -T web chown -R www-data:www-data /var/www/html/docroot/sites/default/files
    echo "Files imported."
    ;;
  deploy-hooks)
    if ! site_installed; then
      echo "Drupal is not installed yet; skipping deploy hooks."
      exit 0
    fi
    drush_www updatedb -y
    if compgen -G "config/sync/*.yml" > /dev/null; then
      drush_www config:import -y
    fi
    drush_www cache:rebuild
    drush_www deploy:hook -y
    ;;
  check)
    site_host="$(sed -n 's/^SITE_HOST=//p' .env | tr -d "'\"")"
    if [ -z "$site_host" ]; then
      site_host="$(sed -n 's/^SITE_ADDRESS=//p' .env | tr -d "'\"")"
      case "$site_host" in ""|:*) site_host=localhost ;; esac
    fi
    drush_q() { dc exec -T -u www-data -e HOME=/tmp web php -d display_errors=0 vendor/drush/drush/drush.php "$@" 2>/dev/null; }
    fail=0
    ok()  { printf '  ok    %s\n' "$1"; }
    bad() { printf '  FAIL  %s\n' "$1"; fail=1; }
    echo "Checking (Host: $site_host)"
    for _ in $(seq 1 30); do
      [ "$(dc exec -T web curl -s -o /dev/null -w '%{http_code}' -H "Host: $site_host" http://localhost/user/login 2>/dev/null)" = 200 ] && break
      sleep 2
    done
    if [ "$(drush_q status --field=bootstrap)" = Successful ]; then ok "Drupal bootstraps"; else bad "Drupal does not bootstrap"; fi
    if drush_q updatedb:status --format=json | grep -q '[a-z]'; then bad "pending database updates"; else ok "no pending database updates"; fi
    since="$(date +%s)"
    for path in / /user/login /api/1/metastore/schemas /api/1/metastore/schemas/dataset/items \
                "/api/1/search?page-size=1" /frontend/build/static/js/index.js; do
      code="$(dc exec -T web curl -s -o /dev/null -w '%{http_code}' -H "Host: $site_host" "http://localhost$path")"
      if [ "$code" = 200 ]; then ok "$code $path"; else bad "$code $path"; fi
    done
    errors="$(drush_q sqlq "SELECT COUNT(*) FROM watchdog WHERE type='php' AND severity <= 3 AND timestamp >= $since")"
    if [ "${errors:-0}" = 0 ]; then ok "no PHP errors logged during the check"
    else bad "$errors PHP error(s) logged during the check (see: ./run.sh $ENV drush watchdog:show --type=php)"; fi
    if [ "$fail" = 0 ]; then echo "All checks passed."; else echo "Checks FAILED."; exit 1; fi
    ;;
  is-installed)
    site_installed
    ;;
  init-env)
    [ "$ENV" = prod ] || die "init-env is for prod (dev uses .env.example)."
    if [ -f .env ]; then echo ".env exists; leaving it unchanged."; exit 0; fi
    rand() { head -c 48 /dev/urandom | od -An -tx1 | tr -d ' \n' | cut -c1-"$1"; }
    esc() { printf '%s' "$1" | sed 's/\./\\./g'; }
    domain="${DOMAIN:-}"; ip="${SERVER_IP:-}"
    trusted=""
    for h in "$domain" "$ip"; do [ -n "$h" ] && trusted="${trusted:+$trusted,}^$(esc "$h")\$"; done
    [ -n "$trusted" ] || trusted='^localhost$'
    site_host="${domain:-${ip:-localhost}}"
    proxy="${PROXY:-nginx}"
    case "$proxy" in nginx|caddy) ;; *) die "PROXY must be nginx or caddy" ;; esac
    umask 077
    {
      echo "APP_ENV=prod"
      [ -n "${COMPOSE_PROJECT_NAME:-}" ] && echo "COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME"
      echo "# Front proxy: nginx = host nginx proxies to 127.0.0.1:WEB_PORT; caddy = Caddy owns 80/443."
      echo "PROXY=$proxy"
      [ "$proxy" = caddy ] && echo "COMPOSE_PROFILES=caddy"
      echo "WEB_PORT=${WEB_PORT:-8090}"
      echo "DB_NAME=drupal"
      echo "DB_USER=drupal"
      echo "DB_PASSWORD=$(rand 40)"
      echo "DB_ROOT_PASSWORD=$(rand 40)"
      echo "HASH_SALT=$(rand 64)"
      echo "TRUSTED_HOSTS='$trusted'"
      echo "SITE_HOST=$site_host"
      echo "SITE_ADDRESS=${domain:-:80}"
      echo "HTTP_PORT=${HTTP_PORT:-80}"
      echo "HTTPS_PORT=${HTTPS_PORT:-443}"
      echo "CRON_INTERVAL=60"
      echo "SMTP_HOST="
      echo "SMTP_PORT=587"
      echo "SMTP_USER="
      echo "SMTP_PASSWORD="
      echo "SMTP_FROM="
    } > .env
    echo "Created .env (site: $site_host, proxy: $proxy)"
    ;;
  nginx-site)
    [ "$ENV" = prod ] || die "nginx-site is for prod."
    domain="$(sed -n 's/^SITE_HOST=//p' .env)"
    port="$(sed -n 's/^WEB_PORT=//p' .env)"
    [ -n "$domain" ] && [ -n "$port" ] || die ".env has no SITE_HOST/WEB_PORT"
    sed -e "s/__DOMAIN__/$domain/g" -e "s/__PORT__/$port/g" docker/nginx/site.conf.template
    ;;
  site-install)
    if [ "$ENV" = prod ]; then
      read -r -p "This wipes the PRODUCTION database. Type 'oneblue-data' to continue: " ok
      [ "$ok" = oneblue-data ] || die "aborted"
    fi
    drush_www site:install minimal --site-name="DKAN" --account-name="admin" -y
    drush_www pm-enable dkan harvest datastore admin_toolbar admin_toolbar_tools -y
    drush_www role:create administrator Administrator -y
    drush_www config-set user.role.administrator is_admin true -y
    drush_www user:role:add administrator admin
    drush_www theme:enable claro stark
    drush_www config-set system.theme admin claro -y
    drush_www config-set system.theme default stark -y
    drush_www config-set node.settings use_admin_theme true -y
    drush_www cache:rebuild
    ;;
  frontend-build)
    [ "$ENV" = dev ] || die "prod builds the frontend inside the image."
    if [ ! -d docroot/frontend ]; then
      git clone "$FRONTEND_REPO" docroot/frontend
    elif [ ! -d docroot/frontend/.git ]; then
      echo "warning: docroot/frontend is not a git checkout of $FRONTEND_REPO; building it as-is."
    fi
    docker run --rm -u "$HOST_UID:$HOST_GID" -e HOME=/tmp \
      -v "$PWD/docroot/frontend:/app" -w /app "$NODE_IMAGE" \
      sh -c 'if [ -f package-lock.json ]; then npm ci --loglevel=error --no-audit --no-fund;
             else npm install --loglevel=error --no-audit --no-fund; fi && npm run build'
    ;;
  sync-from-prod)
    [ "$ENV" = dev ] || die "sync-from-prod copies prod INTO dev; run it as ./run.sh sync-from-prod."
    : "${HOST:?usage: HOST=<ip> SSH_KEY=/path/to/key ./run.sh sync-from-prod [all|db|files]}"
    : "${SSH_KEY:?usage: HOST=<ip> SSH_KEY=/path/to/key ./run.sh sync-from-prod [all|db|files]}"
    [ -f "$SSH_KEY" ] || die "SSH key not found: $SSH_KEY"
    what="${1:-all}"
    case "$what" in all|db|files) ;; *) die "sync-from-prod: expected all, db or files" ;; esac
    remote_dir="${REMOTE_DIR:-/opt/oneblue-data}"
    target="${SSH_USER:-root}@$HOST"
    ssh_cmd="ssh -i $SSH_KEY -o StrictHostKeyChecking=accept-new -o BatchMode=yes"

    if [ "$what" != db ]; then
      echo "==> Syncing files from $target (local files not on prod are deleted)"
      mkdir -p docroot/sites/default/files private
      # php/, css/, js/ are caches Drupal regenerates.
      rsync -az --delete --info=stats1 \
        --exclude=/php/ --exclude=/css/ --exclude=/js/ \
        -e "$ssh_cmd" "$target:$remote_dir/docroot/sites/default/files/" docroot/sites/default/files/
      rsync -az --delete --info=stats1 \
        -e "$ssh_cmd" "$target:$remote_dir/private/" private/
    fi

    if [ "$what" != files ]; then
      mkdir -p backups
      dump="backups/prod-db-$(date +%Y%m%d-%H%M%S).sql.gz"
      echo "==> Dumping prod database to $dump"
      # shellcheck disable=SC2016  # $MARIADB_* expand inside the remote db container
      $ssh_cmd "$target" "cd $remote_dir && ./run.sh prod compose exec -T db sh -c 'exec mariadb-dump -uroot -p\"\$MARIADB_ROOT_PASSWORD\" --single-transaction --quick --routines --triggers \"\$MARIADB_DATABASE\"' | gzip" > "$dump"
      gzip -t "$dump" || die "the prod dump in $dump is corrupt"
      "$0" dev db-import "$dump"
    fi
    echo "==> Dev now mirrors prod ($what)."
    ;;
  compose) dc "$@" ;;
  *) usage; exit 1 ;;
esac
