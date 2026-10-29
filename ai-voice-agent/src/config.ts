import "dotenv/config";
import { z } from "zod";

const Env = z
  .object({
    // "openai": speech-to-speech with OpenAI Realtime (the ChatGPT-style voice).
    // "claude": Claude for the conversation + ElevenLabs voice via ConversationRelay.
    VOICE_ENGINE: z.enum(["openai", "claude"]).default("openai"),

    OPENAI_API_KEY: z.string().optional(),
    OPENAI_REALTIME_MODEL: z.string().default("gpt-realtime-2.1"),
    // marin (female) and cedar (male) are OpenAI's recommended, most natural voices
    OPENAI_VOICE: z.string().default("marin"),
    OPENAI_VOICE_SPEED: z.coerce.number().min(0.25).max(1.5).default(1.0),
    OPENAI_REASONING_EFFORT: z.enum(["", "minimal", "low", "medium", "high", "xhigh"]).default("low"),
    // semantic_vad waits for the caller to finish their thought; server_vad reacts to silence only
    OPENAI_TURN_DETECTION: z.enum(["semantic_vad", "server_vad"]).default("semantic_vad"),

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
    // ElevenLabs voice ID. Default: "Alice", a friendly British female voice.
    TTS_VOICE: z.string().default("Xb7hH8MSUJpSbSDYk0k2"),
    // ElevenLabs model: flash_v2_5 (fastest), turbo_v2_5 (a bit richer, slightly slower)
    TTS_MODEL: z.string().default("flash_v2_5"),
    // Speed 0.7-1.2; stability/similarity 0.0-1.0 (lower stability = more expressive)
    TTS_SPEED: z.coerce.number().min(0.7).max(1.2).default(1.0),
    TTS_STABILITY: z.coerce.number().min(0).max(1).default(0.45),
    TTS_SIMILARITY: z.coerce.number().min(0).max(1).default(0.8),
    LANGUAGE: z.string().default("en-GB"),
  })
  .superRefine((env, ctx) => {
    if (env.VOICE_ENGINE === "openai" && !env.OPENAI_API_KEY) {
      ctx.addIssue({ code: "custom", path: ["OPENAI_API_KEY"], message: "required when VOICE_ENGINE=openai" });
    }
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

/**
 * ConversationRelay `voice` attribute. ElevenLabs takes
 * "<voiceId>-<model>-<speed>_<stability>_<similarity>"; other providers take the voice name as-is.
 */
export function ttsVoice(config: Config): string | undefined {
  if (!config.TTS_VOICE) return undefined;
  if (config.TTS_PROVIDER.toLowerCase() !== "elevenlabs") return config.TTS_VOICE;
  const settings = [config.TTS_SPEED, config.TTS_STABILITY, config.TTS_SIMILARITY].map(formatSetting).join("_");
  return [config.TTS_VOICE, config.TTS_MODEL, settings].filter(Boolean).join("-");
}

/** 1 -> "1.0", 0.45 -> "0.45", 0.8 -> "0.8" (the format Twilio's docs use). */
function formatSetting(n: number): string {
  const s = n.toFixed(2).replace(/0+$/, "");
  return s.endsWith(".") ? s + "0" : s;
}

/** wss:// URL Twilio Media Streams connect to (OpenAI engine). */
export function mediaStreamUrl(config: Config): string {
  return config.PUBLIC_BASE_URL.replace(/^http/, "ws") + "/media-stream";
}
