#!/usr/bin/env bash
# Claude independent curl/HTTP protocol tests (50)
# Focus: HTTP methods, headers, security, resilience — novel areas not covered elsewhere

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
    local cat="$1" name="$2"; shift 2
    if "$@" >/dev/null 2>&1; then
        printf '  [ok] [%s] %s\n' "$cat" "$name"; PASS=$((PASS + 1))
    else
        printf '  [FAIL] [%s] %s\n' "$cat" "$name"; FAIL=$((FAIL + 1))
    fi
}

http_code() { curl -s -L -o /dev/null -w '%{http_code}' "$@" 2>/dev/null; }
http_code_nf() { curl -s -o /dev/null -w '%{http_code}' "$@" 2>/dev/null; }
body() { curl -sL "$@" 2>/dev/null; }
headers() { curl -sI "$@" 2>/dev/null; }

echo
echo "Running CI curl/protocol tests (50):"

# ===== REGULAR (15) =====
run regular "R01 GET / follows redirect to 200" test "$(http_code "${BASE}/")" = "200"
run regular "R02 GET /admin.php Content-Type text/html" bash -c "curl -sI '${BASE}/admin.php' | grep -qi 'Content-Type.*text/html'"
run regular "R03 POST admin with valid data returns 200" bash -c "code=\$(curl -sf -L -o /dev/null -w '%{http_code}' -d 'title=CurlCI&body=testbody&published_at=' '${BASE}/admin.php'); [ \"\$code\" = '200' ]"
run regular "R04 GET view valid token returns 200" test "$(http_code "${BASE}/view.php?token=${SEED_TOKEN}")" = "200"
run regular "R05 GET share valid rid returns 200" test "$(http_code "${BASE}/share.php?doc=${SEED_RID}")" = "200"
run regular "R06 CSS file accessible and non-empty" bash -c "test \$(curl -s '${BASE}/assets/style.css' | wc -c) -gt 100"
run regular "R07 admin response > 500 bytes" bash -c "test \$(curl -s '${BASE}/admin.php' | wc -c) -gt 500"
run regular "R08 view response > 200 bytes" bash -c "test \$(curl -s '${BASE}/view.php?token=${SEED_TOKEN}' | wc -c) -gt 200"
run regular "R09 share response > 500 bytes" bash -c "test \$(curl -s '${BASE}/share.php?doc=${SEED_RID}' | wc -c) -gt 500"
run regular "R10 admin search returns Welcome Packet" bash -c "curl -s '${BASE}/admin.php?q=welcome' | grep -q 'Welcome Packet'"
run regular "R11 admin page has meta charset" bash -c "curl -s '${BASE}/admin.php' | grep -qi 'charset'"
run regular "R12 admin page has nav element" bash -c "curl -s '${BASE}/admin.php' | grep -q 'class=\"nav\"'"
run regular "R13 view page has doc-body class" bash -c "curl -s '${BASE}/view.php?token=${SEED_TOKEN}' | grep -q 'doc-body'"
run regular "R14 share page has back-link class" bash -c "curl -s '${BASE}/share.php?doc=${SEED_RID}' | grep -q 'back-link'"
run regular "R15 response time admin < 3 seconds" bash -c "time_ms=\$(curl -s -o /dev/null -w '%{time_total}' '${BASE}/admin.php'); python3 -c \"import sys; sys.exit(0 if float('\$time_ms') < 3.0 else 1)\""

# ===== EXTREME (15) =====
run extreme "E16 10KB query string does not 500" bash -c "code=\$(http_code '${BASE}/admin.php?q=$(python3 -c "print('x'*10000)")'); [ \"\$code\" != '500' ]"
run extreme "E17 HEAD returns same status as GET for admin" bash -c "get=\$(curl -sL -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); head=\$(curl -sI -L -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$get\" = \"\$head\" ]"
run extreme "E18 Accept-Encoding gzip does not break response" bash -c "code=\$(curl -s -H 'Accept-Encoding: gzip' -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" = '200' ]"
run extreme "E19 very long User-Agent does not break" bash -c "code=\$(curl -s -H 'User-Agent: $(python3 -c "print('A'*2000)")' -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" = '200' ]"
run extreme "E20 custom X-header does not break" bash -c "code=\$(curl -s -H 'X-Custom: test' -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" = '200' ]"
run extreme "E21 POST with duplicate title fields" bash -c "code=\$(curl -sf -L -o /dev/null -w '%{http_code}' -d 'title=dup1&title=dup2&body=body&published_at=' '${BASE}/admin.php'); [ \"\$code\" != '500' ]"
run extreme "E22 OPTIONS request returns non-500" bash -c "code=\$(curl -s -X OPTIONS -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" != '500' ]"
run extreme "E23 PATCH request returns non-500" bash -c "code=\$(curl -s -X PATCH -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" != '500' ]"
run extreme "E24 DELETE request returns non-500" bash -c "code=\$(curl -s -X DELETE -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" != '500' ]"
run extreme "E25 15 rapid sequential requests all 200" bash -c "ok=true; for i in \$(seq 1 15); do code=\$(curl -sL -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" = '200' ] || ok=false; done; \$ok"
run extreme "E26 POST with no Content-Type does not 500" bash -c "code=\$(curl -s -X POST -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" != '500' ]"
run extreme "E27 POST with text/plain Content-Type does not 500" bash -c "code=\$(curl -s -H 'Content-Type: text/plain' -d 'hello' -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" != '500' ]"
run extreme "E28 double-slash in path does not 500" bash -c "code=\$(http_code '${BASE}//admin.php'); [ \"\$code\" != '500' ]"
run extreme "E29 null byte in query does not 500" bash -c "code=\$(curl -sf -o /dev/null -w '%{http_code}' '${BASE}/admin.php?q=%00' 2>/dev/null); [ \"\$code\" != '500' ]"
run extreme "E30 fragment in URL does not affect response" bash -c "code=\$(curl -sL -o /dev/null -w '%{http_code}' '${BASE}/admin.php#section'); [ \"\$code\" = '200' ]"

