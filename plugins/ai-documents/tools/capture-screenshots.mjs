/**
 * Re-photographs every screenshot the documentation uses, straight from a
 * running site, so a screen that changes in the plugin can be brought back
 * into the manual with one command instead of eighteen manual crops.
 *
 *   SITE=http://cirlot.local \
 *   WP_LOAD="/path/to/wp-load.php" \
 *   node tools/capture-screenshots.mjs [name ...]
 *
 * Pass names to re-take only those shots. With no names, it takes them all.
 *
 * Drives Google Chrome over the DevTools protocol with nothing installed:
 * Node 22's own WebSocket is the only client needed. Admin screens are
 * reached with a session minted by tools/wp-session.php.
 */

import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';
import path from 'node:path';
import os from 'node:os';

const ROOT     = path.resolve(import.meta.dirname, '..');
const OUT_DIR  = path.join(ROOT, 'docs/assets/screenshots');
const SITE     = (process.env.SITE || 'http://cirlot.local').replace(/\/$/, '');
const CHROME   = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCALE    = Number(process.env.SCALE || 1);
const PORT     = Number(process.env.CDP_PORT || 9333);
const PAD      = 14;   // breathing room around a clipped panel, in CSS pixels

// ── The shot list ─────────────────────────────────────────────────────────
// `clip` is a CSS selector; the shot is cropped to that element plus padding.
// `before` runs in the page before the capture — expanding a panel, switching
// a tab. `waitFor` is polled until it returns true.
const HIDE_ADMIN_BAR = `(() => {
  document.getElementById('wpadminbar')?.remove();
  document.documentElement.style.marginTop = '0';
  document.body.classList.remove('admin-bar');
  return 'hidden';
})()`;

const DOC_ID     = process.env.DOC_ID     || '342';   // an entry that already has content
const COMPILATION = process.env.COMPILATION || '122';  // a media item holding several policies

