#!/usr/bin/env bash
# Richtet eine frisch per Composer installierte Contao Managed Edition für die Prüfung ein (MySQL, contao:migrate,
# Startpunkt mit Seite, Artikel und Nachrichtenarchiv, drei Tokens), startet den PHP-Server und prüft die Schnittstelle
# mit api-check.php: einmal alle Endpunkte, dann ein widerrufenes Token, zuletzt die HTTPS-Pflicht.
#
#   Tests/Integration/run.sh <Verzeichnis der Contao-Installation> [<Port>]
#
# Umgebung: PHP (Standard php), DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME (Standard 127.0.0.1, 3306, root, root,
# contao_ci; die Datenbank muss leer sein oder fehlen), VL_CONTAO (erwartete Version, optional).
set -euo pipefail

EXT="$(cd "$(dirname "$0")/../.." && pwd)"
SITE="$(cd "$1" && pwd)"
PORT="${2:-8080}"
PHP="${PHP:-php}"
DB_HOST="${DB_HOST:-127.0.0.1}" DB_PORT="${DB_PORT:-3306}" DB_USER="${DB_USER:-root}" DB_PASS="${DB_PASS:-root}" DB_NAME="${DB_NAME:-contao_ci}"
export DB_HOST DB_PORT DB_USER DB_PASS DB_NAME
cd "$SITE"

console() { "$PHP" vendor/bin/contao-console --no-ansi "$@"; }
sql() { "$PHP" -r '$p = new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . (($argv[2] ?? "") === "" ? ";dbname=" . getenv("DB_NAME") : ""), getenv("DB_USER"), getenv("DB_PASS"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $p->exec($argv[1]);' "$@"; }

# Einstellungen des Bundles: config/config.yaml der Installation plus eigener Block
ORIGINAL_CONFIG="$(cat config/config.yaml 2> /dev/null || true)"
require_https() {
  mkdir -p config
  printf '%s\n\nvelundo_contao_connect:\n    require_https: %s\n' "$ORIGINAL_CONFIG" "$1" > config/config.yaml
  console cache:clear --env=prod > /dev/null
}

echo "::group::Contao einrichten"
sql "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" server
printf '\nDATABASE_URL=mysql://%s:%s@%s:%s/%s\n' "$DB_USER" "$DB_PASS" "$DB_HOST" "$DB_PORT" "$DB_NAME" >> .env.local
require_https false
console contao:migrate --no-interaction --no-backup
echo "::endgroup::"

# Testdaten wie im Backend angelegt: Startpunkt, eine veröffentlichte Seite mit Artikel, ein Nachrichtenarchiv
NOW="$(date +%s)"
sql "INSERT INTO tl_page (pid, sorting, tstamp, title, alias, type, language, fallback, published) VALUES (0, 128, $NOW, 'CI', 'ci', 'root', 'de', '1', '1')"
sql "INSERT INTO tl_page (pid, sorting, tstamp, title, alias, type, published) SELECT id, 128, $NOW, 'Start', 'index', 'regular', '1' FROM tl_page WHERE type = 'root'"
sql "INSERT INTO tl_article (pid, sorting, tstamp, title, alias, inColumn, published) SELECT id, 128, $NOW, 'Start', 'start', 'main', '1' FROM tl_page WHERE type = 'regular'"
sql "INSERT INTO tl_news_archive (tstamp, title, jumpTo) SELECT $NOW, 'CI Nachrichten', id FROM tl_page WHERE type = 'regular'"

# Token erzeugen; Ausgabe „<Kennung> <Token>“
token() {
  local out
  out="$(console velundo:token create "$@")"
  printf '%s %s\n' "$(printf '%s' "$out" | grep -oE 'Kennung [0-9]+' | head -1 | cut -d' ' -f2)" \
    "$(printf '%s\n' "$out" | grep -E '^[A-Za-z0-9_-]{30,}$' | head -1)"
}
read -r PLAIN_ID PLAIN < <(token "CI ohne Rechte")
read -r _ PUBLISH < <(token "CI veröffentlichen" --allow-publish)
read -r _ FULL < <(token "CI alle Rechte" --allow-publish --allow-live-edit)
test -n "$PLAIN" && test -n "$PUBLISH" && test -n "$FULL" && test -n "$PLAIN_ID"
console velundo:token list

"$PHP" -S "127.0.0.1:$PORT" -t public public/index.php > "$SITE/server.log" 2>&1 &
SERVER=$!
trap 'kill "$SERVER" 2> /dev/null || true' EXIT
curl -s -o /dev/null --retry 30 --retry-connrefused --retry-delay 1 "http://127.0.0.1:$PORT/"

export VL_BASE="http://127.0.0.1:$PORT/_velundo/v1/" VL_TOKEN="$PLAIN" VL_TOKEN_PUBLISH="$PUBLISH" VL_TOKEN_FULL="$FULL"
export VL_DB_DSN="mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4" VL_DB_USER="$DB_USER" VL_DB_PASS="$DB_PASS"

echo "::group::Endpunkte"
VL_MODE=api "$PHP" "$EXT/Tests/Integration/api-check.php"
echo "::endgroup::"

console velundo:token revoke "$PLAIN_ID"
VL_MODE=revoked "$PHP" "$EXT/Tests/Integration/api-check.php"

require_https true
VL_MODE=https VL_TOKEN="$FULL" "$PHP" "$EXT/Tests/Integration/api-check.php"
