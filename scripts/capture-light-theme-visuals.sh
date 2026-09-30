#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/.visual-audit/light-theme"
mkdir -p "$OUT"
PORT=8099
php -S 127.0.0.1:$PORT -t "$ROOT" >"$OUT/server.log" 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
sleep 1
CHROME="$(command -v google-chrome || command -v chromium || command -v chromium-browser || true)"
if [ -z "$CHROME" ]; then
  echo "Chrome/Chromium not found" >&2
  exit 1
fi
SCREENS=(dashboard rules activity integrations reports ai reset connect)
for screen in "${SCREENS[@]}"; do
  "$CHROME" --headless --no-sandbox --disable-gpu --virtual-time-budget=1200 --window-size=1440,1000 --screenshot="$OUT/${screen}-desktop.png" "http://127.0.0.1:$PORT/tests/light-theme-visual-fixture.html?screen=${screen}" >/dev/null 2>&1
  "$CHROME" --headless --no-sandbox --disable-gpu --virtual-time-budget=1200 --window-size=768,1024 --screenshot="$OUT/${screen}-tablet.png" "http://127.0.0.1:$PORT/tests/light-theme-visual-fixture.html?screen=${screen}" >/dev/null 2>&1
  "$CHROME" --headless --no-sandbox --disable-gpu --virtual-time-budget=1200 --window-size=390,844 --screenshot="$OUT/${screen}-mobile.png" "http://127.0.0.1:$PORT/tests/light-theme-visual-fixture.html?screen=${screen}" >/dev/null 2>&1
  test -s "$OUT/${screen}-desktop.png"
  test -s "$OUT/${screen}-tablet.png"
  test -s "$OUT/${screen}-mobile.png"
done
echo LIGHT_THEME_VISUAL_AUDIT_CAPTURED

# Focused responsive captures for sections below the first viewport.
"$CHROME" --headless --no-sandbox --disable-gpu --virtual-time-budget=1400 --window-size=768,1024 --screenshot="$OUT/ai-library-tablet.png" "http://127.0.0.1:$PORT/tests/light-theme-visual-fixture.html?screen=ai#biblioteca" >/dev/null 2>&1
"$CHROME" --headless --no-sandbox --disable-gpu --virtual-time-budget=1400 --window-size=390,844 --screenshot="$OUT/ai-library-mobile.png" "http://127.0.0.1:$PORT/tests/light-theme-visual-fixture.html?screen=ai#biblioteca" >/dev/null 2>&1
"$CHROME" --headless --no-sandbox --disable-gpu --virtual-time-budget=1400 --window-size=768,1024 --screenshot="$OUT/reports-history-tablet.png" "http://127.0.0.1:$PORT/tests/light-theme-visual-fixture.html?screen=reports#reporting-history-audit" >/dev/null 2>&1
"$CHROME" --headless --no-sandbox --disable-gpu --virtual-time-budget=1400 --window-size=390,844 --screenshot="$OUT/reports-history-mobile.png" "http://127.0.0.1:$PORT/tests/light-theme-visual-fixture.html?screen=reports#reporting-history-audit" >/dev/null 2>&1
test -s "$OUT/ai-library-tablet.png"
test -s "$OUT/ai-library-mobile.png"
test -s "$OUT/reports-history-tablet.png"
test -s "$OUT/reports-history-mobile.png"
