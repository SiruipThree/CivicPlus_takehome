<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$docRef = trim((string) ($_GET['doc'] ?? ''));
$doc = find_document_by_reference($docRef);

if (!$doc) {
    http_response_code(404);
    render_header('Not found', $staff);
    ?>
    <div class="banner banner-error">Document not found.</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

$error = null;
$message = null;
$created_token = null;
$schedulePublishedAtInput = format_utc_for_datetime_local($doc['published_at']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'share';

    if ($action === 'schedule') {
        $schedulePublishedAtInput = trim($_POST['published_at'] ?? '');
        try {
            $publishedAt = parse_datetime_local_to_utc($schedulePublishedAtInput);
            update_document_schedule((int) $doc['id'], $publishedAt);
            $doc = find_document_by_reference($docRef) ?? $doc;
            $schedulePublishedAtInput = format_utc_for_datetime_local($doc['published_at']);
            $message = 'Publishing schedule updated.';
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    } else {
        $email = trim($_POST['email'] ?? '');
        if ($email === '') {
            $error = 'Recipient email is required.';
        } else {
            try {
                $created_token = create_share((int) $doc['id'], $email);
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
    }
}

render_header('Share · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Share "<?= h($doc['title']) ?>"</h1>
<p class="page-subtitle">Document ID <?= h($doc['readable_id'] ?? ('#' . $doc['id'])) ?> · Generate a one-time link for a recipient.</p>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<?php if ($message): ?>
    <div class="banner banner-success"><?= h($message) ?></div>
<?php endif ?>

<?php if ($created_token): ?>
    <div class="banner banner-success">
        Share link ready:
        <code>http://<?= h($_SERVER['HTTP_HOST']) ?>/view.php?token=<?= h($created_token) ?></code>
    </div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Publishing schedule</h2>
    <form method="post">
        <input type="hidden" name="action" value="schedule">
        <div class="form-field">
            <label for="published_at">Publish at</label>
            <input type="datetime-local" id="published_at" name="published_at" value="<?= h($schedulePublishedAtInput) ?>">
            <p class="field-help">Leave blank to make the document available immediately.</p>
        </div>
        <p class="meta">Current availability: <?= h(format_utc_for_display($doc['published_at'])) ?></p>
        <button type="submit" class="btn">Update schedule</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Create share link</h2>
    <form method="post">
        <input type="hidden" name="action" value="share">
        <div class="form-field">
            <label for="email">Recipient email</label>
            <input type="email" id="email" name="email" required>
        </div>
        <button type="submit" class="btn">Generate link</button>
    </form>
</section>

<?php render_footer(); ?>
