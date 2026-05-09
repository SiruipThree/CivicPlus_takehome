<?php

require __DIR__ . '/../../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../../seed.php') . ' > /dev/null', $seedRc);
if ($seedRc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function ind_test(string $category, string $name, callable $fn): void {
    global $pass, $fail;

    try {
        $fn();
        echo "  [ok] [{$category}] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] [{$category}] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function ind_true($cond, string $msg = 'expected true'): void {
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function ind_false($cond, string $msg = 'expected false'): void {
    if ($cond) {
        throw new RuntimeException($msg);
    }
}

function ind_eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ind_ne($unexpected, $actual, string $msg = ''): void {
    if ($unexpected === $actual) {
        throw new RuntimeException($msg !== '' ? $msg : 'unexpected value ' . var_export($actual, true));
    }
}

function ind_match(string $pattern, string $actual, string $msg = ''): void {
    if (!preg_match($pattern, $actual)) {
        throw new RuntimeException($msg !== '' ? $msg : "{$actual} did not match {$pattern}");
    }
}

function ind_throws(callable $fn, string $msg = 'expected exception'): void {
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }

    throw new RuntimeException($msg);
}

function ind_doc(int $id): array {
    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw new RuntimeException('document not found: ' . $id);
    }

    return $doc;
}

function ind_seed_doc(): array {
    $doc = db()->query('SELECT * FROM documents ORDER BY id LIMIT 1')->fetch();
    if (!$doc) {
        throw new RuntimeException('expected seeded document');
    }

    return $doc;
}

function ind_seed_share(): array {
    $share = db()->query('SELECT * FROM shares ORDER BY id LIMIT 1')->fetch();
    if (!$share) {
        throw new RuntimeException('expected seeded share');
    }

    return $share;
}

function ind_audit(string $action, string $entityType, int $entityId): array {
    $stmt = db()->prepare('
        SELECT *
        FROM audit_log
        WHERE action = ? AND entity_type = ? AND entity_id = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([$action, $entityType, $entityId]);
    $audit = $stmt->fetch();
    if (!$audit) {
        throw new RuntimeException("audit not found for {$action} {$entityType} {$entityId}");
    }

    return $audit;
}

function ind_details(array $audit): array {
    $details = json_decode($audit['details'], true);
    if (!is_array($details)) {
        throw new RuntimeException('audit details are not valid JSON');
    }

    return $details;
}

function ind_ids(array $rows): array {
    return array_map(static fn ($row) => (int) $row['id'], $rows);
}

function ind_unique_title(string $base): string {
    static $n = 0;
    $n++;

    return "Independent {$base} {$n}";
}

echo "\nRunning Codex independent PHP tests (120):\n";

// Regular behavior: 50 tests.
ind_test('regular', '01 seed creates exactly one staff member', function () {
    ind_eq(1, (int) db()->query('SELECT COUNT(*) FROM staff')->fetchColumn());
});

ind_test('regular', '02 seed creates a welcome packet document', function () {
    ind_eq('Welcome Packet', ind_seed_doc()['title']);
});

ind_test('regular', '03 seed creates a recipient share row', function () {
    ind_eq('recipient@example.com', ind_seed_share()['recipient_email']);
});

ind_test('regular', '04 schema migration table exists after seed', function () {
    $stmt = db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'");
    ind_eq('schema_migrations', $stmt->fetchColumn());
});

ind_test('regular', '05 both migration files are recorded', function () {
    $rows = db()->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll();
    ind_eq(['000_create_schema_migrations.sql', '001_document_publishing_and_readable_ids.sql'], array_column($rows, 'migration'));
});

ind_test('regular', '06 documents table has readable_id column', function () {
    $names = array_column(db()->query('PRAGMA table_info(documents)')->fetchAll(), 'name');
    ind_true(in_array('readable_id', $names, true));
});

ind_test('regular', '07 documents table has published_at column', function () {
    $names = array_column(db()->query('PRAGMA table_info(documents)')->fetchAll(), 'name');
    ind_true(in_array('published_at', $names, true));
});

ind_test('regular', '08 readable_id has a unique index', function () {
    $indexes = db()->query('PRAGMA index_list(documents)')->fetchAll();
    $unique = array_filter($indexes, static fn ($row) => (int) $row['unique'] === 1 && $row['name'] === 'idx_documents_readable_id');
    ind_eq(1, count($unique));
});

ind_test('regular', '09 seeded readable ID is a lowercase slug with random suffix', function () {
    ind_match('/^welcome-packet-[a-f0-9]{4}$/', ind_seed_doc()['readable_id']);
});

ind_test('regular', '10 seeded recipient token is opaque lowercase hex', function () {
    ind_match('/^[a-f0-9]{32}$/', ind_seed_share()['token']);
});

ind_test('regular', '11 exact readable ID lookup finds the document', function () {
    $doc = ind_seed_doc();
    $found = find_document_by_reference($doc['readable_id']);
    ind_true($found !== null);
    ind_eq((int) $doc['id'], (int) $found['id']);
});

ind_test('regular', '12 uppercase readable ID lookup is accepted for typed URLs', function () {
    $doc = ind_seed_doc();
    $found = find_document_by_reference(strtoupper($doc['readable_id']));
    ind_true($found !== null);
    ind_eq((int) $doc['id'], (int) $found['id']);
});

ind_test('regular', '13 readable ID lookup trims copied whitespace', function () {
    $doc = ind_seed_doc();
    $found = find_document_by_reference(" \t" . $doc['readable_id'] . "\n");
    ind_true($found !== null);
    ind_eq((int) $doc['id'], (int) $found['id']);
});

ind_test('regular', '14 numeric document reference remains backward compatible', function () {
    $doc = ind_seed_doc();
    $found = find_document_by_reference((string) $doc['id']);
    ind_true($found !== null);
    ind_eq((int) $doc['id'], (int) $found['id']);
});

ind_test('regular', '15 blank document reference is rejected', function () {
    ind_eq(null, find_document_by_reference('   '));
});

ind_test('regular', '16 unknown document reference returns null', function () {
    ind_eq(null, find_document_by_reference('not-a-real-document'));
});

ind_test('regular', '17 exact share token resolves recipient document', function () {
    $doc = recipient_document_for_token(ind_seed_share()['token']);
    ind_true($doc !== null);
    ind_eq('Welcome Packet', $doc['title']);
});

ind_test('regular', '18 share token lookup trims copied whitespace', function () {
    $token = ind_seed_share()['token'];
    $doc = recipient_document_for_token(" \t{$token}\n");
    ind_true($doc !== null);
    ind_eq('recipient@example.com', $doc['recipient_email']);
});

ind_test('regular', '19 unknown share token returns null', function () {
    ind_eq(null, recipient_document_for_token('definitely-not-a-real-token'));
});

ind_test('regular', '20 readable ID cannot be used as recipient token', function () {
    ind_eq(null, recipient_document_for_token(ind_seed_doc()['readable_id']));
});

ind_test('regular', '21 create_document returns a persisted new integer ID', function () {
    $id = create_document(ind_unique_title('Create'), 'body', 1);
    ind_true($id > 1);
    ind_eq($id, (int) ind_doc($id)['id']);
});

ind_test('regular', '22 create_document trims the title before storage', function () {
    $id = create_document('  ' . ind_unique_title('Trim Title') . '  ', 'body', 1);
    ind_false(str_starts_with(ind_doc($id)['title'], ' '));
    ind_false(str_ends_with(ind_doc($id)['title'], ' '));
});

ind_test('regular', '23 helper preserves nonblank body content exactly', function () {
    $body = "  keep leading spaces\nkeep trailing spaces  ";
    $id = create_document(ind_unique_title('Preserve Body'), $body, 1);
    ind_eq($body, ind_doc($id)['body']);
});

ind_test('regular', '24 null schedule is stored as immediate availability', function () {
    $id = create_document(ind_unique_title('Null Schedule'), 'body', 1, null);
    ind_eq(null, ind_doc($id)['published_at']);
});

ind_test('regular', '25 explicit future schedule is stored unchanged', function () {
    $future = '2999-05-09 12:00:00';
    $id = create_document(ind_unique_title('Future Stored'), 'body', 1, $future);
    ind_eq($future, ind_doc($id)['published_at']);
});

ind_test('regular', '26 explicit past schedule is stored unchanged', function () {
    $past = '2000-05-09 12:00:00';
    $id = create_document(ind_unique_title('Past Stored'), 'body', 1, $past);
    ind_eq($past, ind_doc($id)['published_at']);
});

ind_test('regular', '27 immediate document is considered published', function () {
    $id = create_document(ind_unique_title('Published Now'), 'body', 1);
    ind_true(document_is_published(ind_doc($id)));
});

ind_test('regular', '28 future document is not considered published', function () {
    $id = create_document(ind_unique_title('Hidden Future'), 'body', 1, '2999-01-01 00:00:00');
    ind_false(document_is_published(ind_doc($id)));
});

ind_test('regular', '29 past document is considered published', function () {
    $id = create_document(ind_unique_title('Visible Past'), 'body', 1, '2000-01-01 00:00:00');
    ind_true(document_is_published(ind_doc($id)));
});

ind_test('regular', '30 publish time equal to now is visible', function () {
    ind_true(document_is_published(['published_at' => '2026-05-09 17:30:00'], '2026-05-09 17:30:00'));
});

ind_test('regular', '31 create_document writes an audit row', function () {
    $id = create_document(ind_unique_title('Audit Row'), 'body', 1);
    ind_eq('create', ind_audit('create', 'document', $id)['action']);
});

ind_test('regular', '32 create audit records document title', function () {
    $title = ind_unique_title('Audit Title');
    $id = create_document($title, 'body', 1);
    ind_eq($title, ind_details(ind_audit('create', 'document', $id))['title']);
});

ind_test('regular', '33 create audit records readable ID', function () {
    $id = create_document(ind_unique_title('Audit Readable'), 'body', 1);
    $doc = ind_doc($id);
    ind_eq($doc['readable_id'], ind_details(ind_audit('create', 'document', $id))['readable_id']);
});

ind_test('regular', '34 create audit records publish schedule', function () {
    $publishedAt = '2999-03-04 05:06:07';
    $id = create_document(ind_unique_title('Audit Schedule'), 'body', 1, $publishedAt);
    ind_eq($publishedAt, ind_details(ind_audit('create', 'document', $id))['published_at']);
});

ind_test('regular', '35 create_share returns a fresh opaque token', function () {
    $token = create_share((int) ind_seed_doc()['id'], 'php-token@example.com');
    ind_match('/^[a-f0-9]{32}$/', $token);
});

ind_test('regular', '36 create_share stores recipient email', function () {
    $token = create_share((int) ind_seed_doc()['id'], 'php-store@example.com');
    $stmt = db()->prepare('SELECT recipient_email FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    ind_eq('php-store@example.com', $stmt->fetchColumn());
});

ind_test('regular', '37 create_share writes an audit row', function () {
    $token = create_share((int) ind_seed_doc()['id'], 'php-audit@example.com');
    $stmt = db()->prepare('SELECT id FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    $shareId = (int) $stmt->fetchColumn();
    ind_eq('create', ind_audit('create', 'share', $shareId)['action']);
});

ind_test('regular', '38 share audit records document ID', function () {
    $doc = ind_seed_doc();
    $token = create_share((int) $doc['id'], 'php-audit-doc@example.com');
    $stmt = db()->prepare('SELECT id FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    $details = ind_details(ind_audit('create', 'share', (int) $stmt->fetchColumn()));
    ind_eq((int) $doc['id'], (int) $details['document_id']);
});

ind_test('regular', '39 share audit records recipient email', function () {
    $token = create_share((int) ind_seed_doc()['id'], 'php-audit-email@example.com');
    $stmt = db()->prepare('SELECT id FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    $details = ind_details(ind_audit('create', 'share', (int) $stmt->fetchColumn()));
    ind_eq('php-audit-email@example.com', $details['recipient_email']);
});

ind_test('regular', '40 schedule update changes stored published_at', function () {
    $id = create_document(ind_unique_title('Schedule Change'), 'body', 1);
    update_document_schedule($id, '2999-04-05 06:07:08');
    ind_eq('2999-04-05 06:07:08', ind_doc($id)['published_at']);
});

ind_test('regular', '41 schedule update audit records previous value', function () {
    $id = create_document(ind_unique_title('Schedule Previous'), 'body', 1, '2999-01-01 00:00:00');
    update_document_schedule($id, '2999-01-02 00:00:00');
    ind_eq('2999-01-01 00:00:00', ind_details(ind_audit('schedule_update', 'document', $id))['previous_published_at']);
});

ind_test('regular', '42 schedule update audit records new value', function () {
    $id = create_document(ind_unique_title('Schedule New'), 'body', 1);
    update_document_schedule($id, '2999-02-03 04:05:06');
    ind_eq('2999-02-03 04:05:06', ind_details(ind_audit('schedule_update', 'document', $id))['published_at']);
});

ind_test('regular', '43 clearing a schedule sets published_at to null', function () {
    $id = create_document(ind_unique_title('Schedule Clear'), 'body', 1, '2999-01-01 00:00:00');
    update_document_schedule($id, null);
    ind_eq(null, ind_doc($id)['published_at']);
});

ind_test('regular', '44 empty title search returns existing documents', function () {
    ind_true(count(search_documents_by_title('')) >= 1);
});

ind_test('regular', '45 lowercase partial title search finds a document', function () {
    $id = create_document(ind_unique_title('Budget Hearing Packet'), 'body', 1);
    ind_true(in_array($id, ind_ids(search_documents_by_title('hearing')), true));
});

ind_test('regular', '46 uppercase ASCII title search finds a document', function () {
    $id = create_document(ind_unique_title('Capital Plan Review'), 'body', 1);
    ind_true(in_array($id, ind_ids(search_documents_by_title('CAPITAL PLAN')), true));
});

ind_test('regular', '47 title search trims copied query spaces', function () {
    $id = create_document(ind_unique_title('Trim Query Target'), 'body', 1);
    ind_true(in_array($id, ind_ids(search_documents_by_title('  Query Target  ')), true));
});

ind_test('regular', '48 apostrophe title search is parameterized and works', function () {
    $id = create_document("Independent Mayor's Memo", 'body', 1);
    ind_true(in_array($id, ind_ids(search_documents_by_title("Mayor's")), true));
});

ind_test('regular', '49 percent sign search is literal', function () {
    $id = create_document('Independent 100% Literal Search', 'body', 1);
    ind_true(in_array($id, ind_ids(search_documents_by_title('100% Literal')), true));
});

ind_test('regular', '50 Chinese exact substring search works', function () {
    $id = create_document('Independent 市政公告资料', 'body', 1);
    ind_true(in_array($id, ind_ids(search_documents_by_title('公告')), true));
});

// Extreme behavior: 30 tests.
ind_test('extreme', '01 duplicate titles receive distinct readable IDs', function () {
    $a = ind_doc(create_document('Independent Duplicate Title', 'a', 1))['readable_id'];
    $b = ind_doc(create_document('Independent Duplicate Title', 'b', 1))['readable_id'];
    ind_ne($a, $b);
});

ind_test('extreme', '02 one hundred same-title documents keep IDs unique', function () {
    $ids = [];
    for ($i = 0; $i < 100; $i++) {
        $ids[] = ind_doc(create_document('Independent Collision Stress', 'body ' . $i, 1))['readable_id'];
    }
    ind_eq(count($ids), count(array_unique($ids)));
});

ind_test('extreme', '03 very long title readable ID remains bounded', function () {
    $id = create_document(str_repeat('Independent Long Title ', 20), 'body', 1);
    ind_true(strlen(ind_doc($id)['readable_id']) <= 37, 'readable ID too long');
});

ind_test('extreme', '04 truncated readable ID does not end with a dash before suffix', function () {
    $id = create_document(str_repeat('abcdef ', 20), 'body', 1);
    ind_false(str_contains(ind_doc($id)['readable_id'], '--'));
});

ind_test('extreme', '05 punctuation-only title falls back to document base', function () {
    $id = create_document('!!! --- ###', 'body', 1);
    ind_match('/^document-[a-f0-9]{4}$/', ind_doc($id)['readable_id']);
});

ind_test('extreme', '06 numeric-only title produces a speakable numeric slug', function () {
    $id = create_document('2026', 'body', 1);
    ind_match('/^2026-[a-f0-9]{4}$/', ind_doc($id)['readable_id']);
});

ind_test('extreme', '07 punctuation in title is normalized cleanly', function () {
    $id = create_document('Hello, CivicPlus: 2026!', 'body', 1);
    ind_match('/^hello-civicplus-2026-[a-f0-9]{4}$/', ind_doc($id)['readable_id']);
});

ind_test('extreme', '08 non-Latin title falls back without crashing', function () {
    $id = create_document('欢迎 пакет 🚀', 'body', 1);
    ind_match('/^document-[a-f0-9]{4}$/', ind_doc($id)['readable_id']);
});

ind_test('extreme', '09 slash characters do not survive in readable IDs', function () {
    $id = create_document('Finance/Legal/HR Packet', 'body', 1);
    ind_match('/^finance-legal-hr-packet-[a-f0-9]{4}$/', ind_doc($id)['readable_id']);
});

ind_test('extreme', '10 random_token one byte returns two hex characters', function () {
    ind_match('/^[a-f0-9]{2}$/', random_token(1));
});

ind_test('extreme', '11 random_token four bytes returns eight hex characters', function () {
    ind_match('/^[a-f0-9]{8}$/', random_token(4));
});

ind_test('extreme', '12 random_token stays unique across 500 attempts', function () {
    $tokens = [];
    for ($i = 0; $i < 500; $i++) {
        $tokens[] = random_token();
    }
    ind_eq(500, count(array_unique($tokens)));
});

ind_test('extreme', '13 leap-day storage datetime is accepted', function () {
    ind_eq('2028-02-29 23:59:59', normalize_published_at('2028-02-29 23:59:59'));
});

ind_test('extreme', '14 invalid leap day storage datetime is rejected', function () {
    ind_throws(static fn () => normalize_published_at('2027-02-29 00:00:00'));
});

ind_test('extreme', '15 invalid month storage datetime is rejected', function () {
    ind_throws(static fn () => normalize_published_at('2026-13-01 00:00:00'));
});

ind_test('extreme', '16 timezone-suffixed storage datetime is rejected', function () {
    ind_throws(static fn () => normalize_published_at('2026-05-09 12:00:00Z'));
});

ind_test('extreme', '17 datetime-local conversion uses the configured app timezone', function () {
    ind_eq('2026-01-01 06:00:00', parse_datetime_local_to_utc('2026-01-01T00:00'));
});

ind_test('extreme', '18 very large body round-trips through SQLite', function () {
    $body = str_repeat("large body line\n", 12000);
    $id = create_document(ind_unique_title('Huge Body'), $body, 1);
    ind_eq($body, ind_doc($id)['body']);
});

ind_test('extreme', '19 multiline body preserves line breaks', function () {
    $body = "first\n\nsecond\r\nthird";
    $id = create_document(ind_unique_title('Multiline Body'), $body, 1);
    ind_eq($body, ind_doc($id)['body']);
});

ind_test('extreme', '20 h escapes element brackets', function () {
    ind_eq('&lt;b&gt;bold&lt;/b&gt;', h('<b>bold</b>'));
});

ind_test('extreme', '21 h escapes both quote styles', function () {
    ind_eq('&quot;double&quot; and &#039;single&#039;', h('"double" and \'single\''));
});

ind_test('extreme', '22 two hundred generated documents have unique readable IDs', function () {
    $ids = [];
    for ($i = 0; $i < 200; $i++) {
        $ids[] = ind_doc(create_document('Independent Unique Batch ' . $i, 'body', 1))['readable_id'];
    }
    ind_eq(count($ids), count(array_unique($ids)));
});

ind_test('extreme', '23 underscore search is literal', function () {
    $id = create_document('Independent ABC_DEF Literal', 'body', 1);
    ind_true(in_array($id, ind_ids(search_documents_by_title('ABC_DEF')), true));
});

ind_test('extreme', '24 query containing only percent finds percent titles only', function () {
    $id = create_document('Independent Percent % Marker', 'body', 1);
    $ids = ind_ids(search_documents_by_title('%'));
    ind_true(in_array($id, $ids, true));
});

ind_test('extreme', '25 emoji title falls back to document base if no ASCII remains', function () {
    $id = create_document('🚀🔥✨', 'body', 1);
    ind_match('/^document-[a-f0-9]{4}$/', ind_doc($id)['readable_id']);
});

ind_test('extreme', '26 readable ID contains only URL-safe lowercase characters', function () {
    $id = create_document('Independent URL Safe + Spaces', 'body', 1);
    ind_match('/^[a-z0-9-]+$/', ind_doc($id)['readable_id']);
});

ind_test('extreme', '27 far future schedule stays hidden', function () {
    $id = create_document(ind_unique_title('Far Future'), 'body', 1, '9999-12-31 23:59:59');
    ind_false(document_is_published(ind_doc($id), '2999-01-01 00:00:00'));
});

ind_test('extreme', '28 Unix-era schedule is visible now', function () {
    $id = create_document(ind_unique_title('Unix Era'), 'body', 1, '1970-01-01 00:00:00');
    ind_true(document_is_published(ind_doc($id)));
});

ind_test('extreme', '29 create_document accepts the maximum sqlite-friendly simple date', function () {
    $id = create_document(ind_unique_title('Max Date'), 'body', 1, '9999-12-31 23:59:59');
    ind_eq('9999-12-31 23:59:59', ind_doc($id)['published_at']);
});

ind_test('extreme', '30 one second before schedule boundary remains hidden', function () {
    ind_false(document_is_published(['published_at' => '2026-05-09 17:30:01'], '2026-05-09 17:30:00'));
});

// Unexpected inputs and cross-contract checks: 20 tests.
ind_test('unexpected', '01 document audit staff_id follows the staff creator argument', function () {
    db()->exec("INSERT INTO staff (email, name) VALUES ('auditor2@example.com', 'Auditor Two')");
    $staffId = (int) db()->lastInsertId();
    $id = create_document(ind_unique_title('Second Staff Audit'), 'body', $staffId);
    ind_eq($staffId, (int) ind_audit('create', 'document', $id)['staff_id']);
});

ind_test('unexpected', '02 document created_by stores non-default valid staff', function () {
    db()->exec("INSERT INTO staff (email, name) VALUES ('creator2@example.com', 'Creator Two')");
    $staffId = (int) db()->lastInsertId();
    $id = create_document(ind_unique_title('Second Staff Creator'), 'body', $staffId);
    ind_eq($staffId, (int) ind_doc($id)['created_by']);
});

ind_test('unexpected', '03 unknown staff cannot create documents', function () {
    ind_throws(static fn () => create_document(ind_unique_title('Missing Staff'), 'body', 999999));
});

ind_test('unexpected', '04 unknown document cannot be shared', function () {
    ind_throws(static fn () => create_share(999999, 'nobody@example.com'));
});

ind_test('unexpected', '05 updating a missing document throws', function () {
    ind_throws(static fn () => update_document_schedule(999999, null));
});

ind_test('unexpected', '06 malformed email is rejected', function () {
    ind_throws(static fn () => create_share((int) ind_seed_doc()['id'], 'not-an-email'));
});

ind_test('unexpected', '07 newline email injection is rejected', function () {
    ind_throws(static fn () => create_share((int) ind_seed_doc()['id'], "reader@example.com\nBcc: bad@example.com"));
});

ind_test('unexpected', '08 uppercase recipient email is stored without unwanted lowercasing', function () {
    $token = create_share((int) ind_seed_doc()['id'], 'Reader@Example.COM');
    $stmt = db()->prepare('SELECT recipient_email FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    ind_eq('Reader@Example.COM', $stmt->fetchColumn());
});

ind_test('unexpected', '09 token with inserted internal spaces does not match', function () {
    $token = ind_seed_share()['token'];
    ind_eq(null, recipient_document_for_token(substr($token, 0, 8) . ' ' . substr($token, 8)));
});

ind_test('unexpected', '10 zero-padded numeric old reference resolves as the old numeric ID', function () {
    $found = find_document_by_reference('0001');
    ind_true($found !== null);
    ind_eq(1, (int) $found['id']);
});

ind_test('unexpected', '11 plus-prefixed numeric reference is not silently accepted', function () {
    ind_eq(null, find_document_by_reference('+1'));
});

ind_test('unexpected', '12 decimal numeric reference is not silently accepted', function () {
    ind_eq(null, find_document_by_reference('1.0'));
});

ind_test('unexpected', '13 title string zero is accepted as nonblank', function () {
    $id = create_document('0', 'body', 1);
    ind_eq('0', ind_doc($id)['title']);
});

ind_test('unexpected', '14 body string zero is accepted as nonblank', function () {
    $id = create_document(ind_unique_title('Zero Body'), '0', 1);
    ind_eq('0', ind_doc($id)['body']);
});

ind_test('unexpected', '15 blank storage schedule normalizes to null on create', function () {
    $id = create_document(ind_unique_title('Blank Storage'), 'body', 1, '    ');
    ind_eq(null, ind_doc($id)['published_at']);
});

ind_test('unexpected', '16 blank storage schedule normalizes to null on update', function () {
    $id = create_document(ind_unique_title('Blank Update'), 'body', 1, '2999-01-01 00:00:00');
    update_document_schedule($id, " \t\n");
    ind_eq(null, ind_doc($id)['published_at']);
});

ind_test('unexpected', '17 datetime-local storage format is rejected at helper boundary', function () {
    ind_throws(static fn () => create_document(ind_unique_title('Bad T Format'), 'body', 1, '2026-05-09T12:00'));
});

ind_test('unexpected', '18 impossible storage date is rejected at helper boundary', function () {
    ind_throws(static fn () => create_document(ind_unique_title('Impossible Date'), 'body', 1, '2026-02-31 12:00:00'));
});

ind_test('unexpected', '19 SQL-looking title search is treated as plain text', function () {
    ind_eq(0, count(search_documents_by_title("%' OR 1=1 --")));
});

ind_test('unexpected', '20 accented uppercase search finds accented title', function () {
    $id = create_document('Independent Résumé Packet', 'body', 1);
    ind_true(in_array($id, ind_ids(search_documents_by_title('RÉSUMÉ')), true), 'accented uppercase query did not match accented title');
});

// "Customer fool behavior": 20 tests.
ind_test('fool', '01 blank title is rejected server-side', function () {
    ind_throws(static fn () => create_document('', 'body', 1));
});

ind_test('fool', '02 space-only title is rejected server-side', function () {
    ind_throws(static fn () => create_document('   ', 'body', 1));
});

ind_test('fool', '03 tab-newline title is rejected server-side', function () {
    ind_throws(static fn () => create_document("\t\n", 'body', 1));
});

ind_test('fool', '04 blank body is rejected server-side', function () {
    ind_throws(static fn () => create_document(ind_unique_title('Blank Body'), '', 1));
});

ind_test('fool', '05 space-only body is rejected server-side', function () {
    ind_throws(static fn () => create_document(ind_unique_title('Space Body'), '     ', 1));
});

ind_test('fool', '06 empty email is rejected server-side', function () {
    ind_throws(static fn () => create_share((int) ind_seed_doc()['id'], ''));
});

ind_test('fool', '07 space-only email is rejected server-side', function () {
    ind_throws(static fn () => create_share((int) ind_seed_doc()['id'], '     '));
});

ind_test('fool', '08 schedule update with spaces clears schedule', function () {
    $id = create_document(ind_unique_title('Spaces Clear'), 'body', 1, '2999-01-01 00:00:00');
    update_document_schedule($id, '     ');
    ind_eq(null, ind_doc($id)['published_at']);
});

ind_test('fool', '09 datetime-local parser treats copied spaces as blank', function () {
    ind_eq(null, parse_datetime_local_to_utc(" \t\n"));
});

ind_test('fool', '10 blank token returns null', function () {
    ind_eq(null, recipient_document_for_token(''));
});

ind_test('fool', '11 space-only token returns null', function () {
    ind_eq(null, recipient_document_for_token('     '));
});

ind_test('fool', '12 whitespace-only document reference returns null', function () {
    ind_eq(null, find_document_by_reference("\n\t "));
});

ind_test('fool', '13 copied token with newline still opens', function () {
    $token = ind_seed_share()['token'];
    ind_true(recipient_document_for_token($token . "\n") !== null);
});

ind_test('fool', '14 repeated schedule update to the same time still records an audit entry', function () {
    $time = '2999-07-08 09:10:11';
    $id = create_document(ind_unique_title('Same Schedule'), 'body', 1, $time);
    $before = (int) db()->query('SELECT COUNT(*) FROM audit_log WHERE action = "schedule_update"')->fetchColumn();
    update_document_schedule($id, $time);
    $after = (int) db()->query('SELECT COUNT(*) FROM audit_log WHERE action = "schedule_update"')->fetchColumn();
    ind_eq($before + 1, $after);
});

ind_test('fool', '15 recipient email trims leading and trailing spaces', function () {
    $token = create_share((int) ind_seed_doc()['id'], '  spaced@example.com  ');
    $stmt = db()->prepare('SELECT recipient_email FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    ind_eq('spaced@example.com', $stmt->fetchColumn());
});

ind_test('fool', '16 recipient email trims leading and trailing tabs', function () {
    $token = create_share((int) ind_seed_doc()['id'], "\ttabbed@example.com\t");
    $stmt = db()->prepare('SELECT recipient_email FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    ind_eq('tabbed@example.com', $stmt->fetchColumn());
});

ind_test('fool', '17 title trims copied whitespace around real title', function () {
    $id = create_document("\n  Human Title  \t", 'body', 1);
    ind_eq('Human Title', ind_doc($id)['title']);
});

ind_test('fool', '18 create schedule trims surrounding spaces around storage datetime', function () {
    $id = create_document(ind_unique_title('Trim Schedule Create'), 'body', 1, ' 2999-01-01 00:00:00 ');
    ind_eq('2999-01-01 00:00:00', ind_doc($id)['published_at']);
});

ind_test('fool', '19 update schedule trims surrounding spaces around storage datetime', function () {
    $id = create_document(ind_unique_title('Trim Schedule Update'), 'body', 1);
    update_document_schedule($id, ' 2999-01-02 03:04:05 ');
    ind_eq('2999-01-02 03:04:05', ind_doc($id)['published_at']);
});

ind_test('fool', '20 lowercase copied readable ID with spaces still resolves', function () {
    $doc = ind_seed_doc();
    $found = find_document_by_reference(' ' . $doc['readable_id'] . ' ');
    ind_true($found !== null);
    ind_eq((int) $doc['id'], (int) $found['id']);
});

echo "\nPHP independent result: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
