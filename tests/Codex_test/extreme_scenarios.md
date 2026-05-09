# Codex Extreme Scenario Tests

These are non-PHP scenario tests intended to stress the product from a reviewer/user perspective. They are written as executable-quality manual or HTTP checks, not as production fixes.

## 01 Root Redirect

- Steps: Open `/`.
- Expected: User lands on `/admin.php`.
- Risk checked: The default entry point should not expose a blank or broken page.

## 02 Future Publish Through UI

- Steps: In Admin, create a document with a publish time one hour in the future, generate a share link, then open the recipient URL.
- Expected: Recipient sees "Document not yet available" and cannot see the body.
- Risk checked: Scheduled publishing works end to end, not only in helper functions.

## 03 Past Publish Through UI

- Steps: Create a document with a publish time one hour in the past, generate a share link, then open the recipient URL.
- Expected: Recipient sees the document body.
- Risk checked: Past schedules do not block content.

## 04 Immediate Publish Through UI

- Steps: Create a document with an empty publish date, generate a share link, then open the recipient URL.
- Expected: Recipient sees the document body immediately.
- Risk checked: Blank schedule is interpreted as immediately available.

## 05 Schedule Clear

- Steps: Create a future-scheduled document, open its share page, clear the publish date, save, then open the recipient URL.
- Expected: Recipient can now see the document body.
- Risk checked: Schedule updates are applied after creation.

## 06 Invalid Schedule Input Bypass

- Steps: POST directly to `share.php?doc=<readable_id>` with `action=schedule&published_at=not-a-date`.
- Expected: Server rejects the invalid schedule and keeps the old schedule.
- Risk checked: Browser controls are not the only validation layer.

## 07 Blank Recipient Email

- Steps: POST directly to `share.php?doc=<readable_id>` with `action=share&email=`.
- Expected: Server rejects the request and does not create a share.
- Risk checked: Required recipient field is enforced server-side.

## 08 Invalid Recipient Email Bypass

- Steps: POST directly to `share.php?doc=<readable_id>` with `action=share&email=not-an-email`.
- Expected: Server rejects the request and does not create a share.
- Risk checked: `type=email` in HTML is not sufficient validation.

## 09 Uppercase Readable ID URL

- Steps: Take a generated readable ID such as `welcome-packet-ab12`, uppercase it, then open `/share.php?doc=WELCOME-PACKET-AB12`.
- Expected: The document resolves, because humans may type IDs with different casing.
- Risk checked: Human-readable IDs should be forgiving.

## 10 Numeric URL Backward Compatibility

- Steps: Open `/share.php?doc=1` after readable IDs are enabled.
- Expected: The document still resolves.
- Risk checked: Existing numeric links do not break.

## 11 Readable ID Is Not Recipient Access

- Steps: Open `/view.php?token=<readable_id>`.
- Expected: Recipient sees "Share link not found."
- Risk checked: Readable IDs do not replace share tokens.

## 12 Random Token Rejection

- Steps: Open `/view.php?token=random-not-real`.
- Expected: Recipient sees "Share link not found."
- Risk checked: Unknown tokens do not leak documents.

## 13 Empty Token Rejection

- Steps: Open `/view.php?token=`.
- Expected: Recipient sees "Share link not found."
- Risk checked: Empty token does not match anything accidentally.

## 14 Script Injection In Title

- Steps: Create a document titled `<script>alert(1)</script>`, then load Admin, Share, and View pages.
- Expected: The script text is escaped and never executes.
- Risk checked: XSS protection in visible title surfaces.

## 15 Script Injection In Body

- Steps: Create a document body with `<img src=x onerror=alert(1)>`, then open the recipient URL.
- Expected: The body is escaped as text and does not execute.
- Risk checked: XSS protection in document content.

## 16 Duplicate Titles

- Steps: Create two documents with the exact same title.
- Expected: Both documents receive different readable IDs and can be shared independently.
- Risk checked: Readable ID collision handling.

## 17 Long Title Layout

- Steps: Create a document with a 300-character title, then view Admin and Share pages on mobile width.
- Expected: Text wraps without overlapping buttons, table cells, or cards.
- Risk checked: UI resilience with long content.

## 18 Special Character Search

- Steps: Create `100% Coverage _ Draft`, search for `100% Coverage _`.
- Expected: Search finds that document literally.
- Risk checked: Search does not treat `%` or `_` as accidental SQL wildcards.

## 19 Non-ASCII Search

- Steps: Create a document titled `Résumé 市政公告`, search for `résumé`, `RÉSUMÉ`, and `市政`.
- Expected: Search behavior is documented and consistent; ideally all variants work.
- Risk checked: Case-insensitive search may only be reliable for ASCII.

## 20 Migration Re-run Safety

- Steps: Apply migrations to an already-migrated database without deleting `db.sqlite`.
- Expected: Migration runner skips already-applied migrations instead of crashing.
- Risk checked: Migration system should be safe outside the current fresh-seed flow.
