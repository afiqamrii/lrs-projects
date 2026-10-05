"""Build a read-only sharing preview from the local, fictional Laravel workspace.

Uses an ignored browser state file for GET requests to localhost only. Published
output contains sanitized HTML and an allowlist of public assets, never cookies,
application configuration, private files or a database export.
"""

from __future__ import annotations

import argparse
import hashlib
import html
import json
import re
import shutil
import time
from collections import deque
from html.parser import HTMLParser
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import parse_qsl, urlencode, urljoin, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


SOURCE = "http://127.0.0.1:8000"
ROOT = Path(__file__).resolve().parent.parent
QUERY_KEYS = {"section", "data", "tab", "status", "state", "classification", "version", "page", "kind", "mode", "message", "from", "to", "owner", "source", "vendor"}
VOID_TAGS = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "param", "source", "track", "wbr"}
SEEDS = [
    "/overview", "/inquiries", "/vendors", "/clients", "/mail", "/mail/outgoing", "/attention",
    "/reports", "/reports?tab=rfqs", "/reports?tab=ai", "/operations/health", "/settings",
    "/settings/ai", "/settings/mailbox", "/settings/followups", "/settings/handoff", "/profile",
    "/staff", "/staff/create", "/request-quote", "/login", "/forgot-password", "/inquiries/create",
    "/vendors/create", "/clients/create", "/inquiries/8", "/inquiries/8/edit",
    "/inquiries/8?section=shipment", "/inquiries/8?section=documents", "/inquiries/8?section=activity",
    "/inquiries/8/public-contact", "/inquiries/8/extraction", "/inquiries/8/sourcing",
    "/inquiries/8/offers", "/inquiries/8/quotations", "/inquiries/8/email",
    "/inquiries/8/lifecycle", "/inquiries/8/handoff", "/inquiries/8/decisions/13",
    "/inquiries/8/reconfirmations/2", "/inquiries/8/offers/3", "/inquiries/8/rfqs/5/review",
]

PREVIEW_JS = """
(() => {
  const publicLink = document.querySelector('#public-inquiry-link');
  if (publicLink) publicLink.value = location.origin + '/request-quote';
  const notice = document.getElementById('lrs-preview-notice');
  const message = notice.querySelector('[data-preview-message]');
  function show(text, trigger) {
    message.textContent = text;
    if (!notice.open) notice.showModal();
    notice.addEventListener('close', () => trigger?.focus(), {once:true});
  }
  notice.querySelector('[data-preview-close]').addEventListener('click', () => notice.close());
  document.addEventListener('submit', event => {
    event.preventDefault(); event.stopImmediatePropagation();
    show('This is a read-only demonstration. Saving, approvals, uploads and sending are available in the full Laravel workspace.', event.submitter);
  }, true);
  document.addEventListener('click', event => {
    const link = event.target.closest('a[data-preview-unavailable]');
    if (link) {
      event.preventDefault(); event.stopImmediatePropagation();
      show('This source, download or external action stays in the full workspace. You can explore the included fictional screens here.', link);
      return;
    }
    const button = event.target.closest('button');
    if (button?.closest('form') && (button.type === 'submit' || !button.hasAttribute('type'))) {
      event.preventDefault(); event.stopImmediatePropagation();
      show('Changes are not saved in this sharing preview. Explore the screens freely; no email, upload, approval or booking is created.', button);
    }
  }, true);
})();
"""

PREVIEW_CSS = """
.lrs-preview-banner{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px;padding:16px 20px;margin:0 0 24px;border:1px solid #d9e7fb;border-radius:16px;background:#edf4ff;color:#1d1d1f;font-size:12px;line-height:1.7}
.lrs-preview-banner p{margin:0}.lrs-preview-banner strong{font-weight:600}.lrs-preview-links{display:flex;flex-wrap:wrap;gap:14px}.lrs-preview-links a{color:#0066cc;font-weight:500}
#lrs-preview-notice{width:min(440px,calc(100vw - 40px));max-height:calc(100dvh - 40px);overflow:auto;border:1px solid #dedee3;border-radius:20px;padding:28px;background:white;color:#1d1d1f;box-shadow:0 24px 80px #0003}
#lrs-preview-notice::backdrop{background:#1d1d1f66;backdrop-filter:blur(4px)}#lrs-preview-notice h2{font-size:22px;margin:0 0 12px}#lrs-preview-notice p{font-size:14px;line-height:1.7;margin:0 0 20px}
.lrs-preview-source{padding:20px;border:1px dashed #dedee3;border-radius:14px;font-size:12px;line-height:1.7;background:#f5f5f7;color:#515157}
.lrs-preview-auth{flex-direction:column}.lrs-preview-auth .lrs-preview-banner{width:100%;max-width:384px}
"""

