#!/usr/bin/env bash
# ===========================================================================
# Surgical Devices ERP — release a branch to an existing EC2 deployment.
# Run from your machine, not the server:
#
#   ./deploy/release.sh ubuntu@<ELASTIC_IP> -i ~/path/to/key.pem
#   ./deploy/release.sh ubuntu@<ELASTIC_IP> -i key.pem --branch main
#
# What it does, in order:
#   1. Ships the code. If the server directory is a git clone it checks out
#      origin/<branch>; otherwise it rsyncs your local checkout (which must be
#      on <branch> with nothing uncommitted).
#   2. Backs up Postgres to ~/backups on the server before any migration.
#   3. Rebuilds and restarts the stack, with the HTTPS (Caddy) overlay when
#      the server's .env sets DOMAIN — deploy.sh on its own drops it.
#   4. Runs migrations and FAILS on error (deploy.sh swallows it).
#   5. Restarts the queue worker, then smoke-tests the API.
#
# First install is still ./deploy/deploy.sh --seed on the server (README §4).
# Never re-seed a live database: it resets customised role permissions.
#
# Options:
#   -i, --key <file>       SSH private key
#   -b, --branch <name>    branch to release (default: your current branch)
#   -d, --dir <path>       app directory on the server (default: /home/ubuntu/surgicaltool)
#       --no-backup        skip the database backup (not recommended)
#   -y, --yes              do not ask for confirmation
# ===========================================================================
set -euo pipefail

cd "$(dirname "$0")/.."

HOST=""
KEY=""
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
REMOTE_DIR="/home/ubuntu/surgicaltool"
BACKUP=true
ASSUME_YES=false

while [ $# -gt 0 ]; do
  case "$1" in
    -i|--key)    KEY="$2"; shift 2 ;;
    -b|--branch) BRANCH="$2"; shift 2 ;;
    -d|--dir)    REMOTE_DIR="$2"; shift 2 ;;
    --no-backup) BACKUP=false; shift ;;
    -y|--yes)    ASSUME_YES=true; shift ;;
    -h|--help)   sed -n '2,31p' "$0"; exit 0 ;;
    -*)          echo "Unknown option: $1" >&2; exit 2 ;;
    *)           HOST="$1"; shift ;;
  esac
done

if [ -z "$HOST" ]; then
  echo "Usage: $0 ubuntu@<ELASTIC_IP> [-i key.pem] [--branch <name>]" >&2
  exit 2
fi

SSH=(ssh -o ConnectTimeout=15)
[ -n "$KEY" ] && SSH+=(-i "$KEY")

# --- Local checks -----------------------------------------------------------
git fetch --quiet origin "$BRANCH" 2>/dev/null || true
LOCAL_SHA="$(git rev-parse --short HEAD)"
REMOTE_SHA="$(git rev-parse --short "origin/$BRANCH" 2>/dev/null || echo '')"

echo "==> Releasing '$BRANCH' to $HOST:$REMOTE_DIR"
"${SSH[@]}" "$HOST" true || { echo "Cannot SSH to $HOST." >&2; exit 1; }

if "${SSH[@]}" "$HOST" "test -d '$REMOTE_DIR/.git'"; then
  MODE=git
  if [ -z "$REMOTE_SHA" ]; then
    echo "Branch '$BRANCH' is not on GitHub. Push it first: git push -u origin $BRANCH" >&2
    exit 1
  fi
  echo "    Code: git — the server checks out origin/$BRANCH ($REMOTE_SHA)"
  if [ "$(git rev-parse --abbrev-ref HEAD)" = "$BRANCH" ] && [ "$LOCAL_SHA" != "$REMOTE_SHA" ]; then
    echo "    ⚠  Your local $BRANCH ($LOCAL_SHA) differs from GitHub ($REMOTE_SHA) — unpushed commits will NOT ship."
  fi
else
  MODE=rsync
  if [ "$(git rev-parse --abbrev-ref HEAD)" != "$BRANCH" ]; then
    echo "The server has no git clone, so your local checkout is copied — but you are on" >&2
    echo "'$(git rev-parse --abbrev-ref HEAD)', not '$BRANCH'. Run: git checkout $BRANCH" >&2
    exit 1
  fi
  if [ -n "$(git status --porcelain)" ]; then
    echo "Uncommitted changes would be deployed. Commit or stash them first:" >&2
    git status --short >&2
    exit 1
  fi
  echo "    Code: rsync of your local checkout ($LOCAL_SHA)"
fi

