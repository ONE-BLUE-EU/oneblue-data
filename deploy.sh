#!/usr/bin/env bash
# Deploy a locally built and tested release to the server.
#
#   ./deploy.sh                 Guide: shows where the release for your commit stands
#                               and the next step. Never connects to the server.
#   HOST=<ip> SSH_KEY=<key> ./deploy.sh --apply
#                               Deploy the tested release.
#
# Optional (with --apply):
#   RELEASE=<commit>        release in dist/ to deploy (default: the current HEAD)
#   SSH_USER=root           remote user (needs docker access; sudo for first mkdir)
#   DOMAIN=data.one-blue.eu first deploy only: the site's domain (DOMAIN= for the bare IP)
#   PROXY=nginx             first deploy only: nginx = the host's nginx proxies to
#                           127.0.0.1:WEB_PORT (Caddy off); caddy = Caddy owns 80/443
#   WEB_PORT=8090           first deploy only, PROXY=nginx: local port for the site
#   ALLOW_UNTESTED=1        deploy a release that has not passed ./release.sh test
#
# The server never builds or pulls anything. It receives the release's images
# (only those it doesn't already have) and its runtime files, in /opt/oneblue-data.
# Uploaded files, private files, backups/ and the server's .env are never touched.
# Rollback: RELEASE=<older commit> ./deploy.sh --apply (its image is usually still on the server).
set -euo pipefail

cd "$(dirname "$(readlink -f "$0")")"

APPLY=""
for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    -h|--help) sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "error: unknown argument: $arg (see ./deploy.sh --help)" >&2; exit 1 ;;
  esac
done

die() { echo "error: $*" >&2; exit 1; }
step() { echo; echo "==> $*"; }

COMMIT="${RELEASE:-$(git rev-parse --short=12 HEAD)}"
DIR="dist/$COMMIT"

# Without --apply: explain the state of the release and the next step, then stop.
guide() {
  local next=""
  echo "Deploy guide (nothing is sent to the server without --apply)"
  echo
  if git cat-file -e "$COMMIT^{commit}" 2>/dev/null; then
    echo "  [x] Commit:  $COMMIT  $(git log -1 --format=%s "$COMMIT")"
  elif [ ! -f "$DIR/RELEASE" ]; then
    echo "  [ ] Release: $COMMIT is neither a commit nor a release in dist/ (see ./release.sh list)"
    echo
    echo "Next step: pick an existing release: RELEASE=<commit> ./deploy.sh"
    exit 1
  fi
  if [ -z "${RELEASE:-}" ] && [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "  [!] You have uncommitted changes. They are NOT in this release."
    echo "      To deploy them: commit, then ./release.sh build and ./release.sh test again."
  fi

  if [ -f "$DIR/RELEASE" ]; then
    echo "  [x] Build:   $DIR ($(sed -n 's/^built_at=//p' "$DIR/RELEASE"))"
  else
    echo "  [ ] Build:   run ./release.sh build"
    echo "      (builds the prod image and release artifacts locally into $DIR)"
    next="${next:-./release.sh build}"
  fi

  if [ -f "$DIR/TESTED" ]; then
    echo "  [x] Test:    passed $(sed -n 's/^tested_at=//p' "$DIR/TESTED") with $(sed -n 's/^database=//p' "$DIR/TESTED")"
  else
    local db
    db="$(ls -t backups/prod-db-*.sql.gz 2>/dev/null | head -1 || true)"
    echo "  [ ] Test:    run ./release.sh test"
    echo "      (runs the prod release locally exactly as the server will, at http://localhost:8081:"
    echo "       imports a DB, runs the deploy hooks and smoke checks, then marks the release tested)"
    if [ -n "$db" ]; then
      echo "      It will use the newest prod dump: $db"
    else
      echo "      Tip: get a fresh prod DB to test with first: HOST=<ip> SSH_KEY=<key> ./run.sh sync-from-prod db"
      echo "      (or pass one: DB=<dump.sql.gz> ./release.sh test; otherwise the dev DB is used)"
    fi
    next="${next:-./release.sh test}"
  fi

  echo
  if [ -n "$next" ]; then
    echo "Next step: $next"
    exit 1
  fi
  echo "  [ ] Deploy:  everything above is done; this release is ready to deploy."
  echo
  echo "Next step: HOST=<server-ip> SSH_KEY=<key> ./deploy.sh --apply"
}

if [ -z "$APPLY" ]; then
  guide
  exit 0
fi

: "${HOST:?Usage: HOST=<ip> SSH_KEY=/path/to/key ./deploy.sh --apply}"
: "${SSH_KEY:?Usage: HOST=<ip> SSH_KEY=/path/to/key ./deploy.sh --apply}"
[ -f "$SSH_KEY" ] || die "SSH key not found: $SSH_KEY"
SSH_USER="${SSH_USER:-root}"
DOMAIN="${DOMAIN-data.one-blue.eu}"
PROXY="${PROXY:-nginx}"
WEB_PORT="${WEB_PORT:-8090}"
case "$PROXY" in nginx|caddy) ;; *) die "PROXY must be nginx or caddy" ;; esac
REMOTE_DIR="${REMOTE_DIR:-/opt/oneblue-data}"
KEEP_RELEASES=3

