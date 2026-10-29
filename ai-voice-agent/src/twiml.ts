import twilio from "twilio";
import { relayUrl, type Config } from "./config.js";

export interface CallContext {
  direction: "inbound" | "outbound";
  /** Why we are calling (outbound) — passed through to the agent's system prompt. */
  purpose?: string;
}

/**
 * TwiML that hands the call to ConversationRelay: Twilio does speech-to-text and
 * text-to-speech, and streams the conversation over a WebSocket to /relay.
 */
export function conversationRelayTwiml(config: Config, ctx: CallContext): string {
  const response = new twilio.twiml.VoiceResponse();
  const connect = response.connect({
    action: `${config.PUBLIC_BASE_URL}/voice/ended`,
  });
  const relay = connect.conversationRelay({
    url: relayUrl(config),
    welcomeGreeting: ctx.direction === "inbound" ? config.AGENT_GREETING : undefined,
    welcomeGreetingInterruptible: "any",
    interruptible: "any",
    language: config.LANGUAGE,
    ttsProvider: config.TTS_PROVIDER,
    ...(config.TTS_VOICE ? { voice: config.TTS_VOICE } : {}),
  });
  relay.parameter({ name: "direction", value: ctx.direction });
  if (ctx.purpose) relay.parameter({ name: "purpose", value: ctx.purpose });
  return response.toString();
}
