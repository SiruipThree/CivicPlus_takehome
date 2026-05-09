<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
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

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function fetch_document(int $id): array {
    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('document not found: ' . $id);
    }
    return $row;
}

function fetch_latest_audit(string $action, string $entityType, int $entityId): array {
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

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('seed applies document publishing and readable ID migrations', function () {
    $columns = db()->query('PRAGMA table_info(documents)')->fetchAll();
    $names = array_column($columns, 'name');
    assert_true(in_array('published_at', $names, true), 'expected published_at column');
    assert_true(in_array('readable_id', $names, true), 'expected readable_id column');

    $stmt = db()->prepare('SELECT readable_id FROM documents WHERE title = ? LIMIT 1');
    $stmt->execute(['Welcome Packet']);
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected seeded document');
    assert_true($row['readable_id'] !== '', 'expected seeded readable ID');
});

test('scheduled publishing blocks future documents until the schedule is cleared', function () {
    $future = gmdate('Y-m-d H:i:s', time() + 3600);
    $docId = create_document('Future Notice', 'This should not be visible yet.', 1, $future);
    $token = create_share($docId, 'future@example.com');

    $doc = recipient_document_for_token($token);
    assert_true($doc !== null, 'expected recipient document');
    assert_true(!document_is_published($doc), 'future document should not be published');

    update_document_schedule($docId, null);
    $doc = recipient_document_for_token($token);
    assert_true($doc !== null, 'expected recipient document after schedule update');
    assert_true(document_is_published($doc), 'cleared schedule should publish immediately');

    $audit = fetch_latest_audit('schedule_update', 'document', $docId);
    $details = json_decode($audit['details'], true);
    assert_equals($future, $details['previous_published_at'], 'expected previous schedule in audit log');
    assert_true(array_key_exists('published_at', $details), 'expected updated schedule in audit log');
    assert_equals(null, $details['published_at'], 'expected cleared schedule in audit log');
});

test('human-readable document IDs are generated and resolve documents without replacing share tokens', function () {
    $docId = create_document('FOLIO Welcome Packet 2026', 'Readable ID body.', 1);
    $doc = fetch_document($docId);

    assert_true((bool) preg_match('/^folio-welcome-packet-2026-[a-f0-9]{4}$/', $doc['readable_id']), 'unexpected readable ID: ' . $doc['readable_id']);

    $byReference = find_document_by_reference($doc['readable_id']);
    assert_true($byReference !== null, 'expected readable ID lookup to find the document');
    assert_equals($docId, (int) $byReference['id'], 'readable ID resolved the wrong document');

    $token = create_share($docId, 'reader@example.com');
    assert_true(strlen($token) === 32, 'share token should remain the opaque random token');
    assert_true($token !== $doc['readable_id'], 'readable ID should not replace share token');
});

test('share by name finds documents by case-insensitive partial title', function () {
    $docId = create_document('Budget Hearing Notes', 'Search body.', 1);
    create_document('Parks Maintenance Plan', 'Another body.', 1);

    $matches = search_documents_by_title('hearing');
    $ids = array_map(static fn ($row) => (int) $row['id'], $matches);
    assert_true(in_array($docId, $ids, true), 'expected lowercase partial match');

    $upperMatches = search_documents_by_title('BUDGET');
    $upperIds = array_map(static fn ($row) => (int) $row['id'], $upperMatches);
    assert_true(in_array($docId, $upperIds, true), 'expected uppercase partial match');

    $none = search_documents_by_title('definitely missing title');
    assert_equals(0, count($none), 'expected no matches for unrelated query');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
