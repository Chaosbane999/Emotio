import { randomBytes, scryptSync, timingSafeEqual } from "node:crypto";
import { chmodSync, existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { loadConfig, type Config } from "./config.js";

/** Settings the admin page can change. `secret` values are never sent back to the browser. */
export const EDITABLE = {
  TWILIO_ACCOUNT_SID: { secret: false },
  TWILIO_AUTH_TOKEN: { secret: true },
  TWILIO_PHONE_NUMBER: { secret: false },
  OPENAI_API_KEY: { secret: true },
  ANTHROPIC_API_KEY: { secret: true },
  API_KEY: { secret: true },
  VOICE_ENGINE: { secret: false },
  OPENAI_VOICE: { secret: false },
  AGENT_NAME: { secret: false },
  AGENT_GREETING: { secret: false },
  AGENT_INSTRUCTIONS: { secret: false },
} as const;

export type EditableKey = keyof typeof EDITABLE;

interface SettingsFile {
  values: Partial<Record<EditableKey, string>>;
  adminPasswordHash?: string;
  sessionSecret: string;
}

/**
 * Runtime settings, saved as JSON (mode 0600) in DATA_DIR. Saved values override
 * env vars, so the deployment itself needs no secrets.
 */
export class SettingsStore {
  private readonly file: string;
  private data: SettingsFile;
  private cached: Config | null = null;
  /** One-time code shown in the server logs for creating the admin password. */
  readonly setupCode: string | null;

  constructor(private readonly env: NodeJS.ProcessEnv = process.env) {
    const dir = loadConfig(env).DATA_DIR;
    mkdirSync(dir, { recursive: true });
    this.file = join(dir, "settings.json");
    this.data = existsSync(this.file)
      ? JSON.parse(readFileSync(this.file, "utf8"))
      : { values: {}, sessionSecret: randomBytes(32).toString("hex") };

    const presetPassword = loadConfig(env).ADMIN_PASSWORD;
    if (!this.data.adminPasswordHash && presetPassword) this.data.adminPasswordHash = hashPassword(presetPassword);
    this.write();

    this.setupCode = this.hasAdmin() ? null : randomBytes(4).toString("hex").toUpperCase();
  }

  config(): Config {
    this.cached ??= loadConfig({ ...this.env, ...this.data.values });
    return this.cached;
  }

  /** Current values for the admin form, with secrets replaced by a hint. */
  formValues(): Record<EditableKey, { value: string; hint: string }> {
    const config = this.config();
    const out = {} as Record<EditableKey, { value: string; hint: string }>;
    for (const key of Object.keys(EDITABLE) as EditableKey[]) {
      const value = String(config[key] ?? "");
      out[key] = EDITABLE[key].secret
        ? { value: "", hint: value ? `Saved (ends in ${value.slice(-4)})` : "Not set" }
        : { value, hint: "" };
    }
    return out;
  }

  /**
   * Save admin form values. A blank secret keeps the saved one. Values are checked
   * against the config schema before anything is written.
   */
  update(input: Partial<Record<string, string>>): void {
    const next = { ...this.data.values };
    for (const key of Object.keys(EDITABLE) as EditableKey[]) {
      const raw = input[key];
      if (raw === undefined) continue;
      const value = raw.trim();
      if (EDITABLE[key].secret && value === "") continue;
      next[key] = value;
    }
    loadConfig({ ...this.env, ...next }); // throws a readable error if invalid
    this.data.values = next;
    this.cached = null;
    this.write();
  }

  hasAdmin(): boolean {
    return Boolean(this.data.adminPasswordHash);
  }

  setAdminPassword(password: string): void {
    this.data.adminPasswordHash = hashPassword(password);
    this.write();
  }

  checkPassword(password: string): boolean {
    return Boolean(this.data.adminPasswordHash) && verifyPassword(password, this.data.adminPasswordHash!);
  }

  get sessionSecret(): string {
    return this.data.sessionSecret;
  }

  private write(): void {
    const tmp = `${this.file}.tmp`;
    writeFileSync(tmp, JSON.stringify(this.data, null, 2), { mode: 0o600 });
    renameSync(tmp, this.file);
    chmodSync(this.file, 0o600);
  }
}

function hashPassword(password: string): string {
  const salt = randomBytes(16);
  return `scrypt:${salt.toString("hex")}:${scryptSync(password, salt, 32).toString("hex")}`;
}

function verifyPassword(password: string, stored: string): boolean {
  const [scheme, salt, hash] = stored.split(":");
  if (scheme !== "scrypt" || !salt || !hash) return false;
  const expected = Buffer.from(hash, "hex");
  const actual = scryptSync(password, Buffer.from(salt, "hex"), expected.length);
  return timingSafeEqual(actual, expected);
}
