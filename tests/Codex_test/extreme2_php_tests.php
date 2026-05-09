<?php

require __DIR__ . '/../../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function x2_test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function x2_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function x2_false($cond, string $msg = ''): void {
    if ($cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected false');
    }
}

function x2_eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function x2_match(string $pattern, string $actual, string $msg = ''): void {
    if (!preg_match($pattern, $actual)) {
        throw new RuntimeException($msg !== '' ? $msg : "{$actual} did not match {$pattern}");
    }
}

function x2_throws(callable $fn, string $msg = ''): void {
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }

    throw new RuntimeException($msg !== '' ? $msg : 'expected exception');
}

function x2_doc(int $id): array {
    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw new RuntimeException('document not found: ' . $id);
    }

    return $doc;
}

function x2_seed_doc(): array {
    $doc = db()->query('SELECT * FROM documents ORDER BY id LIMIT 1')->fetch();
    if (!$doc) {
        throw new RuntimeException('expected seeded document');
    }

    return $doc;
}

function x2_audit(string $action, string $entityType, int $entityId): array {
    $stmt = db()->prepare('
        SELECT *
        FROM audit_log
        WHERE action = ? AND entity_type = ? AND entity_id = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([$action, $entityType, $entityId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException("audit entry not found for {$action} {$entityType} {$entityId}");
    }

    return $row;
}

function x2_ids(array $rows): array {
    return array_map(static fn ($row) => (int) $row['id'], $rows);
}

echo "\nRunning Codex extreme2 PHP tests:\n";

// Scheduled publishing, readable IDs, and title search: 30 tests.
x2_test('01 scheduled future document is hidden', function () {
    $id = create_document('Future Hidden', 'body', 1, gmdate('Y-m-d H:i:s', time() + 3600));
    x2_false(document_is_published(x2_doc($id)));
});

x2_test('02 scheduled past document is visible', function () {
    $id = create_document('Past Visible', 'body', 1, gmdate('Y-m-d H:i:s', time() - 3600));
    x2_true(document_is_published(x2_doc($id)));
});

x2_test('03 null published_at means immediate visibility', function () {
    $id = create_document('Immediate Visible', 'body', 1, null);
    x2_true(document_is_published(x2_doc($id)));
});

x2_test('04 publish time equal to now is visible', function () {
    $now = '2026-05-09 17:00:00';
    $doc = ['published_at' => $now];
    x2_true(document_is_published($doc, $now));
});

x2_test('05 one second before publish time is hidden', function () {
    $doc = ['published_at' => '2026-05-09 17:00:01'];
    x2_false(document_is_published($doc, '2026-05-09 17:00:00'));
});

x2_test('06 blank datetime-local parses as immediate', function () {
    x2_eq(null, parse_datetime_local_to_utc(''));
    x2_eq(null, parse_datetime_local_to_utc('     '));
});

x2_test('07 valid datetime-local converts from app timezone to UTC', function () {
    x2_eq('2026-01-01 06:00:00', parse_datetime_local_to_utc('2026-01-01T00:00'));
});

x2_test('08 invalid datetime-local is rejected', function () {
    x2_throws(static fn () => parse_datetime_local_to_utc('not-a-date'));
});

x2_test('09 schedule update can make an immediate doc future-hidden', function () {
    $id = create_document('Hide Later', 'body', 1);
    update_document_schedule($id, gmdate('Y-m-d H:i:s', time() + 3600));
    x2_false(document_is_published(x2_doc($id)));
});

x2_test('10 schedule update can clear a future schedule', function () {
    $id = create_document('Clear Schedule', 'body', 1, gmdate('Y-m-d H:i:s', time() + 3600));
    update_document_schedule($id, null);
    x2_true(document_is_published(x2_doc($id)));
});

x2_test('11 readable ID follows slug plus suffix shape', function () {
    $id = create_document('Council Agenda Packet', 'body', 1);
    x2_match('/^council-agenda-packet-[a-f0-9]{4}$/', x2_doc($id)['readable_id']);
});

x2_test('12 duplicate titles produce unique readable IDs', function () {
    $a = x2_doc(create_document('Same Title', 'a', 1))['readable_id'];
    $b = x2_doc(create_document('Same Title', 'b', 1))['readable_id'];
    x2_true($a !== $b);
});

x2_test('13 uppercase readable ID lookup resolves', function () {
    $doc = x2_seed_doc();
    $found = find_document_by_reference(strtoupper($doc['readable_id']));
    x2_true($found !== null);
    x2_eq((int) $doc['id'], (int) $found['id']);
});

x2_test('14 readable ID lookup trims surrounding whitespace', function () {
    $doc = x2_seed_doc();
    $found = find_document_by_reference('  ' . $doc['readable_id'] . " \n");
    x2_true($found !== null);
    x2_eq((int) $doc['id'], (int) $found['id']);
});

x2_test('15 numeric document reference still resolves', function () {
    $doc = x2_seed_doc();
    $found = find_document_by_reference((string) $doc['id']);
    x2_true($found !== null);
    x2_eq((int) $doc['id'], (int) $found['id']);
});

x2_test('16 readable ID does not work as recipient token', function () {
    $doc = x2_seed_doc();
    x2_eq(null, recipient_document_for_token($doc['readable_id']));
});

x2_test('17 punctuation-only title falls back to document base', function () {
    $id = create_document('!!! --- ###', 'body', 1);
    x2_match('/^document-[a-f0-9]{4}$/', x2_doc($id)['readable_id']);
});

x2_test('18 long title readable ID stays bounded', function () {
    $id = create_document(str_repeat('Long Title ', 20), 'body', 1);
    x2_true(strlen(x2_doc($id)['readable_id']) <= 37);
});

x2_test('19 readable ID base removes punctuation cleanly', function () {
    $id = create_document('Hello, CivicPlus: 2026!', 'body', 1);
    x2_match('/^hello-civicplus-2026-[a-f0-9]{4}$/', x2_doc($id)['readable_id']);
});

x2_test('20 readable ID uniqueness is enforced by the database', function () {
    $doc = x2_seed_doc();
    x2_throws(function () use ($doc) {
        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, readable_id)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute(['Manual Collision', 'body', 1, $doc['readable_id']]);
    });
});

x2_test('21 empty title search returns all documents', function () {
    $count = (int) db()->query('SELECT COUNT(*) FROM documents')->fetchColumn();
    x2_eq($count, count(search_documents_by_title('')));
});

x2_test('22 title search supports lowercase partial match', function () {
    $id = create_document('Finance Committee Packet', 'body', 1);
    x2_true(in_array($id, x2_ids(search_documents_by_title('committee')), true));
});

x2_test('23 title search supports uppercase query', function () {
    $id = create_document('Planning Board Minutes', 'body', 1);
    x2_true(in_array($id, x2_ids(search_documents_by_title('PLANNING')), true));
});

x2_test('24 title search trims query whitespace', function () {
    $id = create_document('Trimmed Search Target', 'body', 1);
    x2_true(in_array($id, x2_ids(search_documents_by_title('  Search Target  ')), true));
});

x2_test('25 title search returns no rows for missing term', function () {
    x2_eq(0, count(search_documents_by_title('term-that-should-not-exist')));
});

x2_test('26 percent and underscore search are literal', function () {
    $id = create_document('100% Coverage _ Draft', 'body', 1);
    x2_true(in_array($id, x2_ids(search_documents_by_title('100% Coverage _')), true));
});

x2_test('27 apostrophe search does not break SQL', function () {
    $id = create_document("Mayor's Office Memo", 'body', 1);
    x2_true(in_array($id, x2_ids(search_documents_by_title("Mayor's")), true));
});

x2_test('28 Chinese title search works by exact substring', function () {
    $id = create_document('市政公告材料', 'body', 1);
    x2_true(in_array($id, x2_ids(search_documents_by_title('公告')), true));
});

x2_test('29 accented title search works for exact lowercase substring', function () {
    $id = create_document('résumé packet', 'body', 1);
    x2_true(in_array($id, x2_ids(search_documents_by_title('résumé')), true));
});

x2_test('30 accented title search should be case-insensitive', function () {
    $id = create_document('Résumé Packet', 'body', 1);
    x2_true(in_array($id, x2_ids(search_documents_by_title('RÉSUMÉ')), true), 'accented uppercase search did not match');
});

// General system tests: 20 tests.
x2_test('31 create_document should reject blank title server-side', function () {
    x2_throws(static fn () => create_document('', 'body', 1), 'blank title was accepted');
});

x2_test('32 create_document should reject whitespace-only title server-side', function () {
    x2_throws(static fn () => create_document('   ', 'body', 1), 'whitespace title was accepted');
});

x2_test('33 create_document should reject blank body server-side', function () {
    x2_throws(static fn () => create_document('Blank Body', '', 1), 'blank body was accepted');
});

x2_test('34 create_document should reject invalid schedule strings', function () {
    x2_throws(static fn () => create_document('Bad Schedule', 'body', 1, 'tomorrow-ish'), 'invalid schedule was accepted');
});

x2_test('35 update_document_schedule should reject invalid schedule strings', function () {
    $id = create_document('Bad Update Schedule', 'body', 1);
    x2_throws(static fn () => update_document_schedule($id, 'later'), 'invalid schedule update was accepted');
});

x2_test('36 create_document rejects unknown staff by foreign key', function () {
    x2_throws(static fn () => create_document('Unknown Staff', 'body', 999999));
});

x2_test('37 create_share rejects malformed recipient email', function () {
    $doc = x2_seed_doc();
    x2_throws(static fn () => create_share((int) $doc['id'], 'not-an-email'));
});

x2_test('38 create_share trims valid recipient email', function () {
    $doc = x2_seed_doc();
    $token = create_share((int) $doc['id'], '  trimmed@example.com  ');
    $stmt = db()->prepare('SELECT recipient_email FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    x2_eq('trimmed@example.com', $stmt->fetchColumn());
});

x2_test('39 create_share rejects unknown document by foreign key', function () {
    x2_throws(static fn () => create_share(999999, 'reader@example.com'));
});

x2_test('40 unknown token returns null', function () {
    x2_eq(null, recipient_document_for_token('definitely-not-a-real-token'));
});

x2_test('41 token lookup should tolerate copied whitespace', function () {
    $doc = x2_seed_doc();
    $token = create_share((int) $doc['id'], 'copy@example.com');
    x2_true(recipient_document_for_token('  ' . $token . " \n") !== null, 'token lookup did not trim whitespace');
});

x2_test('42 random_token default is 32 lowercase hex chars', function () {
    x2_match('/^[a-f0-9]{32}$/', random_token());
});

x2_test('43 random_token creates unique values across 200 attempts', function () {
    $tokens = [];
    for ($i = 0; $i < 200; $i++) {
        $tokens[] = random_token();
    }
    x2_eq(count($tokens), count(array_unique($tokens)));
});

x2_test('44 h escapes dangerous HTML', function () {
    x2_eq('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', h("<script>alert('x')</script>"));
});

x2_test('45 create audit details are valid JSON', function () {
    $id = create_document('JSON Audit', 'body', 1);
    $details = json_decode(x2_audit('create', 'document', $id)['details'], true);
    x2_true(is_array($details));
    x2_eq('JSON Audit', $details['title']);
});

x2_test('46 share audit details are valid JSON', function () {
    $doc = x2_seed_doc();
    $token = create_share((int) $doc['id'], 'json-share@example.com');
    $stmt = db()->prepare('SELECT id FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    $details = json_decode(x2_audit('create', 'share', (int) $stmt->fetchColumn())['details'], true);
    x2_true(is_array($details));
    x2_eq('json-share@example.com', $details['recipient_email']);
});

x2_test('47 apply_migrations can run repeatedly', function () {
    apply_migrations(db(), __DIR__ . '/../../migrations');
    apply_migrations(db(), __DIR__ . '/../../migrations');
    x2_true(true);
});

x2_test('48 schema_migrations records both migration files', function () {
    $rows = db()->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll();
    $names = array_column($rows, 'migration');
    x2_true(in_array('000_create_schema_migrations.sql', $names, true));
    x2_true(in_array('001_document_publishing_and_readable_ids.sql', $names, true));
});

x2_test('49 find_document_by_reference rejects empty input', function () {
    x2_eq(null, find_document_by_reference('   '));
});

x2_test('50 extremely large body round-trips through database', function () {
    $body = str_repeat('Large body line.' . "\n", 1000);
    $id = create_document('Large Body', $body, 1);
    x2_eq($body, x2_doc($id)['body']);
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