[ -f "$DIR/RELEASE" ] || die "no release $DIR. Run ./deploy.sh (without --apply) to see what to do first."
if [ ! -f "$DIR/TESTED" ]; then
  [ -n "${ALLOW_UNTESTED:-}" ] || die "release $COMMIT has not passed ./release.sh test. Run ./deploy.sh (without --apply) for the next step. (ALLOW_UNTESTED=1 to override)"
  echo "warning: deploying UNTESTED release $COMMIT"
fi
APP_IMAGE="$(sed -n 's/^APP_IMAGE_PROD=//p' "$DIR/runtime/.env.images")"

SSH_OPTS=(-i "$SSH_KEY" -o StrictHostKeyChecking=accept-new -o BatchMode=yes)
TARGET="$SSH_USER@$HOST"
remote() { ssh "${SSH_OPTS[@]}" "$TARGET" "$@"; }

print_nginx_steps() {
  cat <<MSG

The site listens on 127.0.0.1:$WEB_PORT on the server. Point the host's nginx at
it (once), then get the certificate:

  cd $REMOTE_DIR
  ./run.sh prod nginx-site | sudo tee /etc/nginx/sites-available/oneblue-data
  sudo ln -sf /etc/nginx/sites-available/oneblue-data /etc/nginx/sites-enabled/oneblue-data
  sudo nginx -t && sudo systemctl reload nginx
  sudo certbot --nginx -d ${DOMAIN:-<domain>}
MSG
}

echo "Release $COMMIT: $(sed -n 's/^commit_subject=//p' "$DIR/RELEASE")"
[ -f "$DIR/TESTED" ] && echo "Tested:  $(sed -n 's/^tested_at=//p' "$DIR/TESTED") with $(sed -n 's/^database=//p' "$DIR/TESTED")"

step "Checking $TARGET"
if ! remote true; then
  die "can't log in to $TARGET with $SSH_KEY.
       deploy.sh runs ssh non-interactively, so a key with a passphrase must be loaded
       in an SSH agent first:
         eval \"\$(ssh-agent)\" && ssh-add $SSH_KEY     # or point SSH_AUTH_SOCK at a running agent
       Check with: ssh -i $SSH_KEY $TARGET true"
fi
remote 'command -v docker >/dev/null && docker compose version >/dev/null && command -v rsync >/dev/null' \
  || die "the server needs docker, the docker compose plugin and rsync."
# An existing install keeps its proxy mode and port (from its .env).
if remote "test -f $REMOTE_DIR/.env"; then
  PROXY="$(remote "sed -n 's/^PROXY=//p' $REMOTE_DIR/.env")"
  WEB_PORT="$(remote "sed -n 's/^WEB_PORT=//p' $REMOTE_DIR/.env")"
  if [ -z "$PROXY" ]; then
    remote "grep -q '^COMPOSE_PROFILES=.*caddy' $REMOTE_DIR/.env" && PROXY=caddy || PROXY=nginx
  fi
  WEB_PORT="${WEB_PORT:-8090}"
fi
echo "Proxy: $PROXY$([ "$PROXY" = nginx ] && echo " (host nginx -> 127.0.0.1:$WEB_PORT)")"

# The ports this mode needs must be free, or already published by Docker (a
# previous deploy of this stack). Works without root (ss -p needs root).
if [ "$PROXY" = caddy ]; then ports="80 443"; else ports="$WEB_PORT"; fi
for port in $ports; do
  if remote "ss -ltnH '( sport = :$port )' | grep -q . && ! docker ps --format '{{.Ports}}' | grep -q ':$port->'"; then
    if [ "$PROXY" = caddy ]; then
      die "port $port on the server is in use by something other than Docker. Caddy needs 80/443;
       if the server's nginx serves other sites, deploy with PROXY=nginx instead."
    else
      die "port $port on the server is already in use. Pick a free one: WEB_PORT=<port> ./deploy.sh --apply"
    fi
  fi
