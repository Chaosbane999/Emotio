import twilio from "twilio";
import type { Config } from "./config.js";
import { callTwiml } from "./twiml.js";

const E164 = /^\+[1-9]\d{6,14}$/;

export function isE164(number: string): boolean {
  return E164.test(number);
}

/** Place an outbound call; the agent speaks first once it's answered. */
export async function placeCall(config: Config, to: string, purpose?: string) {
  if (!isE164(to)) throw new Error(`"${to}" is not an E.164 phone number (e.g. +447700900123)`);
  const client = twilio(config.TWILIO_ACCOUNT_SID, config.TWILIO_AUTH_TOKEN);
  return client.calls.create({
    to,
    from: config.TWILIO_PHONE_NUMBER,
    twiml: callTwiml(config, { direction: "outbound", from: config.TWILIO_PHONE_NUMBER, to, purpose }),
    statusCallback: `${config.PUBLIC_BASE_URL}/voice/status`,
    statusCallbackEvent: ["initiated", "ringing", "answered", "completed"],
  });
}
