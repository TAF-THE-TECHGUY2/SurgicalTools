#!/usr/bin/env bash
# ===========================================================================
# Surgical Devices ERP — instance inspection (READ ONLY)
#
# Reports what is actually on this box and whether it is ready to serve the
# app: Docker, the project, containers, database, migrations, config and the
# two things that must be settled before field use (HTTPS and the voucher
# sequence).
#
# This script changes NOTHING. It starts no containers, runs no migrations and
# writes no files. Safe to run on a live instance at any time.
#
#   scp -i ~/key.pem deploy/inspect.sh ubuntu@<IP>:~/     # if the repo isn't there yet
#   ssh -i ~/key.pem ubuntu@<IP> 'bash ~/inspect.sh'
#
# or, once the repo is synced:
#   cd ~/surgicaltool && ./deploy/inspect.sh
#
# Secret VALUES are never printed — only whether a key is set. The output is
# safe to copy and paste.
# ===========================================================================

# Deliberately NOT `set -e`: a missing tool must not abort the report.
set -uo pipefail

PROJECT="${PROJECT_DIR:-$HOME/surgicaltool}"
# If run from inside the repo, inspect that copy instead.
if [ -f "$(dirname "${BASH_SOURCE[0]}")/../docker-compose.yml" ]; then
  PROJECT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi

BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'; GRN=$'\033[32m'
YEL=$'\033[33m'; CYN=$'\033[36m'; OFF=$'\033[0m'

section() { printf '\n%s── %s %s\n' "$BOLD$CYN" "$1" "$OFF"; }
ok()      { printf '  %s✓%s %s\n' "$GRN" "$OFF" "$1"; }
warn()    { printf '  %s!%s %s\n' "$YEL" "$OFF" "$1"; }
bad()     { printf '  %s✗%s %s\n' "$RED" "$OFF" "$1"; }
info()    { printf '    %s%s%s\n' "$DIM" "$1" "$OFF"; }

ISSUES=0
note_issue() { ISSUES=$((ISSUES + 1)); }

printf '%s\n' "$BOLD== Surgical Devices ERP — instance report ==$OFF"
info "$(date '+%Y-%m-%d %H:%M:%S %Z')  ·  $(hostname)  ·  read-only"

# --------------------------------------------------------------------------
section "Machine"

if [ -r /etc/os-release ]; then
  . /etc/os-release
  ok "OS: ${PRETTY_NAME:-unknown}"
fi
ok "Kernel: $(uname -r)  ·  Arch: $(uname -m)  ·  CPUs: $(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo '?')"

MEM_MB=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo 2>/dev/null || echo 0)
if [ "$MEM_MB" -gt 0 ]; then
  if [ "$MEM_MB" -lt 1900 ]; then
    warn "RAM: ${MEM_MB} MB — the frontend build can OOM below ~2 GB"; note_issue
  else
    ok "RAM: ${MEM_MB} MB"
  fi
fi

SWAP_MB=$(awk '/SwapTotal/ {printf "%d", $2/1024}' /proc/meminfo 2>/dev/null || echo 0)
if [ "$SWAP_MB" -eq 0 ]; then
  warn "No swap — aws-bootstrap.sh adds some; builds may OOM without it"
else
  ok "Swap: ${SWAP_MB} MB"
fi

DISK_USE=$(df -h / | awk 'NR==2 {print $5" used of "$2}')
DISK_PCT=$(df / | awk 'NR==2 {gsub("%","",$5); print $5}')
if [ "${DISK_PCT:-0}" -gt 85 ]; then
  bad "Disk: $DISK_USE — low space breaks docker builds and Postgres"; note_issue
else
  ok "Disk: $DISK_USE"
fi

# --------------------------------------------------------------------------
section "Docker"

if command -v docker >/dev/null 2>&1; then
  ok "Docker: $(docker --version 2>/dev/null | sed 's/Docker version //')"

  if docker compose version >/dev/null 2>&1; then
    ok "Compose plugin: $(docker compose version --short 2>/dev/null)"
  else
    bad "Compose plugin missing — run: sudo ./deploy/aws-bootstrap.sh"; note_issue
  fi

  if ! docker info >/dev/null 2>&1; then
    bad "Cannot talk to the Docker daemon as $(whoami)."; note_issue
    info "Either the daemon is stopped, or you are not in the docker group."
    info "Fix: sudo usermod -aG docker \$USER  — then log out and back in."
    DOCKER_OK=false
  else
    DOCKER_OK=true
  fi
else
  bad "Docker is not installed — run: sudo ./deploy/aws-bootstrap.sh"; note_issue
  DOCKER_OK=false
fi

# --------------------------------------------------------------------------
section "Project"

if [ ! -d "$PROJECT" ]; then
  bad "No project at $PROJECT"; note_issue
  info "rsync the repo across (deploy/README.md section 2), or set"
  info "PROJECT_DIR=/path/to/repo before running this script."
  printf '\n%sStopping: nothing else can be checked without the project.%s\n' "$YEL" "$OFF"
  exit 1
