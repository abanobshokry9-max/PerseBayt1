'use strict';

const http = require('http');
const crypto = require('crypto');
const dns = require('dns').promises;
const net = require('net');
const { chromium } = require('playwright');

const PORT = Number(process.env.PORT || 8787);
const TOKEN = String(process.env.BROWSER_BRIDGE_TOKEN || '');
const HEADLESS = String(process.env.BROWSER_HEADLESS || '1') !== '0';
const SESSION_TTL_MS = Math.max(5, Number(process.env.BROWSER_SESSION_TTL_MINUTES || 30)) * 60 * 1000;
const MAX_BODY = 512 * 1024;
const MAX_TEXT = 2000;
const sessions = new Map();
let browserPromise = null;

function json(res, status, body) {
  const data = Buffer.from(JSON.stringify(body));
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': data.length,
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
  });
  res.end(data);
}

function safeEq(a, b) {
  const aa = Buffer.from(String(a || ''));
  const bb = Buffer.from(String(b || ''));
  return aa.length === bb.length && aa.length > 0 && crypto.timingSafeEqual(aa, bb);
}

function authOk(req) {
  if (!TOKEN) return false;
  const h = String(req.headers.authorization || '');
  return h.startsWith('Bearer ') && safeEq(h.slice(7), TOKEN);
}

async function readJson(req) {
  return await new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (c) => {
      size += c.length;
      if (size > MAX_BODY) {
        reject(new Error('request_too_large'));
        req.destroy();
        return;
      }
      chunks.push(c);
    });
    req.on('end', () => {
      try {
        const raw = Buffer.concat(chunks).toString('utf8');
        resolve(raw ? JSON.parse(raw) : {});
      } catch {
        reject(new Error('invalid_json'));
      }
    });
    req.on('error', reject);
  });
}

function isPrivateIp(ip) {
  if (net.isIP(ip) === 4) {
    const p = ip.split('.').map(Number);
    return p[0] === 10 || p[0] === 127 || p[0] === 0 ||
      (p[0] === 169 && p[1] === 254) ||
      (p[0] === 172 && p[1] >= 16 && p[1] <= 31) ||
      (p[0] === 192 && p[1] === 168) ||
      (p[0] === 100 && p[1] >= 64 && p[1] <= 127) ||
      p[0] >= 224;
  }
  if (net.isIP(ip) === 6) {
    const low = ip.toLowerCase();
    return low === '::1' || low === '::' || low.startsWith('fc') || low.startsWith('fd') || low.startsWith('fe8') || low.startsWith('fe9') || low.startsWith('fea') || low.startsWith('feb');
  }
  return true;
}

async function publicUrl(raw) {
  let u;
  try { u = new URL(String(raw || '')); } catch { throw new Error('invalid_url'); }
  if (!['http:', 'https:'].includes(u.protocol)) throw new Error('url_scheme_not_allowed');
  const host = u.hostname.toLowerCase();
  if (!host || host === 'localhost' || host.endsWith('.local')) throw new Error('private_target_not_allowed');
  if (net.isIP(host) && isPrivateIp(host)) throw new Error('private_target_not_allowed');
  try {
    const resolved = await dns.lookup(host, { all: true, verbatim: true });
    if (!resolved.length || resolved.some((r) => isPrivateIp(r.address))) throw new Error('private_target_not_allowed');
  } catch (e) {
    if (String(e.message) === 'private_target_not_allowed') throw e;
    throw new Error('target_dns_failed');
  }
  return u.toString();
}

async function getBrowser() {
  if (!browserPromise) browserPromise = chromium.launch({ headless: HEADLESS });
  return browserPromise;
}

async function newSession(key) {
  const browser = await getBrowser();
  const context = await browser.newContext({
    acceptDownloads: false,
    viewport: { width: 1365, height: 900 },
  });
  const page = await context.newPage();
  const session = { context, page, touched: Date.now() };
  sessions.set(key, session);
  return session;
}

async function sessionFor(account) {
  const key = account && Number(account.id) > 0 ? `account:${Number(account.id)}` : 'anonymous';
  let s = sessions.get(key);
  if (!s || Date.now() - s.touched > SESSION_TTL_MS) {
    if (s) { try { await s.context.close(); } catch {} }
    s = await newSession(key);
  }
  s.touched = Date.now();
  return s;
}

