#!/usr/bin/env bash
# Claude independent SQL/database integrity tests (30)
# Focus: schema constraints, data integrity, post-seed validation — direct sqlite3 checks

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT" || exit 1
DB="$ROOT/db.sqlite"

PASS=0
FAIL=0

php seed.php >/dev/null

run() {
    local cat="$1" name="$2"; shift 2
    if "$@" >/dev/null 2>&1; then
        printf '  [ok] [%s] %s\n' "$cat" "$name"; PASS=$((PASS + 1))
    else
        printf '  [FAIL] [%s] %s\n' "$cat" "$name"; FAIL=$((FAIL + 1))
    fi
}

tbl_exists() { sqlite3 "$DB" "SELECT name FROM sqlite_master WHERE type='table' AND name='$1'" | grep -q "$1"; }
col_exists() { sqlite3 "$DB" "PRAGMA table_info($1)" | grep -q "$2"; }
idx_exists() { sqlite3 "$DB" "SELECT name FROM sqlite_master WHERE type='index' AND name='$1'" | grep -q "$1"; }
count() { sqlite3 "$DB" "$1"; }

echo
echo "Running CI SQL/database tests (30):"

# ===== REGULAR (10) =====
run regular "R01 documents table exists" tbl_exists documents
run regular "R02 shares table exists" tbl_exists shares
run regular "R03 staff table exists" tbl_exists staff
run regular "R04 audit_log table exists" tbl_exists audit_log
run regular "R05 schema_migrations table exists" tbl_exists schema_migrations
run regular "R06 documents has id column" col_exists documents id
run regular "R07 documents has readable_id column" col_exists documents readable_id
run regular "R08 documents has published_at column" col_exists documents published_at
run regular "R09 shares has token column" col_exists shares token
run regular "R10 audit_log has details column" col_exists audit_log details

# ===== EXTREME (10) =====
run extreme "E11 no orphaned shares exist" bash -c "test \"\$(sqlite3 '$DB' 'SELECT COUNT(*) FROM shares WHERE document_id NOT IN (SELECT id FROM documents)')\" = '0'"
run extreme "E12 no orphaned documents exist" bash -c "test \"\$(sqlite3 '$DB' 'SELECT COUNT(*) FROM documents WHERE created_by NOT IN (SELECT id FROM staff)')\" = '0'"
run extreme "E13 all audit_log details are valid JSON" bash -c "bad=\$(sqlite3 '$DB' \"SELECT COUNT(*) FROM audit_log WHERE json_valid(details) = 0\"); test \"\$bad\" = '0'"
run extreme "E14 all share tokens are exactly 32 hex chars" bash -c "bad=\$(sqlite3 '$DB' \"SELECT COUNT(*) FROM shares WHERE length(token) != 32 OR token GLOB '*[^a-f0-9]*'\"); test \"\$bad\" = '0'"
run extreme "E15 all readable_ids match URL-safe pattern" bash -c "bad=\$(sqlite3 '$DB' \"SELECT COUNT(*) FROM documents WHERE readable_id IS NOT NULL AND readable_id GLOB '*[^a-z0-9-]*'\"); test \"\$bad\" = '0'"
run extreme "E16 no null readable_ids on any document" bash -c "test \"\$(sqlite3 '$DB' 'SELECT COUNT(*) FROM documents WHERE readable_id IS NULL')\" = '0'"
run extreme "E17 all documents have non-empty titles" bash -c "test \"\$(sqlite3 '$DB' \"SELECT COUNT(*) FROM documents WHERE trim(title) = ''\")\" = '0'"
run extreme "E18 all documents have non-empty bodies" bash -c "test \"\$(sqlite3 '$DB' \"SELECT COUNT(*) FROM documents WHERE trim(body) = ''\")\" = '0'"
run extreme "E19 published_at values are valid format or NULL" bash -c "bad=\$(sqlite3 '$DB' \"SELECT COUNT(*) FROM documents WHERE published_at IS NOT NULL AND published_at != '' AND published_at NOT GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] [0-9][0-9]:[0-9][0-9]:[0-9][0-9]'\"); test \"\$bad\" = '0'"
run extreme "E20 schema_migrations has at least 1 applied migration" bash -c "test \$(sqlite3 '$DB' 'SELECT COUNT(*) FROM schema_migrations') -ge 1"

# ===== UNEXPECTED (5) =====
run unexpected "U21 unique index exists on readable_id" idx_exists idx_documents_readable_id
run unexpected "U22 no duplicate tokens in shares" bash -c "test \"\$(sqlite3 '$DB' 'SELECT COUNT(*) FROM (SELECT token, COUNT(*) c FROM shares GROUP BY token HAVING c > 1)')\" = '0'"
run unexpected "U23 no duplicate readable_ids" bash -c "test \"\$(sqlite3 '$DB' 'SELECT COUNT(*) FROM (SELECT readable_id, COUNT(*) c FROM documents WHERE readable_id IS NOT NULL GROUP BY readable_id HAVING c > 1)')\" = '0'"
run unexpected "U24 audit_log entries have valid timestamps" bash -c "bad=\$(sqlite3 '$DB' \"SELECT COUNT(*) FROM audit_log WHERE created_at NOT GLOB '[0-9][0-9][0-9][0-9]-*'\"); test \"\$bad\" = '0'"
run unexpected "U25 documents created_at values are valid timestamps" bash -c "bad=\$(sqlite3 '$DB' \"SELECT COUNT(*) FROM documents WHERE created_at NOT GLOB '[0-9][0-9][0-9][0-9]-*'\"); test \"\$bad\" = '0'"

# ===== FOOL (5) =====
run fool "F26 staff table has seeded Freddy Folio" bash -c "sqlite3 '$DB' \"SELECT name FROM staff WHERE id=1\" | grep -q 'Freddy Folio'"
run fool "F27 seeded document title is Welcome Packet" bash -c "sqlite3 '$DB' \"SELECT title FROM documents ORDER BY id LIMIT 1\" | grep -q 'Welcome Packet'"
run fool "F28 seeded share has valid 32-char hex token" bash -c "token=\$(sqlite3 '$DB' 'SELECT token FROM shares ORDER BY id LIMIT 1'); test \${#token} -eq 32"
run fool "F29 seeded readable_id starts with welcome-packet" bash -c "sqlite3 '$DB' \"SELECT readable_id FROM documents ORDER BY id LIMIT 1\" | grep -q '^welcome-packet'"
run fool "F30 no empty string values in staff email" bash -c "test \"\$(sqlite3 '$DB' \"SELECT COUNT(*) FROM staff WHERE trim(email) = ''\")\" = '0'"

echo
echo "CI SQL tests: ${PASS} passed, ${FAIL} failed."
exit "$FAIL"
