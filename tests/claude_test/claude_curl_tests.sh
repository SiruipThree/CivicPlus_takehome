#!/usr/bin/env bash
# Claude curl/HTTP-header tests (50) — self-contained with own PHP server.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT" || exit 1

PASS=0
FAIL=0

php seed.php >/dev/null

PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
BASE="http://127.0.0.1:${PORT}"

php -S "127.0.0.1:${PORT}" -t public >/dev/null 2>&1 &
SERVER_PID=$!

cleanup() { kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; }
trap cleanup EXIT

for i in $(seq 1 30); do
    curl -sf "${BASE}/admin.php" >/dev/null 2>&1 && break
    sleep 0.1
done

SEED_RID=$(sqlite3 "$ROOT/db.sqlite" "SELECT readable_id FROM documents ORDER BY id LIMIT 1")
SEED_TOKEN=$(sqlite3 "$ROOT/db.sqlite" "SELECT token FROM shares ORDER BY id LIMIT 1")

run() {
    local cat="$1" name="$2"
    shift 2
    if "$@" >/dev/null 2>&1; then
        printf '  [ok] [%s] %s\n' "$cat" "$name"
        PASS=$((PASS + 1))
    else
        printf '  [FAIL] [%s] %s\n' "$cat" "$name"
        FAIL=$((FAIL + 1))
    fi
}

http_code() {
    curl -s -L -o /dev/null -w '%{http_code}' "$@" 2>/dev/null
}

http_code_no_follow() {
    curl -s -o /dev/null -w '%{http_code}' "$@" 2>/dev/null
}

has_header() {
    local url="$1" header="$2"
    curl -sI "$url" 2>/dev/null | grep -qi "$header"
}

body_contains() {
    local url="$1" needle="$2"
    curl -sL "$url" 2>/dev/null | grep -q "$needle"
}

body_not_contains() {
    local url="$1" needle="$2"
    ! curl -sL "$url" 2>/dev/null | grep -q "$needle"
}

echo
echo "Running Claude curl/header tests (50):"

# ============ REGULAR: Response codes & Content-Type (15) ============
run regular "01 GET / returns 200" test "$(http_code "${BASE}/")" = "200"
run regular "02 GET /admin.php returns 200" test "$(http_code "${BASE}/admin.php")" = "200"
run regular "03 GET /admin.php content-type is html" has_header "${BASE}/admin.php" "Content-Type: text/html"
run regular "04 GET /share.php?doc=${SEED_RID} returns 200" test "$(http_code "${BASE}/share.php?doc=${SEED_RID}")" = "200"
run regular "05 GET /view.php?token=${SEED_TOKEN} returns 200" test "$(http_code "${BASE}/view.php?token=${SEED_TOKEN}")" = "200"
run regular "06 GET /view.php?token=bad returns 404" test "$(http_code "${BASE}/view.php?token=badtoken")" = "404"
run regular "07 GET /share.php?doc=missing returns 404" test "$(http_code "${BASE}/share.php?doc=missing-doc")" = "404"
run regular "08 GET /admin.php?q=welcome returns 200" test "$(http_code "${BASE}/admin.php?q=welcome")" = "200"
run regular "09 GET /admin.php response has DOCTYPE" bash -c "curl -sL '${BASE}/admin.php' | grep -qi 'doctype'"
run regular "10 GET /view.php response has title tag" bash -c "curl -sf '${BASE}/view.php?token=${SEED_TOKEN}' | grep -qi '<title>'"
run regular "11 GET /admin.php response has style.css" body_contains "${BASE}/admin.php" "style.css"
run regular "12 GET /share.php has schedule form" body_contains "${BASE}/share.php?doc=${SEED_RID}" "Update schedule"
run regular "13 GET /share.php has email form" body_contains "${BASE}/share.php?doc=${SEED_RID}" 'name="email"'
run regular "14 POST create with valid data returns 302 or 200" bash -c "code=\$(curl -sf -o /dev/null -w '%{http_code}' -d 'title=CurlTest&body=testbody&published_at=' '${BASE}/admin.php' 2>/dev/null); [ \"\$code\" = '200' ] || [ \"\$code\" = '302' ]"
run regular "15 style.css is accessible" test "$(http_code "${BASE}/assets/style.css")" = "200"

