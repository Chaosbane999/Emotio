import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, statSync } from "node:fs";
import type { AddressInfo } from "node:net";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { after, before, test } from "node:test";
import { createApp } from "../src/server.js";
import { SettingsStore } from "../src/settings.js";

const env = (dir: string) => ({ PUBLIC_BASE_URL: "http://localhost", DATA_DIR: dir });
const freshDir = () => mkdtempSync(join(tmpdir(), "voice-admin-"));

test("settings store: saves, keeps blank secrets, and writes a private file", () => {
  const dir = freshDir();
  const store = new SettingsStore(env(dir));
  store.update({ TWILIO_ACCOUNT_SID: "AC123", TWILIO_AUTH_TOKEN: "tok-secret-9876", OPENAI_API_KEY: "sk-abcd" });
  store.update({ TWILIO_ACCOUNT_SID: "AC456", TWILIO_AUTH_TOKEN: "" }); // blank secret = keep

  const reopened = new SettingsStore(env(dir));
  assert.equal(reopened.config().TWILIO_ACCOUNT_SID, "AC456");
  assert.equal(reopened.config().TWILIO_AUTH_TOKEN, "tok-secret-9876");
  assert.equal(reopened.formValues().TWILIO_AUTH_TOKEN.value, "", "secret never sent to the form");
  assert.match(reopened.formValues().TWILIO_AUTH_TOKEN.hint, /ends in 9876/);
  assert.equal(statSync(join(dir, "settings.json")).mode & 0o777, 0o600);
});

test("settings store: rejects invalid values without saving", () => {
  const store = new SettingsStore(env(freshDir()));
  assert.throws(() => store.update({ VOICE_ENGINE: "skype" }));
  assert.equal(store.config().VOICE_ENGINE, "openai");
});

test("settings store: admin password is hashed, and ADMIN_PASSWORD presets it", () => {
  const dir = freshDir();
  const store = new SettingsStore({ ...env(dir), ADMIN_PASSWORD: "correct horse battery" });
  assert.equal(store.setupCode, null);
  assert.ok(store.checkPassword("correct horse battery"));
  assert.ok(!store.checkPassword("wrong"));
  assert.doesNotMatch(readFileSync(join(dir, "settings.json"), "utf8"), /correct horse/);
});

// --- HTTP flow --------------------------------------------------------------

let base = "";
let store: SettingsStore;
let close: () => void;

before(async () => {
  store = new SettingsStore(env(freshDir()));
  const server = createApp(store).listen(0);
  await new Promise((r) => server.once("listening", r));
  base = `http://localhost:${(server.address() as AddressInfo).port}`;
  close = () => server.close();
});
after(() => close());

const post = (path: string, body: Record<string, string>, headers: Record<string, string> = {}) =>
  fetch(base + path, {
    method: "POST",
    redirect: "manual",
    headers: { "content-type": "application/x-www-form-urlencoded", ...headers },
    body: new URLSearchParams(body),
  });

test("admin: first run asks for the setup code and rejects a wrong one", async () => {
  assert.match(await (await fetch(`${base}/admin`)).text(), /Setup code/);
  const res = await post("/admin/setup", { code: "WRONG", password: "longenough1", confirm: "longenough1" });
  assert.equal(res.status, 403);
  assert.ok(!store.hasAdmin());
});

test("admin: setup, save keys, generate API key, and block cross-site posts", async () => {
  const res = await post("/admin/setup", { code: store.setupCode!, password: "longenough1", confirm: "longenough1" });
  assert.equal(res.status, 303);
  const cookie = res.headers.get("set-cookie")!.split(";")[0];
  assert.match(res.headers.get("set-cookie")!, /HttpOnly/i);

  const page = await (await fetch(`${base}/admin`, { headers: { cookie } })).text();
  assert.match(page, /Voice agent settings/);
  assert.match(page, /Still needed: Twilio Account SID/);

  const saved = await post(
    "/admin/settings",
    { TWILIO_ACCOUNT_SID: "AC1", TWILIO_AUTH_TOKEN: "tok", TWILIO_PHONE_NUMBER: "+447700900123", OPENAI_API_KEY: "sk-1", generate_api_key: "on" },
    { cookie },
  );
  const html = await saved.text();
  assert.equal(saved.status, 200);
  assert.match(html, /Ready to make and take calls/);
  assert.equal(store.config().OPENAI_API_KEY, "sk-1");
  assert.ok(store.config().API_KEY.length >= 30);
  assert.ok(html.includes(store.config().API_KEY), "new key shown once");
  assert.ok(!html.includes('value="sk-1"'), "secrets not echoed into inputs");

  const blocked = await post("/admin/settings", { AGENT_NAME: "Evil" }, { cookie, origin: "https://evil.example" });
  assert.equal(blocked.status, 403);
  assert.notEqual(store.config().AGENT_NAME, "Evil");
});

test("admin: settings can't be changed without signing in", async () => {
  const res = await post("/admin/settings", { AGENT_NAME: "Nope" });
  assert.equal(res.status, 303);
  assert.notEqual(store.config().AGENT_NAME, "Nope");
  const forged = await post("/admin/settings", { AGENT_NAME: "Nope" }, { cookie: `voice_admin=${Date.now() + 1e6}.deadbeef` });
  assert.equal(forged.status, 303);
});

test("admin: login with the right password only", async () => {
  assert.equal((await post("/admin/login", { password: "nottheone" })).status, 403);
  const ok = await post("/admin/login", { password: "longenough1" });
  assert.equal(ok.status, 303);
  assert.ok(ok.headers.get("set-cookie"));
});

test("/calls uses the key saved in admin", async () => {
  const res = await fetch(`${base}/calls`, {
    method: "POST",
    headers: { "content-type": "application/json", authorization: "Bearer wrong" },
    body: JSON.stringify({ to: "+447700900123" }),
  });
  assert.equal(res.status, 401);
});
