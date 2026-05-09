#!/usr/bin/env bash
# Run all Claude independent tests (300 total)
set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"

echo "============================================"
echo " Claude Independent Test Suite (300 tests)"
echo "============================================"
echo

TOTAL_PASS=0
TOTAL_FAIL=0

parse_result() {
    local output="$1"
    local p f
    p=$(echo "$output" | grep -oE '[0-9]+ passed' | head -1 | grep -oE '[0-9]+')
    f=$(echo "$output" | grep -oE '[0-9]+ failed' | head -1 | grep -oE '[0-9]+')
    TOTAL_PASS=$((TOTAL_PASS + ${p:-0}))
    TOTAL_FAIL=$((TOTAL_FAIL + ${f:-0}))
}

echo "--- PHP tests (120) ---"
OUT_PHP=$(php "$DIR/ci_php_tests.php" 2>&1) || true
echo "$OUT_PHP"
parse_result "$OUT_PHP"
echo

echo "--- Python HTTP tests (100) ---"
OUT_PY=$(python3 "$DIR/ci_http_tests.py" 2>&1) || true
echo "$OUT_PY"
parse_result "$OUT_PY"
echo

echo "--- Curl/protocol tests (50) ---"
OUT_CURL=$(bash "$DIR/ci_curl_tests.sh" 2>&1) || true
echo "$OUT_CURL"
parse_result "$OUT_CURL"
echo

echo "--- SQL/database tests (30) ---"
OUT_SQL=$(bash "$DIR/ci_sql_tests.sh" 2>&1) || true
echo "$OUT_SQL"
parse_result "$OUT_SQL"
echo

echo "============================================"
echo " TOTAL: ${TOTAL_PASS} passed, ${TOTAL_FAIL} failed"
echo "============================================"

[ "$TOTAL_FAIL" -eq 0 ] && exit 0 || exit 1
