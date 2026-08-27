# -*- coding: utf-8 -*-
"""
Rebuilds the "Volta_Analytics" Artifact (formerly "Volta Funnel Dashboard",
https://claude.ai/code/artifact/8c1cd133-55be-4b16-850e-32ea7154bd2e) end to
end: renders the live-DB PHP funnel/sales/portfolio app fresh, then merges in
the waybills/invoices/reconciliation dashboard as an "Accounting" nav-group,
and writes the result ready to publish.

Why this exists: Volta_Analytics used to be refreshed by a separate,
manually-triggered pipeline (a different Claude session's "Daily Mail" splice
script) that has no idea the Accounting group exists — if it ever runs again,
it will silently wipe Accounting out, since it rebuilds the whole artifact
from its own PHP template. This script makes the Accounting merge part of
THIS project's own daily automation instead, so Volta_Analytics self-heals
(with fresh data on both halves) every time this runs, regardless of what
that other pipeline does in between. See the "volta_analytics_merge" memory
for the full story and why this residual risk was never fully eliminated —
only mitigated to "wrong for at most a day."

Run this AFTER refresh_dashboard.py (it reads that script's fresh output:
template.html's structure + waybill_dashboard.html's embedded JSON).

Requires a local PHP 8.3 CLI with pdo_mysql at PHP_EXE below (portable,
extracted once — see README_PHP.txt in the same folder for how it was set
up) and the sibling "Volta_Daily Sales\\php-dashboard" PHP project checked
out at PHP_PROJECT_DIR.
"""
import json
import re
import socket
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
PHP_EXE = HERE / "php83" / "php.exe"
PHP_PROJECT_DIR = Path(r"C:\Users\Lenovo\Desktop\Volta_Daily Sales\php-dashboard")
WAYBILL_TEMPLATE = HERE / "template.html"
WAYBILL_BUILT = HERE / "waybill_dashboard.html"
MERGED_OUT = HERE / "volta_analytics_merged.html"
ACCESS_PERMISSIONS_PATH = HERE / "access_permissions.json"

PHP_HOST = "127.0.0.1"
PHP_PORT = 8899
SCOPE = "acct-scope"


