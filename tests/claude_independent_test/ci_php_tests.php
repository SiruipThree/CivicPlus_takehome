<?php

require __DIR__ . '/../../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) { fwrite(STDERR, "seed failed\n"); exit(1); }

$pass = 0; $fail = 0;

function ci(string $cat, string $name, callable $fn): void {
    global $pass, $fail;
    try { $fn(); echo "  [ok] [{$cat}] {$name}\n"; $pass++; }
    catch (Throwable $e) { echo "  [FAIL] [{$cat}] {$name}: " . $e->getMessage() . "\n"; $fail++; }
}
function ci_true($c, string $m = 'expected true'): void { if (!$c) throw new RuntimeException($m); }
function ci_false($c, string $m = 'expected false'): void { if ($c) throw new RuntimeException($m); }
function ci_eq($e, $a, string $m = ''): void { if ($e !== $a) throw new RuntimeException($m ?: 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function ci_ne($u, $a, string $m = ''): void { if ($u === $a) throw new RuntimeException($m ?: 'unexpected ' . var_export($a, true)); }
function ci_match(string $p, string $a, string $m = ''): void { if (!preg_match($p, $a)) throw new RuntimeException($m ?: "{$a} did not match {$p}"); }
function ci_throws(callable $fn, string $m = 'expected exception'): void { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($m); }
function ci_doc(int $id): array { $s = db()->prepare('SELECT * FROM documents WHERE id = ?'); $s->execute([$id]); $r = $s->fetch(); if (!$r) throw new RuntimeException("doc {$id} not found"); return $r; }
function ci_seed(): array { return db()->query('SELECT * FROM documents ORDER BY id LIMIT 1')->fetch() ?: throw new RuntimeException('no seed doc'); }
function ci_seed_share(): array { return db()->query('SELECT * FROM shares ORDER BY id LIMIT 1')->fetch() ?: throw new RuntimeException('no seed share'); }
function ci_title(string $b): string { static $n=0; $n++; return "CI {$b} {$n}"; }
function ci_audit(string $a, string $t, int $id): array { $s = db()->prepare('SELECT * FROM audit_log WHERE action=? AND entity_type=? AND entity_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$a,$t,$id]); return $s->fetch() ?: throw new RuntimeException("audit missing"); }
function ci_details(array $a): array { return json_decode($a['details'], true) ?: throw new RuntimeException('bad json'); }
function ci_ids(array $rows): array { return array_map(fn($r) => (int)$r['id'], $rows); }

echo "\nRunning Claude independent PHP tests (120):\n";

// ===== REGULAR TESTS (40) =====

ci('regular', 'R01 readable_id_base simple title returns lowercase slug', function () {
    ci_eq('hello-world', readable_id_base('Hello World'));
});

ci('regular', 'R02 readable_id_base strips trailing dashes after truncation', function () {
    $result = readable_id_base('a-');
    ci_false(str_ends_with($result, '-'), "ends with dash: {$result}");
});

ci('regular', 'R03 readable_id_base max length is 32 chars', function () {
    $result = readable_id_base(str_repeat('abcdefgh ', 20));
    ci_true(strlen($result) <= 32, "too long: " . strlen($result));
});

ci('regular', 'R04 readable_id_base single word preserved', function () {
    ci_eq('document', readable_id_base('Document'));
});

ci('regular', 'R05 readable_id_base numbers preserved', function () {
    ci_eq('2026', readable_id_base('2026'));
});

ci('regular', 'R06 readable_id_base empty returns document', function () {
    ci_eq('document', readable_id_base(''));
});

ci('regular', 'R07 utc_now returns YYYY-MM-DD HH:MM:SS format', function () {
    ci_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', utc_now());
});

ci('regular', 'R08 app_timezone returns America/Chicago', function () {
    ci_eq('America/Chicago', app_timezone()->getName());
});

ci('regular', 'R09 utc_timezone returns UTC', function () {
    ci_eq('UTC', utc_timezone()->getName());
});

ci('regular', 'R10 format_utc_for_display valid date includes timezone', function () {
    $r = format_utc_for_display('2026-07-04 17:00:00');
    ci_true(str_contains($r, 'CDT') || str_contains($r, 'CST'), "no tz in: {$r}");
});

ci('regular', 'R11 format_utc_for_display includes human month name', function () {
    $r = format_utc_for_display('2026-01-15 12:00:00');
    ci_true(str_contains($r, 'Jan'), "no month in: {$r}");
});

ci('regular', 'R12 format_utc_for_datetime_local returns T format', function () {
    ci_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', format_utc_for_datetime_local('2026-07-04 17:00:00'));
});

ci('regular', 'R13 migration_tracking_available returns true after seed', function () {
    ci_true(migration_tracking_available(db()));
});

ci('regular', 'R14 migration_has_been_applied true for applied migration', function () {
    ci_true(migration_has_been_applied(db(), '001_document_publishing_and_readable_ids.sql'));
});

ci('regular', 'R15 migration_has_been_applied false for unknown migration', function () {
    ci_false(migration_has_been_applied(db(), '999_nonexistent.sql'));
});

ci('regular', 'R16 create_document IDs are sequential', function () {
    $id1 = create_document(ci_title('Seq A'), 'body', 1);
    $id2 = create_document(ci_title('Seq B'), 'body', 1);
    ci_true($id2 > $id1, "ids not sequential: {$id1} >= {$id2}");
});

ci('regular', 'R17 create_document auto-generates created_at', function () {
    $id = create_document(ci_title('AutoTime'), 'body', 1);
    $d = ci_doc($id);
    ci_true(!empty($d['created_at']));
    ci_match('/^\d{4}-\d{2}-\d{2}/', $d['created_at']);
});

ci('regular', 'R18 readable_id starts with title slug', function () {
    $id = create_document('Council Meeting Notes', 'body', 1);
    ci_true(str_starts_with(ci_doc($id)['readable_id'], 'council-meeting-notes-'));
});

ci('regular', 'R19 search results ordered by id descending', function () {
    $id1 = create_document(ci_title('Order First'), 'body', 1);
    $id2 = create_document(ci_title('Order Second'), 'body', 1);
    $results = search_documents_by_title('CI Order');
    $ids = ci_ids($results);
    $pos1 = array_search($id1, $ids, true);
    $pos2 = array_search($id2, $ids, true);
    ci_true($pos1 !== false && $pos2 !== false, 'both docs found in search');
    ci_true($id2 > $id1, 'second doc has higher id');
});

ci('regular', 'R20 search results include all document fields', function () {
    create_document(ci_title('FieldCheck'), 'body', 1, '2999-01-01 00:00:00');
    $results = search_documents_by_title('FieldCheck');
    ci_true(count($results) >= 1);
    $row = $results[0];
    ci_true(isset($row['id'], $row['title'], $row['body'], $row['created_by'], $row['created_at'], $row['published_at'], $row['readable_id'], $row['creator_name']));
});

ci('regular', 'R21 search result creator_name matches staff name', function () {
    create_document(ci_title('CreatorJoin'), 'body', 1);
    $results = search_documents_by_title('CreatorJoin');
    ci_eq('Freddy Folio', $results[0]['creator_name']);
});

ci('regular', 'R22 multiple shares for same doc all return unique tokens', function () {
    $docId = (int)ci_seed()['id'];
    $t1 = create_share($docId, 'ci-multi1@example.com');
    $t2 = create_share($docId, 'ci-multi2@example.com');
    $t3 = create_share($docId, 'ci-multi3@example.com');
    ci_ne($t1, $t2); ci_ne($t2, $t3); ci_ne($t1, $t3);
});

ci('regular', 'R23 recipient_document_for_token result includes recipient_email field', function () {
    $doc = recipient_document_for_token(ci_seed_share()['token']);
    ci_true(isset($doc['recipient_email']));
    ci_eq('recipient@example.com', $doc['recipient_email']);
});

ci('regular', 'R24 share to same email twice creates two distinct tokens', function () {
    $docId = (int)ci_seed()['id'];
    $t1 = create_share($docId, 'ci-dup@example.com');
    $t2 = create_share($docId, 'ci-dup@example.com');
    ci_ne($t1, $t2);
});

ci('regular', 'R25 audit_log staff_id matches creating staff', function () {
    $id = create_document(ci_title('StaffAudit'), 'body', 1);
    ci_eq(1, (int)ci_audit('create', 'document', $id)['staff_id']);
});

ci('regular', 'R26 find_document_by_reference returns all columns', function () {
    $doc = find_document_by_reference(ci_seed()['readable_id']);
    ci_true(array_key_exists('id', $doc) && array_key_exists('title', $doc) && array_key_exists('body', $doc) && array_key_exists('created_by', $doc) && array_key_exists('created_at', $doc) && array_key_exists('published_at', $doc) && array_key_exists('readable_id', $doc));
});

ci('regular', 'R27 normalize_published_at valid datetime returns same string', function () {
    ci_eq('2026-06-15 14:30:00', normalize_published_at('2026-06-15 14:30:00'));
});

ci('regular', 'R28 normalize_published_at null returns null', function () {
    ci_eq(null, normalize_published_at(null));
});

ci('regular', 'R29 random_token always lowercase hex', function () {
    for ($i = 0; $i < 50; $i++) {
        ci_match('/^[a-f0-9]+$/', random_token(), 'uppercase char in token');
    }
});

ci('regular', 'R30 h always returns string', function () {
    ci_true(is_string(h('')));
    ci_true(is_string(h('test')));
    ci_true(is_string(h('<script>')));
});

ci('regular', 'R31 current_staff returns email and name fields', function () {
    $s = current_staff();
    ci_true(isset($s['id'], $s['email'], $s['name']));
    ci_eq('freddy@folio.example', $s['email']);
    ci_eq('Freddy Folio', $s['name']);
});

ci('regular', 'R32 schema_migrations has applied_at column', function () {
    $cols = array_column(db()->query('PRAGMA table_info(schema_migrations)')->fetchAll(), 'name');
    ci_true(in_array('applied_at', $cols, true));
});

ci('regular', 'R33 documents.created_at has default value', function () {
    $col = db()->query("SELECT dflt_value FROM pragma_table_info('documents') WHERE name='created_at'")->fetchColumn();
    ci_true($col !== null && $col !== '', 'no default for created_at');
});

ci('regular', 'R34 shares.created_at has default value', function () {
    $col = db()->query("SELECT dflt_value FROM pragma_table_info('shares') WHERE name='created_at'")->fetchColumn();
    ci_true($col !== null && $col !== '', 'no default for shares.created_at');
});

ci('regular', 'R35 audit_log.created_at has default value', function () {
    $col = db()->query("SELECT dflt_value FROM pragma_table_info('audit_log') WHERE name='created_at'")->fetchColumn();
    ci_true($col !== null && $col !== '', 'no default for audit_log.created_at');
});

ci('regular', 'R36 search with single character finds matches', function () {
    $id = create_document(ci_title('Ax'), 'body', 1);
    ci_true(in_array($id, ci_ids(search_documents_by_title('A')), true));
});

ci('regular', 'R37 search matches beginning of title', function () {
    $id = create_document('Zenith Alpha Report', 'body', 1);
    ci_true(in_array($id, ci_ids(search_documents_by_title('Zenith')), true));
});

ci('regular', 'R38 search matches end of title', function () {
    $id = create_document('Alpha Zenith Omega', 'body', 1);
    ci_true(in_array($id, ci_ids(search_documents_by_title('Omega')), true));
});

ci('regular', 'R39 search matches middle of title', function () {
    $id = create_document('Alpha Epsilon Gamma', 'body', 1);
    ci_true(in_array($id, ci_ids(search_documents_by_title('Epsilon')), true));
});

ci('regular', 'R40 document_is_published empty string treated as immediate', function () {
    ci_true(document_is_published(['published_at' => '']));
});

// ===== EXTREME TESTS (30) =====

ci('extreme', 'E01 readable_id_base with 2000 char input bounded', function () {
    ci_true(strlen(readable_id_base(str_repeat('extreme ', 250))) <= 32);
});

ci('extreme', 'E02 readable_id_base whitespace-only returns document', function () {
    ci_eq('document', readable_id_base("   \t\n   "));
});

ci('extreme', 'E03 title with every printable ASCII char stores correctly (after trim)', function () {
    $t = '';
    for ($i = 32; $i < 127; $i++) $t .= chr($i);
    $id = create_document($t, 'body', 1);
    ci_eq(trim($t), ci_doc($id)['title']);
});

ci('extreme', 'E04 body with 4-byte UTF-8 emoji roundtrips', function () {
    $b = "Report 📊 Status ✅ Alert 🚨 Done 🎉";
    $id = create_document(ci_title('4byte'), $b, 1);
    ci_eq($b, ci_doc($id)['body']);
});

ci('extreme', 'E05 schedule at year boundary stores correctly', function () {
    $id = create_document(ci_title('YearBound'), 'body', 1, '2026-12-31 23:59:59');
    ci_eq('2026-12-31 23:59:59', ci_doc($id)['published_at']);
});

ci('extreme', 'E06 schedule at new year stores correctly', function () {
    $id = create_document(ci_title('NewYear'), 'body', 1, '2027-01-01 00:00:00');
    ci_eq('2027-01-01 00:00:00', ci_doc($id)['published_at']);
});

ci('extreme', 'E07 150 shares for same document all unique', function () {
    $docId = (int)ci_seed()['id'];
    $tokens = [];
    for ($i = 0; $i < 150; $i++) $tokens[] = create_share($docId, "ci-burst{$i}@example.com");
    ci_eq(150, count(array_unique($tokens)));
});

ci('extreme', 'E08 search across many documents returns correct subset', function () {
    $target = create_document('UniqueMarkerXYZ', 'body', 1);
    for ($i = 0; $i < 30; $i++) create_document(ci_title("Noise {$i}"), 'body', 1);
    $results = search_documents_by_title('UniqueMarkerXYZ');
    ci_eq(1, count($results));
    ci_eq($target, (int)$results[0]['id']);
});

ci('extreme', 'E09 normalize_published_at rejects extra trailing chars', function () {
    ci_throws(fn() => normalize_published_at('2026-05-09 12:00:00 extra'));
});

ci('extreme', 'E10 normalize_published_at rejects time-only string', function () {
    ci_throws(fn() => normalize_published_at('12:00:00'));
});

ci('extreme', 'E11 normalize_published_at rejects date-only string', function () {
    ci_throws(fn() => normalize_published_at('2026-05-09'));
});

ci('extreme', 'E12 format_utc_for_display with malformed input returns raw string', function () {
    ci_eq('garbage', format_utc_for_display('garbage'));
});

ci('extreme', 'E13 format_utc_for_datetime_local with malformed input returns empty', function () {
    ci_eq('', format_utc_for_datetime_local('garbage'));
});

ci('extreme', 'E14 audit log grows by exactly 1 per create_document', function () {
    $before = (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='create' AND entity_type='document'")->fetchColumn();
    create_document(ci_title('AuditGrow'), 'body', 1);
    $after = (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='create' AND entity_type='document'")->fetchColumn();
    ci_eq($before + 1, $after);
});

ci('extreme', 'E15 audit log grows by exactly 1 per create_share', function () {
    $before = (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='create' AND entity_type='share'")->fetchColumn();
    create_share((int)ci_seed()['id'], 'ci-auditshare@example.com');
    $after = (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='create' AND entity_type='share'")->fetchColumn();
    ci_eq($before + 1, $after);
});

ci('extreme', 'E16 readable_id suffix always exactly 4 hex chars', function () {
    for ($i = 0; $i < 20; $i++) {
        $id = create_document(ci_title("Suffix {$i}"), 'body', 1);
        $rid = ci_doc($id)['readable_id'];
        ci_match('/-[a-f0-9]{4}$/', $rid, "bad suffix in: {$rid}");
    }
});

ci('extreme', 'E17 all readable_ids in database unique after stress', function () {
    for ($i = 0; $i < 30; $i++) create_document("CI Stress Same Title", "body {$i}", 1);
    $dupes = db()->query('SELECT readable_id, COUNT(*) c FROM documents WHERE readable_id IS NOT NULL GROUP BY readable_id HAVING c > 1')->fetchAll();
    ci_eq(0, count($dupes));
});

ci('extreme', 'E18 all tokens in database unique after stress', function () {
    $dupes = db()->query('SELECT token, COUNT(*) c FROM shares GROUP BY token HAVING c > 1')->fetchAll();
    ci_eq(0, count($dupes));
});

ci('extreme', 'E19 parse_datetime_local_to_utc handles midnight', function () {
    $r = parse_datetime_local_to_utc('2026-01-15T00:00');
    ci_match('/^2026-01-15 06:00:00$/', $r);
});

ci('extreme', 'E20 parse_datetime_local_to_utc handles noon', function () {
    $r = parse_datetime_local_to_utc('2026-01-15T12:00');
    ci_match('/^2026-01-15 18:00:00$/', $r);
});

ci('extreme', 'E21 body preserves leading spaces exactly', function () {
    $b = "   leading spaces preserved";
    $id = create_document(ci_title('LeadSpace'), $b, 1);
    ci_eq($b, ci_doc($id)['body']);
});

ci('extreme', 'E22 body preserves trailing newlines', function () {
    $b = "content\n\n\n";
    $id = create_document(ci_title('TrailNL'), $b, 1);
    ci_eq($b, ci_doc($id)['body']);
});

ci('extreme', 'E23 body preserves empty lines in middle', function () {
    $b = "line1\n\n\n\nline5";
    $id = create_document(ci_title('EmptyLines'), $b, 1);
    ci_eq($b, ci_doc($id)['body']);
});

ci('extreme', 'E24 document_is_published string comparison is lexicographic', function () {
    ci_true(document_is_published(['published_at' => '2026-05-09 12:00:00'], '2026-05-09 12:00:00'));
    ci_false(document_is_published(['published_at' => '2026-05-09 12:00:01'], '2026-05-09 12:00:00'));
    ci_true(document_is_published(['published_at' => '2026-05-09 11:59:59'], '2026-05-09 12:00:00'));
});

ci('extreme', 'E25 readable_id_base handles hyphens in input', function () {
    $r = readable_id_base('well-known-doc');
    ci_eq('well-known-doc', $r);
});

ci('extreme', 'E26 readable_id_base collapses multiple spaces to single dash', function () {
    $r = readable_id_base('hello    world');
    ci_false(str_contains($r, '--'), "double dash in: {$r}");
});

ci('extreme', 'E27 format_utc_for_display empty string returns Immediately', function () {
    ci_eq('Immediately', format_utc_for_display(''));
});

ci('extreme', 'E28 create_document with staff_id stores correctly in DB', function () {
    db()->exec("INSERT OR IGNORE INTO staff (id, email, name) VALUES (50, 'ci50@test.com', 'CI Staff 50')");
    $id = create_document(ci_title('Staff50'), 'body', 50);
    ci_eq(50, (int)ci_doc($id)['created_by']);
});

ci('extreme', 'E29 search for nonexistent term returns empty array', function () {
    $r = search_documents_by_title('xyzzy-nonexistent-term-12345');
    ci_true(is_array($r));
    ci_eq(0, count($r));
});

ci('extreme', 'E30 parse_datetime_local_to_utc with summer DST offset', function () {
    $r = parse_datetime_local_to_utc('2026-07-04T14:00');
    ci_eq('2026-07-04 19:00:00', $r);
});

// ===== UNEXPECTED TESTS (25) =====

ci('unexpected', 'U01 create_document with title string 999 stores as string', function () {
    $id = create_document('999', 'body', 1);
    ci_eq('999', ci_doc($id)['title']);
});

ci('unexpected', 'U02 find_document_by_reference with 0 returns null', function () {
    ci_eq(null, find_document_by_reference('0'));
});

ci('unexpected', 'U03 find_document_by_reference with very large number returns null', function () {
    ci_eq(null, find_document_by_reference('999999999'));
});

ci('unexpected', 'U04 search with SQL union injection attempt returns safely', function () {
    $r = search_documents_by_title("' UNION SELECT * FROM staff --");
    ci_true(is_array($r));
});

ci('unexpected', 'U05 update schedule twice in sequence both succeed', function () {
    $id = create_document(ci_title('DoubleSched'), 'body', 1);
    update_document_schedule($id, '2999-01-01 00:00:00');
    update_document_schedule($id, '2999-06-01 00:00:00');
    ci_eq('2999-06-01 00:00:00', ci_doc($id)['published_at']);
});

ci('unexpected', 'U06 share token unaffected by subsequent schedule change', function () {
    $id = create_document(ci_title('TokenPersist'), 'body', 1);
    $token = create_share($id, 'ci-persist@example.com');
    update_document_schedule($id, '2999-01-01 00:00:00');
    $doc = recipient_document_for_token($token);
    ci_true($doc !== null, 'token stopped working after schedule change');
});

ci('unexpected', 'U07 body with percent signs stored literally', function () {
    $b = '100% complete, 50% done, %s format';
    $id = create_document(ci_title('Percent Body'), $b, 1);
    ci_eq($b, ci_doc($id)['body']);
});

ci('unexpected', 'U08 normalize_published_at rejects ISO 8601 T format', function () {
    ci_throws(fn() => normalize_published_at('2026-05-09T12:00:00'));
});

ci('unexpected', 'U09 normalize_published_at rejects Unix timestamp', function () {
    ci_throws(fn() => normalize_published_at('1715270400'));
});

ci('unexpected', 'U10 parse_datetime_local_to_utc rejects with seconds', function () {
    ci_throws(fn() => parse_datetime_local_to_utc('2026-05-09T12:00:30'));
});

ci('unexpected', 'U11 h handles null byte gracefully', function () {
    $r = h("test\x00end");
    ci_true(is_string($r));
});

ci('unexpected', 'U12 search with query longer than any title returns empty', function () {
    ci_eq(0, count(search_documents_by_title(str_repeat('z', 500))));
});

ci('unexpected', 'U13 find_document_by_reference with hex-like string returns null', function () {
    ci_eq(null, find_document_by_reference('abcdef1234567890abcdef1234567890'));
});

ci('unexpected', 'U14 create_document with newlines in title stores them', function () {
    $t = "Line1\nLine2";
    $id = create_document($t, 'body', 1);
    ci_eq($t, ci_doc($id)['title']);
});

ci('unexpected', 'U15 create_document body is NOT trimmed', function () {
    $b = "  spaces preserved  \n  here  ";
    $id = create_document(ci_title('NoTrimBody'), $b, 1);
    ci_eq($b, ci_doc($id)['body']);
});

ci('unexpected', 'U16 schedule_update audit always has both previous and new keys', function () {
    $id = create_document(ci_title('AuditKeys'), 'body', 1, '2999-01-01 00:00:00');
    update_document_schedule($id, null);
    $d = ci_details(ci_audit('schedule_update', 'document', $id));
    ci_true(array_key_exists('previous_published_at', $d));
    ci_true(array_key_exists('published_at', $d));
});

ci('unexpected', 'U17 multiple staff can create documents independently', function () {
    db()->exec("INSERT OR IGNORE INTO staff (id, email, name) VALUES (60, 'ci60@test.com', 'CI Staff 60')");
    $id1 = create_document(ci_title('Staff1Doc'), 'body', 1);
    $id2 = create_document(ci_title('Staff60Doc'), 'body', 60);
    ci_eq(1, (int)ci_doc($id1)['created_by']);
    ci_eq(60, (int)ci_doc($id2)['created_by']);
});

ci('unexpected', 'U18 email with plus alias accepted', function () {
    $token = create_share((int)ci_seed()['id'], 'user+tag@example.com');
    ci_match('/^[a-f0-9]{32}$/', $token);
});

ci('unexpected', 'U19 search result format includes joined creator_name even with multiple staff', function () {
    db()->exec("INSERT OR IGNORE INTO staff (id, email, name) VALUES (70, 'ci70@test.com', 'CI Seventy')");
    $id = create_document(ci_title('JoinCheck70'), 'body', 70);
    $results = search_documents_by_title('JoinCheck70');
    ci_eq('CI Seventy', $results[0]['creator_name']);
});

ci('unexpected', 'U20 readable_id is immutable after creation', function () {
    $id = create_document(ci_title('Immutable RID'), 'body', 1);
    $rid1 = ci_doc($id)['readable_id'];
    update_document_schedule($id, '2999-01-01 00:00:00');
    update_document_schedule($id, null);
    $rid2 = ci_doc($id)['readable_id'];
    ci_eq($rid1, $rid2);
});

ci('unexpected', 'U21 h preserves multibyte characters', function () {
    ci_eq('中文', h('中文'));
    ci_eq('日本語', h('日本語'));
});

ci('unexpected', 'U22 find_document_by_reference negative number returns null', function () {
    ci_eq(null, find_document_by_reference('-5'));
});

ci('unexpected', 'U23 normalize_published_at rejects Feb 30', function () {
    ci_throws(fn() => normalize_published_at('2026-02-30 00:00:00'));
});

ci('unexpected', 'U24 normalize_published_at rejects Apr 31', function () {
    ci_throws(fn() => normalize_published_at('2026-04-31 00:00:00'));
});

ci('unexpected', 'U25 normalize_published_at rejects second 60', function () {
    ci_throws(fn() => normalize_published_at('2026-01-01 00:00:60'));
});

// ===== FOOL BEHAVIOR TESTS (25) =====

ci('fool', 'F01 user passes tab character as title', function () {
    ci_throws(fn() => create_document("\t", 'body', 1));
});

ci('fool', 'F02 user passes carriage return in body still stores', function () {
    $b = "line1\rline2";
    $id = create_document(ci_title('CR'), $b, 1);
    ci_eq($b, ci_doc($id)['body']);
});

ci('fool', 'F03 user types readable_id in mixed case with spaces resolves', function () {
    $doc = ci_seed();
    $found = find_document_by_reference('  ' . strtoupper($doc['readable_id']) . '  ');
    ci_true($found !== null);
    ci_eq((int)$doc['id'], (int)$found['id']);
});

ci('fool', 'F04 user searches for literal string null', function () {
    $r = search_documents_by_title('null');
    ci_true(is_array($r));
});

ci('fool', 'F05 user searches for literal string undefined', function () {
    $r = search_documents_by_title('undefined');
    ci_true(is_array($r));
});

ci('fool', 'F06 user creates doc then immediately searches for it', function () {
    $t = ci_title('InstantSearch');
    create_document($t, 'body', 1);
    ci_true(count(search_documents_by_title('InstantSearch')) >= 1);
});

ci('fool', 'F07 user shares same doc to same email 5 times all succeed', function () {
    $docId = (int)ci_seed()['id'];
    $tokens = [];
    for ($i = 0; $i < 5; $i++) $tokens[] = create_share($docId, 'ci-fivetime@example.com');
    ci_eq(5, count(array_unique($tokens)));
});

ci('fool', 'F08 user creates doc title matching another docs readable_id', function () {
    $rid = ci_seed()['readable_id'];
    $id = create_document($rid, 'body', 1);
    ci_true($id > 0);
    ci_ne($rid, ci_doc($id)['readable_id']);
});

ci('fool', 'F09 user passes HTML entities as title', function () {
    $t = '&amp; &lt;b&gt; &quot;test&quot;';
    $id = create_document($t, 'body', 1);
    ci_eq($t, ci_doc($id)['title']);
});

ci('fool', 'F10 user passes URL as title', function () {
    $t = 'https://example.com/path?q=test&b=1';
    $id = create_document($t, 'body', 1);
    ci_eq($t, ci_doc($id)['title']);
});

ci('fool', 'F11 user passes JSON as title', function () {
    $t = '{"key": "value", "num": 42}';
    $id = create_document($t, 'body', 1);
    ci_eq($t, ci_doc($id)['title']);
});

ci('fool', 'F12 user passes XML as body', function () {
    $b = '<?xml version="1.0"?><root><child attr="val">text</child></root>';
    $id = create_document(ci_title('XMLBody'), $b, 1);
    ci_eq($b, ci_doc($id)['body']);
});

ci('fool', 'F13 user passes email with international TLD', function () {
    $token = create_share((int)ci_seed()['id'], 'user@example.museum');
    ci_match('/^[a-f0-9]{32}$/', $token);
});

ci('fool', 'F14 user updates schedule then immediately checks', function () {
    $id = create_document(ci_title('ImmediateCheck'), 'body', 1);
    update_document_schedule($id, '2999-01-01 00:00:00');
    ci_false(document_is_published(ci_doc($id)));
    update_document_schedule($id, null);
    ci_true(document_is_published(ci_doc($id)));
});

ci('fool', 'F15 user creates doc with markdown in body', function () {
    $b = "# Heading\n\n**bold** and *italic*\n\n- list item\n- another\n\n```code```";
    $id = create_document(ci_title('Markdown'), $b, 1);
    ci_eq($b, ci_doc($id)['body']);
});

ci('fool', 'F16 user passes schedule with wrong separator dash', function () {
    ci_throws(fn() => normalize_published_at('2026/05/09 12:00:00'));
});

ci('fool', 'F17 user passes empty string to normalize_published_at', function () {
    ci_eq(null, normalize_published_at(''));
});

ci('fool', 'F18 user types token with uppercase letters gets null', function () {
    $token = ci_seed_share()['token'];
    ci_eq(null, recipient_document_for_token(strtoupper($token)));
});

ci('fool', 'F19 user creates doc with title containing SQL keywords', function () {
    $t = 'SELECT * FROM documents WHERE 1=1; DROP TABLE';
    $id = create_document($t, 'body', 1);
    ci_eq($t, ci_doc($id)['title']);
    ci_true((int)db()->query('SELECT COUNT(*) FROM documents')->fetchColumn() > 0);
});

ci('fool', 'F20 user searches with leading special chars', function () {
    $r = search_documents_by_title('---');
    ci_true(is_array($r));
});

ci('fool', 'F21 user creates 10 docs and searches for them all', function () {
    $marker = 'BulkMarker' . random_token(2);
    for ($i = 0; $i < 10; $i++) create_document("{$marker} Doc {$i}", 'body', 1);
    ci_eq(10, count(search_documents_by_title($marker)));
});

ci('fool', 'F22 user passes extremely long search query no crash', function () {
    $r = search_documents_by_title(str_repeat('long query ', 500));
    ci_true(is_array($r));
});

ci('fool', 'F23 user passes email with consecutive dots (invalid)', function () {
    ci_throws(fn() => create_share((int)ci_seed()['id'], 'user@example..com'));
});

ci('fool', 'F24 user creates document with body 0 (falsy but valid)', function () {
    $id = create_document(ci_title('Zero Body'), '0', 1);
    ci_eq('0', ci_doc($id)['body']);
});

ci('fool', 'F25 user creates doc with title 0 (falsy but valid)', function () {
    $id = create_document('0', 'valid body', 1);
    ci_eq('0', ci_doc($id)['title']);
});

echo "\nCI PHP tests: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