BANNER = """<aside class="lrs-preview-banner" aria-label="Sharing preview"><p><strong>Read-only sharing preview</strong><br>Fictional data · changes are not saved · no email is sent.</p><nav class="lrs-preview-links" aria-label="Demo shortcuts"><a href="/overview">Workspace</a><a href="/inquiries/8">Sample inquiry</a><a href="/request-quote">Public form</a></nav></aside>"""
NOTICE = """<dialog id="lrs-preview-notice" aria-labelledby="lrs-preview-title"><h2 id="lrs-preview-title">Sharing preview</h2><p data-preview-message>This demonstration is read-only.</p><button type="button" class="btn btn-primary" data-preview-close>Continue exploring</button></dialog>"""


def canonical(value: str) -> str | None:
    parsed = urlsplit(urljoin(SOURCE, html.unescape(value)))
    if f"{parsed.scheme}://{parsed.netloc}" != SOURCE:
        return None
    path = parsed.path.rstrip("/") or "/overview"
    if re.search(r"/(?:file|download|export|callback|connect|authorize|confirm|resend|received)(?:/|$)", path):
        return None
    if path.startswith("/inquiries/"):
        case = re.match(r"/inquiries/(\d+)(.*)", path)
        if case and not 8 <= int(case.group(1)) <= 13:
            return None
        if case and case.group(1) != "8" and case.group(2) not in {"", "/edit", "/public-contact"}:
            return None
    elif path.startswith("/vendors/"):
        record = re.match(r"/vendors/(\d+)", path)
        if record and not 6 <= int(record.group(1)) <= 10:
            return None
    elif path.startswith("/clients/"):
        record = re.match(r"/clients/(\d+)", path)
        if record and not 3 <= int(record.group(1)) <= 5:
            return None
    allowed_roots = {"overview", "inquiries", "vendors", "clients", "mail", "attention", "reports", "operations", "settings", "profile", "staff", "request-quote", "login", "forgot-password", "followups"}
    if path.split("/")[1] not in allowed_roots:
        return None
    pairs = parse_qsl(parsed.query, keep_blank_values=False)
    if any(key not in QUERY_KEYS for key, _ in pairs) or any(key == "data" and value == "fixtures" for key, value in pairs):
        return None
    query = urlencode(sorted(pairs))
    return path + ("?" + query if query else "")


def published_path(key: str) -> str:
    path, _, query = key.partition("?")
    if not query:
        return path
    slug = re.sub(r"[^a-zA-Z0-9-]+", "-", query).strip("-")[:70]
    digest = hashlib.sha256(query.encode()).hexdigest()[:8]
    return f"{path}/views/{slug}-{digest}"


