# ONE-BLUE Database Management System

DKAN (Drupal 10) site, run with Docker Compose. Dev and prod share one
Dockerfile, the same PHP/Apache/MariaDB versions and the same paths.

| | dev | prod |
|---|---|---|
| Code | bind-mounted from the repo | baked into the image |
| Uploaded files | `docroot/sites/default/files`, `private/` | same paths, in `/opt/oneblue-data` |
| Database | Docker volume `oneblue-data_db-data` | Docker volume `oneblue-data_db-data` |
| Extras | Xdebug (off by default), Mailpit | HTTPS via the host's nginx (or Caddy) |
| URL | http://localhost:8080 | https://data.one-blue.eu |

Services: `web` (PHP 8.4 + Apache), `db` (MariaDB 11.4), `cron` (runs
`drush cron` every `CRON_INTERVAL` seconds, which also processes DKAN's
harvest and datastore queues), plus `mailpit` (dev) and optionally `caddy` (prod).

## Local development

Requirements: Docker with the compose plugin.

```bash
./run.sh                          # build + start dev (dev is the default profile)
./run.sh dev db-import ../dbs/dkan/<dump>.sql.gz
./run.sh dev drush uli            # one-time admin login link
```

### Copying prod data to dev

```bash
HOST=<server-ip> SSH_KEY=~/.ssh/<key> ./run.sh sync-from-prod         # files + DB
HOST=<server-ip> SSH_KEY=~/.ssh/<key> ./run.sh sync-from-prod files   # files only
HOST=<server-ip> SSH_KEY=~/.ssh/<key> ./run.sh sync-from-prod db      # DB only
```