def render_php_dashboard() -> str:
    """Start the local PHP dev server, fetch the fully-rendered dashboard
    (live DB queries, ~30-60s), return its raw HTML, then stop the server."""
    proc = subprocess.Popen(
        [str(PHP_EXE), "-S", f"{PHP_HOST}:{PHP_PORT}", "-t", "public"],
        cwd=str(PHP_PROJECT_DIR),
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    try:
        url = f"http://{PHP_HOST}:{PHP_PORT}/"
        # Readiness check: just confirm the port is accepting TCP connections.
        # Cannot use an HTTP GET here — index.php runs ~25 live DB queries on
        # every request (even "/"), taking 30-60s, so any HTTP-based
        # readiness probe would misreport "not up yet" for that whole window
        # (hit this exact bug on the first version of this script).
        deadline = time.time() + 15
        last_err = None
        while time.time() < deadline:
            try:
                with socket.create_connection((PHP_HOST, PHP_PORT), timeout=1):
                    break
            except OSError as e:
                last_err = e
                time.sleep(0.3)
        else:
            raise RuntimeError(f"PHP dev server port never opened: {last_err}")

        with urllib.request.urlopen(url, timeout=120) as resp:
            raw = resp.read().decode("utf-8")
        if "connectionError" in raw and "Connection failed" in raw:
            raise RuntimeError("PHP app reported a DB connection error")
        return raw
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()


def normalize_php_html(raw: str) -> str:
    """Turn a full <html><head>...<body>...</body></html> PHP page into the
    same 'title + style blocks + body-inner, ending in a literal
    </body></html> marker' shape this script's merge step expects."""
    title_m = re.search(r"<title>(.*?)</title>", raw, re.S)
    assert title_m, "no <title> in PHP render"
    styles = re.findall(r"<style>.*?</style>", raw, re.S)
    assert styles, "no <style> in PHP render"
    body_m = re.search(r"<body>(.*)</body>", raw, re.S)
    assert body_m, "no <body>...</body> in PHP render"
    return "<title>" + title_m.group(1) + "</title>\n" + "\n".join(styles) + "\n" + body_m.group(1) + "\n</body></html>"


def transform_selector(sel: str):
    sel = sel.strip()
    if not sel:
        return None
    if sel == ":root" or sel == "html":
        return "." + SCOPE
    if sel in ("html,body", "html, body", "body,html", "body, html"):
        return None
    if sel == "body":
        return "." + SCOPE
    if sel == "*":
        return "." + SCOPE + ", ." + SCOPE + " *"
    if sel == "::selection":
        return "." + SCOPE + "::selection"
    if sel == "a":
        return "." + SCOPE + " a"
    m = re.match(r'^:root(:not\(\[data-theme="light"\]\))$', sel)
    if m:
        return "html" + m.group(1) + " ." + SCOPE
    m = re.match(r'^:root(\[data-theme="[a-z]+"\])$', sel)
    if m:
        return "html" + m.group(1) + " ." + SCOPE
    return "." + SCOPE + " " + sel


def transform_selector_list(sel_list: str):
    parts = [transform_selector(s) for s in sel_list.split(",")]
    parts = [p for p in parts if p]
    return ", ".join(parts) if parts else None


def split_top_level_rules(css: str):
    i, n, out = 0, len(css), []
    while i < n:
        while i < n and css[i] in " \t\r\n":
            i += 1
        if i >= n:
            break
        if css[i:i+2] == "/*":
            end = css.find("*/", i+2)
            i = end + 2 if end != -1 else n
            continue
        brace = css.find("{", i)
        if brace == -1:
            break
        header = css[i:brace].strip()
        if header.startswith("@media"):
            depth, j = 1, brace + 1
            while j < n and depth > 0:
                if css[j] == "{":
                    depth += 1
                elif css[j] == "}":
                    depth -= 1
                j += 1
            out.append(("media", header, css[brace+1:j-1]))
            i = j
        else:
            end = css.find("}", brace)
            if end == -1:
                break
            out.append(("rule", header, css[brace+1:end]))
            i = end + 1
    return out


def transform_css(css: str) -> str:
    pieces = []
    for kind, header, body in split_top_level_rules(css):
        if kind == "media":
            pieces.append(header + " {\n" + transform_css(body) + "\n}")
        else:
            new_sel = transform_selector_list(header)
            if new_sel is not None:
                pieces.append(new_sel + " {" + body + "}")
    return "\n".join(pieces)


def extract_lines(path: Path, start: int, end: int) -> str:
    lines = path.read_text(encoding="utf-8").split("\n")
    return "\n".join(lines[start-1:end])


def extract_tab_labels():
    text = WAYBILL_TEMPLATE.read_text(encoding="utf-8")
    labels = {}
    for m in re.finditer(r'<button class="tabbtn[^"]*" data-tab="(\w+)">([^<]+)</button>', text):
        labels[m.group(1)] = m.group(2)
    return labels["wb"], labels["inv"], labels["recon"]


def build_accounting_snippet():
    """Returns (scoped_css, pages_html, wrapped_js) — the three ported
    pieces from the waybill dashboard, ready to splice into any funnel-app
    HTML with the same structure as dashboard.php / the Volta_Analytics
    artifact (single <style>, single <script>, #pageNav nav-groups, .page
    divs). NOTE: line ranges below are pinned to this project's
    template.html/waybill_dashboard.html layout as of 2026-08-27 — if either
    file's structure changes materially, re-locate these line numbers (find
    the <style>/</style> and tab-wb/tab-inv/tab-recon boundaries) rather than
    assuming they still hold."""
    raw_css = extract_lines(WAYBILL_TEMPLATE, 7, 362)
    scoped_css = transform_css(raw_css)

    wb_html = extract_lines(WAYBILL_TEMPLATE, 380, 448)
    inv_html = extract_lines(WAYBILL_TEMPLATE, 451, 516)
    recon_html = extract_lines(WAYBILL_TEMPLATE, 519, 574)

    def prep_page(html, old_id, new_id):
        html = html.replace(f'class="tabpanel active" id="{old_id}"', f'class="page {SCOPE}" id="page-{new_id}"')
        html = html.replace(f'class="tabpanel" id="{old_id}"', f'class="page {SCOPE}" id="page-{new_id}"')
        assert f"page {SCOPE}" in html, f"{old_id}: class rename failed"
        return html

    wb_html = prep_page(wb_html, "tab-wb", "acct-waybills")
    inv_html = prep_page(inv_html, "tab-inv", "acct-invoices")
    recon_html = prep_page(recon_html, "tab-recon", "acct-recon")
    pages_html = "\n\n" + wb_html + "\n\n" + inv_html + "\n\n" + recon_html + "\n"

    raw_js = extract_lines(WAYBILL_BUILT, 579, 1313)
    assert len(raw_js) > 200000, "waybill_dashboard.html's JS doesn't look data-filled — did refresh_dashboard.py run first?"
    wrapped_js = "(function(){\n" + raw_js + "\n})();"

    return scoped_css, pages_html, wrapped_js


def merge(funnel_content: str) -> str:
    scoped_css, pages_html, wrapped_js = build_accounting_snippet()
    wb_label, inv_label, recon_label = extract_tab_labels()
    content = funnel_content

    assert content.startswith("<title>Volta Funnel Dashboard</title>") or content.startswith("<title>Volta_Analytics</title>")
    content = re.sub(r"^<title>[^<]*</title>", "<title>Volta_Analytics</title>", content, count=1)

    if "fonts.googleapis.com/css2?family=Noto" not in content:
        font_links = (
            '\n<link rel="preconnect" href="https://fonts.googleapis.com">'
            '\n<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            '\n<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Georgian:wght@500;600;700&family=Noto+Sans+Georgian:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">'
        )
        content = content.replace("<title>Volta_Analytics</title>", "<title>Volta_Analytics</title>" + font_links, 1)

    style_close_idx = content.index("</style>")
    insertion_point = style_close_idx + len("</style>")
    content = content[:insertion_point] + "\n<style>\n" + scoped_css + "\n</style>\n" + content[insertion_point:]

    if 'data-group="accounting"' not in content:
        pagenav_close_marker = re.search(r'\n  </div>\n\s*\n\s*<div class="page[ "]', content)
        assert pagenav_close_marker, "could not find #pageNav's own closing </div>"
        nav_groups_end = pagenav_close_marker.start()
        accounting_group = (
            '\n    <div class="nav-group" data-group="accounting">'
            '\n      <div class="nav-group-title">Accounting</div>'
            '\n      <div class="nav-group-items">'
            f'\n        <button data-page="acct-waybills">{wb_label}</button>'
            f'\n        <button data-page="acct-invoices">{inv_label}</button>'
            f'\n        <button data-page="acct-recon">{recon_label}</button>'
            '\n      </div>'
            '\n    </div>'
            '\n  '
        )
        content = content[:nav_groups_end] + accounting_group + content[nav_groups_end:]

    if 'id="page-acct-waybills"' not in content:
        script_idx = content.index("<script>")
        content = content[:script_idx] + pages_html + "\n" + content[script_idx:]
    else:
        # a previous merge's page divs are already there (funnel_content came
        # from a live artifact re-read rather than a fresh PHP render) —
        # replace them in place instead of duplicating.
        for old_id in ("page-acct-waybills", "page-acct-invoices", "page-acct-recon"):
            pat = re.compile(r'\n\n<div class="page ' + SCOPE + r'" id="' + old_id + r'">.*?\n  </div>\n', re.S)
            content = pat.sub("\n", content, count=1)
        script_idx = content.index("<script>")
        content = content[:script_idx] + pages_html + "\n" + content[script_idx:]

    if "acct-waybills' || page === 'acct-invoices'" not in content:
        anchor = "  if (page === 'logistics') {\n    return `Manual snapshot"
        idx = content.index(anchor)
        branch = (
            "  if (page === 'acct-waybills' || page === 'acct-invoices' || page === 'acct-recon') {\n"
            "    return `Manual refresh &mdash; <b>see notes below</b>`;\n"
            "  }\n"
        )
        content = content[:idx] + branch + content[idx:]

    # replace the PREVIOUS ported <script> (if this is a re-merge of an
    # already-merged artifact) rather than appending a second copy
    scripts = list(re.finditer(r"<script>.*?</script>", content, re.S))
    assert len(scripts) >= 1
    last_script_end = scripts[-1].end()
    if len(scripts) >= 2 and "RAW_RECON" in scripts[-1].group(0):
        # last script IS the previous accounting bundle — replace it wholesale
        start = scripts[-1].start()
        content = content[:start] + "<script>\n" + wrapped_js + "\n</script>" + content[last_script_end:]
    else:
        content = content[:last_script_end] + "\n<script>\n" + wrapped_js + "\n</script>\n" + content[last_script_end:]

    return content


AUTH_GATE_MARKER = "vaAuthGate"


def build_auth_gate_overlay_html() -> str:
    return """
<div id="vaAuthGate" style="display:none;position:fixed;inset:0;z-index:99999;align-items:center;justify-content:center;background:#11151a;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;">
  <div style="background:#fff;border-radius:12px;padding:32px 28px;max-width:360px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.35);">
    <div style="font-size:18px;font-weight:700;margin-bottom:4px;color:#141413;">Volta_Analytics</div>
    <div style="font-size:13px;color:#6b6b6b;margin-bottom:18px;">გთხოვთ, შეიყვანოთ თქვენი სამსახურის ელ-ფოსტა</div>
    <input id="vaAuthEmail" type="email" placeholder="you@company.ge" autocomplete="off" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d7d7d2;border-radius:8px;font-size:14px;margin-bottom:10px;">
    <div id="vaAuthError" style="display:none;color:#b23a3a;font-size:12.5px;margin-bottom:10px;"></div>
    <button id="vaAuthSubmit" style="width:100%;padding:10px 12px;border:none;border-radius:8px;background:#141413;color:#fff;font-size:14px;font-weight:600;cursor:pointer;">შესვლა</button>
  </div>
</div>
<div id="vaAccessDenied" style="display:none;position:fixed;inset:0;z-index:99998;align-items:center;justify-content:center;background:#faf9f5;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;text-align:center;padding:20px;">
  <div>
    <div style="font-size:20px;font-weight:700;margin-bottom:8px;color:#141413;">წვდომა შეზღუდულია</div>
    <div id="vaDeniedMsg" style="font-size:14px;color:#6b6b6b;margin-bottom:18px;max-width:420px;"></div>
    <button id="vaSwitchUser" style="padding:8px 16px;border:1px solid #d7d7d2;border-radius:8px;background:#fff;cursor:pointer;font-size:13px;">სხვა მეილით შესვლა</button>
  </div>
</div>
<div id="vaUserBadge" style="display:none;position:fixed;bottom:10px;right:10px;z-index:9000;background:rgba(20,20,19,0.85);color:#fff;font-size:11px;padding:6px 10px;border-radius:20px;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;">
  <span id="vaUserBadgeName"></span> &middot; <a href="#" id="vaLogoutLink" style="color:#9fd8e0;">გამოსვლა</a>
</div>
""".strip("\n")


def build_auth_gate_script() -> str:
    permissions = json.loads(ACCESS_PERMISSIONS_PATH.read_text(encoding="utf-8"))
    perms_json = json.dumps(permissions, ensure_ascii=False)
    js = """
(function(){
  var VA_PERMISSIONS = __PERMISSIONS__;
  var STORAGE_KEY = "va_auth_email";

  function norm(s){ return (s||"").trim().toLowerCase(); }
  function findPerm(email){
    var e = norm(email);
    for (var i=0;i<VA_PERMISSIONS.length;i++){
      if (norm(VA_PERMISSIONS[i].email) === e) return VA_PERMISSIONS[i];
    }
    return null;
  }
  function setAppVisible(visible){
    var app = document.querySelector('.app');
    if (app) app.style.display = visible ? '' : 'none';
  }
  function showGate(){
    document.getElementById('vaAuthGate').style.display = 'flex';
    document.getElementById('vaAccessDenied').style.display = 'none';
    document.getElementById('vaUserBadge').style.display = 'none';
    setAppVisible(false);
  }
  function showDenied(email){
    document.getElementById('vaAuthGate').style.display = 'none';
    document.getElementById('vaAccessDenied').style.display = 'flex';
    document.getElementById('vaUserBadge').style.display = 'none';
    document.getElementById('vaDeniedMsg').textContent =
      '"' + email + '" ელ-ფოსტას არ აქვს დაშვება Volta_Analytics-ზე. დაუკავშირდით ადმინისტრატორს, ან სცადეთ სხვა მეილით.';
    setAppVisible(false);
  }
  function applyFilter(perm){
    var tabs = (perm.tabs || []).map(function(t){ return t.trim(); });
    if (tabs.indexOf('ALL') !== -1) return; // full access — no nav filtering at all

    var subtabs = (perm.subtabs || []).map(function(t){ return t.trim(); }).filter(Boolean);
    var groups = document.querySelectorAll('.nav-group');
    var firstVisibleBtn = null;

    groups.forEach(function(group){
      var titleEl = group.querySelector('.nav-group-title');
      var title = titleEl ? titleEl.textContent.trim() : '';
      if (tabs.indexOf(title) === -1) { group.style.display = 'none'; return; }
      group.style.display = '';

      var btns = group.querySelectorAll('.nav-group-items button');
      // A person's subtab list may only be meant for ONE of their several
      // allowed tab-groups — the restriction only "activates" for a group if
      // at least one of ITS OWN buttons is named in that list. A group with
      // no matching button names stays fully open.
      var groupHasNamedSubtab = false;
      btns.forEach(function(btn){
        if (subtabs.indexOf(btn.textContent.trim()) !== -1) groupHasNamedSubtab = true;
      });
      btns.forEach(function(btn){
        var label = btn.textContent.trim();
        var allowed = !groupHasNamedSubtab || subtabs.indexOf(label) !== -1;
        btn.style.display = allowed ? '' : 'none';
        if (allowed && !firstVisibleBtn) firstVisibleBtn = btn;
      });
    });

    // If whatever page loaded as "active" by default is now hidden from this
    // person, jump to the first page they're actually allowed to see.
    var activeBtn = document.querySelector('#pageNav button.active');
    var activeBtnVisible = activeBtn && activeBtn.offsetParent !== null;
    if (!activeBtnVisible && firstVisibleBtn) firstVisibleBtn.click();
  }
  function showApp(perm){
    document.getElementById('vaAuthGate').style.display = 'none';
    document.getElementById('vaAccessDenied').style.display = 'none';
    setAppVisible(true);
    var badge = document.getElementById('vaUserBadge');
    badge.style.display = 'block';
    document.getElementById('vaUserBadgeName').textContent = perm.name || perm.email;
    applyFilter(perm);
  }
  function tryLogin(email){
    var perm = findPerm(email);
    if (!perm) { showDenied(email); return; }
    try { localStorage.setItem(STORAGE_KEY, norm(email)); } catch(e){}
    showApp(perm);
  }

  document.getElementById('vaAuthSubmit').addEventListener('click', function(){
    var val = document.getElementById('vaAuthEmail').value;
    if (!val || val.indexOf('@') === -1){
      var err = document.getElementById('vaAuthError');
      err.textContent = 'შეიყვანეთ სწორი ელ-ფოსტა';
      err.style.display = 'block';
      return;
    }
    document.getElementById('vaAuthError').style.display = 'none';
    tryLogin(val);
  });
  document.getElementById('vaAuthEmail').addEventListener('keydown', function(e){
    if (e.key === 'Enter') document.getElementById('vaAuthSubmit').click();
  });
  document.getElementById('vaSwitchUser').addEventListener('click', function(){
    try { localStorage.removeItem(STORAGE_KEY); } catch(e){}
    document.getElementById('vaAuthEmail').value = '';
    showGate();
  });
  document.getElementById('vaLogoutLink').addEventListener('click', function(e){
    e.preventDefault();
    try { localStorage.removeItem(STORAGE_KEY); } catch(e){}
    location.reload();
  });

  setAppVisible(false);
  var saved = null;
  try { saved = localStorage.getItem(STORAGE_KEY); } catch(e){}
  if (saved){
    var perm = findPerm(saved);
    if (perm) { showApp(perm); } else { try { localStorage.removeItem(STORAGE_KEY); } catch(e){} showGate(); }
  } else {
    showGate();
  }
})();
""".strip("\n")
    return js.replace("__PERMISSIONS__", perms_json)


def inject_auth_gate(content: str) -> str:
    """Client-side-only navigation gate: prompts for an email, checks it
    against access_permissions.json, and hides nav-groups/sub-tab buttons
    the person isn't listed for. This is NOT real security — the full data
    for every tab is still embedded in the page every viewer downloads,
    it's just visually hidden. Explicitly agreed with the user (2026-08-27)
    as an interim "simple navigation filter" ahead of eventually building
    real server-side authorization in the PHP app. Update
    access_permissions.json (synced from the user's Google Sheet) and rerun
    this script to change who sees what — no other code changes needed."""
    if AUTH_GATE_MARKER in content:
        # idempotent re-merge: strip a previous injection before reapplying
        content = re.sub(r'\n<div id="vaAuthGate".*?<div id="vaUserBadge".*?</div>\n', "\n", content, flags=re.S)
        content = re.sub(r"\n<script>\n\(function\(\)\{\n  var VA_PERMISSIONS.*?\n</script>\n", "\n", content, flags=re.S)

    overlay = build_auth_gate_overlay_html()
    title_close = content.index("</title>") + len("</title>")
    content = content[:title_close] + "\n" + overlay + content[title_close:]

    script = build_auth_gate_script()
    return content + "\n<script>\n" + script + "\n</script>\n"


def main():
    print("starting local PHP dev server and rendering Volta_Analytics live...", file=sys.stderr)
    raw = render_php_dashboard()
    print(f"got {len(raw)} bytes from PHP", file=sys.stderr)

    funnel_content = normalize_php_html(raw)
    merged = merge(funnel_content)
    merged = inject_auth_gate(merged)

    MERGED_OUT.write_text(merged, encoding="utf-8")
    print(f"wrote {MERGED_OUT} ({len(merged.encode('utf-8'))} bytes)", file=sys.stderr)


if __name__ == "__main__":
    main()
