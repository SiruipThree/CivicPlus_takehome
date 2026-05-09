#!/usr/bin/env bash

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PASS=0
FAIL=0

cd "$ROOT" || exit 1
php seed.php >/dev/null

run() {
    local category="$1"
    local name="$2"
    shift 2

    if "$@" >/dev/null 2>&1; then
        printf '  [ok] [%s] %s\n' "$category" "$name"
        PASS=$((PASS + 1))
    else
        printf '  [FAIL] [%s] %s\n' "$category" "$name"
        FAIL=$((FAIL + 1))
    fi
}

sql_value() {
    sqlite3 "$ROOT/db.sqlite" "$1"
}

sql_eq() {
    local expected="$1"
    local query="$2"
    local actual
    actual="$(sql_value "$query")"
    [[ "$actual" == "$expected" ]]
}

sql_nonempty() {
    local query="$1"
    local actual
    actual="$(sql_value "$query")"
    [[ -n "$actual" ]]
}

grep_q() {
    grep -Eq "$1" "$2"
}

not_grep_q() {
    ! grep -Eq "$1" "$2"
}

php_snippet_ok() {
    php -r "$1"
}

echo
echo "Running Codex independent CLI/SQLite/static tests (60):"

# Regular behavior and requirement checks: 25 tests.
run regular "01 bootstrap.php passes PHP lint" php -l lib/bootstrap.php
run regular "02 layout.php passes PHP lint" php -l lib/layout.php
run regular "03 admin.php passes PHP lint" php -l public/admin.php
run regular "04 share.php passes PHP lint" php -l public/share.php
run regular "05 view.php passes PHP lint" php -l public/view.php
run regular "06 index.php passes PHP lint" php -l public/index.php
run regular "07 seed.php passes PHP lint" php -l seed.php
run regular "08 baseline test.php passes PHP lint" php -l tests/test.php
run regular "09 schema declares staff table" grep_q '^CREATE TABLE staff' schema.sql
run regular "10 schema declares documents table" grep_q '^CREATE TABLE documents' schema.sql
run regular "11 schema declares shares table" grep_q '^CREATE TABLE shares' schema.sql
run regular "12 schema declares audit_log table" grep_q '^CREATE TABLE audit_log' schema.sql
run regular "13 base schema was not edited with published_at" not_grep_q 'published_at' schema.sql
run regular "14 base schema was not edited with readable_id" not_grep_q 'readable_id' schema.sql
run regular "15 migration directory exists" test -d migrations
run regular "16 migration 000 creates schema_migrations" grep_q 'CREATE TABLE IF NOT EXISTS schema_migrations' migrations/000_create_schema_migrations.sql
run regular "17 migration 001 adds published_at" grep_q 'ADD COLUMN published_at' migrations/001_document_publishing_and_readable_ids.sql
run regular "18 migration 001 adds readable_id" grep_q 'ADD COLUMN readable_id' migrations/001_document_publishing_and_readable_ids.sql
run regular "19 migration 001 creates readable ID unique index" grep_q 'UNIQUE INDEX idx_documents_readable_id' migrations/001_document_publishing_and_readable_ids.sql
run regular "20 docker compose exposes port 8000" grep_q '"8000:8000"' docker-compose.yml
run regular "21 docker compose seeds before starting server" grep_q 'php seed.php && php -S' docker-compose.yml
run regular "22 Dockerfile uses PHP 8.3 CLI" grep_q 'php:8\.3-cli' Dockerfile
run regular "23 seeded database has one staff row" sql_eq "1" 'SELECT COUNT(*) FROM staff;'
run regular "24 seeded document has readable ID" sql_nonempty "SELECT readable_id FROM documents WHERE title = 'Welcome Packet';"
run regular "25 seeded share token length is 32" sql_eq "32" 'SELECT length(token) FROM shares ORDER BY id LIMIT 1;'

