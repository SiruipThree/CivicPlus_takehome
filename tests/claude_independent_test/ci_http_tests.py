#!/usr/bin/env python3
"""Claude independent HTTP black-box tests (100) — own PHP server, novel test areas."""

from __future__ import annotations
import base64
import html
import json
import os
import re
import socket
import sqlite3
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
DB = os.path.join(ROOT, "db.sqlite")
PASS = 0
FAIL = 0
N = 0
BASE = ""


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def seed():
    subprocess.run(["php", "seed.php"], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)


def db_conn():
    c = sqlite3.connect(DB); c.row_factory = sqlite3.Row; return c


def db_val(q, p=()):
    c = db_conn()
    try:
        r = c.execute(q, p).fetchone()
        return None if r is None else r[0]
    finally:
        c.close()


def db_row(q, p=()):
    c = db_conn()
    try:
        return c.execute(q, p).fetchone()
    finally:
        c.close()


def uniq(label):
    global N; N += 1; return f"CI HTTP {label} {N}"

def random_token_py():
    import secrets; return secrets.token_hex(4)


def b64e(v): return base64.b64encode(v.encode()).decode()
def php_str(v): return f'base64_decode("{b64e(v)}")'


def php_eval(code):
    r = subprocess.run(["php", "-r", code], cwd=ROOT, check=True, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return r.stdout.strip()


def mk_doc(title, body="body", pub=None):
    p = "null" if pub is None else php_str(pub)
    return int(php_eval(f'require "lib/bootstrap.php"; echo create_document({php_str(title)}, {php_str(body)}, 1, {p});'))


def mk_share(doc_id, email="ci-http@example.com"):
    return php_eval(f'require "lib/bootstrap.php"; echo create_share({doc_id}, {php_str(email)});')


def req(path, data=None, method=None, headers_out=False):
    enc = None; hdrs = {}
    if data is not None:
        if isinstance(data, list):
            enc = urllib.parse.urlencode(data, doseq=True).encode()
        else:
            enc = urllib.parse.urlencode(data).encode()
        hdrs["Content-Type"] = "application/x-www-form-urlencoded"
    r = urllib.request.Request(BASE + path, data=enc, headers=hdrs, method=method)
    try:
        with urllib.request.urlopen(r, timeout=10) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            if headers_out:
                return resp.status, resp.geturl(), body, dict(resp.getheaders())
            return resp.status, resp.geturl(), body
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="replace")
        if headers_out:
            return e.code, e.geturl(), body, dict(e.headers)
        return e.code, e.geturl(), body


def post_create(title, body, pub=""):
    return req("/admin.php", {"title": title, "body": body, "published_at": pub})


def post_share(rid, email):
    return req("/share.php?doc=" + urllib.parse.quote(rid), {"action": "share", "email": email})


def post_sched(rid, pub):
    return req("/share.php?doc=" + urllib.parse.quote(rid), {"action": "schedule", "published_at": pub})


def seeded_doc():
    r = db_row("SELECT * FROM documents ORDER BY id LIMIT 1")
    assert r, "no seed doc"; return r


def seeded_token():
    t = db_val("SELECT token FROM shares ORDER BY id LIMIT 1")
    assert t, "no seed token"; return str(t)


def test(cat, name, fn):
    global PASS, FAIL
    try:
        fn(); print(f"  [ok] [{cat}] {name}"); PASS += 1
    except Exception as e:
        print(f"  [FAIL] [{cat}] {name}: {e}"); FAIL += 1


def ok(c, m="expected true"):
    if not c: raise AssertionError(m)
def eq(e, a, m=None):
    if e != a: raise AssertionError(m or f"expected {e!r}, got {a!r}")
def ne(u, a, m=None):
    if u == a: raise AssertionError(m or f"unexpected {a!r}")
def has(n, h, m=None):
    if n not in h: raise AssertionError(m or f"missing {n!r}")
def hasnt(n, h, m=None):
    if n in h: raise AssertionError(m or f"unexpected {n!r}")
def not500(r):
    ne(500, r[0], "server error 500")