const SHOTS = [
  // ── The admin, at rest ──────────────────────────────────────────────────
  {
    name: 'documents-list',
    url: '/wp-admin/edit.php?post_type=aidoc',
    before: HIDE_ADMIN_BAR,
    clip: '#wpbody-content',
    maxHeight: 1000,
  },
  {
    name: 'admin-menu',
    url: '/wp-admin/edit.php?post_type=aidoc',
    viewport: [1400, 1200],
    // From the top of the sidebar down to the Documentation item, so the
    // whole Documents group is in frame and the rest of WordPress is not.
    clip: `js:(() => {
      const menu = document.querySelector('#adminmenuwrap');
      const last = [...document.querySelectorAll('#menu-posts-aidoc a')]
        .find(a => a.href.includes('page=aidocs-documentation'));
      const m = menu.getBoundingClientRect(), l = last.getBoundingClientRect();
      return { x: m.left + scrollX, y: m.top + scrollY,
               width: m.width, height: l.bottom + scrollY - (m.top + scrollY) + 8 };
    })()`,
  },

  // ── Settings ────────────────────────────────────────────────────────────
  ...['AI', 'Taxonomy', 'Shortcodes'].map((heading, i) => ({
    name: 'settings-' + heading.toLowerCase(),
    url: '/wp-admin/edit.php?post_type=aidoc&page=aidocs-settings',
    maxHeight: heading === 'Shortcodes' ? 1500 : 0,
    clip: `js:(() => {
      const s = [...document.querySelectorAll('.cd-settings-section')]
        .find(el => el.querySelector('h2')?.textContent.trim() === ${JSON.stringify(heading)});
      if (!s) return null;
      const r = s.getBoundingClientRect();
      return { x: r.left + scrollX, y: r.top + scrollY, width: r.width, height: r.height };
    })()`,
  })),
  {
    name: 'admin-documentation',
    url: '/wp-admin/edit.php?post_type=aidoc&page=aidocs-documentation',
    viewport: [1500, 1200],
    clip: '.aidocs-docs-page',
    maxHeight: 1400,
  },

  // ── Creating an entry ───────────────────────────────────────────────────
  {
    name: 'add-new',
    url: '/wp-admin/post-new.php?post_type=aidoc',
    clip: '#aidocs_meta',
  },
  {
    name: 'compilation-mode',
    url: '/wp-admin/post-new.php?post_type=aidoc',
    before: `document.querySelector('input[name="document_source_mode"][value="multi"]').click()`,
    clip: '#cd-mode-wrap',
  },
  {
    name: 'compilation-policies',
    url: '/wp-admin/post-new.php?post_type=aidoc',
    // Pick the compilation straight out of the media library, then let the
    // splitter read it. Nothing is imported: this stops at the review list.
    before: `(async () => {
      document.querySelector('input[name="document_source_mode"][value="multi"]').click();
      document.querySelector('#cd-upload-btn').click();
      const until = async (fn, ms = 20000) => {
        for (let t = 0; t < ms; t += 200) { const v = fn(); if (v) return v; await new Promise(r => setTimeout(r, 200)); }
        return null;
      };
      const item = await until(() => document.querySelector('.media-modal li.attachment[data-id="${COMPILATION}"]'));
      if (!item) return 'no attachment ${COMPILATION} in the library';
      item.click();
      const use = await until(() => {
        const b = document.querySelector('.media-modal .media-button-select');
        return b && !b.disabled ? b : null;
      });
      use.click();
      return 'selected';
    })()`,
    waitFor: `(() => { const r = document.querySelector('#cd-split-review'); return !!r && !r.hidden && document.querySelector('#cd-split-list').children.length > 0; })()`,
    after: 1500,
    clip: '#cd-split-wrap',
    maxHeight: 1600,
  },

  // ── An entry that already has content ───────────────────────────────────
  {
    name: 'edit-existing',
    url: `/wp-admin/post.php?post=${DOC_ID}&action=edit`,
    clip: '#aidocs_meta',
    maxHeight: 1500,
  },
  {
    name: 'extracted-content',
    url: `/wp-admin/post.php?post=${DOC_ID}&action=edit`,
    before: `document.querySelector('#cd-content-tabs .cd-tab-btn[data-tab="preview"]').click()`,
    clip: '#cd-extract-wrap',
    maxHeight: 1100,
  },
  {
    name: 'edit-extracted-content',
    url: `/wp-admin/post.php?post=${DOC_ID}&action=edit`,
    clip: '#cd-extract-wrap',
    maxHeight: 1100,
  },
  {
    name: 'ai-fields-panel',
    url: `/wp-admin/post.php?post=${DOC_ID}&action=edit`,
    before: `document.querySelector('#cd-ai-panel').open = true`,
    clip: '#cd-ai-panel',
    maxHeight: 900,
  },
  {
    name: 'ai-proposals',
    url: `/wp-admin/post.php?post=${DOC_ID}&action=edit`,
    // One real Gemini call, on the two fields the label schema never supplies.
    before: `(() => {
      const p = document.querySelector('#cd-ai-panel'); p.open = true;
      ['audience', 'document_type'].forEach(f => {
        const cb = p.querySelector('.cd-ai-field-check[data-field-id="' + f + '"]');
        if (cb && !cb.checked) cb.click();
      });
      document.querySelector('#cd-ai-process-btn').click();
      return 'asked';
    })()`,
    waitFor: `(() => { const r = document.querySelector('#cd-ai-review'); return r && !r.hidden && r.offsetHeight > 0; })()`,
    after: 1200,
    clip: '#cd-ai-review',
    maxHeight: 900,
  },
  {
    name: 'ai-restructure',
    url: `/wp-admin/post.php?post=${DOC_ID}&action=edit`,
    before: `document.querySelector('#cd-ai-panel').open = true`,
    clip: '#cd-ai-restructure',
  },

  // ── What readers see ────────────────────────────────────────────────────
  {
    name: 'frontend-search',
    url: '/documents/',
    viewport: [1360, 1100],
    settle: 2500,
    before: HIDE_ADMIN_BAR,
    clip: '.cd-fs-wrap',
    maxHeight: 1200,
  },
  {
    name: 'frontend-search-ai',
    url: '/documents/',
    viewport: [1360, 1100],
    settle: 2500,
    before: `(() => {
      ${HIDE_ADMIN_BAR};
      const kw = document.querySelector('.cd-fs-keyword');
      kw.focus();
      kw.value = 'can we offer a degree with fewer than 120 credit hours?';
      kw.dispatchEvent(new Event('input', { bubbles: true }));
      kw.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
      return 'typed';
    })()`,
    waitFor: `!!document.querySelector('.cd-fs-ai-suggest-box')`,
    after: 1200,
    clip: `js:(() => {
      const wrap = document.querySelector('.cd-fs-wrap');
      const last = document.querySelector('.cd-fs-ai-explain');
      const w = wrap.getBoundingClientRect(), l = last.getBoundingClientRect();
      return { x: w.left + scrollX, y: w.top + scrollY,
               width: w.width, height: l.bottom + scrollY - (w.top + scrollY) + 12 };
    })()`,
  },
  {
    name: 'frontend-single',
    url: `/?p=${DOC_ID}&post_type=aidoc`,
    viewport: [1360, 1100],
    settle: 2000,
    before: HIDE_ADMIN_BAR,
    clip: '.aidocs-single',
    maxHeight: 1400,
  },
];