class SnapshotParser(HTMLParser):
    def __init__(self, current: str, known: set[str] | None = None) -> None:
        super().__init__(convert_charrefs=False)
        self.current = current
        self.known = known
        self.links: set[str] = set()
        self.parts: list[str] = []
        self.skip: str | None = None
        self.banner_added = False

    def handle_decl(self, decl: str) -> None:
        self.parts.append(f"<!{decl}>")

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if self.skip:
            return
        values = dict(attrs)
        if tag == "script" and not values.get("src"):
            self.skip = tag
            return
        if tag in {"iframe", "object", "embed"}:
            self.parts.append('<div class="lrs-preview-source">Private source documents and approved PDFs remain in the full workspace. This sharing link contains fictional screen previews only.</div>')
            if tag not in VOID_TAGS:
                self.skip = tag
            return
        if tag == "meta" and values.get("name", "").lower() == "csrf-token":
            return
        if tag == "input" and values.get("type", "").lower() == "hidden" and not values.get("name", "").endswith("[key]"):
            return
        changed: list[tuple[str, str | None]] = []
        for name, value in attrs:
            if value:
                value = value.replace(SOURCE, "")
            if name.startswith("on") or name in {"data-unsaved", "data-confirm-form", "data-confirmed"}:
                continue
            if name == "action":
                changed.append(("action", "/preview-unavailable"))
                continue
            if name == "href" and tag == "a" and value:
                if value.startswith("#"):
                    changed.append((name, value))
                    continue
                absolute = urljoin(SOURCE + self.current, html.unescape(value))
                parsed = urlsplit(absolute)
                if parsed.scheme in {"mailto", "tel"}:
                    changed.extend([("href", "#"), ("data-preview-unavailable", "")])
                    continue
                if f"{parsed.scheme}://{parsed.netloc}" == SOURCE:
                    key = canonical(absolute)
                    if key:
                        self.links.add(key)
                    if self.known is None:
                        changed.append((name, value.replace(SOURCE, "")))
                    elif key in self.known:
                        changed.append((name, published_path(key) + ("#" + parsed.fragment if parsed.fragment else "")))
                    else:
                        changed.extend([("href", "#"), ("data-preview-unavailable", "")])
                    continue
                changed.extend([(name, value), ("rel", "noopener noreferrer")])
                continue
            if value and name in {"src", "href", "data", "poster", "srcset"}:
                value = value.replace(SOURCE, "")
            if name == "class" and tag == "main" and self.current in {"/login", "/forgot-password"} and self.known is None:
                value = (value or "") + " lrs-preview-auth"
            changed.append((name, value))
        rendered = "".join(" " + name + ("=\"" + html.escape(value, quote=True) + "\"" if value is not None else "") for name, value in changed)
        self.parts.append(f"<{tag}{rendered}>")
        if tag == "main" and not self.banner_added and self.known is None:
            self.parts.append(BANNER)
            self.banner_added = True

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.handle_starttag(tag, attrs)
        if tag not in VOID_TAGS:
            self.handle_endtag(tag)

    def handle_endtag(self, tag: str) -> None:
        if self.skip:
            if self.skip == tag:
                self.skip = None
            return
        if tag == "head" and self.known is None:
            self.parts.append('<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><link rel="stylesheet" href="/preview.css"><script src="/preview.js" defer></script>')
        if tag == "body" and self.known is None:
            self.parts.append(NOTICE)
        self.parts.append(f"</{tag}>")

    def handle_data(self, data: str) -> None:
        if not self.skip:
            self.parts.append(data.replace(SOURCE, "[local workspace]"))

    def handle_entityref(self, name: str) -> None:
        if not self.skip:
            self.parts.append(f"&{name};")

    def handle_charref(self, name: str) -> None:
        if not self.skip:
            self.parts.append(f"&#{name};")

    def output(self) -> str:
        result = "".join(self.parts)
        if SOURCE in result or "browser-logger-active" in result or re.search(r'name=[\"\'](?:_token|confirmation_token|intake_token)[\"\']', result):
            raise RuntimeError(f"Unsafe preview output for {self.current}")
        return result


class LocalRedirects(HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, target):
        if f"{urlsplit(target).scheme}://{urlsplit(target).netloc}" != SOURCE:
            raise RuntimeError("Preview capture cannot follow an external redirect.")
        return super().redirect_request(request, response, code, message, headers, target)