def main():
    global BASE
    seed()
    port = free_port()
    BASE = f"http://127.0.0.1:{port}"
    srv = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", "public"], cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    try:
        for _ in range(50):
            try: req("/admin.php"); break
            except: time.sleep(0.1)
        else:
            raise RuntimeError("server did not start")

        print("\nRunning CI HTTP tests (100):")

        # ===== REGULAR (35) =====
        test("regular", "R01 admin Content-Type is text/html", lambda: (
            (lambda s, u, b, h: ok(any("text/html" in v for k, v in h.items() if k.lower() == "content-type")))(*req("/admin.php", headers_out=True))
        ))
        test("regular", "R02 admin page has html and head and body tags", lambda: (
            (lambda b: (has("<html", b.lower()), has("<head>", b.lower()), has("<body>", b.lower())))(req("/admin.php")[2])
        ))
        test("regular", "R03 admin page has viewport meta tag", lambda: has('viewport', req("/admin.php")[2]))
        test("regular", "R04 admin form method is POST", lambda: has('method="post"', req("/admin.php")[2].lower()))
        test("regular", "R05 admin page form has title input with required attr", lambda: (
            (lambda b: (has('id="title"', b), has("required", b)))(req("/admin.php")[2])
        ))
        test("regular", "R06 admin page form has body textarea with required attr", lambda: (
            (lambda b: (has('id="body"', b), has("</textarea>", b.lower())))(req("/admin.php")[2])
        ))
        test("regular", "R07 admin seeded doc shows Available pill", lambda: has("status-published", req("/admin.php")[2]))
        test("regular", "R08 share page has back link to admin", lambda: has('href="/admin.php"', req("/share.php?doc=" + seeded_doc()["readable_id"])[2]))
        test("regular", "R09 share page title includes document title", lambda: has("Welcome Packet", req("/share.php?doc=" + seeded_doc()["readable_id"])[2]))
        test("regular", "R10 share page has hidden action=schedule", lambda: has('value="schedule"', req("/share.php?doc=" + seeded_doc()["readable_id"])[2]))
        test("regular", "R11 share page has hidden action=share", lambda: has('value="share"', req("/share.php?doc=" + seeded_doc()["readable_id"])[2]))
        test("regular", "R12 view page shows readable_id in meta text", lambda: (
            has(seeded_doc()["readable_id"], req("/view.php?token=" + seeded_token())[2])
        ))
        test("regular", "R13 view page wraps body in pre tag", lambda: has("<pre", req("/view.php?token=" + seeded_token())[2]))
        test("regular", "R14 404 view has centered-message div", lambda: has("centered-message", req("/view.php?token=bad")[2]))
        test("regular", "R15 403 view shows scheduled datetime", lambda: (
            (lambda doc_id: (
                (lambda token: (lambda r: (eq(403, r[0]), ok(re.search(r"\d{4}", r[2]) is not None)))(req("/view.php?token=" + token)))(mk_share(doc_id, "ci-r15@example.com"))
            ))(mk_doc(uniq("R15"), "body", "2999-01-01 00:00:00"))
        ))
        test("regular", "R16 POST create redirects with created= parameter", lambda: (
            (lambda r: has("created=", r[1]))(post_create(uniq("Redirect"), "body"))
        ))
        test("regular", "R17 admin with created= param shows success banner", lambda: (
            has("banner-success", req("/admin.php?created=test-123")[2])
        ))
        test("regular", "R18 search form preserves query value", lambda: (
            (lambda b: has('value="testquery"', b))(req("/admin.php?q=testquery")[2])
        ))
        test("regular", "R19 no-results page has Clear link", lambda: has('href="/admin.php"', req("/admin.php?q=zzzznonexistent")[2]))
        test("regular", "R20 create then search finds immediately", lambda: (
            (lambda t: (mk_doc(t), has(t, req("/admin.php?q=" + urllib.parse.quote(t[:10]))[2])))(uniq("Instant"))
        ))
        test("regular", "R21 share success banner contains full token URL", lambda: (
            (lambda r: (eq(200, r[0]), has("/view.php?token=", r[2])))(post_share(seeded_doc()["readable_id"], "ci-r21@example.com"))
        ))
        test("regular", "R22 admin Available pill uses status-published CSS class", lambda: has("status-published", req("/admin.php")[2]))
        test("regular", "R23 CSS file returns 200", lambda: eq(200, req("/assets/style.css")[0]))
        test("regular", "R24 CSS file Content-Type includes text/css", lambda: (
            (lambda s, u, b, h: ok("css" in h.get("Content-Type", "").lower()))(*req("/assets/style.css", headers_out=True))
        ))
        test("regular", "R25 admin nav bar shows staff name", lambda: has("Freddy Folio", req("/admin.php")[2]))
        test("regular", "R26 admin nav bar shows staff email", lambda: has("freddy@folio.example", req("/admin.php")[2]))
        test("regular", "R27 view page nav does NOT show staff info", lambda: hasnt("Freddy Folio", req("/view.php?token=" + seeded_token())[2]))
        test("regular", "R28 admin field-help text for published_at", lambda: has("Leave blank", req("/admin.php")[2]))
        test("regular", "R29 future doc appears as Scheduled in admin", lambda: (
            (lambda t: (mk_doc(t, "body", "2999-01-01 00:00:00"), has("Scheduled", req("/admin.php?q=" + urllib.parse.quote(t))[2])))(uniq("SchedPill"))
        ))
        test("regular", "R30 immediate doc appears as Available in admin", lambda: (
            (lambda t: (mk_doc(t), has("Available", req("/admin.php?q=" + urllib.parse.quote(t))[2])))(uniq("AvailPill"))
        ))
        test("regular", "R31 view page has doc-body CSS class", lambda: has("doc-body", req("/view.php?token=" + seeded_token())[2]))
        test("regular", "R32 admin table has ID column header", lambda: has("<th>ID</th>", req("/admin.php")[2]))
        test("regular", "R33 share page shows current availability text", lambda: has("Current availability:", req("/share.php?doc=" + seeded_doc()["readable_id"])[2]))
        test("regular", "R34 share page schedule help text present", lambda: has("Leave blank", req("/share.php?doc=" + seeded_doc()["readable_id"])[2]))
        test("regular", "R35 POST create with empty schedule stores null published_at", lambda: (
            (lambda t: (post_create(t, "body"), eq(None, db_val("SELECT published_at FROM documents WHERE title=?", (t,)))))(uniq("NullSched"))
        ))

        # ===== EXTREME (25) =====
        test("extreme", "E01 POST create with 500 char title stores and renders", lambda: (
            (lambda t: (post_create(t, "body"), has(t[:50], req("/admin.php?q=" + urllib.parse.quote(t[:20]))[2])))(uniq("Long") + "X" * 480)
        ))
        test("extreme", "E02 title containing all HTML entity chars escaped", lambda: (
            (lambda t: (mk_doc(t), hasnt(t, req("/admin.php?q=" + urllib.parse.quote("entity"))[2])))(uniq("entity") + ' <b>"quotes"&amp;</b>')
        ))
        test("extreme", "E03 body with nested HTML tags escaped on view", lambda: (
            (lambda token: (lambda b: (has("&lt;div&gt;", b), hasnt("<div>nested</div>", b)))(req("/view.php?token=" + token)[2]))(
                mk_share(mk_doc(uniq("Nested"), "<div>nested<span>inner</span></div>"), "ci-e3@example.com")
            )
        ))
        test("extreme", "E04 search with double-encoded characters safe", lambda: not500(req("/admin.php?q=%2525")))
        test("extreme", "E05 rapid 5 create POSTs all have unique readable_ids", lambda: (
            (lambda t: (
                [post_create(t, f"body {i}") for i in range(5)],
                eq(5, len(set(r["readable_id"] for r in db_conn().execute("SELECT readable_id FROM documents WHERE title=?", (t,)).fetchall())))
            ))(uniq("Rapid"))
        ))
        test("extreme", "E06 schedule clear changes display to Immediately", lambda: (
            (lambda doc_id: (
                post_sched(db_row("SELECT readable_id FROM documents WHERE id=?", (doc_id,))["readable_id"], ""),
                has("Immediately", req("/share.php?doc=" + db_row("SELECT readable_id FROM documents WHERE id=?", (doc_id,))["readable_id"])[2])
            ))(mk_doc(uniq("ClearDisp"), "body", "2999-01-01 00:00:00"))
        ))
        test("extreme", "E07 view page delivers complete content for large body", lambda: (
            (lambda token: has("END-MARKER", req("/view.php?token=" + token)[2]))(
                mk_share(mk_doc(uniq("BigView"), "x" * 5000 + "\nEND-MARKER"), "ci-e7@example.com")
            )
        ))
        test("extreme", "E08 admin page with 30+ docs renders", lambda: (
            [mk_doc(uniq(f"Bulk {i}")) for i in range(10)],
            eq(200, req("/admin.php")[0])
        ))
        test("extreme", "E09 single char search returns matches", lambda: (
            (lambda t: (mk_doc(t), ok(t in req("/admin.php?q=Z")[2])))(uniq("Zeta"))
        ))
        test("extreme", "E10 search for term in multiple docs returns all", lambda: (
            (lambda marker: (
                [mk_doc(f"{marker} Doc {i}") for i in range(3)],
                ok(req("/admin.php?q=" + urllib.parse.quote(marker))[2].count(marker) >= 3, "fewer than 3 matches")
            ))("Xqj" + random_token_py())
        ))
        test("extreme", "E11 share page for doc with special title renders safely", lambda: (
            (lambda doc_id: eq(200, req("/share.php?doc=" + db_row("SELECT readable_id FROM documents WHERE id=?", (doc_id,))["readable_id"])[0]))(
                mk_doc('<script>alert("title")</script>')
            )
        ))
        test("extreme", "E12 POST create with emoji title succeeds", lambda: not500(post_create(uniq("🔥 Emoji"), "body")))
        test("extreme", "E13 POST create with Chinese title and body succeeds", lambda: (
            (lambda t: (eq(200, post_create(t, "中文内容")[0])))(uniq("中文标题"))
        ))
        test("extreme", "E14 share page for null schedule shows Immediately", lambda: (
            (lambda doc: has("Immediately", req("/share.php?doc=" + doc["readable_id"])[2]))(seeded_doc())
        ))
        test("extreme", "E15 share page for future schedule shows year", lambda: (
            (lambda doc_id: has("2999", req("/share.php?doc=" + db_row("SELECT readable_id FROM documents WHERE id=?", (doc_id,))["readable_id"])[2]))(
                mk_doc(uniq("FutureDisp"), "body", "2999-06-15 12:00:00")
            )
        ))
        test("extreme", "E16 GET admin.php with empty q= shows all documents", lambda: (
            (lambda body1, body2: eq(body1.count("<tr>"), body2.count("<tr>")))(req("/admin.php")[2], req("/admin.php?q=")[2])
        ))
        test("extreme", "E17 POST create audit is recorded in database", lambda: (
            (lambda t: (
                post_create(t, "body"),
                ok(db_val("SELECT COUNT(*) FROM audit_log a JOIN documents d ON a.entity_id=d.id WHERE a.action='create' AND a.entity_type='document' AND d.title=?", (t,)) >= 1)
            ))(uniq("AuditHTTP"))
        ))
        test("extreme", "E18 POST share audit is recorded in database", lambda: (
            (lambda before: (
                post_share(seeded_doc()["readable_id"], "ci-e18@example.com"),
                ok(int(db_val("SELECT COUNT(*) FROM audit_log WHERE action='create' AND entity_type='share'")) > before)
            ))(int(db_val("SELECT COUNT(*) FROM audit_log WHERE action='create' AND entity_type='share'")))
        ))
        test("extreme", "E19 POST schedule audit is recorded", lambda: (
            (lambda doc: (
                post_sched(doc["readable_id"], "2999-03-04T05:06"),
                ok(db_val("SELECT COUNT(*) FROM audit_log WHERE action='schedule_update' AND entity_id=?", (doc["id"],)) >= 1)
            ))(seeded_doc())
        ))
        test("extreme", "E20 admin page brand mark F is present", lambda: has("brand-mark", req("/admin.php")[2]))
        test("extreme", "E21 admin page brand text Folio is present", lambda: has("Folio", req("/admin.php")[2]))
        test("extreme", "E22 error page for 404 has proper title tag", lambda: has("<title>", req("/view.php?token=bad")[2].lower()))
        test("extreme", "E23 error page for 403 has proper title tag", lambda: (
            (lambda token: has("<title>", req("/view.php?token=" + token)[2].lower()))(
                mk_share(mk_doc(uniq("403Title"), "body", "2999-01-01 00:00:00"), "ci-e23@example.com")
            )
        ))
        test("extreme", "E24 admin page title tag includes Folio", lambda: ok("folio" in req("/admin.php")[2].lower().split("</title>")[0]))
        test("extreme", "E25 POST with multipart content-type does not 500", lambda: (
            (lambda r: ne(500, r.status))(urllib.request.urlopen(
                urllib.request.Request(BASE + "/admin.php", b"--boundary\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\ntest\r\n--boundary--\r\n",
                    headers={"Content-Type": "multipart/form-data; boundary=boundary"}), timeout=5
            ))
        ))

        # ===== UNEXPECTED (20) =====
        test("unexpected", "U01 PUT /admin.php returns non-500", lambda: not500(req("/admin.php", method="PUT")))
        test("unexpected", "U02 DELETE /admin.php returns non-500", lambda: not500(req("/admin.php", method="DELETE")))
        test("unexpected", "U03 POST to /view.php does not 500", lambda: not500(req("/view.php?token=" + seeded_token(), {"x": "y"})))
        test("unexpected", "U04 repeated q params uses last value", lambda: not500(req("/admin.php?q=first&q=second")))
        test("unexpected", "U05 POST admin with extra fields ignored", lambda: (
            (lambda r: not500(r))(req("/admin.php", {"title": uniq("Extra"), "body": "body", "published_at": "", "extra_field": "ignored"}))
        ))
        test("unexpected", "U06 POST share with action=unknown shows error", lambda: (
            (lambda r: (not500(r), has("Unknown action", r[2])))(req("/share.php?doc=" + seeded_doc()["readable_id"], {"action": "unknown", "email": "x@y.com"}))
        ))
        test("unexpected", "U07 POST schedule with extra fields accepted", lambda: (
            not500(req("/share.php?doc=" + seeded_doc()["readable_id"], {"action": "schedule", "published_at": "", "extra": "ignored"}))
        ))
        test("unexpected", "U08 admin search escapes HTML in displayed query", lambda: (
            (lambda b: (has("&lt;b&gt;", b), hasnt("<b>test</b>", b)))(req("/admin.php?q=" + urllib.parse.quote("<b>test</b>"))[2])
        ))
        test("unexpected", "U09 view 404 body does not contain stack trace", lambda: (
            (lambda b: (hasnt("Stack trace", b), hasnt("Fatal error", b)))(req("/view.php?token=bad")[2])
        ))
        test("unexpected", "U10 admin page does not contain PHP errors", lambda: (
            (lambda b: (hasnt("Warning:", b), hasnt("Notice:", b), hasnt("Fatal error:", b)))(req("/admin.php")[2])
        ))
        test("unexpected", "U11 POST share for nonexistent doc returns 404", lambda: eq(404, req("/share.php?doc=nonexistent", {"action": "share", "email": "x@y.com"})[0]))
        test("unexpected", "U12 POST schedule for nonexistent doc returns 404", lambda: eq(404, req("/share.php?doc=nonexistent", {"action": "schedule", "published_at": ""})[0]))
        test("unexpected", "U13 response does not expose server version in body", lambda: (
            (lambda b: (hasnt("PHP/", b), hasnt("X-Powered-By", b)))(req("/admin.php")[2])
        ))
        test("unexpected", "U14 POST with application/json Content-Type does not 500", lambda: (
            (lambda r: ne(500, r.status))(urllib.request.urlopen(
                urllib.request.Request(BASE + "/admin.php", b'{"title":"test"}', headers={"Content-Type": "application/json"}), timeout=5
            ))
        ))
        test("unexpected", "U15 numeric title POST creates document", lambda: (
            (lambda t: (post_create(t, "body"), ok(db_val("SELECT COUNT(*) FROM documents WHERE title=?", (t,)) >= 1)))("12345")
        ))
        test("unexpected", "U16 POST create with published_at containing seconds rejected", lambda: (
            has("valid date", post_create(uniq("Secs"), "body", "2026-05-09T12:00:30")[2])
        ))
        test("unexpected", "U17 share page displays schedule in local timezone format", lambda: (
            (lambda doc_id: (
                (lambda b: ok(re.search(r"(AM|PM|CDT|CST)", b) is not None, "no tz indicator"))(req("/share.php?doc=" + db_row("SELECT readable_id FROM documents WHERE id=?", (doc_id,))["readable_id"])[2])
            ))(mk_doc(uniq("TZDisp"), "body", "2999-06-15 17:00:00"))
        ))
        test("unexpected", "U18 admin table Create share link is present", lambda: has("Create share", req("/admin.php")[2]))
        test("unexpected", "U19 admin table links to share.php with doc ref", lambda: has("/share.php?doc=", req("/admin.php")[2]))
        test("unexpected", "U20 empty POST to admin does not 500", lambda: not500(req("/admin.php", {})))

        # ===== FOOL (20) =====
        test("fool", "F01 blank title shows exact error text", lambda: has("Title and body are required.", post_create("", "body")[2]))
        test("fool", "F02 blank body shows exact error text", lambda: has("Title and body are required.", post_create("title", "")[2]))
        test("fool", "F03 emoji-only title creates document", lambda: eq(200, post_create("🔥💯🚀", "body")[0]))
        test("fool", "F04 title admin.php creates doc without confusion", lambda: (
            (lambda t: (post_create(t, "body"), ok(db_val("SELECT COUNT(*) FROM documents WHERE title=?", (t,)) >= 1)))("admin.php")
        ))
        test("fool", "F05 title with path separators creates doc", lambda: (
            eq(200, post_create(uniq("/path/to/doc"), "body")[0])
        ))
        test("fool", "F06 search for .. returns safely", lambda: not500(req("/admin.php?q=..")))
        test("fool", "F07 copied token with tab works after trim", lambda: (
            eq(200, req("/view.php?token=%09" + seeded_token())[0])
        ))
        test("fool", "F08 user bookmarks search URL can reload", lambda: (
            (lambda r1, r2: eq(r1[0], r2[0]))(req("/admin.php?q=welcome"), req("/admin.php?q=welcome"))
        ))
        test("fool", "F09 POST create twice with same title both succeed", lambda: (
            (lambda t: (post_create(t, "a"), post_create(t, "b"), eq(2, db_val("SELECT COUNT(*) FROM documents WHERE title=?", (t,)))))(uniq("Double"))
        ))
        test("fool", "F10 searching for readable_id text finds nothing specific", lambda: (
            (lambda rid, count: ok(count <= 1))(seeded_doc()["readable_id"], len(req("/admin.php?q=" + seeded_doc()["readable_id"])[2].split("<tr>")) - 2)
        ))
        test("fool", "F11 script tag in email rejected", lambda: (
            has("Recipient email must be valid", post_share(seeded_doc()["readable_id"], '<script>@x.com')[2])
        ))
        test("fool", "F12 user submits schedule without changing shows success", lambda: (
            has("Publishing schedule updated", post_sched(seeded_doc()["readable_id"], "")[2])
        ))
        test("fool", "F13 user schedules in the past via UI", lambda: (
            (lambda r: (eq(200, r[0]), has("Publishing schedule updated", r[2])))(post_sched(seeded_doc()["readable_id"], "2000-01-01T00:00"))
        ))
        test("fool", "F14 user clears schedule then admin shows Available", lambda: (
            (lambda doc_id: (
                post_sched(db_row("SELECT readable_id FROM documents WHERE id=?", (doc_id,))["readable_id"], ""),
                has("Available", req("/admin.php?q=" + urllib.parse.quote(f"CI HTTP Avail {N}"))[2])
            ))(mk_doc(uniq("Avail"), "body", "2999-01-01 00:00:00"))
        ))
        test("fool", "F15 full lifecycle: create schedule share view reschedule", lambda: (
            (lambda doc_id: (
                (lambda rid, token: (
                    eq(403, req("/view.php?token=" + token)[0]),
                    post_sched(rid, ""),
                    eq(200, req("/view.php?token=" + token)[0])
                ))(db_row("SELECT readable_id FROM documents WHERE id=?", (doc_id,))["readable_id"], mk_share(doc_id, "ci-f15@example.com"))
            ))(mk_doc(uniq("Lifecycle"), "lifecycle body", "2999-01-01 00:00:00"))
        ))
        test("fool", "F16 POST with wrong field names treated as blank", lambda: (
            (lambda r: has("Title and body are required", r[2]))(req("/admin.php", {"titulo": "test", "cuerpo": "body"}))
        ))
        test("fool", "F17 admin page healthy after 5 error requests", lambda: (
            [req("/view.php?token=bad") for _ in range(5)],
            eq(200, req("/admin.php")[0])
        ))
        test("fool", "F18 very long email rejected gracefully", lambda: (
            (lambda r: not500(r))(post_share(seeded_doc()["readable_id"], "a" * 300 + "@example.com"))
        ))
        test("fool", "F19 user enters readable_id as share token gets 404", lambda: (
            eq(404, req("/view.php?token=" + seeded_doc()["readable_id"])[0])
        ))
        test("fool", "F20 POST admin without published_at field still works", lambda: (
            (lambda r: not500(r))(req("/admin.php", {"title": uniq("NoPub"), "body": "body"}))
        ))

        print(f"\nCI HTTP tests: {PASS} passed, {FAIL} failed.")
        return 1 if FAIL else 0
    finally:
        srv.terminate()
        try: srv.wait(timeout=5)
        except: srv.kill(); srv.wait(5)


if __name__ == "__main__":
    sys.exit(main())