fi

ok "Project: $PROJECT"

for required in docker-compose.yml deploy/docker-compose.prod.yml backend frontend; do
  if [ -e "$PROJECT/$required" ]; then
    ok "$required present"
  else
    bad "$required MISSING — the sync is incomplete"; note_issue
  fi
done

if [ -d "$PROJECT/.git" ] && command -v git >/dev/null 2>&1; then
  BRANCH=$(git -C "$PROJECT" branch --show-current 2>/dev/null)
  COMMIT=$(git -C "$PROJECT" log -1 --format='%h %s' 2>/dev/null)
  ok "Git: ${BRANCH:-detached} @ ${COMMIT:-unknown}"
  DIRTY=$(git -C "$PROJECT" status --porcelain 2>/dev/null | wc -l | tr -d ' ')
  [ "$DIRTY" != "0" ] && info "$DIRTY uncommitted change(s) on the instance"
else
  info "No git metadata (expected — the rsync excludes .git)"
fi

# Does this copy include the scan/voucher module?
if [ -f "$PROJECT/backend/app/Services/ScanExtractionService.php" ]; then
  ok "Scan & voucher module present in this copy"
else
  warn "This copy predates the scan/voucher module — re-sync before deploying"; note_issue
fi

# --------------------------------------------------------------------------
section "Configuration"

ENV_FILE="$PROJECT/.env"
if [ ! -f "$ENV_FILE" ]; then
  warn "No .env yet — deploy.sh generates one on first run"
  APP_URL=""
else
  ok ".env present"

  # Presence only. Values are never printed.
  env_val() { grep -E "^$1=" "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2-; }
  env_set() { [ -n "$(env_val "$1")" ]; }

  for key in APP_KEY DB_PASSWORD; do
    if env_set "$key"; then ok "$key is set ${DIM}(value hidden)${OFF}"
    else bad "$key is EMPTY — the app will not boot"; note_issue; fi
  done

  APP_URL=$(env_val APP_URL)
  [ -n "$APP_URL" ] && ok "APP_URL: $APP_URL" || { bad "APP_URL is empty"; note_issue; }

  MAILER=$(env_val MAIL_MAILER)
  case "${MAILER:-log}" in
    log|"") warn "MAIL_MAILER=${MAILER:-log} — emails go to the log, not to anyone"; note_issue ;;
    *)      ok "MAIL_MAILER: $MAILER" ;;
  esac

  DISK=$(env_val FILESYSTEM_DISK)
  if [ "${DISK:-local}" = "local" ]; then
    info "FILESYSTEM_DISK=local — PDFs/signatures live in a docker volume (back it up)"
  else
    ok "FILESYSTEM_DISK: $DISK"
  fi

  VOUCHER=$(env_val VOUCHER_START_NUMBER)
  if [ -z "$VOUCHER" ]; then
    warn "VOUCHER_START_NUMBER not set — falls back to the 130119 default"; note_issue
  else
    ok "VOUCHER_START_NUMBER: $VOUCHER ${DIM}(must clear the paper pads)${OFF}"
  fi

  if env_set ANTHROPIC_API_KEY; then
    ok "ANTHROPIC_API_KEY is set ${DIM}(value hidden)${OFF} — photo OCR available"
  else
    info "ANTHROPIC_API_KEY unset — barcode scanning works; photo OCR disabled"
  fi

  DIGEST=$(env_val STOCK_COUNT_DIGEST_MINUTES)
  info "STOCK_COUNT_DIGEST_MINUTES: ${DIGEST:-5 (default)}"

  if [ -n "$(env_val DOMAIN)" ]; then
    ok "DOMAIN: $(env_val DOMAIN) — TLS overlay is configured"
  fi
fi

# --------------------------------------------------------------------------
section "Secure context (camera + offline)"

case "$APP_URL" in
  https://*)
    ok "APP_URL is HTTPS — label scanning and offline capture can work"
    ;;
  "")
    warn "APP_URL unknown — cannot judge"
    ;;
  *)
    bad "APP_URL is not HTTPS: $APP_URL"; note_issue
    info "Browsers only expose the camera (getUserMedia) and service workers on"
    info "a secure origin. Barcode/label scanning WILL NOT WORK as configured."
    info "Fix: set DOMAIN + ACME_EMAIL + APP_URL=https://… and bring up the TLS"
    info "overlay (deploy/README.md section 5)."
    ;;
esac

# --------------------------------------------------------------------------
if [ "${DOCKER_OK:-false}" != true ]; then
  printf '\n%sSkipping container and database checks — Docker is unavailable.%s\n' "$YEL" "$OFF"
else

DC=(docker compose -f "$PROJECT/docker-compose.yml" -f "$PROJECT/deploy/docker-compose.prod.yml")
cd "$PROJECT" || exit 1

