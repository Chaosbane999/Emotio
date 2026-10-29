import twilio from "twilio";
import { mediaStreamUrl, relayUrl, ttsVoice, type Config } from "./config.js";

export interface CallContext {
  direction: "inbound" | "outbound";
  from?: string;
  to?: string;
  /** Why we are calling (outbound) — passed through to the agent's system prompt. */
  purpose?: string;
}

/** TwiML that connects a call to whichever voice engine is configured. */
export function callTwiml(config: Config, ctx: CallContext): string {
  return config.VOICE_ENGINE === "openai" ? mediaStreamTwiml(config, ctx) : conversationRelayTwiml(config, ctx);
}

/**
 * OpenAI engine: a bidirectional Media Stream sends the raw call audio to
 * /media-stream, where it is bridged to OpenAI Realtime (speech-to-speech).
 */
export function mediaStreamTwiml(config: Config, ctx: CallContext): string {
  const response = new twilio.twiml.VoiceResponse();
  const connect = response.connect({ action: `${config.PUBLIC_BASE_URL}/voice/ended` });
  const stream = connect.stream({ url: mediaStreamUrl(config) });
  addParameters(stream, ctx);
  return response.toString();
}

/**
 * Claude engine: ConversationRelay does speech-to-text and text-to-speech and
 * streams the conversation as text over a WebSocket to /relay.
 */
export function conversationRelayTwiml(config: Config, ctx: CallContext): string {
  const response = new twilio.twiml.VoiceResponse();
  const connect = response.connect({ action: `${config.PUBLIC_BASE_URL}/voice/ended` });
  const voice = ttsVoice(config);
  const relay = connect.conversationRelay({
    url: relayUrl(config),
    welcomeGreeting: ctx.direction === "inbound" ? config.AGENT_GREETING : undefined,
    welcomeGreetingInterruptible: "any",
    interruptible: "any",
    language: config.LANGUAGE,
    ttsProvider: config.TTS_PROVIDER,
    ...(voice ? { voice } : {}),
  });
  addParameters(relay, ctx);
  return response.toString();
}

function addParameters(target: { parameter(attrs: { name: string; value: string }): unknown }, ctx: CallContext) {
  target.parameter({ name: "direction", value: ctx.direction });
  if (ctx.from) target.parameter({ name: "from", value: ctx.from });
  if (ctx.to) target.parameter({ name: "to", value: ctx.to });
  if (ctx.purpose) target.parameter({ name: "purpose", value: ctx.purpose });
}
