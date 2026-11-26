#!/usr/bin/env bash
# Build and test production releases locally. Nothing here touches the server.
#
#   ./release.sh build          Build a release of the committed HEAD into dist/<commit>/
#   ./release.sh test [commit]  Install the release into .release-test/ (a stand-in for
#                               /opt/oneblue-data), start it exactly as the server would,
#                               load prod data, run the deploy hooks and smoke checks.
#                               On success the release is marked TESTED.
#   ./release.sh stop           Stop and remove the local release test (incl. its DB)
#   ./release.sh list           List releases in dist/
#   ./release.sh prune [N]      Delete all but the newest N releases (default 3) and
#                               their local app images
#
# test options (env):
#   DB=<dump.sql.gz>   DB to test with. Default: newest backups/prod-db-*.sql.gz
#                      (from ./run.sh sync-from-prod), else a dump of the dev DB.
#   TEST_PROXY=nginx   proxy mode to test, as on the server (nginx: the site is
#                      served straight from the web container; caddy: via Caddy)
#   TEST_HTTP_PORT=8081, TEST_HTTPS_PORT=8443
#
# A release contains everything the server needs and nothing else:
#   dist/<commit>/
#     RELEASE        manifest: commit, frontend commit, images and their IDs
#     TESTED         written by "test" when all checks pass
#     images/*.tar.gz  docker save of the app, MariaDB and Caddy images
#     runtime/       files installed into /opt/oneblue-data (compose files,
#                    run.sh, .env.images pinned to this release, Caddy/MariaDB config)
set -euo pipefail

cd "$(dirname "$(readlink -f "$0")")"

die() { echo "error: $*" >&2; exit 1; }
step() { echo; echo "==> $*"; }

TEST_DIR=.release-test
TEST_PROJECT=oneblue-data-releasetest
compress() { if command -v pigz >/dev/null; then pigz -c; else gzip -c; fi; }

set -a; . ./.env.images; set +a

# Image identity across machines: .Id differs between Docker's containerd image
# store (manifest digest) and the classic store (config digest), even for the same
# image. The fingerprint hashes the build time and the layer digests instead, which
# are identical in both stores. Must match the remote command in deploy.sh exactly.
image_fp() { docker image inspect -f '{{.Created}} {{json .RootFS.Layers}}' "$1" | sha256sum | cut -d' ' -f1; }

head_commit() { git rev-parse --short=12 HEAD; }

release_dir() {
  local c="${1:-$(head_commit)}"
  [ -f "dist/$c/RELEASE" ] || die "no release dist/$c (run: ./release.sh build)"
  echo "dist/$c"
}

# Remove the test install. Files in it may be owned by the container's www-data
# (uid 33), so delete them from inside a container.
clean_test_dir() {
  if [ -f "$TEST_DIR/run.sh" ] && [ -f "$TEST_DIR/.env" ]; then
    (cd "$TEST_DIR" && ./run.sh prod down -v --remove-orphans >/dev/null 2>&1) || true
  fi
  if [ -d "$TEST_DIR" ]; then
    docker run --rm -v "$PWD/$TEST_DIR:/t" --entrypoint sh "${MARIADB_IMAGE}" \
      -c 'rm -rf /t/* /t/.[!.]* 2>/dev/null; true'
    rmdir "$TEST_DIR" 2>/dev/null || true
  fi
}

