import assert from "node:assert/strict";
import { test } from "node:test";
import type { RealtimeClientEvent, RealtimeServerEvent } from "openai/resources/realtime/realtime";
import { handleMediaStream, sessionConfig, type RealtimeConnection } from "../src/realtime.js";
import { config, FakeSocket, tick } from "./helpers.js";

class FakeRealtime implements RealtimeConnection {
  sent: RealtimeClientEvent[] = [];
  closed = false;
  private handlers: { open?: () => void; event?: (e: RealtimeServerEvent) => void; close?: () => void } = {};
  send(e: RealtimeClientEvent) {
    this.sent.push(e);
  }
  onOpen(fn: () => void) {
    this.handlers.open = fn;
  }
  onEvent(fn: (e: RealtimeServerEvent) => void) {
    this.handlers.event = fn;
  }
  onClose(fn: () => void) {
    this.handlers.close = fn;
  }
  close() {
    this.closed = true;
  }
  open() {
    this.handlers.open?.();
  }
  emit(e: Partial<RealtimeServerEvent> & { type: string }) {
    this.handlers.event?.(e as RealtimeServerEvent);
  }
  types() {
    return this.sent.map((e) => e.type);
  }
}

const quiet = () => {};

function startCall(direction = "inbound", hangupTimeoutMs = 15_000) {
  const twilio = new FakeSocket();
  const rt = new FakeRealtime();
  handleMediaStream(twilio as any, { config, connect: () => rt, log: quiet, hangupTimeoutMs });
  twilio.receive({
    event: "start",
    streamSid: "MZ1",
    start: { streamSid: "MZ1", callSid: "CA1", customParameters: { direction, from: "+447700900001", to: "+447700900002" } },
  });
  rt.open();
  return { twilio, rt };
}

const audio = (item_id: string, delta = "AAAA") => ({ type: "response.output_audio.delta", item_id, delta });

test("session uses μ-law passthrough, the configured voice and interruptions", () => {
  const s = sessionConfig(config, { direction: "inbound", from: "+44", to: "+44" });
  assert.equal(s.model, "gpt-realtime-2.1");
  assert.deepEqual(s.audio?.input?.format, { type: "audio/pcmu" });
  assert.deepEqual(s.audio?.output?.format, { type: "audio/pcmu" });
  assert.equal(s.audio?.output?.voice, "marin");
  assert.equal(s.audio?.input?.turn_detection?.interrupt_response, true);
  assert.match(s.instructions ?? "", /British accent/);
  assert.match(s.instructions ?? "", /Open with: "Hi, this is Ava/);
});

test("on connect: configures the session and the agent speaks first", () => {
  const { rt } = startCall();
  assert.deepEqual(rt.types(), ["session.update", "conversation.item.create", "response.create"]);
});

test("caller audio is forwarded to OpenAI and agent audio back to Twilio", () => {
  const { twilio, rt } = startCall();
  twilio.receive({ event: "media", media: { payload: "dXNlcg==", timestamp: "20", track: "inbound" } });
  assert.deepEqual(rt.sent.at(-1), { type: "input_audio_buffer.append", audio: "dXNlcg==" });

  rt.emit(audio("item_1", "YWdlbnQ="));
  assert.deepEqual(twilio.sent[0], { event: "media", streamSid: "MZ1", media: { payload: "YWdlbnQ=" } });
  assert.equal(twilio.sent[1].event, "mark");
});

test("barge-in clears Twilio playback and truncates to what was heard", () => {
  const { twilio, rt } = startCall();
  twilio.receive({ event: "media", media: { payload: "", timestamp: "1000" } });
  rt.emit(audio("item_1"));
  rt.emit(audio("item_1"));
  twilio.receive({ event: "mark", mark: { name: "audio" } }); // one chunk played, one still queued
  twilio.receive({ event: "media", media: { payload: "", timestamp: "1640" } });

  rt.emit({ type: "input_audio_buffer.speech_started" });

  assert.deepEqual(rt.sent.at(-1), {
    type: "conversation.item.truncate",
    item_id: "item_1",
    content_index: 0,
    audio_end_ms: 640,
  });
  assert.deepEqual(twilio.sent.at(-1), { event: "clear", streamSid: "MZ1" });
});

test("speech while the agent is silent doesn't truncate anything", () => {
  const { twilio, rt } = startCall();
  rt.emit(audio("item_1"));
  twilio.receive({ event: "mark", mark: { name: "audio" } }); // all audio played
  const before = rt.sent.length;
  rt.emit({ type: "input_audio_buffer.speech_started" });
  assert.equal(rt.sent.length, before);
  assert.notEqual(twilio.sent.at(-1).event, "clear");
});

test("end_call hangs up after the goodbye has played", () => {
  const { twilio, rt } = startCall();
  rt.emit(audio("item_9"));
  rt.emit({ type: "response.function_call_arguments.done", name: "end_call", arguments: '{"reason":"done"}' });
  rt.emit({ type: "response.done" });

  assert.deepEqual(twilio.sent.at(-1), { event: "mark", streamSid: "MZ1", mark: { name: "hangup" } });
  assert.equal(twilio.closed, false, "waits for playback");

  twilio.receive({ event: "mark", mark: { name: "hangup" } });
  assert.equal(twilio.closed, true);
});

test("end_call still hangs up if Twilio never confirms playback", async () => {
  const { twilio, rt } = startCall("inbound", 10);
  rt.emit({ type: "response.function_call_arguments.done", name: "end_call", arguments: "not json" });
  rt.emit({ type: "response.done" });
  await tick(30);
  assert.equal(twilio.closed, true);
});

test("keypad presses reach the agent", () => {
  const { twilio, rt } = startCall();
  twilio.receive({ event: "dtmf", dtmf: { digit: "5" } });
  const item = rt.sent.at(-2) as any;
  assert.match(item.item.content[0].text, /pressed the "5" key/);
  assert.equal(rt.sent.at(-1)?.type, "response.create");
});

test("call ending closes the OpenAI session", () => {
  const { twilio, rt } = startCall();
  twilio.emit("close");
  assert.equal(rt.closed, true);
});
