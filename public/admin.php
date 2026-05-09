<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;
$formTitle = '';
$formBody = '';
$formPublishedAt = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawTitle = $_POST['title'] ?? '';
    $rawBody = $_POST['body'] ?? '';
    $rawPublishedAt = $_POST['published_at'] ?? '';
    $formTitle = is_string($rawTitle) ? trim($rawTitle) : '';
    $formBody = is_string($rawBody) ? trim($rawBody) : '';
    $formPublishedAt = is_string($rawPublishedAt) ? trim($rawPublishedAt) : '';

    if ($formTitle === '' || $formBody === '') {
        $error = 'Title and body are required.';
    } else {
        try {
            $publishedAt = parse_datetime_local_to_utc($formPublishedAt);
            $docId = create_document($formTitle, $formBody, (int) $staff['id'], $publishedAt);

            $stmt = db()->prepare('SELECT readable_id FROM documents WHERE id = ?');
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();

            header('Location: /admin.php?created=' . rawurlencode($doc['readable_id'] ?? (string) $docId));
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

$rawSearch = $_GET['q'] ?? '';
$search = is_string($rawSearch) ? trim($rawSearch) : '';
$docs = search_documents_by_title($search);

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document <?= h((string) $_GET['created']) ?> created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="<?= h($formTitle) ?>" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required><?= h($formBody) ?></textarea>
        </div>
        <div class="form-field">
            <label for="published_at">Publish at</label>
            <input type="datetime-local" id="published_at" name="published_at" value="<?= h($formPublishedAt) ?>">
            <p class="field-help">Leave blank to make the document available immediately.</p>
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <form method="get" class="search-form">
        <div class="form-field search-field">
            <label for="q">Search by title</label>
            <input type="text" id="q" name="q" value="<?= h($search) ?>" placeholder="Search documents">
        </div>
        <button type="submit" class="btn">Search</button>
        <?php if ($search !== ''): ?>
            <a href="/admin.php" class="btn-link">Clear</a>
        <?php endif ?>
    </form>
    <?php if (empty($docs)): ?>
        <p class="empty"><?= $search === '' ? 'No documents yet.' : 'No documents matched your search.' ?></p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Availability</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <?php
                    $docRef = $d['readable_id'] ?: (string) $d['id'];
                    $isPublished = document_is_published($d);
                    $statusClass = $isPublished ? 'status-published' : 'status-scheduled';
                    $statusText = $isPublished ? 'Available' : 'Scheduled';
                    ?>
                    <tr>
                        <td class="id"><?= h($docRef) ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td>
                            <span class="status-pill <?= h($statusClass) ?>"><?= h($statusText) ?></span>
                            <div class="table-note"><?= h(format_utc_for_display($d['published_at'])) ?></div>
                        </td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><a href="/share.php?doc=<?= rawurlencode($docRef) ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