cmd_build() {
  [ -z "$(git status --porcelain --untracked-files=no)" ] || die "uncommitted changes. A release is built from a commit; commit or stash first."
  local commit dir src fe_ref fe_commit app_image
  commit="$(head_commit)"
  dir="dist/$commit"
  if [ -f "$dir/RELEASE" ] && [ -z "${REBUILD:-}" ]; then
    echo "Release $dir already exists (REBUILD=1 to rebuild)."; return
  fi
  rm -rf "$dir"; mkdir -p "$dir/images" "$dir/runtime"

  step "Exporting commit $commit"
  src="$(mktemp -d)"; SRC_TMP="$src"; trap 'rm -rf "${SRC_TMP:-}"' EXIT
  # Everything below uses this exact commit, even if HEAD moves during the build.
  git archive "$commit" | tar -x -C "$src"

  fe_ref="$FRONTEND_REF"
  if [[ ! "$fe_ref" =~ ^[0-9a-f]{40}$ ]]; then
    fe_commit="$(git ls-remote "$FRONTEND_REPO" "$fe_ref" | awk 'NR==1{print $1}')"
    [ -n "$fe_commit" ] || die "could not resolve frontend ref '$fe_ref' in $FRONTEND_REPO"
  else
    fe_commit="$fe_ref"
  fi
  echo "Frontend: $FRONTEND_REPO @ $fe_ref -> $fe_commit"

  step "Pulling service images"
  docker pull -q "$MARIADB_IMAGE"
  docker pull -q "$CADDY_IMAGE"

  app_image="oneblue-data:$commit"
  step "Building $app_image"
  docker build --pull --target prod -f "$src/docker/Dockerfile" \
    --build-arg PHP_IMAGE="$PHP_IMAGE" \
    --build-arg NODE_IMAGE="$NODE_IMAGE" \
    --build-arg COMPOSER_IMAGE="$COMPOSER_IMAGE" \
    --build-arg PHP_EXT_INSTALLER_IMAGE="$PHP_EXT_INSTALLER_IMAGE" \
    --build-arg FRONTEND_REPO="$FRONTEND_REPO" \
    --build-arg FRONTEND_REF="$fe_commit" \
    -t "$app_image" "$src"

  step "Assembling runtime files"
  # docroot/sites/default/ is where prod's uploads live; shipping the (empty) path
  # stops rsync --delete from trying to remove it on every deploy.
  mkdir -p "$dir/runtime/docker/caddy" "$dir/runtime/docker/mariadb" "$dir/runtime/docroot/sites/default"
  cp "$src/docker-compose.yaml" "$src/docker-compose.prod.yaml" "$src/run.sh" "$dir/runtime/"
  cp "$src/docker/caddy/Caddyfile" "$dir/runtime/docker/caddy/"
  mkdir -p "$dir/runtime/docker/nginx"
  cp "$src/docker/nginx/site.conf.template" "$dir/runtime/docker/nginx/"
  cp "$src/docker/mariadb/dkan.cnf" "$dir/runtime/docker/mariadb/"
  sed -e "s|^APP_IMAGE_PROD=.*|APP_IMAGE_PROD=$app_image|" \
      -e "s|^FRONTEND_REF=.*|FRONTEND_REF=$fe_commit|" \
      "$src/.env.images" > "$dir/runtime/.env.images"

  step "Saving images"
  : > "$dir/images/manifest"
  for image in "$app_image" "$MARIADB_IMAGE" "$CADDY_IMAGE"; do
    local file id fp
    file="$(echo "$image" | tr '/:' '__').tar.gz"
    id="$(docker image inspect -f '{{.Id}}' "$image")"
    fp="$(image_fp "$image")"
    docker save "$image" | compress > "$dir/images/$file"
    echo "$image $id $file $fp" >> "$dir/images/manifest"
    printf '  %-40s %s\n' "$image" "$(du -h "$dir/images/$file" | cut -f1)"
  done

  {
    echo "commit=$commit"
    echo "commit_subject=$(git log -1 --format=%s "$commit")"
    echo "built_at=$(date -u +%FT%TZ)"
    echo "frontend_repo=$FRONTEND_REPO"
    echo "frontend_commit=$fe_commit"
    sed 's/^/image=/' "$dir/images/manifest"
  } > "$dir/RELEASE"
  cp "$dir/RELEASE" "$dir/runtime/RELEASE"

  step "Release $dir is ready ($(du -sh "$dir" | cut -f1)). Next: ./release.sh test (runs the prod release locally)"
}

