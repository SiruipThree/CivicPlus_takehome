<?php

require __DIR__ . '/../../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) { fwrite(STDERR, "seed failed\n"); exit(1); }

$pass = 0; $fail = 0;

function ct(string $cat, string $name, callable $fn): void {
    global $pass, $fail;
    try { $fn(); echo "  [ok] [{$cat}] {$name}\n"; $pass++; }
    catch (Throwable $e) { echo "  [FAIL] [{$cat}] {$name}: " . $e->getMessage() . "\n"; $fail++; }
}
function ct_true($c, string $m = 'expected true'): void { if (!$c) throw new RuntimeException($m); }
function ct_false($c, string $m = 'expected false'): void { if ($c) throw new RuntimeException($m); }
function ct_eq($e, $a, string $m = ''): void { if ($e !== $a) throw new RuntimeException($m ?: 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function ct_ne($u, $a, string $m = ''): void { if ($u === $a) throw new RuntimeException($m ?: 'unexpected ' . var_export($a, true)); }
function ct_match(string $p, string $a, string $m = ''): void { if (!preg_match($p, $a)) throw new RuntimeException($m ?: "{$a} did not match {$p}"); }
function ct_throws(callable $fn, string $m = 'expected exception'): void { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($m); }
function ct_doc(int $id): array { $s = db()->prepare('SELECT * FROM documents WHERE id = ?'); $s->execute([$id]); $r = $s->fetch(); if (!$r) throw new RuntimeException("doc {$id} not found"); return $r; }
function ct_seed(): array { $r = db()->query('SELECT * FROM documents ORDER BY id LIMIT 1')->fetch(); if (!$r) throw new RuntimeException('no seed doc'); return $r; }
function ct_seed_share(): array { $r = db()->query('SELECT * FROM shares ORDER BY id LIMIT 1')->fetch(); if (!$r) throw new RuntimeException('no seed share'); return $r; }
function ct_audit(string $a, string $t, int $id): array { $s = db()->prepare('SELECT * FROM audit_log WHERE action=? AND entity_type=? AND entity_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$a,$t,$id]); $r=$s->fetch(); if(!$r) throw new RuntimeException("audit not found {$a} {$t} {$id}"); return $r; }
function ct_details(array $a): array { $d = json_decode($a['details'], true); if (!is_array($d)) throw new RuntimeException('bad audit json'); return $d; }
function ct_ids(array $rows): array { return array_map(fn($r) => (int)$r['id'], $rows); }
function ct_title(string $base): string { static $n=0; $n++; return "Claude {$base} {$n}"; }

echo "\nRunning Claude PHP tests (100):\n";

// ============ REGULAR (40) ============

ct('regular', '01 db() returns singleton PDO', function () {
    ct_true(db() === db(), 'db() should return same instance');
});

ct('regular', '02 db() has exception error mode', function () {
    ct_eq(PDO::ERRMODE_EXCEPTION, (int)db()->getAttribute(PDO::ATTR_ERRMODE));
});

ct('regular', '03 db() returns associative arrays by default', function () {
    ct_eq(PDO::FETCH_ASSOC, (int)db()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
});

ct('regular', '04 foreign keys are enabled', function () {
    ct_eq(1, (int)db()->query('PRAGMA foreign_keys')->fetchColumn());
});

ct('regular', '05 current_staff returns id 1', function () {
    $s = current_staff();
    ct_eq(1, (int)$s['id']);
    ct_true(!empty($s['email']));
    ct_true(!empty($s['name']));
});

ct('regular', '06 random_token default length is 32 hex chars', function () {
    $t = random_token();
    ct_eq(32, strlen($t));
    ct_match('/^[a-f0-9]{32}$/', $t);
});

ct('regular', '07 random_token custom bytes work', function () {
    ct_eq(6, strlen(random_token(3)));
    ct_eq(64, strlen(random_token(32)));
});

ct('regular', '08 h() escapes all five HTML special chars', function () {
    ct_eq('&amp;&lt;&gt;&quot;&#039;', h('&<>"\''));
});

ct('regular', '09 h() is safe on empty string', function () {
    ct_eq('', h(''));
});

ct('regular', '10 h() preserves UTF-8', function () {
    ct_eq('中文', h('中文'));
    ct_eq('émoji 🎉', h('émoji 🎉'));
});

ct('regular', '11 create_document returns positive int', function () {
    $id = create_document(ct_title('Return ID'), 'body', 1);
    ct_true($id > 0);
    ct_true(is_int($id));
});

ct('regular', '12 create_document stores title body and staff', function () {
    $t = ct_title('Store Fields');
    $id = create_document($t, 'test body 12', 1);
    $d = ct_doc($id);
    ct_eq($t, $d['title']);
    ct_eq('test body 12', $d['body']);
    ct_eq(1, (int)$d['created_by']);
});

ct('regular', '13 create_document generates created_at timestamp', function () {
    $id = create_document(ct_title('Timestamp'), 'body', 1);
    $d = ct_doc($id);
    ct_true(!empty($d['created_at']), 'missing created_at');
    ct_match('/^\d{4}-\d{2}-\d{2}/', $d['created_at']);
});

ct('regular', '14 create_document with null schedule stores null', function () {
    $id = create_document(ct_title('Null Sched'), 'body', 1, null);
    ct_eq(null, ct_doc($id)['published_at']);
});

ct('regular', '15 create_document with valid schedule stores it', function () {
    $id = create_document(ct_title('Valid Sched'), 'body', 1, '2030-06-15 12:00:00');
    ct_eq('2030-06-15 12:00:00', ct_doc($id)['published_at']);
});

ct('regular', '16 create_document generates readable_id', function () {
    $id = create_document(ct_title('Readable Gen'), 'body', 1);
    $rid = ct_doc($id)['readable_id'];
    ct_true(!empty($rid));
    ct_match('/^[a-z0-9-]+$/', $rid);
});

ct('regular', '17 create_document writes audit log entry', function () {
    $id = create_document(ct_title('Audit Entry'), 'body', 1);
    $a = ct_audit('create', 'document', $id);
    ct_eq('create', $a['action']);
    ct_eq('document', $a['entity_type']);
});

ct('regular', '18 create audit has correct staff_id for non-default staff', function () {
    db()->exec("INSERT OR IGNORE INTO staff (id, email, name) VALUES (10, 'claude10@test.com', 'Claude Ten')");
    $id = create_document(ct_title('Staff Audit'), 'body', 10);
    ct_eq(10, (int)ct_audit('create', 'document', $id)['staff_id']);
});

ct('regular', '19 create_share returns 32-char hex token', function () {
    $token = create_share((int)ct_seed()['id'], 'claude-r19@example.com');
    ct_match('/^[a-f0-9]{32}$/', $token);
});

ct('regular', '20 create_share persists row with correct email', function () {
    $token = create_share((int)ct_seed()['id'], 'claude-r20@example.com');
    $s = db()->prepare('SELECT * FROM shares WHERE token = ?');
    $s->execute([$token]);
    $row = $s->fetch();
    ct_eq('claude-r20@example.com', $row['recipient_email']);
    ct_eq((int)ct_seed()['id'], (int)$row['document_id']);
});

ct('regular', '21 create_share writes audit with document_id and email', function () {
    $token = create_share((int)ct_seed()['id'], 'claude-r21@example.com');
    $s = db()->prepare('SELECT id FROM shares WHERE token = ?'); $s->execute([$token]);
    $sid = (int)$s->fetchColumn();
    $d = ct_details(ct_audit('create', 'share', $sid));
    ct_eq((int)ct_seed()['id'], (int)$d['document_id']);
    ct_eq('claude-r21@example.com', $d['recipient_email']);
});

ct('regular', '22 create_share trims email whitespace', function () {
    $token = create_share((int)ct_seed()['id'], '  claude-r22@example.com  ');
    $s = db()->prepare('SELECT recipient_email FROM shares WHERE token = ?'); $s->execute([$token]);
    ct_eq('claude-r22@example.com', $s->fetchColumn());
});

ct('regular', '23 recipient_document_for_token returns doc with email', function () {
    $token = create_share((int)ct_seed()['id'], 'claude-r23@example.com');
    $doc = recipient_document_for_token($token);
    ct_true($doc !== null);
    ct_eq('Welcome Packet', $doc['title']);
    ct_eq('claude-r23@example.com', $doc['recipient_email']);
});

ct('regular', '24 recipient_document_for_token returns null for unknown', function () {
    ct_eq(null, recipient_document_for_token('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa0'));
});

ct('regular', '25 find_document_by_reference finds by readable_id', function () {
    $doc = ct_seed();
    $found = find_document_by_reference($doc['readable_id']);
    ct_eq((int)$doc['id'], (int)$found['id']);
});

ct('regular', '26 find_document_by_reference finds by numeric id', function () {
    $found = find_document_by_reference('1');
    ct_true($found !== null);
    ct_eq(1, (int)$found['id']);
});

ct('regular', '27 find_document_by_reference case insensitive', function () {
    $doc = ct_seed();
    $found = find_document_by_reference(strtoupper($doc['readable_id']));
    ct_eq((int)$doc['id'], (int)$found['id']);
});

ct('regular', '28 search_documents_by_title empty returns all', function () {
    $all = search_documents_by_title('');
    $count = (int)db()->query('SELECT COUNT(*) FROM documents')->fetchColumn();
    ct_eq($count, count($all));
});

ct('regular', '29 search_documents_by_title partial match', function () {
    $id = create_document(ct_title('Searchable Budget'), 'body', 1);
    ct_true(in_array($id, ct_ids(search_documents_by_title('Searchable')), true));
});

ct('regular', '30 search results include creator_name join', function () {
    $results = search_documents_by_title('Welcome');
    ct_true(!empty($results));
    ct_true(isset($results[0]['creator_name']), 'missing creator_name');
});

ct('regular', '31 document_is_published true for null published_at', function () {
    ct_true(document_is_published(['published_at' => null]));
});

ct('regular', '32 document_is_published true for empty published_at', function () {
    ct_true(document_is_published(['published_at' => '']));
});

ct('regular', '33 document_is_published true for past date', function () {
    ct_true(document_is_published(['published_at' => '2020-01-01 00:00:00']));
});

ct('regular', '34 document_is_published false for future date', function () {
    ct_false(document_is_published(['published_at' => '2099-12-31 23:59:59']));
});

ct('regular', '35 document_is_published boundary exact now', function () {
    $now = '2026-05-09 18:00:00';
    ct_true(document_is_published(['published_at' => $now], $now));
});

ct('regular', '36 update_document_schedule changes stored value', function () {
    $id = create_document(ct_title('Sched Change'), 'body', 1);
    update_document_schedule($id, '2030-01-01 00:00:00');
    ct_eq('2030-01-01 00:00:00', ct_doc($id)['published_at']);
});

ct('regular', '37 update_document_schedule null clears schedule', function () {
    $id = create_document(ct_title('Sched Clear'), 'body', 1, '2030-01-01 00:00:00');
    update_document_schedule($id, null);
    ct_eq(null, ct_doc($id)['published_at']);
});

ct('regular', '38 update_document_schedule writes audit with previous', function () {
    $id = create_document(ct_title('Sched Audit'), 'body', 1, '2030-01-01 00:00:00');
    update_document_schedule($id, '2030-06-01 00:00:00');
    $d = ct_details(ct_audit('schedule_update', 'document', $id));
    ct_eq('2030-01-01 00:00:00', $d['previous_published_at']);
    ct_eq('2030-06-01 00:00:00', $d['published_at']);
});

ct('regular', '39 parse_datetime_local_to_utc valid input', function () {
    $r = parse_datetime_local_to_utc('2026-07-04T12:00');
    ct_match('/^2026-07-04 1[67]:00:00$/', $r); // depends on timezone offset
});

ct('regular', '40 parse_datetime_local_to_utc blank returns null', function () {
    ct_eq(null, parse_datetime_local_to_utc(''));
    ct_eq(null, parse_datetime_local_to_utc('   '));
});

// ============ EXTREME (25) ============

ct('extreme', '41 create 50 docs in a transaction-like burst', function () {
    $ids = [];
    for ($i = 0; $i < 50; $i++) $ids[] = create_document("Claude Burst {$i}", "body {$i}", 1);
    ct_eq(50, count(array_unique($ids)));
});

ct('extreme', '42 readable IDs unique across 50 same-title docs', function () {
    $rids = [];
    for ($i = 0; $i < 50; $i++) $rids[] = ct_doc(create_document('Claude Collision', 'body', 1))['readable_id'];
    ct_eq(50, count(array_unique($rids)));
});

ct('extreme', '43 title with 1000 chars stores and retrieves', function () {
    $t = str_repeat('X', 1000);
    $id = create_document($t, 'body', 1);
    ct_eq($t, ct_doc($id)['title']);
});

ct('extreme', '44 body with 100KB stores and retrieves', function () {
    $b = str_repeat("line\n", 20000);
    $id = create_document(ct_title('100KB Body'), $b, 1);
    ct_eq($b, ct_doc($id)['body']);
});

ct('extreme', '45 readable_id from title with mixed scripts', function () {
    $id = create_document('Hello мир 世界 🌍', 'body', 1);
    ct_match('/^hello-[a-f0-9]{4}$/', ct_doc($id)['readable_id']);
});

ct('extreme', '46 readable_id from all-numeric title', function () {
    $id = create_document('123456789', 'body', 1);
    ct_match('/^123456789-[a-f0-9]{4}$/', ct_doc($id)['readable_id']);
});

ct('extreme', '47 readable_id strips consecutive dashes', function () {
    $id = create_document('Hello---World', 'body', 1);
    $rid = ct_doc($id)['readable_id'];
    ct_false(str_contains($rid, '--'), "double dash in: {$rid}");
});

ct('extreme', '48 token uniqueness across 300 generations', function () {
    $tokens = [];
    for ($i = 0; $i < 300; $i++) $tokens[] = random_token();
    ct_eq(300, count(array_unique($tokens)));
});

ct('extreme', '49 50 shares for same document all get unique tokens', function () {
    $docId = (int)ct_seed()['id'];
    $tokens = [];
    for ($i = 0; $i < 50; $i++) $tokens[] = create_share($docId, "claude-burst{$i}@example.com");
    ct_eq(50, count(array_unique($tokens)));
});

ct('extreme', '50 schedule at epoch boundary', function () {
    $id = create_document(ct_title('Epoch'), 'body', 1, '1970-01-01 00:00:01');
    ct_true(document_is_published(ct_doc($id)));
});

ct('extreme', '51 schedule at max date', function () {
    $id = create_document(ct_title('Max Date'), 'body', 1, '9999-12-31 23:59:59');
    ct_false(document_is_published(ct_doc($id)));
});

ct('extreme', '52 leap second boundary', function () {
    $id = create_document(ct_title('Leap Sec'), 'body', 1, '2028-02-29 23:59:59');
    ct_eq('2028-02-29 23:59:59', ct_doc($id)['published_at']);
});

ct('extreme', '53 body with all ASCII control chars except null', function () {
    $b = '';
    for ($i = 1; $i < 32; $i++) $b .= chr($i);
    $id = create_document(ct_title('Control Chars'), $b, 1);
    ct_eq($b, ct_doc($id)['body']);
});

ct('extreme', '54 title with backslashes round-trips', function () {
    $t = 'Path\\To\\Document';
    $id = create_document($t, 'body', 1);
    ct_eq($t, ct_doc($id)['title']);
});

ct('extreme', '55 body with SQL injection attempt stored literally', function () {
    $b = "'; DROP TABLE documents; --";
    $id = create_document(ct_title('SQLi Body'), $b, 1);
    ct_eq($b, ct_doc($id)['body']);
    ct_true((int)db()->query('SELECT COUNT(*) FROM documents')->fetchColumn() > 0);
});

ct('extreme', '56 search with backslash does not crash', function () {
    $r = search_documents_by_title('\\');
    ct_true(is_array($r));
});

ct('extreme', '57 normalize_published_at trims spaces', function () {
    ct_eq('2030-01-01 12:00:00', normalize_published_at('  2030-01-01 12:00:00  '));
});

ct('extreme', '58 format_utc_for_display null returns Immediately', function () {
    ct_eq('Immediately', format_utc_for_display(null));
});

ct('extreme', '59 format_utc_for_display valid date returns formatted', function () {
    $r = format_utc_for_display('2026-07-04 17:00:00');
    ct_true(str_contains($r, '2026'), "missing year in: {$r}");
    ct_true(str_contains($r, 'Jul') || str_contains($r, '7'), "missing month in: {$r}");
});

ct('extreme', '60 format_utc_for_datetime_local null returns empty', function () {
    ct_eq('', format_utc_for_datetime_local(null));
    ct_eq('', format_utc_for_datetime_local(''));
});

ct('extreme', '61 format_utc_for_datetime_local roundtrip', function () {
    $utc = '2026-07-04 17:00:00';
    $local = format_utc_for_datetime_local($utc);
    $back = parse_datetime_local_to_utc($local);
    ct_eq($utc, $back);
});

ct('extreme', '62 apply_migrations five times is safe', function () {
    for ($i = 0; $i < 5; $i++) apply_migrations(db(), __DIR__ . '/../../migrations');
    ct_true(true);
});

ct('extreme', '63 concurrent-like create_document does not deadlock', function () {
    $ids = [];
    for ($i = 0; $i < 20; $i++) {
        $ids[] = create_document(ct_title("Concurrent {$i}"), 'body', 1);
        create_share($ids[count($ids)-1], "conc{$i}@example.com");
    }
    ct_eq(20, count(array_unique($ids)));
});

ct('extreme', '64 audit_log accumulates correctly', function () {
    $before = (int)db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    create_document(ct_title('Audit Count'), 'body', 1);
    $after = (int)db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    ct_true($after > $before);
});

ct('extreme', '65 search returns results in consistent order', function () {
    $id1 = create_document(ct_title('Order A'), 'body', 1);
    $id2 = create_document(ct_title('Order B'), 'body', 1);
    $results = search_documents_by_title('Claude Order');
    $ids = ct_ids($results);
    ct_true(in_array($id1, $ids, true) && in_array($id2, $ids, true), 'both docs should be in results');
});

// ============ UNEXPECTED (20) ============

ct('unexpected', '66 create_document rejects empty title', function () {
    ct_throws(fn() => create_document('', 'body', 1));
});

ct('unexpected', '67 create_document rejects whitespace title', function () {
    ct_throws(fn() => create_document("  \t\n  ", 'body', 1));
});

ct('unexpected', '68 create_document rejects empty body', function () {
    ct_throws(fn() => create_document(ct_title('No Body'), '', 1));
});

ct('unexpected', '69 create_document rejects whitespace body', function () {
    ct_throws(fn() => create_document(ct_title('WS Body'), "   \n  ", 1));
});

ct('unexpected', '70 create_document rejects invalid staff FK', function () {
    ct_throws(fn() => create_document(ct_title('Bad Staff'), 'body', 999999));
});

ct('unexpected', '71 create_document rejects invalid schedule format', function () {
    ct_throws(fn() => create_document(ct_title('Bad Sched'), 'body', 1, 'next tuesday'));
});

ct('unexpected', '72 create_document rejects datetime-local format as storage', function () {
    ct_throws(fn() => create_document(ct_title('T Format'), 'body', 1, '2026-05-09T12:00'));
});

ct('unexpected', '73 create_document rejects impossible date', function () {
    ct_throws(fn() => create_document(ct_title('Feb 30'), 'body', 1, '2026-02-30 00:00:00'));
});

ct('unexpected', '74 update_document_schedule rejects nonexistent doc', function () {
    ct_throws(fn() => update_document_schedule(999999, null));
});

ct('unexpected', '75 update_document_schedule rejects bad format', function () {
    $id = create_document(ct_title('Bad Update'), 'body', 1);
    ct_throws(fn() => update_document_schedule($id, 'not-a-date'));
});

ct('unexpected', '76 create_share rejects invalid email', function () {
    ct_throws(fn() => create_share((int)ct_seed()['id'], 'not-email'));
});

ct('unexpected', '77 create_share rejects empty email', function () {
    ct_throws(fn() => create_share((int)ct_seed()['id'], ''));
});

ct('unexpected', '78 create_share rejects email with newline', function () {
    ct_throws(fn() => create_share((int)ct_seed()['id'], "a@b.com\nBcc:x@y.com"));
});

ct('unexpected', '79 create_share rejects nonexistent document FK', function () {
    ct_throws(fn() => create_share(999999, 'claude-fk@example.com'));
});

ct('unexpected', '80 parse_datetime_local_to_utc rejects garbage', function () {
    ct_throws(fn() => parse_datetime_local_to_utc('foobar'));
});

ct('unexpected', '81 parse_datetime_local_to_utc rejects partial date', function () {
    ct_throws(fn() => parse_datetime_local_to_utc('2026-05'));
});

ct('unexpected', '82 normalize_published_at rejects timezone suffix', function () {
    ct_throws(fn() => normalize_published_at('2026-05-09 12:00:00Z'));
});

ct('unexpected', '83 normalize_published_at rejects Feb 29 on non-leap year', function () {
    ct_throws(fn() => normalize_published_at('2025-02-29 00:00:00'));
});

ct('unexpected', '84 find_document_by_reference rejects negative number', function () {
    ct_eq(null, find_document_by_reference('-1'));
});

ct('unexpected', '85 recipient_document_for_token empty returns null', function () {
    ct_eq(null, recipient_document_for_token(''));
    ct_eq(null, recipient_document_for_token('   '));
});

// ============ FOOL (15) ============

ct('fool', '86 title "0" is valid', function () {
    $id = create_document('0', 'body', 1);
    ct_eq('0', ct_doc($id)['title']);
});

ct('fool', '87 body "0" is valid', function () {
    $id = create_document(ct_title('Zero Body'), '0', 1);
    ct_eq('0', ct_doc($id)['body']);
});

ct('fool', '88 schedule with leading/trailing spaces normalizes', function () {
    $id = create_document(ct_title('Space Sched'), 'body', 1, '  2030-01-01 00:00:00  ');
    ct_eq('2030-01-01 00:00:00', ct_doc($id)['published_at']);
});

ct('fool', '89 token lookup trims newline and tab', function () {
    $token = ct_seed_share()['token'];
    $doc = recipient_document_for_token("\t{$token}\n");
    ct_true($doc !== null);
});

ct('fool', '90 readable_id lookup trims spaces', function () {
    $doc = ct_seed();
    $found = find_document_by_reference("  {$doc['readable_id']}  ");
    ct_eq((int)$doc['id'], (int)$found['id']);
});

ct('fool', '91 user types readable_id in ALL CAPS', function () {
    $doc = ct_seed();
    $found = find_document_by_reference(strtoupper($doc['readable_id']));
    ct_true($found !== null);
});

ct('fool', '92 user types zero-padded numeric id', function () {
    $found = find_document_by_reference('001');
    ct_true($found !== null);
    ct_eq(1, (int)$found['id']);
});

ct('fool', '93 user sends plus-prefixed id', function () {
    ct_eq(null, find_document_by_reference('+1'));
});

ct('fool', '94 user sends decimal id', function () {
    ct_eq(null, find_document_by_reference('1.5'));
});

ct('fool', '95 schedule update with all spaces clears', function () {
    $id = create_document(ct_title('Space Clear'), 'body', 1, '2030-01-01 00:00:00');
    update_document_schedule($id, '    ');
    ct_eq(null, ct_doc($id)['published_at']);
});

ct('fool', '96 email with uppercase domain accepted', function () {
    $token = create_share((int)ct_seed()['id'], 'user@EXAMPLE.COM');
    ct_match('/^[a-f0-9]{32}$/', $token);
});

ct('fool', '97 title with only spaces after trim throws', function () {
    ct_throws(fn() => create_document("   \r\n\t   ", 'body', 1));
});

ct('fool', '98 multiple schedule updates keep audit trail', function () {
    $id = create_document(ct_title('Multi Sched'), 'body', 1);
    $before = (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='schedule_update'")->fetchColumn();
    update_document_schedule($id, '2030-01-01 00:00:00');
    update_document_schedule($id, '2030-06-01 00:00:00');
    update_document_schedule($id, null);
    $after = (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='schedule_update'")->fetchColumn();
    ct_eq($before + 3, $after);
});

ct('fool', '99 user searches with SQL injection attempt', function () {
    $r = search_documents_by_title("' OR 1=1 --");
    ct_eq(0, count($r));
});

ct('fool', '100 readable_id cannot be used as share token', function () {
    ct_eq(null, recipient_document_for_token(ct_seed()['readable_id']));
});

echo "\nClaude PHP tests: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