async function cleanup() {
  const now = Date.now();
  for (const [key, s] of sessions) {
    if (now - s.touched > SESSION_TTL_MS) {
      sessions.delete(key);
      try { await s.context.close(); } catch {}
    }
  }
}
setInterval(() => void cleanup(), 60_000).unref();

async function challengeState(page) {
  const url = page.url();
  let text = '';
  try { text = (await page.locator('body').innerText({ timeout: 2500 })).slice(0, 12000).toLowerCase(); } catch {}
  const hay = `${url}\n${text}`.toLowerCase();
  const patterns = [
    ['captcha', /captcha|recaptcha|hcaptcha|i am not a robot|أنا لست برنامج|تحقق أنك لست/],
    ['two_factor', /two[- ]?factor|2fa|verification code|authentication code|رمز التحقق|المصادقة الثنائية/],
    ['security_challenge', /security check|challenge required|verify your identity|confirm your identity|تحقق من هويتك/],
    ['waf', /cloudflare|access denied|web application firewall|request blocked|تم حظر الطلب/],
  ];
  for (const [name, re] of patterns) if (re.test(hay)) return name;
  return '';
}

async function safePageSummary(page) {
  let title = '';
  let text = '';
  try { title = await page.title(); } catch {}
  try { text = (await page.locator('body').innerText({ timeout: 2500 })).replace(/\s+/g, ' ').trim().slice(0, MAX_TEXT); } catch {}
  const challenge = await challengeState(page);
  return {
    ok: !challenge,
    url: page.url(),
    title: title.slice(0, 300),
    text_excerpt: text,
    challenge,
    requires_human: Boolean(challenge),
  };
}

async function gotoChecked(page, raw, timeoutMs) {
  const url = await publicUrl(raw);
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
  return await safePageSummary(page);
}

function str(v, max = 2000) { return String(v == null ? '' : v).slice(0, max); }

async function locatorAction(page, selector, fn) {
  selector = str(selector, 500).trim();
  if (!selector) throw new Error('selector_required');
  const loc = page.locator(selector).first();
  await loc.waitFor({ state: 'visible', timeout: 10_000 });
  return await fn(loc);
}