cmd_test() {
  local dir commit dump abs_dump
  dir="$(release_dir "${1:-}")"
  commit="$(basename "$dir")"
  rm -f "$dir/TESTED"

  step "Removing any previous release test"
  clean_test_dir

  step "Verifying and loading the release images"
  while read -r image id file fp; do
    gzip -t "$dir/images/$file" || die "corrupt image archive: $file"
    gunzip -c "$dir/images/$file" | docker load -q >/dev/null
    if [ -n "$fp" ]; then
      [ "$(image_fp "$image")" = "$fp" ] || die "$image does not match the release manifest"
    else
      [ "$(docker image inspect -f '{{.Id}}' "$image")" = "$id" ] || die "$image does not match the release manifest"
    fi
    echo "  $image"
  done < "$dir/images/manifest"

  step "Installing the release into $TEST_DIR/ (as deploy.sh does on the server)"
  mkdir -p "$TEST_DIR"
  rsync -a --delete --exclude=/.env --exclude=/backups/ --exclude=/private/ \
    --exclude=/docroot/sites/default/files/ "$dir/runtime/" "$TEST_DIR/"
  (cd "$TEST_DIR" && DOMAIN= SERVER_IP= COMPOSE_PROJECT_NAME="$TEST_PROJECT" \
    PROXY="${TEST_PROXY:-nginx}" WEB_PORT="$( [ "${TEST_PROXY:-nginx}" = nginx ] && echo "${TEST_HTTP_PORT:-8081}" || echo 8091)" \
    HTTP_PORT="${TEST_HTTP_PORT:-8081}" HTTPS_PORT="${TEST_HTTPS_PORT:-8443}" ./run.sh prod init-env)

  step "Copying dev files into the test install"
  mkdir -p "$TEST_DIR/docroot/sites/default/files" "$TEST_DIR/private"
  [ -d docroot/sites/default/files ] && rsync -a --exclude=/php/ --exclude=/css/ --exclude=/js/ \
    docroot/sites/default/files/ "$TEST_DIR/docroot/sites/default/files/"
  [ -d private ] && rsync -a private/ "$TEST_DIR/private/"

  step "Starting the release (no build, no pull)"
  (cd "$TEST_DIR" && ./run.sh prod up)

  dump="${DB:-$(ls -t backups/prod-db-*.sql.gz 2>/dev/null | head -1 || true)}"
  if [ -z "$dump" ]; then
    echo "No prod dump in backups/ (./run.sh sync-from-prod db makes one); using a dump of the dev DB."
    dump="backups/dev-db-$(date +%Y%m%d-%H%M%S).sql.gz"
    ./run.sh dev db-export "$dump"
  fi
  abs_dump="$(readlink -f "$dump")"
  step "Loading $dump"
  (cd "$TEST_DIR" && ./run.sh prod db-import "$abs_dump")

  step "Running the deploy hooks (the same ones deploy.sh runs on the server)"
  (cd "$TEST_DIR" && ./run.sh prod deploy-hooks)

  step "Smoke checks"
  (cd "$TEST_DIR" && ./run.sh prod check)

  {
    echo "tested_at=$(date -u +%FT%TZ)"
    echo "database=$dump"
  } > "$dir/TESTED"
  step "Release $commit PASSED and is marked tested."
  echo "The test install is still running: http://localhost:${TEST_HTTP_PORT:-8081}"
  echo "  (cd $TEST_DIR && ./run.sh prod drush uli --uri=http://localhost:${TEST_HTTP_PORT:-8081})"
  echo "Stop it with ./release.sh stop. Deploy with: HOST=<ip> SSH_KEY=<key> ./deploy.sh --apply"
}

# Release dirs, newest build first (by built_at in RELEASE, not file times).
releases_newest_first() {
  local f
  for f in dist/*/RELEASE; do
    [ -f "$f" ] && printf '%s %s\n' "$(sed -n 's/^built_at=//p' "$f")" "$(dirname "$f")"
  done | sort -r | cut -d' ' -f2
}

cmd_list() {
  local d
  for d in $(releases_newest_first); do
    printf '%s  %-8s %-6s %s\n' "$(basename "$d")" \
      "$([ -f "$d/TESTED" ] && echo tested || echo untested)" \
      "$(du -sh "$d" | cut -f1)" "$(sed -n 's/^commit_subject=//p' "$d/RELEASE")"
  done
}

cmd_prune() {
  local keep="${1:-3}" d c tested
  tested="$( [ -f "$TEST_DIR/RELEASE" ] && sed -n 's/^commit=//p' "$TEST_DIR/RELEASE" || true)"
  for d in $(releases_newest_first | tail -n +"$((keep + 1))"); do
    c="$(basename "$d")"
    [ "$c" = "$tested" ] && { echo "keeping $c (running in the release test)"; continue; }
    rm -rf "$d"
    docker rmi "oneblue-data:$c" >/dev/null 2>&1 || true
    echo "removed $c"
  done
}

case "${1:-}" in
  build) cmd_build ;;
  test)  shift; cmd_test "${1:-}" ;;
  stop)  clean_test_dir; echo "Release test stopped and removed." ;;
  list)  cmd_list ;;
  prune) shift; cmd_prune "${1:-}" ;;
  *) sed -n '2,25p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