// ── Chrome, over the DevTools protocol ────────────────────────────────────

const userDataDir = path.join(os.tmpdir(), 'aidocs-shots-profile');

function launchChrome() {
  const chrome = spawn(CHROME, [
    '--headless=new',
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-extensions',
    '--hide-scrollbars',
    '--force-color-profile=srgb',
    'about:blank',
  ], { stdio: 'ignore' });
  chrome.unref();
  return chrome;
}

async function cdpEndpoint() {
  for (let i = 0; i < 60; i++) {
    try {
      const r = await fetch(`http://127.0.0.1:${PORT}/json/version`);
      const j = await r.json();
      if (j.webSocketDebuggerUrl) return j;
    } catch {}
    await sleep(250);
  }
  throw new Error('Chrome did not open a debugging port');
}

class Session {
  constructor(ws) { this.ws = ws; this.id = 0; this.pending = new Map(); this.handlers = new Map(); }

  static async open(url) {
    const ws = new WebSocket(url);
    await new Promise((ok, no) => { ws.onopen = ok; ws.onerror = () => no(new Error('CDP socket failed')); });
    const s = new Session(ws);
    ws.onmessage = (e) => {
      const msg = JSON.parse(e.data);
      if (msg.id && s.pending.has(msg.id)) {
        const { ok, no } = s.pending.get(msg.id);
        s.pending.delete(msg.id);
        msg.error ? no(new Error(msg.error.message)) : ok(msg.result);
      } else if (msg.method) {
        (s.handlers.get(msg.method) || []).forEach((fn) => fn(msg.params));
      }
    };
    return s;
  }

  send(method, params = {}) {
    const id = ++this.id;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((ok, no) => this.pending.set(id, { ok, no }));
  }

  on(method, fn) {
    if (!this.handlers.has(method)) this.handlers.set(method, []);
    this.handlers.get(method).push(fn);
  }

  once(method, timeout = 30000) {
    return new Promise((ok) => {
      const done = (p) => ok(p);
      this.on(method, done);
      setTimeout(() => ok(null), timeout);
    });
  }

  async eval(expression) {
    const r = await this.send('Runtime.evaluate', {
      expression, returnByValue: true, awaitPromise: true,
    });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || 'page error');
    return r.result.value;
  }
}

// ── Run ───────────────────────────────────────────────────────────────────