def build(state: Path, output: Path, limit: int) -> None:
    state = state.resolve()
    output = output.resolve()
    ignored_root = (ROOT / ".tools").resolve()
    if not state.is_relative_to(ignored_root) or not output.is_relative_to(ignored_root):
        raise RuntimeError("Authentication state and deployment output must stay inside ignored .tools.")
    if (output / "site").exists():
        raise RuntimeError("Use a fresh output directory; an existing preview is never removed automatically.")
    browser = json.loads(state.read_text(encoding="utf-8-sig"))
    cookie = "; ".join(item["name"] + "=" + item["value"] for item in browser["cookies"] if item["domain"].lstrip(".") in {"127.0.0.1", "localhost"})
    pending = deque(SEEDS)
    visited: set[str] = set()
    snapshots: dict[str, str] = {}
    failures: list[dict[str, str | int]] = []
    opener = build_opener(LocalRedirects())
    while pending and len(snapshots) < limit:
        key = canonical(pending.popleft())
        if not key or key in visited:
            continue
        visited.add(key)
        headers = {"Accept": "text/html", "User-Agent": "LRS-read-only-preview-builder"}
        if key not in {"/login", "/forgot-password", "/request-quote"}:
            headers["Cookie"] = cookie
        try:
            with opener.open(Request(SOURCE + key, headers=headers), timeout=30) as response:
                if "text/html" not in response.headers.get("Content-Type", ""):
                    continue
                raw = response.read(3_000_000).decode("utf-8")
                if urlsplit(response.url).path == "/login" and key != "/login":
                    raise RuntimeError("The local authenticated browser session has expired.")
        except HTTPError as error:
            failures.append({"path": key, "status": error.code})
            continue
        if "<html" not in raw.lower() or "Ignition" in raw or "Symfony\\Component\\ErrorHandler" in raw:
            raise RuntimeError(f"Unexpected response at {key}; no debug page is published.")
        if key == "/overview" and "Business preview" not in raw:
            raise RuntimeError("Sharing export requires the fictional local preview workspace.")
        parser = SnapshotParser(key)
        parser.feed(raw)
        snapshots[key] = parser.output()
        pending.extend(sorted(parser.links - visited))
        if len(snapshots) % 20 == 0:
            print(json.dumps({"captured": len(snapshots), "pending": len(pending)}), flush=True)
        time.sleep(0.025)
    required = set(SEEDS) - {"/forgot-password"}
    if not required.issubset(snapshots):
        raise RuntimeError("Missing required demo screens: " + ", ".join(sorted(required - snapshots.keys())))
    site = output / "site"
    site.mkdir(parents=True)
    for key, snapshot in snapshots.items():
        parser = SnapshotParser(key, set(snapshots))
        parser.feed(snapshot)
        document = parser.output()
        document = re.sub(r'<div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-\[#d9e7fb\] bg-\[#edf4ff\][^\"]*">.*?</div>', '', document, flags=re.S)
        document = re.sub(r'<div class="alert alert-info mb-6">Local business preview.*?</div>', '', document, flags=re.S)
        destination = site / (published_path(key).lstrip("/") + ".html")
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text(document, encoding="utf-8")
    shutil.copytree(ROOT / "public" / "build" / "assets", site / "build" / "assets")
    for name in ["favicon.svg", "favicon.ico"]:
        if (ROOT / "public" / name).is_file():
            shutil.copy2(ROOT / "public" / name, site / name)
    (site / "preview.js").write_text(PREVIEW_JS, encoding="utf-8")
    (site / "preview.css").write_text(PREVIEW_CSS, encoding="utf-8")
    (site / "robots.txt").write_text("User-agent: *\nDisallow: /\n", encoding="utf-8")
    (site / "index.html").write_text('<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=/overview"><title>LRS sharing preview</title><a href="/overview">Explore LRS</a>', encoding="utf-8")
    configuration = {
        "$schema": "https://openapi.vercel.sh/vercel.json", "framework": None,
        "buildCommand": None, "installCommand": None, "outputDirectory": "site", "cleanUrls": True,
        "redirects": [{"source": "/", "destination": "/overview", "permanent": False}],
        "headers": [{"source": "/(.*)", "headers": [
            {"key": "X-Robots-Tag", "value": "noindex, nofollow"},
            {"key": "X-Content-Type-Options", "value": "nosniff"},
            {"key": "Referrer-Policy", "value": "no-referrer"},
            {"key": "Content-Security-Policy", "value": "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'none'; form-action 'none'; frame-src 'none'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'"}
        ]}]
    }
    (output / "vercel.json").write_text(json.dumps(configuration, indent=2) + "\n", encoding="utf-8")
    (output / ".vercelignore").write_text(".vercel\nbuild-report.json\n.gitignore\n.env*\n*credentials*\n*browser-state*\nlive-*\n", encoding="utf-8")
    report = {"pages": len(snapshots), "capped": bool(pending), "routes": {key: published_path(key) for key in sorted(snapshots)}, "unavailable_sources": failures, "read_only": True, "private_files_exported": 0}
    (output / "build-report.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"pages": len(snapshots), "output": str(output), "unavailable_sources": len(failures), "read_only": True}), flush=True)


if __name__ == "__main__":
    arguments = argparse.ArgumentParser(description=__doc__)
    arguments.add_argument("--state", type=Path, required=True)
    arguments.add_argument("--output", type=Path, required=True)
    arguments.add_argument("--limit", type=int, default=240)
    options = arguments.parse_args()
    build(options.state, options.output, options.limit)
