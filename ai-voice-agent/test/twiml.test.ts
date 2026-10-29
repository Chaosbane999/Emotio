import assert from "node:assert/strict";
import { test } from "node:test";
import { isE164 } from "../src/calls.js";
import { loadConfig, missingSettings, relayUrl, ttsVoice } from "../src/config.js";
import { systemPrompt } from "../src/prompt.js";
import { callTwiml, conversationRelayTwiml } from "../src/twiml.js";
import { config } from "./helpers.js";

test("relay URL is wss and trailing slash is stripped", () => {
  assert.equal(relayUrl(config), "wss://agent.example.com/relay");
});

test("inbound TwiML greets and connects to the relay", () => {
  const xml = conversationRelayTwiml(config, { direction: "inbound" });
  assert.match(xml, /<ConversationRelay [^>]*url="wss:\/\/agent\.example\.com\/relay"/);
  assert.match(xml, /welcomeGreeting="Hi, this is Ava/);
  assert.match(xml, /<Parameter name="direction" value="inbound"\/>/);
});

test("ElevenLabs voice includes model and voice settings", () => {
  const xml = conversationRelayTwiml(config, { direction: "inbound" });
  assert.match(xml, /voice="Xb7hH8MSUJpSbSDYk0k2-flash_v2_5-1\.0_0\.45_0\.8"/);
  assert.equal(ttsVoice({ ...config, TTS_PROVIDER: "Google", TTS_VOICE: "en-US-Journey-O" }), "en-US-Journey-O");
});

test("outbound TwiML has no greeting and carries the purpose", () => {
  const xml = conversationRelayTwiml(config, { direction: "outbound", purpose: "Confirm <appointment>" });
  assert.doesNotMatch(xml, /welcomeGreeting=/);
  assert.match(xml, /<Parameter name="purpose" value="Confirm &lt;appointment>"\/>/);
});

test("system prompt reflects call direction and purpose", () => {
  const out = systemPrompt(config, { direction: "outbound", from: "+1", to: "+15555550123", purpose: "Say hi" });
  assert.match(out, /outbound call to \+15555550123/);
  assert.match(out, /Purpose of the call: Say hi/);
  assert.match(out, /British English/);
  assert.doesNotMatch(out, /\n{3,}/);
});

test("E.164 validation", () => {
  assert.ok(isE164("+447700900123"));
  assert.ok(!isE164("07700900123"));
  assert.ok(!isE164("5555550123"));
  assert.ok(!isE164("+0123456789"));
});

test("OpenAI engine connects the call audio to /media-stream", () => {
  const xml = callTwiml(config, { direction: "inbound", from: "+447700900001", to: "+447700900002" });
  assert.match(xml, /<Connect action="https:\/\/agent\.example\.com\/voice\/ended"><Stream url="wss:\/\/agent\.example\.com\/media-stream">/);
  assert.match(xml, /<Parameter name="from" value="\+447700900001"\/>/);
});

test("Claude engine uses ConversationRelay", () => {
  assert.match(callTwiml({ ...config, VOICE_ENGINE: "claude" }, { direction: "inbound" }), /<ConversationRelay /);
});

test("missing settings are reported for the chosen engine", () => {
  assert.deepEqual(missingSettings(config), []);
  const bare = loadConfig({ PUBLIC_BASE_URL: "https://x.example.com" });
  assert.deepEqual(missingSettings(bare), ["Twilio Account SID", "Twilio Auth Token", "Twilio phone number", "OpenAI API key"]);
  assert.deepEqual(missingSettings({ ...bare, VOICE_ENGINE: "claude" }).at(-1), "Anthropic API key");
});