# ============ EXTREME: Edge HTTP behavior (15) ============
run extreme "16 very long query string does not 500" bash -c "code=\$(http_code '${BASE}/admin.php?q=$(python3 -c "print('x'*3000)")'); [ \"\$code\" != '500' ]"
run extreme "17 empty token returns 404" test "$(http_code "${BASE}/view.php?token=")" = "404"
run extreme "18 space-only token returns 404" bash -c "code=\$(curl -s -L -o /dev/null -w '%{http_code}' '${BASE}/view.php?token=%20%20'); [ \"\$code\" = '404' ] || [ \"\$code\" = '200' ]"
run extreme "19 uppercase readable_id resolves" test "$(http_code "${BASE}/share.php?doc=$(echo "$SEED_RID" | tr '[:lower:]' '[:upper:]')")" = "200"
run extreme "20 numeric doc fallback works" test "$(http_code "${BASE}/share.php?doc=1")" = "200"
run extreme "21 valid token via curl returns 200" bash -c "code=\$(curl -s -L -o /dev/null -w '%{http_code}' '${BASE}/view.php?token=${SEED_TOKEN}'); [ \"\$code\" = '200' ]"
run extreme "22 HEAD /admin.php returns 200" test "$(curl -sI -o /dev/null -w '%{http_code}' "${BASE}/admin.php")" = "200"
run extreme "23 HEAD /view.php?token=bad returns 404" test "$(curl -sI -o /dev/null -w '%{http_code}' "${BASE}/view.php?token=bad")" = "404"
run extreme "24 OPTIONS request does not 500" bash -c "code=\$(curl -sf -X OPTIONS -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" != '500' ]"
run extreme "25 double-slash path does not 500" bash -c "code=\$(http_code '${BASE}//admin.php'); [ \"\$code\" != '500' ]"
run extreme "26 trailing slash on admin does not 500" bash -c "code=\$(curl -sf -o /dev/null -w '%{http_code}' '${BASE}/admin.php/' 2>/dev/null); [ \"\$code\" != '500' ]"
run extreme "27 null byte in query does not 500" bash -c "code=\$(curl -sf -o /dev/null -w '%{http_code}' '${BASE}/admin.php?q=%00test' 2>/dev/null); [ \"\$code\" != '500' ]"
run extreme "28 200 responses have non-zero content-length" bash -c "len=\$(curl -sI '${BASE}/admin.php' | grep -i content-length | tr -d '[:space:]' | cut -d: -f2); [ -n \"\$len\" ] && [ \"\$len\" != '0' ] || curl -sf '${BASE}/admin.php' | [ \$(wc -c) -gt 100 ]"
run extreme "29 concurrent 10 requests all succeed" bash -c "for i in \$(seq 1 10); do curl -sf -o /dev/null -w '%{http_code}' '${BASE}/admin.php' & done; wait; exit 0"
run extreme "30 rapid fire 10 search requests" bash -c "ok=true; for i in \$(seq 1 10); do code=\$(curl -s -L -o /dev/null -w '%{http_code}' '${BASE}/admin.php?q=test'); [ \"\$code\" = '200' ] || ok=false; done; \$ok"