if [ "$ASSUME_YES" != true ]; then
  read -r -p "    Continue? [y/N] " answer
  [[ "$answer" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }
fi

# --- Ship the code (rsync mode) --------------------------------------------
if [ "$MODE" = rsync ]; then
  echo "==> Copying code"
  RSYNC_SSH="ssh -o ConnectTimeout=15"
  [ -n "$KEY" ] && RSYNC_SSH+=" -i $KEY"
  # Same excludes as deploy/README.md §2. No --delete: server-only files stay.
  rsync -az --exclude node_modules --exclude vendor --exclude dist \
    --exclude '.git' --exclude '.env' --exclude 'backend/database/database.sqlite' \
    --exclude 'backend/storage/logs' --exclude 'backend/storage/framework' --exclude '.claude' \
    -e "$RSYNC_SSH" ./ "$HOST:$REMOTE_DIR/"
fi

# --- Everything else runs on the server ------------------------------------
"${SSH[@]}" "$HOST" bash -s -- "$REMOTE_DIR" "$MODE" "$BRANCH" "$BACKUP" <<'REMOTE'
set -euo pipefail
REMOTE_DIR="$1"; MODE="$2"; BRANCH="$3"; BACKUP="$4"
cd "$REMOTE_DIR"

[ -f .env ] || { echo "No .env in $REMOTE_DIR — first install? Run ./deploy/deploy.sh --seed there." >&2; exit 1; }

if [ "$MODE" = git ]; then
  echo "==> Checking out origin/$BRANCH"
  if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "The server copy has local edits; refusing to overwrite them:" >&2
    git status --short --untracked-files=no >&2
    exit 1
  fi
  git fetch --quiet origin "$BRANCH"
  git checkout --quiet -B "$BRANCH" "origin/$BRANCH"
  echo "    Now at $(git log -1 --format='%h %s')"
fi

DC=(docker compose -f docker-compose.yml -f deploy/docker-compose.prod.yml)
# Keep HTTPS: without this overlay the frontend grabs port 80 from Caddy.
if grep -qE '^DOMAIN=.+' .env || docker ps --format '{{.Names}}' | grep -q caddy; then
  DC+=(-f deploy/docker-compose.tls.yml)
  echo "    HTTPS overlay: on"
fi

# --- Backup before migrating -------------------------------------------------
if [ "$BACKUP" = true ] && "${DC[@]}" ps --status running --services 2>/dev/null | grep -qx db; then
  mkdir -p "$HOME/backups"
  FILE="$HOME/backups/pre-release-$(date +%F-%H%M%S).sql.gz"
  echo "==> Backing up the database → $FILE"
  "${DC[@]}" exec -T db pg_dump -U surgical surgical_erp | gzip > "$FILE"
  # An empty dump means the backup failed; do not migrate without one.
  if [ "$(gzip -dc "$FILE" | head -c 1000 | wc -c)" -lt 100 ]; then
    echo "Backup looks empty — stopping before any migration." >&2
    exit 1
  fi
  ls -1t "$HOME"/backups/pre-release-*.sql.gz | tail -n +11 | xargs -r rm --   # keep the last 10
else
  echo "==> Skipping backup (disabled, or the database is not running yet)"
fi

# --- Build and start ------------------------------------------------------------
echo "==> Building images and restarting (a few minutes)"
"${DC[@]}" up -d --build

echo "==> Waiting for the API container"
for _ in $(seq 1 40); do
  "${DC[@]}" exec -T backend php artisan --version >/dev/null 2>&1 && break
  sleep 3
done

echo "==> Migrating"
"${DC[@]}" exec -T backend php artisan migrate --force
"${DC[@]}" exec -T backend php artisan queue:restart >/dev/null 2>&1 || true

# --- Checks -----------------------------------------------------------------
echo "==> Checks"
PENDING="$("${DC[@]}" exec -T backend php artisan migrate:status 2>/dev/null | grep -c 'Pending' || true)"
[ "$PENDING" = "0" ] && echo "    ✓ No pending migrations" || { echo "    ✗ $PENDING migration(s) still pending" >&2; exit 1; }

TEMPLATES="$("${DC[@]}" exec -T backend php artisan tinker --execute='echo App\Models\SupplierLabelTemplate::count();' 2>/dev/null | tr -dc '0-9')"
echo "    ✓ Supplier label templates: ${TEMPLATES:-?}"

APP_URL="$(grep '^APP_URL=' .env | cut -d= -f2- | tr -d '"')"
CODE="$(curl -ks -o /dev/null -w '%{http_code}' "$APP_URL/api/meta/options" || echo 000)"
if [ "$CODE" = "200" ] || [ "$CODE" = "401" ]; then
  echo "    ✓ API answering at $APP_URL (HTTP $CODE)"
else
  echo "    ✗ API returned HTTP $CODE at $APP_URL — check: ${DC[*]} logs --tail=100 backend" >&2
  exit 1
fi

if ! grep -qE '^MAIL_ACCOUNTS_ADDRESS=.+' .env; then
  echo "    ℹ  Accounts address not in .env — set it in Stock Counts → Settings,"
  echo "       otherwise variance reports go to MAIL_OFFICE_ADDRESS."
fi
grep -qE '^MAIL_MAILER=log' .env && echo "    ⚠  MAIL_MAILER=log — emails are written to the log, not sent."
case "$APP_URL" in https://*) ;; *) echo "    ⚠  $APP_URL is not HTTPS — camera scanning will not work." ;; esac

echo ""
"${DC[@]}" ps --format 'table {{.Service}}\t{{.Status}}'
echo ""
echo "✅ Released. Open: $APP_URL"
REMOTE