const wanted = process.argv.slice(2);
const shots  = wanted.length ? SHOTS.filter((s) => wanted.includes(s.name)) : SHOTS;
if (!shots.length) {
  console.error(`No shot matches ${wanted.join(', ')}. Known: ${SHOTS.map((s) => s.name).join(', ')}`);
  process.exit(1);
}

mkdirSync(OUT_DIR, { recursive: true });

console.log('Minting an admin session…');
const sessionJson = execFileSync('php', [
  '-d', `mysqli.default_socket=${process.env.MYSQL_SOCKET || ''}`,
  path.join(ROOT, 'tools/wp-session.php'),
], { env: process.env, encoding: 'utf8' });
const wp = JSON.parse(sessionJson);
console.log(`  signed in as ${wp.user} on ${wp.home}`);

const chrome = launchChrome();
let page;
try {
  await cdpEndpoint();
  const target = await (await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' })).json();
  page = await Session.open(target.webSocketDebuggerUrl);

  await page.send('Page.enable');
  await page.send('Runtime.enable');
  await page.send('Network.enable');

  for (const [name, value] of Object.entries(wp.cookies)) {
    await page.send('Network.setCookie', { name, value, domain: wp.domain, path: '/', httpOnly: true });
  }

  for (const shot of shots) {
    const [w, h] = shot.viewport || [1440, 1000];
    await page.send('Emulation.setDeviceMetricsOverride', {
      width: w, height: h, deviceScaleFactor: SCALE, mobile: false,
    });

    const loaded = page.once('Page.loadEventFired');
    await page.send('Page.navigate', { url: SITE + shot.url });
    await loaded;
    await sleep(shot.settle ?? 700);

    if (shot.before) { await page.eval(shot.before); await sleep(shot.after ?? 500); }

    if (shot.waitFor) {
      let ok = false;
      for (let i = 0; i < 120 && !ok; i++) { ok = await page.eval(shot.waitFor); if (!ok) await sleep(500); }
      if (!ok) { console.log(`  ⚠ ${shot.name}: waitFor never became true, capturing anyway`); }
      await sleep(shot.after ?? 500);
    }

    let clip;
    if (shot.clip) {
      const expr = shot.clip.startsWith('js:')
        ? shot.clip.slice(3)
        : `(() => {
            const el = document.querySelector(${JSON.stringify(shot.clip)});
            if (!el) return null;
            const r = el.getBoundingClientRect();
            return { x: r.left + scrollX, y: r.top + scrollY, width: r.width, height: r.height };
          })()`;
      const box = await page.eval(expr);
      if (!box) { console.log(`  ✕ ${shot.name}: ${shot.clip} matched nothing — skipped`); continue; }
      clip = {
        x: Math.max(0, box.x - PAD),
        y: Math.max(0, box.y - PAD),
        width: box.width + PAD * 2,
        height: box.height + PAD * 2,
        scale: SCALE,
      };
    }

    if (shot.maxHeight) {
      if (!clip) {
        const full = await page.eval(`({ w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight })`);
        clip = { x: 0, y: 0, width: full.w, height: full.h, scale: SCALE };
      }
      clip.height = Math.min(clip.height, shot.maxHeight);
    }

    const { data } = await page.send('Page.captureScreenshot', {
      format: 'png',
      captureBeyondViewport: true,
      ...(clip ? { clip } : {}),
    });
    const file = path.join(OUT_DIR, `${shot.name}.png`);
    writeFileSync(file, Buffer.from(data, 'base64'));
    console.log(`  ✓ ${shot.name}.png  ${Math.round(Buffer.from(data, 'base64').length / 1024)} KB`);
  }
} finally {
  try { await fetch(`http://127.0.0.1:${PORT}/json/close/${(await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json())[0]?.id}`); } catch {}
  chrome.kill();
}

console.log('\nDone. Run `bash tools/build-docs.sh` to publish the new screenshots.');
