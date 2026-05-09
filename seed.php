<?php

require __DIR__ . '/lib/bootstrap.php';

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
apply_migrations($pdo, __DIR__ . '/migrations');

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('freddy@folio.example', 'Freddy Folio')
");

$docId = create_document(
    'Welcome Packet',
    "Welcome to Folio!\n\nThis is the body of your welcome packet.",
    1
);

$token = create_share($docId, 'recipient@example.com');

echo "Seeded db.sqlite.\n";
echo "Admin:        http://localhost:8000/admin.php\n";
echo "Sample share: http://localhost:8000/view.php?token={$token}\n";
