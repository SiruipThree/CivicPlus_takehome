#!/usr/bin/env python3
"""Claude HTTP black-box tests (100) — self-contained with own PHP server."""

from __future__ import annotations
import html
import json
import os
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
    c = sqlite3.connect(DB)
    c.row_factory = sqlite3.Row
    return c


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
    global N
    N += 1
    return f"Claude HTTP {label} {N}"


def php_eval(code):
    r = subprocess.run(["php", "-r", code], cwd=ROOT, check=True, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return r.stdout.strip()


import base64
def b64(v): return base64.b64encode(v.encode()).decode()
def php_str(v): return f'base64_decode("{b64(v)}")'


def mk_doc(title, body="body", pub=None):
    p = "null" if pub is None else php_str(pub)
    code = f'require "lib/bootstrap.php"; $id = create_document({php_str(title)}, {php_str(body)}, 1, {p}); echo $id;'
    return int(php_eval(code))


def mk_share(doc_id, email="test@example.com"):
    return php_eval(f'require "lib/bootstrap.php"; echo create_share({doc_id}, {php_str(email)});')


def req(path, data=None, method=None):
    enc = None
    hdrs = {}
    if data is not None:
        if isinstance(data, list):
            enc = urllib.parse.urlencode(data, doseq=True).encode()
        else:
            enc = urllib.parse.urlencode(data).encode()
        hdrs["Content-Type"] = "application/x-www-form-urlencoded"
    r = urllib.request.Request(BASE + path, data=enc, headers=hdrs, method=method)
    try:
        with urllib.request.urlopen(r, timeout=10) as resp:
            return resp.status, resp.geturl(), resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        return e.code, e.geturl(), e.read().decode("utf-8", errors="replace")


def post_create(title, body, pub=""):
    return req("/admin.php", {"title": title, "body": body, "published_at": pub})


def post_share(rid, email, action="share"):
    return req("/share.php?doc=" + urllib.parse.quote(rid), {"action": action, "email": email})


def post_sched(rid, pub):
    return req("/share.php?doc=" + urllib.parse.quote(rid), {"action": "schedule", "published_at": pub})


def seeded_doc():
    r = db_row("SELECT * FROM documents ORDER BY id LIMIT 1")
    assert r, "no seed doc"
    return r


def seeded_token():
    t = db_val("SELECT token FROM shares ORDER BY id LIMIT 1")
    assert t, "no seed token"
    return str(t)


def test(cat, name, fn):
    global PASS, FAIL
    try:
        fn()
        print(f"  [ok] [{cat}] {name}")
        PASS += 1
    except Exception as e:
        print(f"  [FAIL] [{cat}] {name}: {e}")
        FAIL += 1


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

        print("\nRunning Claude HTTP tests (100):")

        # ============ REGULAR (40) ============
        test("regular", "01 GET / redirects to admin", lambda: (eq(200, req("/")[0]), ok(req("/")[1].endswith("/admin.php"))))
        test("regular", "02 admin page has doctype", lambda: has("<!doctype html", req("/admin.php")[2].lower()))
        test("regular", "03 admin has title tag", lambda: has("<title>", req("/admin.php")[2].lower()))
        test("regular", "04 admin has stylesheet link", lambda: has("style.css", req("/admin.php")[2]))
        test("regular", "05 admin form has title input", lambda: has('name="title"', req("/admin.php")[2]))
        test("regular", "06 admin form has body textarea", lambda: has("</textarea>", req("/admin.php")[2].lower()))
        test("regular", "07 admin form has published_at input", lambda: has('name="published_at"', req("/admin.php")[2]))
        test("regular", "08 admin form has submit button", lambda: has("Create document", req("/admin.php")[2]))
        test("regular", "09 admin has search form", lambda: has('name="q"', req("/admin.php")[2]))
        test("regular", "10 admin shows seeded doc", lambda: has("Welcome Packet", req("/admin.php")[2]))
        test("regular", "11 admin shows readable ID", lambda: has(seeded_doc()["readable_id"], req("/admin.php")[2]))
        test("regular", "12 admin shows availability column", lambda: has("<th>Availability</th>", req("/admin.php")[2]))
        test("regular", "13 admin search finds by lowercase", lambda: has("Welcome Packet", req("/admin.php?q=welcome")[2]))
        test("regular", "14 admin search finds by uppercase", lambda: has("Welcome Packet", req("/admin.php?q=WELCOME")[2]))
        test("regular", "15 admin search no match shows empty", lambda: has("No documents matched", req("/admin.php?q=zzz-no-match")[2]))

        test("regular", "16 share page opens by rid", lambda: (eq(200, req("/share.php?doc=" + seeded_doc()["readable_id"])[0]), has('Share "Welcome Packet"', req("/share.php?doc=" + seeded_doc()["readable_id"])[2])))
        test("regular", "17 share page has schedule form", lambda: has("Update schedule", req("/share.php?doc=" + seeded_doc()["readable_id"])[2]))
        test("regular", "18 share page has email form", lambda: has('name="email"', req("/share.php?doc=" + seeded_doc()["readable_id"])[2]))
        test("regular", "19 share page 404 for missing doc", lambda: eq(404, req("/share.php?doc=no-such-doc")[0]))
        test("regular", "20 share page numeric fallback", lambda: eq(200, req("/share.php?doc=1")[0]))

        test("regular", "21 view shows body for valid token", lambda: (eq(200, req("/view.php?token=" + seeded_token())[0]), has("Welcome to Folio!", req("/view.php?token=" + seeded_token())[2])))
        test("regular", "22 view shows recipient email", lambda: has("recipient@example.com", req("/view.php?token=" + seeded_token())[2]))
        test("regular", "23 view 404 for unknown token", lambda: eq(404, req("/view.php?token=badtoken")[0]))
        test("regular", "24 view 404 for empty token", lambda: eq(404, req("/view.php?token=")[0]))
        test("regular", "25 view 404 for rid as token", lambda: eq(404, req("/view.php?token=" + seeded_doc()["readable_id"])[0]))

        test("regular", "26 POST create succeeds", lambda: (eq(200, post_create(uniq("Create"), "body")[0]), has("created", post_create(uniq("Create OK"), "body")[2])))
        test("regular", "27 POST create stores document", lambda: (post_create(uniq("Stored"), "stored body"), ok(db_val("SELECT COUNT(*) FROM documents WHERE title LIKE 'Claude HTTP Stored%'") >= 1)))
        test("regular", "28 POST share valid email", lambda: (eq(200, post_share(seeded_doc()["readable_id"], "claude-h28@example.com")[0]), has("Share link ready", post_share(seeded_doc()["readable_id"], "claude-h28b@example.com")[2])))
        test("regular", "29 POST share invalid email rejected", lambda: has("Recipient email must be valid", post_share(seeded_doc()["readable_id"], "bad-email")[2]))
        test("regular", "30 POST share empty email rejected", lambda: has("Recipient email is required", post_share(seeded_doc()["readable_id"], "")[2]))

        test("regular", "31 POST schedule set", lambda: (eq(200, post_sched(seeded_doc()["readable_id"], "2999-01-01T12:00")[0]), has("Publishing schedule updated", post_sched(seeded_doc()["readable_id"], "2999-02-01T12:00")[2])))
        test("regular", "32 POST schedule clear", lambda: has("Publishing schedule updated", post_sched(seeded_doc()["readable_id"], "")[2]))
        test("regular", "33 POST schedule invalid rejected", lambda: has("Publish date must be a valid date", post_sched(seeded_doc()["readable_id"], "not-a-date")[2]))

        test("regular", "34 future doc token returns 403", lambda: (
            eq(403, req("/view.php?token=" + mk_share(mk_doc(uniq("Future"), "secret", "2999-01-01 00:00:00"), "f34@example.com"))[0])
        ))
        test("regular", "35 future doc body not leaked", lambda: (
            hasnt("secret future body", req("/view.php?token=" + mk_share(mk_doc(uniq("Leak"), "secret future body", "2999-01-01 00:00:00"), "f35@example.com"))[2])
        ))
        test("regular", "36 past doc token visible", lambda: (
            eq(200, req("/view.php?token=" + mk_share(mk_doc(uniq("Past"), "past body", "2000-01-01 00:00:00"), "f36@example.com"))[0])
        ))
        test("regular", "37 admin share link uses readable_id", lambda: has("/share.php?doc=" + urllib.parse.quote(seeded_doc()["readable_id"]), req("/admin.php")[2]))
        test("regular", "38 index.php exists and redirects", lambda: eq(200, req("/index.php")[0]))
        test("regular", "39 view page has title tag", lambda: has("<title>", req("/view.php?token=" + seeded_token())[2].lower()))
        test("regular", "40 admin create audited", lambda: (
            post_create(uniq("Audit Check"), "body"),
            ok(db_val("SELECT COUNT(*) FROM audit_log WHERE action='create' AND entity_type='document'") >= 1)
        ))

        # ============ EXTREME (25) ============
        test("extreme", "41 very long title via POST", lambda: not500(post_create("T" * 2000, "body")))
        test("extreme", "42 very long body via POST", lambda: not500(post_create(uniq("Long Body"), "B" * 50000)))
        test("extreme", "43 very long search query", lambda: not500(req("/admin.php?q=" + urllib.parse.quote("x" * 5000))))
        test("extreme", "44 emoji title via POST", lambda: (eq(200, post_create("🔥 " + uniq("Emoji"), "emoji body")[0])))
        test("extreme", "45 Chinese title via POST", lambda: (eq(200, post_create(uniq("中文标题"), "中文内容")[0])))
        test("extreme", "46 Chinese title searchable", lambda: (
            mk_doc(uniq("搜索目标")),
            has("搜索目标", req("/admin.php?q=" + urllib.parse.quote("搜索目标"))[2])
        ))
        test("extreme", "47 special chars in search", lambda: not500(req("/admin.php?q=" + urllib.parse.quote("!@#$%^&*()"))))
        test("extreme", "48 SQL injection in search", lambda: (
            has("No documents matched", req("/admin.php?q=" + urllib.parse.quote("' OR 1=1 --"))[2])
        ))
        test("extreme", "49 script tag in title escaped", lambda: (
            mk_doc("<script>alert(1)</script>"),
            hasnt("<script>alert(1)</script>", req("/admin.php?q=script")[2])
        ))
        test("extreme", "50 script tag in body escaped on view", lambda: (
            (lambda t: (eq(200, req("/view.php?token=" + t)[0]), hasnt("<script>xss</script>", req("/view.php?token=" + t)[2])))(
                mk_share(mk_doc(uniq("XSS View"), "<script>xss</script>"), "xss@example.com")
            )
        ))
        test("extreme", "51 uppercase readable_id in share URL", lambda: eq(200, req("/share.php?doc=" + seeded_doc()["readable_id"].upper())[0]))
        test("extreme", "52 token with surrounding spaces", lambda: (eq(200, req("/view.php?token=%20" + seeded_token() + "%20")[0])))
        test("extreme", "53 uppercase token rejected", lambda: eq(404, req("/view.php?token=" + seeded_token().upper())[0]))
        test("extreme", "54 duplicate title POST both succeed", lambda: (
            (lambda t: (post_create(t, "a"), post_create(t, "b"), eq(2, db_val("SELECT COUNT(*) FROM documents WHERE title=?", (t,)))))(uniq("DupTitle"))
        ))
        test("extreme", "55 plus alias email accepted", lambda: has("Share link ready", post_share(seeded_doc()["readable_id"], "user+tag@example.com")[2]))
        test("extreme", "56 subdomain email accepted", lambda: has("Share link ready", post_share(seeded_doc()["readable_id"], "a@mail.sub.example.com")[2]))
        test("extreme", "57 invalid calendar date rejected", lambda: has("valid date", post_create(uniq("BadCal"), "body", "2026-02-31T12:00")[2]))
        test("extreme", "58 timezone suffix rejected", lambda: has("valid date", post_create(uniq("TZ"), "body", "2026-05-09T12:00Z")[2]))
        test("extreme", "59 ampersand in body escaped", lambda: (
            (lambda t: has("&amp;", req("/view.php?token=" + t)[2]))(mk_share(mk_doc(uniq("Amp"), "A & B"), "amp@example.com"))
        ))
        test("extreme", "60 quote in title escaped in admin", lambda: (
            mk_doc('Claude "Quoted"'),
            has("&quot;Quoted&quot;", req("/admin.php?q=Quoted")[2])
        ))
        test("extreme", "61 readable_id stays compact", lambda: ok(len(str(db_row("SELECT readable_id FROM documents WHERE id=?", (mk_doc("A " * 100),))["readable_id"])) <= 37))
        test("extreme", "62 POST to view does not 500", lambda: not500(req("/view.php?token=" + seeded_token(), {"x": "y"})))
        test("extreme", "63 schedule display shows publish info", lambda: (
            (lambda rid: has("Publish", req("/share.php?doc=" + rid)[2]))(
                db_row("SELECT readable_id FROM documents WHERE id=?", (mk_doc(uniq("Display"), "body", "2999-01-01 00:00:00"),))["readable_id"]
            )
        ))
        test("extreme", "64 immediate doc shows Available", lambda: (
            (lambda t: (mk_doc(t), has("Available", req("/admin.php?q=" + urllib.parse.quote(t))[2])))(uniq("Avail"))
        ))
        test("extreme", "65 future doc shows Scheduled", lambda: (
            (lambda t: (mk_doc(t, "body", "2999-01-01 00:00:00"), has("Scheduled", req("/admin.php?q=" + urllib.parse.quote(t))[2])))(uniq("Sched"))
        ))

        # ============ UNEXPECTED (20) ============
        test("unexpected", "66 unknown action rejected", lambda: (
            (lambda before, r: (not500(r), eq(before, db_val("SELECT COUNT(*) FROM shares"))))(
                db_val("SELECT COUNT(*) FROM shares"),
                post_share(seeded_doc()["readable_id"], "unk@example.com", action="delete")
            )
        ))
        test("unexpected", "67 q[] array does not 500", lambda: not500(req("/admin.php?q[]=x")))
        test("unexpected", "68 doc[] array does not 500", lambda: not500(req("/share.php?doc[]=1")))
        test("unexpected", "69 token[] array does not 500", lambda: not500(req("/view.php?token[]=x")))
        test("unexpected", "70 title[] array does not 500", lambda: not500(req("/admin.php", [("title[]", "x"), ("body", "b"), ("published_at", "")])))
        test("unexpected", "71 body[] array does not 500", lambda: not500(req("/admin.php", [("title", "t"), ("body[]", "b"), ("published_at", "")])))
        test("unexpected", "72 email[] array does not 500", lambda: not500(req("/share.php?doc=" + seeded_doc()["readable_id"], [("action", "share"), ("email[]", "x")])))
        test("unexpected", "73 published_at[] array does not 500", lambda: not500(req("/share.php?doc=" + seeded_doc()["readable_id"], [("action", "schedule"), ("published_at[]", "x")])))
        test("unexpected", "74 action[] array does not 500", lambda: not500(req("/share.php?doc=" + seeded_doc()["readable_id"], [("action[]", "share"), ("email", "a@b.com")])))
        test("unexpected", "75 empty doc param returns 404", lambda: eq(404, req("/share.php?doc=")[0]))
        test("unexpected", "76 space-only doc param returns 404", lambda: eq(404, req("/share.php?doc=%20%20")[0]))
        test("unexpected", "77 negative doc id returns 404", lambda: eq(404, req("/share.php?doc=-1")[0]))
        test("unexpected", "78 decimal doc id returns 404", lambda: eq(404, req("/share.php?doc=1.5")[0]))
        test("unexpected", "79 share page does not leak db path", lambda: hasnt(DB, post_share(seeded_doc()["readable_id"], "path@example.com")[2]))
        test("unexpected", "80 invalid schedule preserves previous", lambda: (
            (lambda doc: (post_sched(doc["readable_id"], "bad"), eq(str(doc["published_at"]), str(db_val("SELECT published_at FROM documents WHERE id=?", (doc["id"],))))))(
                db_row("SELECT * FROM documents WHERE id=?", (mk_doc(uniq("Preserve"), "body", "2999-01-01 00:00:00"),))
            )
        ))
        test("unexpected", "81 invalid email preserves share count", lambda: (
            (lambda before: (post_share(seeded_doc()["readable_id"], "bad"), eq(before, db_val("SELECT COUNT(*) FROM shares"))))(
                db_val("SELECT COUNT(*) FROM shares")
            )
        ))
        test("unexpected", "82 view page missing token param", lambda: eq(404, req("/view.php")[0]))
        test("unexpected", "83 share page missing doc param", lambda: eq(404, req("/share.php")[0]))
        test("unexpected", "84 non-existent PHP file returns 404", lambda: ne(500, req("/nonexistent.php")[0]))
        test("unexpected", "85 HEAD request does not 500", lambda: not500(req("/admin.php", method="HEAD")))

        # ============ FOOL (15) ============
        test("fool", "86 blank title rejected", lambda: has("Title and body are required", post_create("", "body")[2]))
        test("fool", "87 blank body rejected", lambda: has("Title and body are required", post_create("title", "")[2]))
        test("fool", "88 spaces-only title rejected", lambda: has("Title and body are required", post_create("   ", "body")[2]))
        test("fool", "89 spaces-only body rejected", lambda: has("Title and body are required", post_create("title", "   ")[2]))
        test("fool", "90 copied rid with spaces works", lambda: eq(200, req("/share.php?doc=%20" + seeded_doc()["readable_id"] + "%20")[0]))
        test("fool", "91 copied token with newline works", lambda: eq(200, req("/view.php?token=" + seeded_token() + "%0A")[0]))
        test("fool", "92 user clears schedule with blank", lambda: (
            (lambda doc: (post_sched(doc["readable_id"], ""), eq(None, db_val("SELECT published_at FROM documents WHERE id=?", (doc["id"],)))))(
                db_row("SELECT * FROM documents WHERE id=?", (mk_doc(uniq("UserClear"), "body", "2999-01-01 00:00:00"),))
            )
        ))
        test("fool", "93 email with spaces trimmed", lambda: (
            (lambda before: (post_share(seeded_doc()["readable_id"], "  trim93@example.com  "), eq(before + 1, db_val("SELECT COUNT(*) FROM shares WHERE recipient_email='trim93@example.com'"))))(
                db_val("SELECT COUNT(*) FROM shares WHERE recipient_email='trim93@example.com'")
            )
        ))
        test("fool", "94 double submit creates two docs", lambda: (
            (lambda t: (post_create(t, "a"), post_create(t, "b"), eq(2, db_val("SELECT COUNT(*) FROM documents WHERE title=?", (t,)))))(uniq("Double"))
        ))
        test("fool", "95 zero doc ref does not 500", lambda: not500(req("/share.php?doc=0")))
        test("fool", "96 space in email rejected", lambda: has("Recipient email must be valid", post_share(seeded_doc()["readable_id"], "bad email@x.com")[2]))
        test("fool", "97 no results page has Clear link", lambda: has("Clear", req("/admin.php?q=zzzzz")[2]))
        test("fool", "98 server survives error batch", lambda: (
            req("/view.php?token=bad"),
            req("/share.php?doc=bad"),
            req("/admin.php?q[]=x"),
            eq(200, req("/admin.php")[0])
        ))
        test("fool", "99 schedule future then share then verify blocked", lambda: (
            (lambda doc_id: (
                (lambda token: eq(403, req("/view.php?token=" + token)[0]))(mk_share(doc_id, "f99@example.com"))
            ))(mk_doc(uniq("FutureShare"), "blocked body", "2999-01-01 00:00:00"))
        ))
        test("fool", "100 reschedule from future to past makes visible", lambda: (
            (lambda doc_id: (
                (lambda token: (
                    eq(403, req("/view.php?token=" + token)[0]),
                    post_sched(db_row("SELECT readable_id FROM documents WHERE id=?", (doc_id,))["readable_id"], "2000-01-01T00:00"),
                    eq(200, req("/view.php?token=" + token)[0])
                ))(mk_share(doc_id, "f100@example.com"))
            ))(mk_doc(uniq("Reschedule"), "resched body", "2999-01-01 00:00:00"))
        ))

        print(f"\nClaude HTTP tests: {PASS} passed, {FAIL} failed.")
        return 1 if FAIL else 0
    finally:
        srv.terminate()
        try: srv.wait(timeout=5)
        except: srv.kill(); srv.wait(5)


if __name__ == "__main__":
    sys.exit(main())
