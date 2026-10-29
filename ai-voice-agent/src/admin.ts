import { createHmac, randomBytes, timingSafeEqual } from "node:crypto";
import express, { type NextFunction, type Request, type Response, type Router } from "express";
import { placeCall } from "./calls.js";
import { missingSettings } from "./config.js";
import type { SettingsStore } from "./settings.js";

const COOKIE = "voice_admin";
const SESSION_MS = 12 * 60 * 60 * 1000;
const MAX_FAILURES = 10;
const LOCKOUT_MS = 15 * 60 * 1000;
const OPENAI_VOICES = ["marin", "cedar", "alloy", "ash", "ballad", "coral", "echo", "sage", "shimmer", "verse"];

/** /admin: first-run setup, login, and the settings form. */
export function adminRouter(store: SettingsStore): Router {
  const router = express.Router();
  const failures = new Map<string, { count: number; until: number }>();

  const sign = (expires: number) => createHmac("sha256", store.sessionSecret).update(String(expires)).digest("hex");

  const isLoggedIn = (req: Request): boolean => {
    const token = readCookie(req, COOKIE);
    const [expires, mac] = token?.split(".") ?? [];
    if (!expires || !mac || Number(expires) < Date.now()) return false;
    const expected = Buffer.from(sign(Number(expires)));
    const actual = Buffer.from(mac);
    return actual.length === expected.length && timingSafeEqual(actual, expected);
  };

  const startSession = (res: Response) => {
    const expires = Date.now() + SESSION_MS;
    const secure = store.config().PUBLIC_BASE_URL.startsWith("https:");
    res.cookie(COOKIE, `${expires}.${sign(expires)}`, {
      httpOnly: true,
      sameSite: "strict",
      secure,
      maxAge: SESSION_MS,
      path: "/admin",
    });
  };

  // Forms only ever post from this site; reject cross-site posts.
  const sameOrigin = (req: Request, res: Response, next: NextFunction) => {
    const origin = req.header("origin");
    if (origin && new URL(origin).host !== req.header("host")) return res.status(403).send("Cross-site request blocked");
    next();
  };

  const requireLogin = (req: Request, res: Response, next: NextFunction) => {
    if (!isLoggedIn(req)) return res.redirect(303, "/admin");
    next();
  };

  const throttled = (req: Request) => {
    const f = failures.get(req.ip ?? "");
    return Boolean(f && f.count >= MAX_FAILURES && f.until > Date.now());
  };
  const recordFailure = (req: Request) => {
    const key = req.ip ?? "";
    const f = failures.get(key);
    const count = f && f.until > Date.now() ? f.count + 1 : 1;
    failures.set(key, { count, until: Date.now() + LOCKOUT_MS });
  };

  router.use(express.urlencoded({ extended: false }));
  router.use((_req, res, next) => {
    res.set({
      "Cache-Control": "no-store",
      "X-Frame-Options": "DENY",
      "Content-Security-Policy": "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'",
    });
    next();
  });

  router.get("/", (req, res) => {
    if (!store.hasAdmin()) return res.send(setupPage());
    if (!isLoggedIn(req)) return res.send(loginPage());
    res.send(settingsPage(store));
  });

  router.post("/setup", sameOrigin, (req, res) => {
    if (store.hasAdmin()) return res.redirect(303, "/admin");
    if (throttled(req)) return res.status(429).send(setupPage("Too many attempts. Try again in 15 minutes."));
    const { code, password, confirm } = req.body as Record<string, string>;
    if (!store.setupCode || !safeEqual((code ?? "").trim().toUpperCase(), store.setupCode)) {
      recordFailure(req);
      return res.status(403).send(setupPage("That setup code isn't right. It's in the server logs."));
    }
    if ((password ?? "").length < 10) return res.status(400).send(setupPage("Use a password of at least 10 characters."));
    if (password !== confirm) return res.status(400).send(setupPage("The passwords don't match."));
    store.setAdminPassword(password);
    startSession(res);
    res.redirect(303, "/admin");
  });

  router.post("/login", sameOrigin, (req, res) => {
    if (throttled(req)) return res.status(429).send(loginPage("Too many attempts. Try again in 15 minutes."));
    if (!store.checkPassword((req.body as Record<string, string>).password ?? "")) {
      recordFailure(req);
      return res.status(403).send(loginPage("Wrong password."));
    }
    failures.delete(req.ip ?? "");
    startSession(res);
    res.redirect(303, "/admin");
  });

  router.post("/logout", sameOrigin, (_req, res) => {
    res.clearCookie(COOKIE, { path: "/admin" });
    res.redirect(303, "/admin");
  });

  router.post("/settings", sameOrigin, requireLogin, (req, res) => {
    const input = { ...(req.body as Record<string, string>) };
    let newApiKey: string | null = null;
    if (input.generate_api_key === "on") {
      newApiKey = randomBytes(24).toString("base64url");
      input.API_KEY = newApiKey;
    }
    try {
      store.update(input);
    } catch (err) {
      return res.status(400).send(settingsPage(store, { error: errorText(err) }));
    }
    const notice = newApiKey
      ? `Saved. Your new outbound-calling API key is shown once below. Copy it somewhere safe.`
      : "Saved.";
    res.send(settingsPage(store, { notice, newApiKey }));
  });

  router.post("/test-call", sameOrigin, requireLogin, async (req, res) => {
    const { to, purpose } = req.body as Record<string, string>;
    const config = store.config();
    const missing = missingSettings(config);
    if (missing.length) return res.status(400).send(settingsPage(store, { error: `Fill in first: ${missing.join(", ")}.` }));
    try {
      const call = await placeCall(config, (to ?? "").trim(), purpose?.trim() || undefined);
      res.send(settingsPage(store, { notice: `Calling ${to}… (call SID ${call.sid})` }));
    } catch (err) {
      res.status(400).send(settingsPage(store, { error: errorText(err) }));
    }
  });

  return router;
}

