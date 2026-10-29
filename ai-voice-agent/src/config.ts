import "dotenv/config";
import { z } from "zod";

const Env = z.object({
  ANTHROPIC_API_KEY: z.string().optional(),
  CLAUDE_MODEL: z.string().default("claude-opus-5-5"),
  CLAUDE_EFFORT: z.enum(["low", "medium", "high", "xhigh", "max"]).default("low"),

  TWILIO_ACCOUNT_SID: z.string().min(1),
  TWILIO_AUTH_TOKEN: z.string().min(1),
  TWILIO_PHONE_NUMBER: z.string().min(1),

  PUBLIC_BASE_URL: z.url().transform((u) => u.replace(/\/+$/, "")),
  PORT: z.coerce.number().default(8080),

  API_KEY: z.string().min(8),
  VALIDATE_TWILIO_SIGNATURE: z
    .enum(["true", "false"])
    .default("true")
    .transform((v) => v === "true"),

  AGENT_NAME: z.string().default("Ava"),
  AGENT_GREETING: z.string().default("Hi, this is Ava, an AI assistant. How can I help you today?"),
  AGENT_INSTRUCTIONS: z.string().default(""),
  TTS_PROVIDER: z.string().default("ElevenLabs"),
  TTS_VOICE: z.string().default(""),
  LANGUAGE: z.string().default("en-US"),
});

export type Config = z.infer<typeof Env>;

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const parsed = Env.safeParse(env);
  if (!parsed.success) {
    const issues = parsed.error.issues.map((i) => `  ${i.path.join(".")}: ${i.message}`).join("\n");
    throw new Error(`Invalid configuration (see .env.example):\n${issues}`);
  }
  return parsed.data;
}

/** wss:// URL Twilio ConversationRelay connects to. */
export function relayUrl(config: Config): string {
  return config.PUBLIC_BASE_URL.replace(/^http/, "ws") + "/relay";
}