done
remote "mkdir -p $REMOTE_DIR 2>/dev/null || { sudo mkdir -p $REMOTE_DIR && sudo chown \$(id -u):\$(id -g) $REMOTE_DIR; }"

step "Uploading images the server doesn't have yet"
# Compare by content fingerprint, not .Id: Docker's containerd and classic image
# stores give the same image different IDs (see image_fp in release.sh).
FP_FMT="'{{.Created}} {{json .RootFS.Layers}}'"
remote_fp() { remote "docker image inspect -f $FP_FMT '$1' 2>/dev/null | sha256sum | cut -d' ' -f1" || true; }
# The manifest is read on fd 3: ssh in the loop body would otherwise swallow stdin.
while read -r image id file fp <&3; do
  if [ -z "$fp" ]; then
    # Releases built before fingerprints: take it from the local copy of the image.
    fp="$(docker image inspect -f '{{.Created}} {{json .RootFS.Layers}}' "$image" 2>/dev/null | sha256sum | cut -d' ' -f1)" \
      || die "$image is not in local Docker; rebuild the release"
  fi
  if [ "$(remote "docker image inspect '$image' >/dev/null 2>&1 && echo yes" || true)" = yes ] && [ "$(remote_fp "$image")" = "$fp" ]; then
    echo "  $image: already on the server"
  else
    echo "  $image: uploading $(du -h "$DIR/images/$file" | cut -f1)"
    remote "gunzip | docker load -q" < "$DIR/images/$file"
    [ "$(remote_fp "$image")" = "$fp" ] || die "$image on the server does not match the release"
  fi
done 3< "$DIR/images/manifest"

step "Installing runtime files into $REMOTE_DIR"
# --delete removes files from older releases (including the previous source-based
# layout). Uploads, private files, backups and .env are excluded, so never deleted.
rsync -az --delete \
  --exclude=/.env --exclude=/backups/ --exclude=/private/ \
  --exclude=/docroot/sites/default/files/ \
  -e "ssh ${SSH_OPTS[*]}" "$DIR/runtime/" "$TARGET:$REMOTE_DIR/"
remote "cd $REMOTE_DIR && DOMAIN='$DOMAIN' SERVER_IP='$HOST' PROXY='$PROXY' WEB_PORT='$WEB_PORT' ./run.sh prod init-env"

step "Backing up the database (if running)"
remote "cd $REMOTE_DIR && if [ -n \"\$(./run.sh prod compose ps -q --status running db 2>/dev/null)\" ]; then ./run.sh prod db-export; else echo 'no running database, skipping'; fi"

step "Starting release $COMMIT"
remote "cd $REMOTE_DIR && ./run.sh prod up"

if ! remote "cd $REMOTE_DIR && ./run.sh prod is-installed"; then
  step "First deploy: the database is empty"
  cat <<MSG
The release is running, but there is no site in the database yet. Import the
data on the server, then run the deploy hooks and checks:

  ssh $TARGET
  cd $REMOTE_DIR
  ./run.sh prod db-import /path/to/db.sql.gz
  ./run.sh prod files-import /path/to/files.tar.gz   # or rsync into docroot/sites/default/files
  ./run.sh prod deploy-hooks && ./run.sh prod check
MSG
  [ "$PROXY" = nginx ] && print_nginx_steps
  exit 0
fi

step "Running deploy hooks"
remote "cd $REMOTE_DIR && ./run.sh prod deploy-hooks"

step "Smoke checks on the server"
remote "cd $REMOTE_DIR && ./run.sh prod check" || die "checks failed on the server. Inspect with: ssh $TARGET 'cd $REMOTE_DIR && ./run.sh prod logs'. Roll back with RELEASE=<previous commit> ./deploy.sh --apply"

step "Removing old app images (keeping the newest $KEEP_RELEASES and the running one)"
remote "docker image ls oneblue-data --format '{{.CreatedAt}}|{{.Repository}}:{{.Tag}}' | sort -r | cut -d'|' -f2 \
  | grep -vx '$APP_IMAGE' | tail -n +$KEEP_RELEASES | xargs -r docker rmi >/dev/null 2>&1; docker image prune -f >/dev/null" || true

step "Deployed $COMMIT"
if [ "$PROXY" = nginx ] && ! remote "grep -rqs '127.0.0.1:$WEB_PORT' /etc/nginx/sites-enabled/"; then
  print_nginx_steps
fi
if [ -n "$DOMAIN" ]; then echo "https://$DOMAIN"; else echo "http://$HOST"; fi