function readCookie(req: Request, name: string): string | undefined {
  for (const part of (req.header("cookie") ?? "").split(";")) {
    const [k, ...v] = part.trim().split("=");
    if (k === name) return decodeURIComponent(v.join("="));
  }
  return undefined;
}

function safeEqual(a: string, b: string): boolean {
  const x = Buffer.from(a);
  const y = Buffer.from(b);
  return x.length === y.length && timingSafeEqual(x, y);
}

function errorText(err: unknown): string {
  return err instanceof Error ? err.message.replace(/\(see \.env\.example\)/, "") : String(err);
}

// ---------------------------------------------------------------------------
// Pages

const esc = (s: string) =>
  s.replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]!);

function layout(title: string, body: string): string {
  return `<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>${esc(title)} · Voice agent</title>
<style>
  :root { --bg:#f6f7f9; --card:#fff; --text:#16181d; --muted:#5d6470; --line:#dde1e7; --accent:#2f5bea; --ok:#127a3d; --warn:#a24b00; --err:#b3261e; }
  @media (prefers-color-scheme: dark) { :root { --bg:#111317; --card:#1a1d23; --text:#eceef2; --muted:#9aa3b0; --line:#2c313a; --accent:#7d9bff; --ok:#5fd08f; --warn:#f0a35e; --err:#ff8a80; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  main { max-width:720px; margin:0 auto; padding:32px 16px 64px; }
  h1 { font-size:1.5rem; margin:0 0 4px; } h2 { font-size:1.05rem; margin:0 0 12px; }
  p.sub { color:var(--muted); margin:0 0 24px; }
  section { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:16px; }
  label { display:block; font-weight:600; font-size:.9rem; margin:14px 0 4px; } label:first-of-type { margin-top:0; }
  .hint { font-weight:400; color:var(--muted); font-size:.85rem; }
  input[type=text], input[type=password], input[type=tel], select, textarea { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--text); font:inherit; }
  textarea { min-height:96px; resize:vertical; }
  .check { display:flex; gap:8px; align-items:center; font-weight:400; margin-top:10px; }
  button { background:var(--accent); color:#fff; border:0; border-radius:8px; padding:10px 18px; font:inherit; font-weight:600; cursor:pointer; }
  button.secondary { background:transparent; color:var(--muted); border:1px solid var(--line); }
  .row { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
  .msg { padding:12px 14px; border-radius:8px; margin-bottom:16px; border:1px solid; }
  .msg.ok { color:var(--ok); border-color:var(--ok); } .msg.err { color:var(--err); border-color:var(--err); } .msg.warn { color:var(--warn); border-color:var(--warn); }
  code, .key { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:.9rem; word-break:break-all; }
  .key { display:block; padding:10px 12px; background:var(--bg); border:1px dashed var(--line); border-radius:8px; margin-top:8px; user-select:all; }
</style>
</head>
<body><main>${body}</main></body>
</html>`;
}

function message(kind: "ok" | "err" | "warn", text?: string | null): string {
  return text ? `<div class="msg ${kind}" role="${kind === "err" ? "alert" : "status"}">${esc(text)}</div>` : "";
}

function setupPage(error?: string): string {
  return layout(
    "Set up",
    `<h1>Set up your voice agent</h1>
<p class="sub">Create the admin password. The one-time setup code is printed in the server logs (Hostinger Docker Manager → Logs).</p>
${message("err", error)}
<section><form method="post" action="/admin/setup">
  <label for="code">Setup code</label><input id="code" name="code" type="text" autocomplete="one-time-code" required>
  <label for="password">New admin password <span class="hint">(at least 10 characters)</span></label>
  <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required>
  <label for="confirm">Confirm password</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" required>
  <p><button type="submit">Create admin account</button></p>
</form></section>`,
  );
}

