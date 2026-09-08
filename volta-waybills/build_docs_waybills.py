# -*- coding: utf-8 -*-
"""
Builds the public GitHub Pages copies of the waybill dashboards and pushes
them to `main` of Volta-ge/reporting:

  waybill_dashboard.html     -> docs/waybills.html      (RS_Old DB, myvolta.info CRM)
  waybill_dashboard_gia.html -> docs/waybills-new.html  (RS_New DB, VoltaStoreDB CRM)
  volta-analytics-new-db/deals_amount_migration.html (committed on
  perf/stream-dashboard)     -> docs/analytics-new.html (Volta_Analytics_New DB)

Each source is an Artifact-style body fragment (no <!DOCTYPE>/<html>/<head>/
<body>); it is wrapped into a standalone document here. Only pages whose
content actually changed are committed, so a quiet day produces no commit.

Runs in its own detached-HEAD worktree (PAGES_WORKTREE), re-pinned to
origin/main on every run, and pushes with `git push origin HEAD:main` — so it
no longer depends on which branch the shared Desktop\\reporting clone happens
to have checked out. That clone flips between `main` and
`perf/stream-dashboard` as other sessions work in it, and on 2026-09-08 the
daily docs commit (7b310a8) landed on perf/stream-dashboard, leaving GitHub
Pages stale, for exactly that reason. Detached HEAD (not a checkout of `main`)
is deliberate: a worktree holding `main` would make `git checkout main` fail
in the shared clone for whoever uses it next.

Deliberately NOT done for docs/index.html (Volta_Analytics) — the user asked
that one stay manual, only updated on explicit request.
"""
import subprocess
import sys
from pathlib import Path

LIVE = Path(r"C:\Users\Lenovo\Desktop\Volta_Waybills")
REPO = Path(r"C:\Users\Lenovo\Desktop\reporting")
PAGES_WORKTREE = Path(r"C:\Users\Lenovo\Desktop\reporting-pages")
PAGES = [
    (LIVE / "waybill_dashboard.html", "docs/waybills.html"),
    (LIVE / "waybill_dashboard_gia.html", "docs/waybills-new.html"),
]
# Volta_Analytics_New DB (Artifact c743a673) is built by another session's
# project, volta-analytics-new-db/, which lives on the perf/stream-dashboard
# branch — it is only present in the shared clone's working tree while that
# branch is checked out, so the page is read from the COMMITTED copy on the
# remote branch (git show), never from a path. It is a snapshot refreshed on
# request in that other session; this just republishes whatever was last
# committed there, at https://volta-ge.github.io/reporting/analytics-new.html.
GIT_PAGES = [
    ("perf/stream-dashboard", "volta-analytics-new-db/deals_amount_migration.html", "docs/analytics-new.html"),
]

MARKER = "</style>\n"


def build_wrapped_html(content: str) -> str:
    idx = content.index(MARKER) + len(MARKER)
    head_part = content[:idx]
    body_part = content[idx:]
    return (
        "<!DOCTYPE html>\n<html lang=\"ka\">\n<head>\n"
        "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        + head_part
        + "</head>\n<body>\n"
        + body_part
        + "\n</body>\n</html>\n"
    )


def git(args, cwd):
    # utf-8 explicitly: `git show` of the Georgian-text HTML would otherwise be
    # decoded with the Windows locale codepage and silently mangled.
    return subprocess.run(["git", *args], cwd=cwd, check=True, capture_output=True, text=True, encoding="utf-8")


def ensure_worktree():
    """Create the pages worktree on first use; on every run re-pin it to the
    freshly fetched origin/main so the commit is always built on top of the
    live branch tip (no local branch, no merge, no rebase needed)."""
    if not (PAGES_WORKTREE / ".git").exists():
        git(["worktree", "prune"], REPO)
        git(["fetch", "-q", "origin", "main"], REPO)
        git(["worktree", "add", "--detach", str(PAGES_WORKTREE), "origin/main"], REPO)
    git(["fetch", "-q", "origin", "main"], PAGES_WORKTREE)
    git(["checkout", "-q", "--detach", "origin/main"], PAGES_WORKTREE)


def main():
    ensure_worktree()
    changed = []

    def emit(rel, content):
        (PAGES_WORKTREE / rel).write_text(build_wrapped_html(content), encoding="utf-8")
        if git(["status", "--porcelain", "--", rel], PAGES_WORKTREE).stdout.strip():
            changed.append(rel)

    for src, rel in PAGES:
        if not src.is_file():
            print(f"skipping {rel}: source missing ({src})", file=sys.stderr)
            continue
        emit(rel, src.read_text(encoding="utf-8"))

    for branch, path, rel in GIT_PAGES:
        try:
            git(["fetch", "-q", "origin", branch], PAGES_WORKTREE)
            content = git(["show", f"origin/{branch}:{path}"], PAGES_WORKTREE).stdout
        except subprocess.CalledProcessError as e:
            print(f"skipping {rel}: cannot read {path} from origin/{branch} ({e.stderr.strip()})", file=sys.stderr)
            continue
        emit(rel, content)

    if not changed:
        print("GitHub Pages copies unchanged, nothing to commit.")
        return

    git(["add", *changed], PAGES_WORKTREE)
    git(["commit", "-q", "-m", "Daily refresh: waybills dashboards static build (" + ", ".join(changed) + ")"], PAGES_WORKTREE)
    git(["push", "-q", "origin", "HEAD:main"], PAGES_WORKTREE)
    print("pushed to main: " + ", ".join(changed))


if __name__ == "__main__":
    main()
