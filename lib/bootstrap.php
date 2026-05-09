<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function apply_migrations(PDO $pdo, string $dir): void {
    $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $name = basename($file);
        if (migration_has_been_applied($pdo, $name)) {
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Unable to read migration: ' . $file);
        }

        try {
            $pdo->beginTransaction();
            $pdo->exec($sql);
            record_migration_if_available($pdo, $name);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

function migration_tracking_available(PDO $pdo): bool {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM sqlite_master
        WHERE type = 'table' AND name = 'schema_migrations'
        LIMIT 1
    ");
    $stmt->execute();

    return (bool) $stmt->fetchColumn();
}

function migration_has_been_applied(PDO $pdo, string $name): bool {
    if (!migration_tracking_available($pdo)) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration = ? LIMIT 1');
    $stmt->execute([$name]);

    return (bool) $stmt->fetchColumn();
}

function record_migration_if_available(PDO $pdo, string $name): void {
    if (!migration_tracking_available($pdo)) {
        return;
    }

    $stmt = $pdo->prepare('
        INSERT OR IGNORE INTO schema_migrations (migration)
        VALUES (?)
    ');
    $stmt->execute([$name]);
}

function app_timezone(): DateTimeZone {
    return new DateTimeZone(date_default_timezone_get());
}

function utc_timezone(): DateTimeZone {
    return new DateTimeZone('UTC');
}

function utc_now(): string {
    return gmdate('Y-m-d H:i:s');
}

function parse_datetime_local_to_utc(?string $value): ?string {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, app_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    $hasErrors = $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
    if ($dt === false || $hasErrors) {
        throw new InvalidArgumentException('Publish date must be a valid date and time.');
    }

    return $dt->setTimezone(utc_timezone())->format('Y-m-d H:i:s');
}

function normalize_published_at(?string $value): ?string {
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, utc_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    $hasErrors = $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
    if ($dt === false || $hasErrors || $dt->format('Y-m-d H:i:s') !== $value) {
        throw new InvalidArgumentException('Publish date must be stored as YYYY-MM-DD HH:MM:SS.');
    }

    return $value;
}

function format_utc_for_datetime_local(?string $value): string {
    if ($value === null || $value === '') {
        return '';
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, utc_timezone());
    if ($dt === false) {
        return '';
    }

    return $dt->setTimezone(app_timezone())->format('Y-m-d\TH:i');
}

function format_utc_for_display(?string $value): string {
    if ($value === null || $value === '') {
        return 'Immediately';
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, utc_timezone());
    if ($dt === false) {
        return $value;
    }

    return $dt->setTimezone(app_timezone())->format('M j, Y g:i A T');
}

function document_is_published(array $doc, ?string $now = null): bool {
    if (empty($doc['published_at'])) {
        return true;
    }

    return $doc['published_at'] <= ($now ?? utc_now());
}

function readable_id_base(string $title): string {
    $base = strtolower(trim($title));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'document';
    }

    $base = substr($base, 0, 32);
    return trim($base, '-') ?: 'document';
}

function generate_readable_id(string $title): string {
    $base = readable_id_base($title);

    for ($i = 0; $i < 10; $i++) {
        $candidate = $base . '-' . bin2hex(random_bytes(2));
        $stmt = db()->prepare('SELECT 1 FROM documents WHERE readable_id = ? LIMIT 1');
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
    }

    return $base . '-' . random_token(4);
}

function create_document(string $title, string $body, int $staffId, ?string $publishedAt = null): int {
    $title = trim($title);
    if ($title === '' || trim($body) === '') {
        throw new InvalidArgumentException('Title and body are required.');
    }

    $publishedAt = normalize_published_at($publishedAt);
    $readableId = generate_readable_id($title);
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, published_at, readable_id)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$title, $body, $staffId, $publishedAt, $readableId]);
    $docId = (int) db()->lastInsertId();

    audit_log('create', 'document', $docId, [
        'title' => $title,
        'readable_id' => $readableId,
        'published_at' => $publishedAt,
    ], $staffId);

    return $docId;
}

function update_document_schedule(int $docId, ?string $publishedAt): void {
    $publishedAt = normalize_published_at($publishedAt);
    $stmt = db()->prepare('SELECT published_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw new RuntimeException('Document not found.');
    }

    $stmt = db()->prepare('UPDATE documents SET published_at = ? WHERE id = ?');
    $stmt->execute([$publishedAt, $docId]);

    audit_log('schedule_update', 'document', $docId, [
        'previous_published_at' => $doc['published_at'],
        'published_at' => $publishedAt,
    ]);
}

function find_document_by_reference(string $ref): ?array {
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM documents WHERE readable_id = ?');
    $stmt->execute([strtolower($ref)]);
    $doc = $stmt->fetch();
    if ($doc) {
        return $doc;
    }

    if (ctype_digit($ref)) {
        $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
        $stmt->execute([(int) $ref]);
        $doc = $stmt->fetch();
        if ($doc) {
            return $doc;
        }
    }

    return null;
}

function recipient_document_for_token(string $token): ?array {
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $stmt = db()->prepare('
        SELECT d.*, s.recipient_email
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $doc = $stmt->fetch();

    return $doc ?: null;
}

function search_documents_by_title(string $query = ''): array {
    $query = trim($query);
    if ($query === '') {
        $stmt = db()->query('
            SELECT d.*, s.name AS creator_name
            FROM documents d
            JOIN staff s ON s.id = d.created_by
            ORDER BY d.created_at DESC
        ');
        return $stmt->fetchAll();
    }

    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE instr(LOWER(d.title), LOWER(?)) > 0
        ORDER BY d.created_at DESC
    ');
    $stmt->execute([$query]);

    return $stmt->fetchAll();
}

function create_share(int $docId, string $recipientEmail): string {
    $recipientEmail = trim($recipientEmail);
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Recipient email must be valid.');
    }

    $token = random_token();
    $stmt = db()->prepare('
        INSERT INTO shares (document_id, token, recipient_email)
        VALUES (?, ?, ?)
    ');
    $stmt->execute([$docId, $token, $recipientEmail]);
    $shareId = (int) db()->lastInsertId();

    audit_log('create', 'share', $shareId, [
        'document_id' => $docId,
        'recipient_email' => $recipientEmail,
    ]);

    return $token;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = [], ?int $staffId = null): void {
    $staffId = $staffId ?? (int) current_staff()['id'];
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staffId,
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
