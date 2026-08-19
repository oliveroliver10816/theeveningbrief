#!/usr/bin/env bash
# Package the uploadable ZIP.
#
# What ships is ONLY what the web server needs. Everything we used to build it —
# docs, tests, the design directions, this script, any git metadata — stays behind.
# A delivered ZIP has never contained a .git directory and never will: those carry
# inline access tokens in their remotes.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="theeveningbrief"
OUT="$ROOT/dist"
STAGE="$OUT/$NAME"

rm -rf "$STAGE" "$OUT/$NAME.zip"
mkdir -p "$STAGE"

# --- the payload -------------------------------------------------------------
for item in index.php install.php config.php .htaccess README.txt app assets cron; do
  if [ -e "$ROOT/$item" ]; then
    cp -R "$ROOT/$item" "$STAGE/"
  else
    echo "MISSING: $item" >&2
    exit 1
  fi
done

# data/ ships empty but present, with its deny rule, so the app can create the
# SQLite file on first run without the client having to mkdir anything.
mkdir -p "$STAGE/data"
cp "$ROOT/data/.htaccess" "$STAGE/data/.htaccess"
: > "$STAGE/data/.gitkeep"

# --- scrub -------------------------------------------------------------------
find "$STAGE" -name '.DS_Store' -delete
find "$STAGE" -name '*.log' -delete
find "$STAGE" -name '*.sqlite' -delete
find "$STAGE" -name '*.sqlite-wal' -delete
find "$STAGE" -name '*.sqlite-shm' -delete
find "$STAGE" -name '*.lock' -delete
find "$STAGE" -name '.gstack' -prune -exec rm -rf {} + 2>/dev/null || true
find "$STAGE" -name '.git*' -not -name '.gitkeep' -not -name '.htaccess' -prune -exec rm -rf {} + 2>/dev/null || true

# --- refuse to ship a broken .htaccess ---------------------------------------
# Same class of bug that broke the last batch: a forced redirect to the production
# domain makes the ZIP unviewable anywhere else. Gate the package on it.
HT="$STAGE/.htaccess"
DIRECTIVES="$(grep -vE '^\s*#' "$HT" | grep -vE '^\s*$' || true)"
fail=0
if grep -qiE '^\s*Redirect(Match|Permanent|Temp)?\s' <<<"$DIRECTIVES"; then
  echo "REFUSING: .htaccess contains a Redirect directive" >&2; fail=1
fi
if grep -qiE 'RewriteRule.*\[[^]]*\bR\b[^]]*\]|RewriteRule.*\[[^]]*R=[0-9]{3}' <<<"$DIRECTIVES"; then
  echo "REFUSING: .htaccess contains a RewriteRule with an R (redirect) flag" >&2; fail=1
fi
if grep -qiE '^\s*RewriteBase' <<<"$DIRECTIVES"; then
  echo "REFUSING: .htaccess sets RewriteBase (breaks in a subdirectory)" >&2; fail=1
fi
if grep -qiE 'https?://' <<<"$DIRECTIVES"; then
  echo "REFUSING: .htaccess contains a hardcoded URL" >&2; fail=1
fi
if grep -qiE '^\s*ErrorDocument\s+[0-9]+\s+/' <<<"$DIRECTIVES"; then
  echo "REFUSING: .htaccess ErrorDocument uses a root-absolute path (breaks in a subdirectory)" >&2; fail=1
fi
[ "$fail" -eq 0 ] || exit 1

# --- refuse to ship code that will not parse ---------------------------------
while IFS= read -r f; do
  php -l "$f" > /dev/null || { echo "REFUSING: PHP syntax error in $f" >&2; exit 1; }
done < <(find "$STAGE" -name '*.php')

# --- package -----------------------------------------------------------------
cd "$OUT"
zip -rq "$NAME.zip" "$NAME" -x '*.DS_Store'
cd - > /dev/null

# --- second package: Heroku / GITKU ------------------------------------------
# Heroku is not Apache-on-cPanel. Two differences decide whether it boots:
#   * the PHP buildpack only activates when composer.json sits at the REPO ROOT
#   * a Procfile picks the web server; heroku-php-apache2 honours .htaccess,
#     heroku-php-nginx ignores it entirely and every pretty URL 404s.
# It also has to be FLAT — no wrapper folder — or the buildpack finds nothing.
HSTAGE="$OUT/heroku"
rm -rf "$HSTAGE" "$OUT/$NAME-heroku.zip"
mkdir -p "$HSTAGE"
cp -R "$STAGE/." "$HSTAGE/"
for f in composer.json composer.lock Procfile apache.conf; do
  [ -f "$ROOT/$f" ] || { echo "REFUSING: heroku package needs $f" >&2; exit 1; }
  cp "$ROOT/$f" "$HSTAGE/$f"
done
# apache.conf is a duplicate of .htaccess for hosts that drop dot-files, so it
# must pass the same no-redirect gate.
AD="$(grep -vE '^\s*#' "$HSTAGE/apache.conf" | grep -vE '^\s*$')"
if grep -qiE '^\s*Redirect|\[[^]]*R=[0-9]{3}|https?://' <<<"$AD"; then
  echo "REFUSING: apache.conf contains a redirect or a hardcoded URL" >&2; exit 1
fi
cd "$HSTAGE"
zip -rq "$OUT/$NAME-heroku.zip" . -x '*.DS_Store'
cd - > /dev/null
echo "  heroku package: $OUT/$NAME-heroku.zip ($(md5sum "$OUT/$NAME-heroku.zip" | cut -d' ' -f1))"

SIZE=$(du -h "$OUT/$NAME.zip" | cut -f1)
MD5=$(md5sum "$OUT/$NAME.zip" | cut -d' ' -f1)
COUNT=$(unzip -l "$OUT/$NAME.zip" | tail -1 | awk '{print $2}')

echo "----------------------------------------------------------"
echo "  $OUT/$NAME.zip"
echo "  $SIZE · $COUNT files · md5 $MD5"
echo "----------------------------------------------------------"
unzip -l "$OUT/$NAME.zip" | head -30