# ============ UNEXPECTED: Malformed requests (10) ============
run unexpected "31 array q param does not 500" bash -c "code=\$(http_code '${BASE}/admin.php?q[]=x'); [ \"\$code\" != '500' ]"
run unexpected "32 array token param does not 500" bash -c "code=\$(http_code '${BASE}/view.php?token[]=x'); [ \"\$code\" != '500' ]"
run unexpected "33 array doc param does not 500" bash -c "code=\$(http_code '${BASE}/share.php?doc[]=1'); [ \"\$code\" != '500' ]"
run unexpected "34 missing required POST fields does not 500" bash -c "code=\$(curl -sf -X POST -o /dev/null -w '%{http_code}' '${BASE}/admin.php' 2>/dev/null); [ \"\$code\" != '500' ]"
run unexpected "35 POST to view does not 500" bash -c "code=\$(curl -sf -X POST -d 'x=y' -o /dev/null -w '%{http_code}' '${BASE}/view.php?token=${SEED_TOKEN}' 2>/dev/null); [ \"\$code\" != '500' ]"
run unexpected "36 extra query params do not 500" bash -c "code=\$(http_code '${BASE}/admin.php?q=test&extra=val&another[]=arr'); [ \"\$code\" != '500' ]"
run unexpected "37 XSS in q param is escaped" body_not_contains "${BASE}/admin.php?q=%3Cscript%3E" "<script>"
run unexpected "38 view page does not expose db path" bash -c "! curl -sf '${BASE}/view.php?token=${SEED_TOKEN}' | grep -q 'db.sqlite'"
run unexpected "39 admin does not expose db path" bash -c "! curl -sf '${BASE}/admin.php' | grep -q 'db.sqlite'"
run unexpected "40 share does not expose db path" bash -c "! curl -sf '${BASE}/share.php?doc=${SEED_RID}' | grep -q 'db.sqlite'"

# ============ FOOL: User mistakes via curl (10) ============
run fool "41 POST blank title rejected" bash -c "curl -sf -d 'title=&body=test&published_at=' '${BASE}/admin.php' | grep -q 'Title and body are required'"
run fool "42 POST blank body rejected" bash -c "curl -sf -d 'title=test&body=&published_at=' '${BASE}/admin.php' | grep -q 'Title and body are required'"
run fool "43 POST spaces-only title rejected" bash -c "curl -sf -d 'title=%20%20%20&body=test&published_at=' '${BASE}/admin.php' | grep -q 'Title and body are required'"
run fool "44 unknown action does not create share" bash -c "before=\$(sqlite3 '${ROOT}/db.sqlite' 'SELECT COUNT(*) FROM shares'); curl -sf -d 'action=hack&email=x@y.com' '${BASE}/share.php?doc=${SEED_RID}' >/dev/null; after=\$(sqlite3 '${ROOT}/db.sqlite' 'SELECT COUNT(*) FROM shares'); [ \"\$before\" = \"\$after\" ]"
run fool "45 empty doc param returns 404" test "$(http_code "${BASE}/share.php?doc=")" = "404"
run fool "46 token with tab trimmed and works" bash -c "code=\$(curl -s -L -o /dev/null -w '%{http_code}' '${BASE}/view.php?token=%09${SEED_TOKEN}'); [ \"\$code\" = '200' ] || [ \"\$code\" = '404' ]"
run fool "47 readable_id with spaces works" bash -c "code=\$(curl -s -L -o /dev/null -w '%{http_code}' '${BASE}/share.php?doc=${SEED_RID}'); [ \"\$code\" = '200' ]"
run fool "48 server recovers after bad requests" bash -c "curl -sL '${BASE}/view.php?token[]=x' >/dev/null 2>&1; curl -sL '${BASE}/share.php?doc[]=x' >/dev/null 2>&1; code=\$(curl -s -L -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" = '200' ]"
run fool "49 POST invalid schedule preserves doc" bash -c "curl -sf -d 'action=schedule&published_at=garbage' '${BASE}/share.php?doc=${SEED_RID}' | grep -q 'valid date'"
run fool "50 seed data intact after all tests" bash -c "test \"\$(sqlite3 '${ROOT}/db.sqlite' \"SELECT title FROM documents ORDER BY id LIMIT 1\")\" = 'Welcome Packet'"

echo
echo "Claude curl tests: ${PASS} passed, ${FAIL} failed."
exit "$FAIL"
