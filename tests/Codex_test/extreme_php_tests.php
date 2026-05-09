<?php

require __DIR__ . '/../../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function codex_test(string $name, callable $fn): void {
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

function codex_assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function codex_assert_false($cond, string $msg = ''): void {
    if ($cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected false');
    }
}

function codex_assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function codex_assert_matches(string $pattern, string $actual, string $msg = ''): void {
    if (!preg_match($pattern, $actual)) {
        throw new RuntimeException($msg !== '' ? $msg : "{$actual} did not match {$pattern}");
    }
}

function codex_assert_throws(callable $fn, string $msg = ''): void {
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }

    throw new RuntimeException($msg !== '' ? $msg : 'expected exception');
}

function codex_fetch_document(int $id): array {
    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw new RuntimeException('document not found: ' . $id);
    }

    return $doc;
}

function codex_seeded_document(): array {
    $stmt = db()->query('SELECT * FROM documents ORDER BY id LIMIT 1');
    $doc = $stmt->fetch();
    if (!$doc) {
        throw new RuntimeException('expected seeded document');
    }

    return $doc;
}

function codex_latest_audit(string $action, string $entityType, int $entityId): array {
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

function codex_count_shares_for_email(string $email): int {
    $stmt = db()->prepare('SELECT COUNT(*) FROM shares WHERE recipient_email = ?');
    $stmt->execute([$email]);

    return (int) $stmt->fetchColumn();
}

echo "\nRunning Codex extreme PHP tests:\n";

codex_test('01 migration columns are present after seed', function () {
    $columns = db()->query('PRAGMA table_info(documents)')->fetchAll();
    $names = array_column($columns, 'name');
    codex_assert_true(in_array('published_at', $names, true), 'missing published_at');
    codex_assert_true(in_array('readable_id', $names, true), 'missing readable_id');
});

codex_test('02 seeded document has readable ID and seeded share uses opaque token', function () {
    $doc = codex_seeded_document();
    codex_assert_matches('/^welcome-packet-[a-f0-9]{4}$/', $doc['readable_id']);

    $stmt = db()->query('SELECT token FROM shares ORDER BY id LIMIT 1');
    $share = $stmt->fetch();
    codex_assert_true($share !== false, 'expected seeded share');
    codex_assert_equals(32, strlen($share['token']), 'expected 16-byte hex share token');
    codex_assert_true($share['token'] !== $doc['readable_id'], 'token should not equal readable ID');
});

codex_test('03 exact readable ID lookup resolves the document', function () {
    $doc = codex_seeded_document();
    $found = find_document_by_reference($doc['readable_id']);
    codex_assert_true($found !== null, 'expected lookup by readable ID');
    codex_assert_equals((int) $doc['id'], (int) $found['id']);
});

codex_test('04 readable ID lookup should tolerate uppercase user input', function () {
    $doc = codex_seeded_document();
    $found = find_document_by_reference(strtoupper($doc['readable_id']));
    codex_assert_true($found !== null, 'uppercase readable ID did not resolve');
    codex_assert_equals((int) $doc['id'], (int) $found['id']);
});

codex_test('05 duplicate document titles still get unique readable IDs', function () {
    $first = create_document('Duplicate Title', 'first', 1);
    $second = create_document('Duplicate Title', 'second', 1);
    $firstDoc = codex_fetch_document($first);
    $secondDoc = codex_fetch_document($second);
    codex_assert_true($firstDoc['readable_id'] !== $secondDoc['readable_id'], 'duplicate titles produced duplicate readable IDs');
});

codex_test('06 non-Latin title falls back to document readable ID base', function () {
    $docId = create_document('欢迎 пакет 🚀', 'body', 1);
    $doc = codex_fetch_document($docId);
    codex_assert_matches('/^document-[a-f0-9]{4}$/', $doc['readable_id']);
});

codex_test('07 long titles keep readable IDs short enough for URLs and tables', function () {
    $docId = create_document(str_repeat('Long Title ', 12), 'body', 1);
    $doc = codex_fetch_document($docId);
    codex_assert_true(strlen($doc['readable_id']) <= 37, 'readable ID too long: ' . $doc['readable_id']);
});

codex_test('08 numeric fallback still resolves documents for old URLs', function () {
    $doc = codex_seeded_document();
    $found = find_document_by_reference((string) $doc['id']);
    codex_assert_true($found !== null, 'numeric ID fallback failed');
    codex_assert_equals((int) $doc['id'], (int) $found['id']);
});

codex_test('09 readable IDs cannot be used as recipient access tokens', function () {
    $doc = codex_seeded_document();
    $recipientDoc = recipient_document_for_token($doc['readable_id']);
    codex_assert_equals(null, $recipientDoc, 'readable ID should not grant recipient access');
});

codex_test('10 future scheduled documents are not published', function () {
    $docId = create_document('Future Publish', 'hidden', 1, gmdate('Y-m-d H:i:s', time() + 86400));
    $doc = codex_fetch_document($docId);
    codex_assert_false(document_is_published($doc), 'future document was considered published');
});

codex_test('11 past scheduled documents are published', function () {
    $docId = create_document('Past Publish', 'visible', 1, gmdate('Y-m-d H:i:s', time() - 86400));
    $doc = codex_fetch_document($docId);
    codex_assert_true(document_is_published($doc), 'past document was not considered published');
});

codex_test('12 blank datetime-local input means immediate publish', function () {
    codex_assert_equals(null, parse_datetime_local_to_utc(''));
    codex_assert_equals(null, parse_datetime_local_to_utc('   '));
});

codex_test('13 impossible datetime-local input is rejected', function () {
    codex_assert_throws(function () {
        parse_datetime_local_to_utc('2026-02-31T12:00');
    }, 'impossible date was accepted');
});

codex_test('14 schedule updates write previous and new schedule to audit log', function () {
    $initial = gmdate('Y-m-d H:i:s', time() + 3600);
    $updated = gmdate('Y-m-d H:i:s', time() + 7200);
    $docId = create_document('Audit Schedule', 'body', 1, $initial);
    update_document_schedule($docId, $updated);

    $audit = codex_latest_audit('schedule_update', 'document', $docId);
    $details = json_decode($audit['details'], true);
    codex_assert_equals($initial, $details['previous_published_at']);
    codex_assert_equals($updated, $details['published_at']);
});

codex_test('15 document creation audit includes readable ID and publish schedule', function () {
    $publishedAt = gmdate('Y-m-d H:i:s', time() + 3600);
    $docId = create_document('Audit Create', 'body', 1, $publishedAt);
    $audit = codex_latest_audit('create', 'document', $docId);
    $details = json_decode($audit['details'], true);
    codex_assert_equals('Audit Create', $details['title']);
    codex_assert_true(isset($details['readable_id']) && $details['readable_id'] !== '', 'missing readable ID in audit');
    codex_assert_equals($publishedAt, $details['published_at']);
});

codex_test('16 share creation audit includes recipient and document ID', function () {
    $doc = codex_seeded_document();
    $token = create_share((int) $doc['id'], 'audit-recipient@example.com');
    codex_assert_equals(32, strlen($token));

    $stmt = db()->prepare('SELECT id FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    $shareId = (int) $stmt->fetchColumn();
    $audit = codex_latest_audit('create', 'share', $shareId);
    $details = json_decode($audit['details'], true);
    codex_assert_equals((int) $doc['id'], (int) $details['document_id']);
    codex_assert_equals('audit-recipient@example.com', $details['recipient_email']);
});

codex_test('17 title search is case-insensitive and partial', function () {
    $docId = create_document('Budget Hearing Notes', 'body', 1);
    $matches = search_documents_by_title('HEARING');
    $ids = array_map(static fn ($row) => (int) $row['id'], $matches);
    codex_assert_true(in_array($docId, $ids, true), 'case-insensitive partial search failed');
});

codex_test('18 title search treats percent and underscore as literal text', function () {
    $docId = create_document('100% Coverage _ Draft', 'body', 1);
    $matches = search_documents_by_title('100% Coverage _');
    $ids = array_map(static fn ($row) => (int) $row['id'], $matches);
    codex_assert_true(in_array($docId, $ids, true), 'literal special-character search failed');
});

codex_test('19 share creation should reject invalid recipient email server-side', function () {
    $doc = codex_seeded_document();
    $before = codex_count_shares_for_email('not-an-email');

    codex_assert_throws(function () use ($doc) {
        create_share((int) $doc['id'], 'not-an-email');
    }, 'invalid email was accepted without an exception');

    $after = codex_count_shares_for_email('not-an-email');
    codex_assert_equals($before, $after, 'invalid email share was inserted');
});

codex_test('20 applying migrations twice should be safe', function () {
    try {
        apply_migrations(db(), __DIR__ . '/../../migrations');
    } catch (Throwable $e) {
        throw new RuntimeException('migration runner is not idempotent: ' . $e->getMessage());
    }
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