section "Containers"

RUNNING=$("${DC[@]}" ps --services --filter status=running 2>/dev/null)
if [ -z "$RUNNING" ]; then
  warn "Nothing is running — deploy with: ./deploy/deploy.sh"
  note_issue
else
  for svc in db backend frontend queue scheduler; do
    if printf '%s\n' "$RUNNING" | grep -qx "$svc"; then
      ok "$svc running"
    else
      case "$svc" in
        queue)     bad "queue NOT running — emails and the discrepancy digest will not send"; note_issue ;;
        scheduler) warn "scheduler not running — daily expiry/low-stock alerts will not fire"; note_issue ;;
        *)         bad "$svc NOT running"; note_issue ;;
      esac
    fi
  done

  # Caddy only exists when the TLS overlay is in use.
  if docker ps --format '{{.Names}}' 2>/dev/null | grep -qi caddy; then
    ok "caddy running — TLS terminating in front of the frontend"
  else
    info "No caddy container — the TLS overlay is not active"
  fi

  printf '\n'
  "${DC[@]}" ps 2>/dev/null | sed 's/^/    /'
fi

section "Database & migrations"

if printf '%s\n' "$RUNNING" | grep -qx db; then
  if "${DC[@]}" exec -T db pg_isready -U surgical -d surgical_erp >/dev/null 2>&1; then
    ok "PostgreSQL accepting connections"

    SIZE=$("${DC[@]}" exec -T db psql -U surgical -d surgical_erp -tAc \
      "SELECT pg_size_pretty(pg_database_size('surgical_erp'));" 2>/dev/null | tr -d ' \r')
    [ -n "$SIZE" ] && info "Database size: $SIZE"

    for t in users transfers stock_counts stock_count_scans; do
      N=$("${DC[@]}" exec -T db psql -U surgical -d surgical_erp -tAc \
        "SELECT count(*) FROM $t;" 2>/dev/null | tr -d ' \r')
      [ -n "$N" ] && info "$t: $N row(s)" || warn "table '$t' not found — migrations may be incomplete"
    done
  else
    bad "PostgreSQL is not accepting connections"; note_issue
  fi
fi

if printf '%s\n' "$RUNNING" | grep -qx backend; then
  PENDING=$("${DC[@]}" exec -T backend php artisan migrate:status 2>/dev/null | grep -c "Pending" || true)
  if [ "${PENDING:-0}" -gt 0 ]; then
    bad "$PENDING migration(s) PENDING — run ./deploy/deploy.sh"; note_issue
    "${DC[@]}" exec -T backend php artisan migrate:status 2>/dev/null \
      | grep "Pending" | sed 's/^/      /'
  else
    ok "All migrations applied"
  fi

  section "Delivery-voucher sequence"
  "${DC[@]}" exec -T backend php artisan surgical:voucher-status 2>/dev/null | sed 's/^/  /' \
    || warn "Could not run the voucher check (older copy of the code?)"

  section "Scanning readiness"
  if "${DC[@]}" exec -T backend php artisan surgical:test-label-ocr \
      --barcode='(240)12012029(10)11129D250603(17)270603' >/dev/null 2>&1; then
    ok "GS1 barcode parser working"
  else
    warn "Barcode parse check failed — the catalogue may just be empty"
  fi

  section "Recent errors"
  ERRS=$("${DC[@]}" logs --tail=400 backend 2>/dev/null \
    | grep -iE "exception|fatal|ERROR" | tail -5)
  if [ -z "$ERRS" ]; then
    ok "No errors in the last 400 backend log lines"
  else
    warn "Recent backend errors:"
    printf '%s\n' "$ERRS" | sed 's/^/      /'
  fi
fi

section "Listening ports"
if command -v ss >/dev/null 2>&1; then
  ss -tlnp 2>/dev/null | awk 'NR==1 || /:80|:443|:8000|:5432/' | sed 's/^/    /'
else
  info "ss not available"
fi

fi  # DOCKER_OK

# --------------------------------------------------------------------------
section "Summary"

if [ "$ISSUES" -eq 0 ]; then
  printf '  %s✓ Nothing blocking found.%s\n' "$GRN$BOLD" "$OFF"
else
  printf '  %s%s item(s) above need attention.%s\n' "$YEL$BOLD" "$ISSUES" "$OFF"
fi

cat <<'NEXT'

  Two things this script cannot decide for you:
    1. The voucher start number — ask operations for the highest number on any
       pad issued or still out with a rep, then:
         docker compose -f docker-compose.yml -f deploy/docker-compose.prod.yml \
           exec backend php artisan surgical:voucher-status --paper-high=NNNNNN
    2. OCR quality on real packaging — photograph one real label and run:
         ... exec backend php artisan surgical:test-label-ocr /path/to/label.jpg

NEXT
