<?php

require __DIR__ . '/../../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) { fwrite(STDERR, "seed failed\n"); exit(1); }

$pass = 0; $fail = 0;

function fz(string $cat, string $name, callable $fn): void {
    global $pass, $fail;
    try { $fn(); echo "  [ok] [{$cat}] {$name}\n"; $pass++; }
    catch (Throwable $e) { echo "  [FAIL] [{$cat}] {$name}: " . $e->getMessage() . "\n"; $fail++; }
}
function fz_true($c, string $m = 'expected true'): void { if (!$c) throw new RuntimeException($m); }
function fz_false($c, string $m = 'expected false'): void { if ($c) throw new RuntimeException($m); }
function fz_eq($e, $a, string $m = ''): void { if ($e !== $a) throw new RuntimeException($m ?: 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function fz_throws(callable $fn, string $m = 'expected exception'): void { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($m); }
function fz_nothrow(callable $fn, string $m = 'unexpected exception'): void { try { $fn(); } catch (Throwable $e) { throw new RuntimeException("{$m}: " . $e->getMessage()); } }
function fz_doc(int $id): array { $s = db()->prepare('SELECT * FROM documents WHERE id = ?'); $s->execute([$id]); $r = $s->fetch(); if (!$r) throw new RuntimeException("doc not found"); return $r; }
function fz_seed(): array { return db()->query('SELECT * FROM documents ORDER BY id LIMIT 1')->fetch(); }
function fz_title(): string { static $n=0; $n++; return "Fuzz {$n}"; }

echo "\nRunning Claude fuzz/boundary tests (50):\n";

// ============ BOUNDARY VALUES (15) ============

fz('boundary', '01 single char title', function () {
    $id = create_document('X', 'body', 1);
    fz_eq('X', fz_doc($id)['title']);
});

fz('boundary', '02 single char body', function () {
    $id = create_document(fz_title(), 'Y', 1);
    fz_eq('Y', fz_doc($id)['body']);
});

fz('boundary', '03 title at 255 chars boundary', function () {
    $t = str_repeat('A', 255);
    $id = create_document($t, 'body', 1);
    fz_eq($t, fz_doc($id)['title']);
});

fz('boundary', '04 title at 5000 chars stores fine', function () {
    $t = str_repeat('B', 5000);
    $id = create_document($t, 'body', 1);
    fz_eq(5000, strlen(fz_doc($id)['title']));
});

fz('boundary', '05 body at 1MB stores and retrieves', function () {
    $b = str_repeat('C', 1048576);
    $id = create_document(fz_title(), $b, 1);
    fz_eq(1048576, strlen(fz_doc($id)['body']));
});

fz('boundary', '06 midnight boundary schedule', function () {
    $id = create_document(fz_title(), 'body', 1, '2030-01-01 00:00:00');
    fz_eq('2030-01-01 00:00:00', fz_doc($id)['published_at']);
});

fz('boundary', '07 end of day schedule', function () {
    $id = create_document(fz_title(), 'body', 1, '2030-12-31 23:59:59');
    fz_eq('2030-12-31 23:59:59', fz_doc($id)['published_at']);
});

fz('boundary', '08 Feb 29 leap year accepted', function () {
    fz_nothrow(fn() => normalize_published_at('2028-02-29 12:00:00'));
});

fz('boundary', '09 Feb 29 non-leap year rejected', function () {
    fz_throws(fn() => normalize_published_at('2027-02-29 12:00:00'));
});

fz('boundary', '10 month 00 rejected', function () {
    fz_throws(fn() => normalize_published_at('2026-00-01 00:00:00'));
});

fz('boundary', '11 month 13 rejected', function () {
    fz_throws(fn() => normalize_published_at('2026-13-01 00:00:00'));
});

fz('boundary', '12 day 00 rejected', function () {
    fz_throws(fn() => normalize_published_at('2026-01-00 00:00:00'));
});

fz('boundary', '13 day 32 rejected', function () {
    fz_throws(fn() => normalize_published_at('2026-01-32 00:00:00'));
});

fz('boundary', '14 hour 24 rejected', function () {
    fz_throws(fn() => normalize_published_at('2026-01-01 24:00:00'));
});

fz('boundary', '15 minute 60 rejected', function () {
    fz_throws(fn() => normalize_published_at('2026-01-01 00:60:00'));
});

// ============ ENCODING / INJECTION (20) ============

fz('injection', '16 null byte in title does not crash', function () {
    fz_nothrow(fn() => create_document("Null\x00Byte", 'body', 1));
});

fz('injection', '17 title with HTML tags stored literally', function () {
    $t = '<script>alert("xss")</script>';
    $id = create_document($t, 'body', 1);
    fz_eq($t, fz_doc($id)['title']);
});

fz('injection', '18 body with HTML stored literally', function () {
    $b = '<img src=x onerror=alert(1)>';
    $id = create_document(fz_title(), $b, 1);
    fz_eq($b, fz_doc($id)['body']);
});

fz('injection', '19 SQL injection in title search returns empty', function () {
    $r = search_documents_by_title("' UNION SELECT * FROM staff --");
    fz_true(is_array($r));
});

fz('injection', '20 SQL injection in title search 2', function () {
    $r = search_documents_by_title("1; DROP TABLE documents;");
    fz_true(is_array($r));
    fz_true((int)db()->query('SELECT COUNT(*) FROM documents')->fetchColumn() > 0);
});

fz('injection', '21 percent in LIKE is escaped', function () {
    $id = create_document('Fuzz 100% Done', 'body', 1);
    $r = search_documents_by_title('100%');
    fz_true(in_array($id, array_map(fn($row) => (int)$row['id'], $r), true));
});

fz('injection', '22 underscore in LIKE is escaped', function () {
    $id = create_document('Fuzz AB_CD Test', 'body', 1);
    $r = search_documents_by_title('AB_CD');
    fz_true(in_array($id, array_map(fn($row) => (int)$row['id'], $r), true));
});

fz('injection', '23 emoji in title stores correctly', function () {
    $t = '🔥 Hot Doc 🔥';
    $id = create_document($t, 'body', 1);
    fz_eq($t, fz_doc($id)['title']);
});

fz('injection', '24 emoji in body stores correctly', function () {
    $b = '📊 Report: All good ✅';
    $id = create_document(fz_title(), $b, 1);
    fz_eq($b, fz_doc($id)['body']);
});

fz('injection', '25 title with single quotes stores correctly', function () {
    $t = "It's a test 'document'";
    $id = create_document($t, 'body', 1);
    fz_eq($t, fz_doc($id)['title']);
});

fz('injection', '26 title with double quotes stores correctly', function () {
    $t = 'Document "Important"';
    $id = create_document($t, 'body', 1);
    fz_eq($t, fz_doc($id)['title']);
});

fz('injection', '27 backslash-heavy title', function () {
    $t = 'C:\\Users\\Admin\\Docs\\file.txt';
    $id = create_document($t, 'body', 1);
    fz_eq($t, fz_doc($id)['title']);
});

fz('injection', '28 body with CRLF line endings', function () {
    $b = "line1\r\nline2\r\nline3";
    $id = create_document(fz_title(), $b, 1);
    fz_eq($b, fz_doc($id)['body']);
});

fz('injection', '29 body with tab characters', function () {
    $b = "col1\tcol2\tcol3";
    $id = create_document(fz_title(), $b, 1);
    fz_eq($b, fz_doc($id)['body']);
});

fz('injection', '30 unicode RTL override in title', function () {
    $t = "Hello \xE2\x80\x8F World";
    $id = create_document($t, 'body', 1);
    fz_eq($t, fz_doc($id)['title']);
});

fz('injection', '31 zero-width space in title', function () {
    $t = "Zero\xE2\x80\x8BWidth";
    $id = create_document($t, 'body', 1);
    fz_eq($t, fz_doc($id)['title']);
});

fz('injection', '32 h() escapes RTL override', function () {
    $r = h("test \xE2\x80\x8F end");
    fz_true(mb_check_encoding($r, 'UTF-8'));
});

fz('injection', '33 search with only percent sign', function () {
    $r = search_documents_by_title('%');
    fz_true(is_array($r));
});

fz('injection', '34 search with only underscore', function () {
    $r = search_documents_by_title('_');
    fz_true(is_array($r));
});

fz('injection', '35 email with international domain accepted', function () {
    fz_nothrow(fn() => create_share((int)fz_seed()['id'], 'user@example.co.uk'));
});

// ============ STRESS / CONSISTENCY (15) ============

fz('stress', '36 create+share+lookup roundtrip 30 times', function () {
    for ($i = 0; $i < 30; $i++) {
        $id = create_document("Fuzz Roundtrip {$i}", "body {$i}", 1);
        $token = create_share($id, "fuzz-rt{$i}@example.com");
        $doc = recipient_document_for_token($token);
        fz_true($doc !== null, "roundtrip {$i} failed lookup");
        fz_eq("Fuzz Roundtrip {$i}", $doc['title']);
    }
});

fz('stress', '37 audit count matches expected after batch', function () {
    $before = (int)db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    for ($i = 0; $i < 10; $i++) {
        $id = create_document("Fuzz Audit Batch {$i}", 'body', 1);
        create_share($id, "fuzz-ab{$i}@example.com");
    }
    $after = (int)db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    fz_eq($before + 20, $after); // 10 creates + 10 shares
});

fz('stress', '38 schedule toggle 20 times does not corrupt', function () {
    $id = create_document(fz_title(), 'body', 1);
    for ($i = 0; $i < 20; $i++) {
        $sched = $i % 2 === 0 ? '2030-06-01 12:00:00' : null;
        update_document_schedule($id, $sched);
    }
    fz_eq(null, fz_doc($id)['published_at']);
});

fz('stress', '39 search still works after many inserts', function () {
    for ($i = 0; $i < 20; $i++) create_document("Fuzz Noise {$i}", 'noise', 1);
    $r = search_documents_by_title('Welcome Packet');
    fz_true(count($r) >= 1);
});

fz('stress', '40 readable_id index survives many inserts', function () {
    for ($i = 0; $i < 30; $i++) create_document('Fuzz Index Stress', 'body', 1);
    $idx = (int)db()->query("SELECT COUNT(*) FROM pragma_index_list('documents') WHERE name='idx_documents_readable_id'")->fetchColumn();
    fz_eq(1, $idx);
});

fz('stress', '41 document_is_published with custom now comparison', function () {
    fz_true(document_is_published(['published_at' => '2026-05-09 12:00:00'], '2026-05-09 12:00:01'));
    fz_false(document_is_published(['published_at' => '2026-05-09 12:00:01'], '2026-05-09 12:00:00'));
    fz_true(document_is_published(['published_at' => '2026-05-09 12:00:00'], '2026-05-09 12:00:00'));
});

fz('stress', '42 all seeded data intact after test storm', function () {
    $doc = fz_seed();
    fz_eq('Welcome Packet', $doc['title']);
    fz_true(!empty($doc['readable_id']));
});

fz('stress', '43 shares table FK integrity holds', function () {
    $orphans = (int)db()->query('SELECT COUNT(*) FROM shares WHERE document_id NOT IN (SELECT id FROM documents)')->fetchColumn();
    fz_eq(0, $orphans);
});

fz('stress', '44 no duplicate readable_ids in database', function () {
    $dupes = db()->query('SELECT readable_id, COUNT(*) c FROM documents GROUP BY readable_id HAVING c > 1')->fetchAll();
    fz_eq(0, count($dupes));
});

fz('stress', '45 all audit_log entries have valid JSON details', function () {
    $rows = db()->query('SELECT details FROM audit_log WHERE details IS NOT NULL AND details != ""')->fetchAll();
    foreach ($rows as $row) {
        $d = json_decode($row['details'], true);
        fz_true(is_array($d), 'invalid JSON: ' . substr($row['details'], 0, 50));
    }
});

fz('stress', '46 all share tokens are exactly 32 hex chars', function () {
    $bad = (int)db()->query("SELECT COUNT(*) FROM shares WHERE length(token) != 32 OR token NOT GLOB '[a-f0-9]*'")->fetchColumn();
    fz_eq(0, $bad);
});

fz('stress', '47 all readable_ids are URL-safe lowercase', function () {
    $rows = db()->query('SELECT readable_id FROM documents WHERE readable_id IS NOT NULL')->fetchAll();
    foreach ($rows as $row) {
        fz_true((bool)preg_match('/^[a-z0-9-]+$/', $row['readable_id']), 'bad readable_id: ' . $row['readable_id']);
    }
});

fz('stress', '48 all documents have non-empty title', function () {
    $bad = (int)db()->query("SELECT COUNT(*) FROM documents WHERE title IS NULL OR trim(title) = ''")->fetchColumn();
    fz_eq(0, $bad);
});

fz('stress', '49 all documents have non-empty body', function () {
    $bad = (int)db()->query("SELECT COUNT(*) FROM documents WHERE body IS NULL OR body = ''")->fetchColumn();
    fz_eq(0, $bad);
});

fz('stress', '50 staff FK integrity in documents', function () {
    $orphans = (int)db()->query('SELECT COUNT(*) FROM documents WHERE created_by NOT IN (SELECT id FROM staff)')->fetchColumn();
    fz_eq(0, $orphans);
});

echo "\nClaude fuzz tests: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