async function execute(body) {
  const operation = str(body.operation, 60);
  const account = body.account && typeof body.account === 'object' ? body.account : null;
  const params = body.params && typeof body.params === 'object' ? body.params : {};
  const limits = body.limits && typeof body.limits === 'object' ? body.limits : {};
  const maxSeconds = Math.max(10, Math.min(90, Number(limits.max_seconds || 90)));
  const timeoutMs = maxSeconds * 1000;
  if (limits.allow_captcha_bypass || limits.allow_2fa_bypass || limits.allow_downloads) throw new Error('unsafe_limit_not_allowed');

  const { page, context } = await sessionFor(account);
  page.setDefaultTimeout(Math.min(15_000, timeoutMs));

  if (operation === 'navigate') {
    const target = params.url || params.target_url || (account && account.login_url);
    return await gotoChecked(page, target, timeoutMs);
  }

  if (operation === 'snapshot') return await safePageSummary(page);

  if (operation === 'login') {
    const target = params.url || (account && account.login_url);
    if (target) {
      const pre = await gotoChecked(page, target, timeoutMs);
      if (pre.requires_human) return pre;
    }
    if (!account) throw new Error('account_required');
    const userValue = str(account.username || account.email, 500);
    const passValue = str(account.password, 1000);
    const userSel = params.username_selector || 'input[type="email"],input[name*="user" i],input[name*="email" i]';
    const passSel = params.password_selector || 'input[type="password"]';
    if (!userValue || !passValue) throw new Error('account_credentials_incomplete');
    await locatorAction(page, userSel, (l) => l.fill(userValue));
    await locatorAction(page, passSel, (l) => l.fill(passValue));
    const c = await challengeState(page); if (c) return { ...(await safePageSummary(page)), challenge: c, requires_human: true };
    if (params.submit_selector) await locatorAction(page, params.submit_selector, (l) => l.click());
    else await page.locator(passSel).first().press('Enter');
    await page.waitForLoadState('domcontentloaded', { timeout: Math.min(15_000, timeoutMs) }).catch(() => {});
    return await safePageSummary(page);
  }

  if (operation === 'click') {
    const c = await challengeState(page); if (c) return { ...(await safePageSummary(page)), challenge: c, requires_human: true };
    await locatorAction(page, params.selector, (l) => l.click());
    await page.waitForTimeout(500);
    return await safePageSummary(page);
  }

  if (operation === 'fill') {
    const c = await challengeState(page); if (c) return { ...(await safePageSummary(page)), challenge: c, requires_human: true };
    await locatorAction(page, params.selector, (l) => l.fill(str(params.value, 5000)));
    return await safePageSummary(page);
  }

  if (operation === 'submit') {
    const c = await challengeState(page); if (c) return { ...(await safePageSummary(page)), challenge: c, requires_human: true };
    if (params.selector) await locatorAction(page, params.selector, (l) => l.click());
    else await page.keyboard.press('Enter');
    await page.waitForTimeout(700);
    return await safePageSummary(page);
  }

  if (operation === 'send_message') {
    const c = await challengeState(page); if (c) return { ...(await safePageSummary(page)), challenge: c, requires_human: true };
    const selector = params.message_selector || params.selector;
    await locatorAction(page, selector, (l) => l.fill(str(params.text, 5000)));
    if (params.send_selector) await locatorAction(page, params.send_selector, (l) => l.click());
    else await page.locator(selector).first().press('Enter');
    await page.waitForTimeout(700);
    return { ...(await safePageSummary(page)), sent: true };
  }

  if (operation === 'create_account') {
    const target = params.url || params.signup_url;
    if (target) {
      const pre = await gotoChecked(page, target, timeoutMs);
      if (pre.requires_human) return pre;
    }
    if (!account) throw new Error('account_required');
    const map = Array.isArray(params.fields) ? params.fields.slice(0, 15) : [];
    for (const field of map) {
      if (!field || typeof field !== 'object') continue;
      let value = field.value;
      if (field.source === 'username') value = account.username;
      if (field.source === 'email') value = account.email;
      if (field.source === 'password') value = account.password;
      await locatorAction(page, field.selector, (l) => l.fill(str(value, 5000)));
    }
    const c = await challengeState(page); if (c) return { ...(await safePageSummary(page)), challenge: c, requires_human: true };
    if (params.submit_selector) await locatorAction(page, params.submit_selector, (l) => l.click());
    await page.waitForTimeout(700);
    return await safePageSummary(page);
  }

  if (operation === 'check_notifications') {
    const c = await challengeState(page); if (c) return { ...(await safePageSummary(page)), challenge: c, requires_human: true };
    if (params.selector) {
      const values = await page.locator(str(params.selector, 500)).allInnerTexts();
      return { ...(await safePageSummary(page)), notifications: values.slice(0, 20).map((x) => str(x, 1000)) };
    }
    return await safePageSummary(page);
  }

  if (operation === 'logout') {
    if (params.selector) await locatorAction(page, params.selector, (l) => l.click()).catch(() => {});
    await context.clearCookies();
    return { ...(await safePageSummary(page)), logged_out: true };
  }

  throw new Error('browser_operation_not_supported');
}

const server = http.createServer(async (req, res) => {
  try {
    if (req.method !== 'POST') return json(res, 405, { ok: false, error: 'method_not_allowed' });
    if (!authOk(req)) return json(res, 401, { ok: false, error: 'unauthorized' });
    const body = await readJson(req);
    if (req.url === '/v1/test') {
      const browser = await getBrowser();
      return json(res, 200, { ok: true, runtime: process.version, browser: `chromium/${browser.version()}` });
    }
    if (req.url === '/v1/execute') {
      const result = await execute(body);
      return json(res, 200, result);
    }
    return json(res, 404, { ok: false, error: 'not_found' });
  } catch (e) {
    const message = str(e && e.message ? e.message : 'bridge_error', 240);
    return json(res, 400, { ok: false, error: message });
  }
});

server.listen(PORT, '0.0.0.0', () => {
  // Never print tokens, credentials or request bodies.
  console.log(`PerseBayt Browser Automation Bridge listening on :${PORT}`);
});

async function shutdown() {
  for (const [, s] of sessions) { try { await s.context.close(); } catch {} }
  sessions.clear();
  if (browserPromise) { try { (await browserPromise).close(); } catch {} }
  process.exit(0);
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