function loginPage(error?: string): string {
  return layout(
    "Sign in",
    `<h1>Voice agent admin</h1><p class="sub">Sign in to manage keys and settings.</p>
${message("err", error)}
<section><form method="post" action="/admin/login">
  <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
  <p><button type="submit">Sign in</button></p>
</form></section>`,
  );
}

function settingsPage(
  store: SettingsStore,
  opts: { notice?: string; error?: string; newApiKey?: string | null } = {},
): string {
  const config = store.config();
  const v = store.formValues();
  const missing = missingSettings(config);
  const webhook = `${config.PUBLIC_BASE_URL}/voice/inbound`;

  const text = (key: keyof typeof v, label: string, hint = "", type = "text", placeholder = "") =>
    `<label for="${key}">${label}${hint ? ` <span class="hint">${esc(hint)}</span>` : ""}</label>
     <input id="${key}" name="${key}" type="${type}" value="${esc(v[key].value)}" placeholder="${esc(placeholder)}" autocomplete="off">`;
  const secret = (key: keyof typeof v, label: string) =>
    `<label for="${key}">${label} <span class="hint">${esc(v[key].hint)}. Leave blank to keep.</span></label>
     <input id="${key}" name="${key}" type="password" autocomplete="new-password" spellcheck="false">`;
  const option = (value: string, current: string, label = value) =>
    `<option value="${esc(value)}"${value === current ? " selected" : ""}>${esc(label)}</option>`;

  const status = missing.length
    ? message("warn", `Not ready for calls yet. Still needed: ${missing.join(", ")}.`)
    : message("ok", "Ready to make and take calls.");

  return layout(
    "Settings",
    `<div class="row"><div><h1>Voice agent settings</h1><p class="sub">${esc(config.PUBLIC_BASE_URL)}</p></div>
<form method="post" action="/admin/logout"><button class="secondary" type="submit">Sign out</button></form></div>
${message("ok", opts.notice)}${message("err", opts.error)}
${opts.newApiKey ? `<section><h2>New outbound-calling API key</h2><span class="key">${esc(opts.newApiKey)}</span></section>` : ""}
${status}
<section><h2>Twilio webhook</h2>
<p class="sub" style="margin:0">In Twilio, set your number's <b>A call comes in</b> to Webhook, HTTP POST:</p>
<span class="key">${esc(webhook)}</span></section>

<form method="post" action="/admin/settings">
<section><h2>Phone line (Twilio)</h2>
  ${text("TWILIO_ACCOUNT_SID", "Account SID", "", "text", "AC…")}
  ${secret("TWILIO_AUTH_TOKEN", "Auth Token")}
  ${text("TWILIO_PHONE_NUMBER", "Twilio phone number", "(international format)", "tel", "+447700900123")}
</section>

<section><h2>AI voice</h2>
  <label for="VOICE_ENGINE">Engine</label>
  <select id="VOICE_ENGINE" name="VOICE_ENGINE">
    ${option("openai", v.VOICE_ENGINE.value, "OpenAI speech-to-speech (most natural, best interruptions)")}
    ${option("claude", v.VOICE_ENGINE.value, "Claude + ElevenLabs voice")}
  </select>
  ${secret("OPENAI_API_KEY", "OpenAI API key")}
  <label for="OPENAI_VOICE">OpenAI voice <span class="hint">marin and cedar sound the most natural</span></label>
  <select id="OPENAI_VOICE" name="OPENAI_VOICE">${OPENAI_VOICES.map((x) => option(x, v.OPENAI_VOICE.value)).join("")}</select>
  ${secret("ANTHROPIC_API_KEY", "Anthropic API key (Claude engine only)")}
</section>

<section><h2>Agent</h2>
  ${text("AGENT_NAME", "Name")}
  ${text("AGENT_GREETING", "Greeting", "(first thing said on incoming calls)")}
  <label for="AGENT_INSTRUCTIONS">Instructions <span class="hint">business details, what to help with, what to collect</span></label>
  <textarea id="AGENT_INSTRUCTIONS" name="AGENT_INSTRUCTIONS">${esc(v.AGENT_INSTRUCTIONS.value)}</textarea>
</section>

<section><h2>Outbound calling API</h2>
  ${secret("API_KEY", "API key for POST /calls")}
  <label class="check"><input type="checkbox" name="generate_api_key"> Generate a new random key (shown once)</label>
</section>
<p><button type="submit">Save settings</button></p>
</form>

<section><h2>Test call</h2>
<form method="post" action="/admin/test-call">
  <label for="to">Number to call</label><input id="to" name="to" type="tel" placeholder="+447700900123" required>
  <label for="purpose">Purpose <span class="hint">(optional)</span></label><input id="purpose" name="purpose" type="text" placeholder="Say hello and check the line works">
  <p><button type="submit">Call now</button></p>
</form></section>`,
  );
}
