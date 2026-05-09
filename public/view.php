<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$rawToken = $_GET['token'] ?? '';
$token = is_string($rawToken) ? $rawToken : '';

$doc = recipient_document_for_token($token);

if (!$doc) {
    http_response_code(404);
    render_header('Not found');
    ?>
    <div class="centered-message">
        <h1>Share link not found</h1>
        <p>The link you used is invalid or has been removed.</p>
    </div>
    <?php
    render_footer();
    exit;
}

if (!document_is_published($doc)) {
    http_response_code(403);
    render_header('Not yet available');
    ?>
    <div class="centered-message">
        <h1>Document not yet available</h1>
        <p>This document is scheduled for <?= h(format_utc_for_display($doc['published_at'])) ?>.</p>
    </div>
    <?php
    render_footer();
    exit;
}

render_header($doc['title']);
?>

<h1 class="page-title"><?= h($doc['title']) ?></h1>
<p class="meta">Document ID <?= h($doc['readable_id'] ?? ('#' . $doc['id'])) ?> · Shared with <?= h($doc['recipient_email']) ?></p>

<pre class="doc-body"><?= h($doc['body']) ?></pre>

<?php render_footer(); ?>