Files are mirrored with rsync (only changes are transferred; local files that
aren't on prod are deleted). The DB is dumped on the server, saved to
`backups/prod-db-<timestamp>.sql.gz` and imported, replacing the dev DB.
Because prod uses the same paths, a plain rsync also works:

```bash
rsync -avz --delete --exclude=/php/ --exclude=/css/ --exclude=/js/ -e "ssh -i ~/.ssh/<key>" \
  root@<server-ip>:/opt/oneblue-data/docroot/sites/default/files/ docroot/sites/default/files/
```

Other commands: `./run.sh help`. Common ones:

```bash
./run.sh dev drush cr
./run.sh dev composer require drupal/foo
./run.sh dev logs web
./run.sh dev sh
./run.sh dev frontend-build       # clone (if missing) + build docroot/frontend
./run.sh dev down
```

Xdebug: set `XDEBUG_MODE=debug` in `.env`, then `./run.sh dev up`.

## Releasing and deploying

All preparation happens on your machine. The server is only touched by the
final `deploy.sh`, and it receives a finished release: it never builds, pulls
or runs composer/npm, and no source code is copied to it.

```bash
git commit ...                                     # releases are built from commits
./release.sh build                                 # -> dist/<commit>/
./run.sh sync-from-prod db                         # optional: fresh prod DB to test with
./release.sh test                                  # run the release locally as prod
HOST=<server-ip> SSH_KEY=~/.ssh/<key> ./deploy.sh --apply   # ship the tested release
```

**`./release.sh build`** builds the prod image from the committed code
(`oneblue-data:<commit>`). It resolves `FRONTEND_REF` to a frontend commit and
pins it, pulls the exact MariaDB and Caddy images, and saves everything to
`dist/<commit>/`: the images, plus the runtime files the server needs
(compose files, `run.sh`, `.env.images` pinned to this release, Caddy and
MariaDB config).

**`./release.sh test`** installs the release into `.release-test/` (a stand-in
for `/opt/oneblue-data`). It loads the images from the release archives and
starts the release exactly as the server would. Then it imports a DB (the
newest `backups/prod-db-*.sql.gz`, or `DB=<dump>`, else a dump of dev), copies
the dev files, and runs the same deploy hooks and smoke checks the server runs.
If everything passes, the release is marked tested. The test install keeps
running at http://localhost:8081; `./release.sh stop` removes it.

**`./deploy.sh`** without `--apply` never contacts the server. It shows
where the release for your commit stands (committed, built, tested) and tells
you the next step. **`./deploy.sh --apply`** deploys. It refuses releases that haven't passed the test
(`ALLOW_UNTESTED=1` overrides). It uploads only the images the server doesn't
have yet and installs the runtime files into `/opt/oneblue-data`, never
touching `docroot/sites/default/files`, `private/`, `backups/` or `.env`. Then
it backs up the DB, starts the release, runs the deploy hooks
(`drush updatedb`, config import when `config/sync` has config, cache rebuild)
and the smoke checks.

- Deploy an older release / roll back: `RELEASE=<commit> ./deploy.sh --apply`. The
  server keeps the last 3 app images, so a rollback usually uploads nothing.
- `./release.sh list` shows releases; `./release.sh prune [N]` keeps the newest N.
- `./run.sh prod check` reruns the smoke checks on the server at any time.

On the first deploy, `/opt/oneblue-data/.env` is generated on the server with
random secrets, the domain (`DOMAIN`, default `data.one-blue.eu`), matching
`TRUSTED_HOSTS` and the proxy mode. Later deploys never overwrite that file. To change it (for
example to set `SMTP_*` for outgoing mail), edit it on the server, then run
`./run.sh prod up` there.

The server needs docker, the docker compose plugin and rsync, and DNS for
`data.one-blue.eu` must point at it for the certificate to be issued. Create
`/opt/oneblue-data` once, owned by the deploy user, if that user isn't root:
`sudo mkdir -p /opt/oneblue-data && sudo chown <user>: /opt/oneblue-data`.

### HTTPS: host nginx (default) or Caddy

Chosen with `PROXY` on the first deploy and stored in the server's `.env`:

- **`PROXY=nginx`** (default; the server's nginx also hosts other sites): the
  web container listens only on `127.0.0.1:WEB_PORT` (default 8090), Caddy is
  not started, and the host's nginx and Certbot terminate HTTPS. Set up the
  nginx site once; `deploy.sh` prints these steps when it's missing:

  ```bash
  cd /opt/oneblue-data
  ./run.sh prod nginx-site | sudo tee /etc/nginx/sites-available/oneblue-data
  sudo ln -sf /etc/nginx/sites-available/oneblue-data /etc/nginx/sites-enabled/oneblue-data
  sudo nginx -t && sudo systemctl reload nginx
  sudo certbot --nginx -d data.one-blue.eu
  ```

- **`PROXY=caddy`**: Caddy owns ports 80/443 and gets its own certificate.
  Only for a server where nothing else needs those ports.

`./release.sh test` uses the same mode (`TEST_PROXY`, default `nginx`).

### First deploy to an empty server

The first `./deploy.sh --apply` starts the release with an empty database, then
stops and prints these steps. Put the uploads in
`/opt/oneblue-data/docroot/sites/default/files` (rsync, or `files-import`) and,
on the server:

```bash
cd /opt/oneblue-data
./run.sh prod db-import /path/to/db.sql.gz
./run.sh prod deploy-hooks && ./run.sh prod check
```

## Layout

- `docker/`: Dockerfile, PHP/Apache/MariaDB/Caddy config, the host nginx site template, entrypoint, cron loop
- `.env.images`: every Docker image name and version (the only place to bump them)
- `docker-compose.yaml` plus `docker-compose.dev.yaml` / `docker-compose.prod.yaml`
- `docroot/sites/default/settings.php`: env-driven, committed (no secrets)
- `config/sync`: Drupal config export (`./run.sh dev drush cex`)
- `release.sh` / `deploy.sh`: local release build and test / ship to the server
- `dist/` (ignored): built releases; `.release-test/` (ignored): local release test install
- `docroot/frontend`: git clone of [one-blue-frontend](https://github.com/ONE-BLUE-EU/one-blue-frontend), ignored by this repo. `./run.sh dev frontend-build` clones and builds it. Releases build `FRONTEND_REF` from `.env.images` (default `main`), pinned to the resolved commit.