# Extreme database and migration checks: 15 tests.
run extreme "26 readable_id unique index exists in SQLite" sql_eq "1" "SELECT COUNT(*) FROM pragma_index_list('documents') WHERE name = 'idx_documents_readable_id' AND [unique] = 1;"
run extreme "27 published_at column is nullable" sql_eq "0" "SELECT [notnull] FROM pragma_table_info('documents') WHERE name = 'published_at';"
run extreme "28 readable_id column is nullable for migration compatibility" sql_eq "0" "SELECT [notnull] FROM pragma_table_info('documents') WHERE name = 'readable_id';"
run extreme "29 shares.document_id has a foreign key" sql_eq "documents" "SELECT [table] FROM pragma_foreign_key_list('shares') WHERE [from] = 'document_id';"
run extreme "30 documents.created_by has a staff foreign key" sql_eq "staff" "SELECT [table] FROM pragma_foreign_key_list('documents') WHERE [from] = 'created_by';"
run extreme "31 audit_log has details column" sql_eq "1" "SELECT COUNT(*) FROM pragma_table_info('audit_log') WHERE name = 'details';"
run extreme "32 schema_migrations migration column is primary key" sql_eq "1" "SELECT pk FROM pragma_table_info('schema_migrations') WHERE name = 'migration';"
run extreme "33 both migration files are recorded in SQLite" sql_eq "2" 'SELECT COUNT(*) FROM schema_migrations;'
run extreme "34 migration runner can execute again" php_snippet_ok 'require "lib/bootstrap.php"; apply_migrations(db(), __DIR__ . "/migrations");'
run extreme "35 seed can rerun from scratch" php seed.php
run extreme "36 staff count remains one after reseed" sql_eq "1" 'SELECT COUNT(*) FROM staff;'
run extreme "37 seeded readable ID is URL-safe lowercase" php_snippet_ok 'require "lib/bootstrap.php"; $r = db()->query("SELECT readable_id FROM documents ORDER BY id LIMIT 1")->fetchColumn(); if (!preg_match("/^[a-z0-9-]+$/", $r)) exit(1);'
run extreme "38 seeded token differs from readable ID" php_snippet_ok 'require "lib/bootstrap.php"; $d = db()->query("SELECT readable_id FROM documents ORDER BY id LIMIT 1")->fetchColumn(); $t = db()->query("SELECT token FROM shares ORDER BY id LIMIT 1")->fetchColumn(); if ($d === $t) exit(1);'
run extreme "39 stylesheet exists" test -s public/assets/style.css
run extreme "40 all tracked PHP app files contain opening tags" bash -c 'for f in lib/*.php public/*.php seed.php tests/test.php; do head -n 1 "$f" | grep -q "^<?php" || exit 1; done'

# Unexpected/static robustness checks: 10 tests.
run unexpected "41 admin search input has an array guard" grep_q 'is_array|filter_input' public/admin.php
run unexpected "42 admin POST title/body parsing has array guards" grep_q 'is_array.*title|filter_input' public/admin.php
run unexpected "43 view token parsing has an array guard" grep_q 'is_array|filter_input' public/view.php
run unexpected "44 share action parsing rejects unknown actions explicitly" grep_q 'Unknown action|Invalid action|elseif' public/share.php
run unexpected "45 shares schema supports one-time token consumption" sql_eq "1" "SELECT COUNT(*) FROM pragma_table_info('shares') WHERE name IN ('consumed_at', 'used_at', 'redeemed_at');"
run unexpected "46 recipient view consumes or invalidates token after success" grep_q 'UPDATE shares|DELETE FROM shares|consumed_at|used_at|redeemed_at' public/view.php
run unexpected "47 audit helper can record the actual acting staff ID" grep_q 'function audit_log\(.*staff' lib/bootstrap.php
run unexpected "48 title search is not limited to SQLite ASCII LOWER behavior" grep_q 'mb_strtolower|COLLATE|icu|unicode' lib/bootstrap.php
run unexpected "49 readable ID suffix is generated with CSPRNG bytes" grep_q 'random_bytes\(2\)' lib/bootstrap.php
run unexpected "50 public request parsing avoids direct array-unsafe trim calls" bash -c '! grep -R "trim(\\$_\\(GET\\|POST\\)" public >/dev/null'

# Customer fool behavior through CLI/helper calls: 10 tests.
run fool "51 blank title throws" php_snippet_ok 'require "lib/bootstrap.php"; try { create_document("", "body", 1); exit(1); } catch (Throwable $e) {}'
run fool "52 spaces-only title throws" php_snippet_ok 'require "lib/bootstrap.php"; try { create_document("   ", "body", 1); exit(1); } catch (Throwable $e) {}'
run fool "53 blank body throws" php_snippet_ok 'require "lib/bootstrap.php"; try { create_document("title", "", 1); exit(1); } catch (Throwable $e) {}'
run fool "54 invalid email throws" php_snippet_ok 'require "lib/bootstrap.php"; try { create_share(1, "not-an-email"); exit(1); } catch (Throwable $e) {}'
run fool "55 spaces-only token returns null" php_snippet_ok 'require "lib/bootstrap.php"; if (recipient_document_for_token("   ") !== null) exit(1);'
run fool "56 spaces-only document reference returns null" php_snippet_ok 'require "lib/bootstrap.php"; if (find_document_by_reference("   ") !== null) exit(1);'
run fool "57 spaces-only datetime-local parses as null" php_snippet_ok 'require "lib/bootstrap.php"; if (parse_datetime_local_to_utc("   ") !== null) exit(1);'
run fool "58 malformed stored schedule throws" php_snippet_ok 'require "lib/bootstrap.php"; try { normalize_published_at("tomorrow"); exit(1); } catch (Throwable $e) {}'
run fool "59 recipient email is trimmed before storage" php_snippet_ok 'require "lib/bootstrap.php"; $t = create_share(1, "  cli-trim@example.com  "); $s = db()->prepare("SELECT recipient_email FROM shares WHERE token = ?"); $s->execute([$t]); if ($s->fetchColumn() !== "cli-trim@example.com") exit(1);'
run fool "60 unknown document share throws" php_snippet_ok 'require "lib/bootstrap.php"; try { create_share(999999, "reader@example.com"); exit(1); } catch (Throwable $e) {}'

echo
echo "CLI/SQLite/static independent result: ${PASS} passed, ${FAIL} failed."
exit "$FAIL"