# ===== UNEXPECTED (10) =====
run unexpected "U31 path traversal attempt returns not 500" bash -c "code=\$(http_code '${BASE}/admin.php/../admin.php'); [ \"\$code\" != '500' ]"
run unexpected "U32 search with traversal attempt safe" bash -c "code=\$(curl -sL -o /dev/null -w '%{http_code}' '${BASE}/admin.php?q=../../etc/passwd'); [ \"\$code\" = '200' ]"
run unexpected "U33 /.env request does not leak env vars" bash -c "! curl -sL '${BASE}/.env' 2>/dev/null | grep -qi 'password\\|secret\\|api_key'"
run unexpected "U34 /db.sqlite request does not serve binary" bash -c "! curl -sL '${BASE}/db.sqlite' 2>/dev/null | grep -q 'SQLite format'"
run unexpected "U35 response body does not contain absolute paths" bash -c "! curl -sf '${BASE}/admin.php' 2>/dev/null | grep -q '/Users/\\|/home/\\|/var/'"
run unexpected "U36 response body has no PHP warnings" bash -c "! curl -sf '${BASE}/admin.php' 2>/dev/null | grep -qi 'Warning:\\|Notice:\\|Deprecated:'"
run unexpected "U37 404 page has no Fatal error text" bash -c "! curl -s '${BASE}/view.php?token=bad' 2>/dev/null | grep -q 'Fatal error'"
run unexpected "U38 share page has no Fatal error text" bash -c "! curl -s '${BASE}/share.php?doc=${SEED_RID}' 2>/dev/null | grep -q 'Fatal error'"
run unexpected "U39 POST with binary body does not 500" bash -c "code=\$(printf '\\x00\\x01\\x02\\x03' | curl -s -X POST -d @- -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" != '500' ]"
run unexpected "U40 very long cookie header does not 500" bash -c "code=\$(curl -s -H 'Cookie: x=$(python3 -c "print('A'*5000)")' -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" != '500' ]"

# ===== FOOL (10) =====
run fool "F41 POST admin with empty body field" bash -c "curl -sf -d 'title=test&body=&published_at=' '${BASE}/admin.php' | grep -q 'Title and body are required'"
run fool "F42 POST admin with no fields at all" bash -c "code=\$(curl -sf -X POST -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" != '500' ]"
run fool "F43 GET view without token param returns 404" test "$(http_code "${BASE}/view.php")" = "404"
run fool "F44 GET share without doc param returns 404" test "$(http_code "${BASE}/share.php")" = "404"
run fool "F45 POST share blank action does not 500" bash -c "code=\$(curl -sf -d 'action=&email=x@y.com' -o /dev/null -w '%{http_code}' '${BASE}/share.php?doc=${SEED_RID}'); [ \"\$code\" != '500' ]"
run fool "F46 very long readable_id in URL returns 404" bash -c "longid=\$(python3 -c \"print('a'*500)\"); code=\$(curl -sL -o /dev/null -w '%{http_code}' \"${BASE}/share.php?doc=\$longid\"); [ \"\$code\" = '404' ]"
run fool "F47 URL with special chars in path does not 500" bash -c "code=\$(curl -sf -o /dev/null -w '%{http_code}' '${BASE}/%E2%98%83.php' 2>/dev/null); [ \"\$code\" != '500' ]"
run fool "F48 server healthy after error batch" bash -c "for i in \$(seq 1 5); do curl -s '${BASE}/view.php?token=bad' >/dev/null; done; code=\$(curl -sL -o /dev/null -w '%{http_code}' '${BASE}/admin.php'); [ \"\$code\" = '200' ]"
run fool "F49 POST invalid schedule shows error message" bash -c "curl -sf -d 'action=schedule&published_at=garbage' '${BASE}/share.php?doc=${SEED_RID}' | grep -q 'valid date'"
run fool "F50 seed data intact after all curl tests" bash -c "test \"\$(sqlite3 '${ROOT}/db.sqlite' \"SELECT title FROM documents ORDER BY id LIMIT 1\")\" = 'Welcome Packet'"

echo
echo "CI curl tests: ${PASS} passed, ${FAIL} failed."
exit "$FAIL"
