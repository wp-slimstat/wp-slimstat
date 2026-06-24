#!/usr/bin/env bash
# tests/docker/build-pro.sh — produce build/wp-slimstat-pro.zip (php-scoper) so
# the matrix installs the real shipped Pro artifact (scoped vendor), not source.
# Requires the wp-slimstat-pro sibling checkout + Docker. Idempotent.
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

OUT="$HARNESS_DIR/build/wp-slimstat-pro.zip"
PRO_SRC="$(cd "$PLUGIN_SRC/.." && pwd)/wp-slimstat-pro"
mkdir -p "$HARNESS_DIR/build"

[ -d "$PRO_SRC" ] || { err "wp-slimstat-pro checkout not found at $PRO_SRC"; exit 1; }

# Reuse the prior zip only if the Pro source is unchanged. Staleness is keyed on
# the checkout's git HEAD + working-tree dirty state, so editing Pro forces a
# rebuild (a bare `[ -f "$OUT" ]` would test a stale artifact). Non-git source
# → always rebuild rather than trust a possibly-stale zip.
STAMP="$OUT.stamp"
if SRC_ID="$(git -C "$PRO_SRC" rev-parse HEAD 2>/dev/null)"; then
  SRC_ID="${SRC_ID}-$(git -C "$PRO_SRC" status --porcelain 2>/dev/null | shasum | cut -d' ' -f1)"
  if [ -f "$OUT" ] && [ "$(cat "$STAMP" 2>/dev/null)" = "$SRC_ID" ]; then
    log "Pro zip up-to-date for current source: $OUT"; exit 0
  fi
else
  SRC_ID=""
fi

STAGE="$(mktemp -d)/wp-slimstat-pro"; mkdir -p "$STAGE"
rsync -a --exclude '.git' --exclude 'node_modules' --exclude 'build/wp-slimstat-pro' \
      --exclude 'build/*.zip' "$PRO_SRC/" "$STAGE/" >/dev/null

cat > "$(dirname "$STAGE")/build.sh" <<'SH'
#!/usr/bin/env bash
set -e
cd /work/wp-slimstat-pro
apt-get update -qq && apt-get install -y -qq git unzip zip libzip-dev >/dev/null 2>&1
docker-php-ext-install zip >/dev/null 2>&1 || true
# Composer installer — verify against the official SHA-384 signature before running.
EXPECT_SIG="$(php -r "echo file_get_contents('https://composer.github.io/installer.sig');")"
php -r "copy('https://getcomposer.org/installer','/tmp/ci.php');"
ACTUAL_SIG="$(php -r "echo hash_file('sha384','/tmp/ci.php');")"
[ "$EXPECT_SIG" = "$ACTUAL_SIG" ] || { echo "Composer installer checksum mismatch"; exit 1; }
php /tmp/ci.php --quiet --install-dir=/usr/local/bin --filename=composer
rm -f /tmp/ci.php
composer install --no-dev --optimize-autoloader --ignore-platform-reqs --no-interaction 2>&1 | tail -3
# php-scoper is version-pinned (no stable published checksum endpoint to verify against).
curl -fsSL -o /usr/local/bin/php-scoper https://github.com/humbug/php-scoper/releases/download/0.18.15/php-scoper.phar
chmod +x /usr/local/bin/php-scoper
mkdir -p /work/build
php /usr/local/bin/php-scoper add-prefix --config=scoper.inc.php --output-dir=/work/build/wp-slimstat-pro --force --no-interaction 2>&1 | tail -5
composer --working-dir=/work/build/wp-slimstat-pro dump-autoload --optimize --ignore-platform-reqs --no-interaction 2>&1 | tail -2
cd /work/build && zip -rmq wp-slimstat-pro.zip wp-slimstat-pro/
SH
chmod +x "$(dirname "$STAGE")/build.sh"

log "building Pro php-scoper zip (Docker php:8.2-cli)…"
docker run --rm -v "$(dirname "$STAGE"):/work" -w /work php:8.2-cli bash /work/build.sh
cp "$(dirname "$STAGE")/build/wp-slimstat-pro.zip" "$OUT"
[ -n "$SRC_ID" ] && printf '%s' "$SRC_ID" > "$STAMP"   # record source state for staleness reuse
log "Pro zip ready: $OUT ($(du -h "$OUT" | cut -f1))"
