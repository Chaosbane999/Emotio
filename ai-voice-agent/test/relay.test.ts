import assert from "node:assert/strict";
import { test } from "node:test";
import type { Agent, ReplySink, TurnResult } from "../src/agent.js";
import { handleRelaySession } from "../src/relay.js";
import { FakeSocket, tick } from "./helpers.js";

class ScriptedAgent implements Agent {
  heard: string[] = [];
  interrupts: (string | undefined)[] = [];
  constructor(private readonly reply: (text: string) => { tokens: string[]; result?: TurnResult }) {}
  async respond(text: string, sink: ReplySink): Promise<TurnResult> {
    this.heard.push(text);
    const { tokens, result } = this.reply(text);
    for (const t of tokens) sink.text(t);
    sink.done();
    return result ?? { kind: "reply" };
  }
  interrupt(heard?: string) {
    this.interrupts.push(heard);
  }
}

const quiet = { log: () => {} };
const setup = (direction: string, extra: object = {}) => ({
  type: "setup",
  callSid: "CA1",
  from: "+15555550100",
  to: "+15555550123",
  customParameters: { direction, ...extra },
});

test("inbound: streams the reply for a final prompt", async () => {
  const ws = new FakeSocket();
  const agent = new ScriptedAgent(() => ({ tokens: ["Sure, ", "I can help."] }));
  handleRelaySession(ws as any, () => agent, quiet);

  ws.receive(setup("inbound"));
  ws.receive({ type: "prompt", voicePrompt: "Can you help me?", last: true });
  await tick();

  assert.deepEqual(agent.heard, ["Can you help me?"]);
  assert.deepEqual(ws.sent, [
    { type: "text", token: "Sure, ", last: false },
    { type: "text", token: "I can help.", last: false },
    { type: "text", token: "", last: true },
  ]);
});

test("outbound: agent speaks first with the call purpose", async () => {
  const ws = new FakeSocket();
  let purpose: string | undefined;
  const agent = new ScriptedAgent(() => ({ tokens: ["Hello!"] }));
  handleRelaySession(ws as any, (call) => ((purpose = call.purpose), agent), quiet);

  ws.receive(setup("outbound", { purpose: "Confirm appointment" }));
  await tick();

  assert.equal(purpose, "Confirm appointment");
  assert.equal(agent.heard.length, 1);
  assert.equal(ws.sent[0].token, "Hello!");
});

test("non-final prompts are ignored", async () => {
  const ws = new FakeSocket();
  const agent = new ScriptedAgent(() => ({ tokens: ["ok"] }));
  handleRelaySession(ws as any, () => agent, quiet);
  ws.receive(setup("inbound"));
  ws.receive({ type: "prompt", voicePrompt: "Can you", last: false });
  await tick();
  assert.deepEqual(agent.heard, []);
});

test("interrupt is forwarded with what the caller heard", async () => {
  const ws = new FakeSocket();
  const agent = new ScriptedAgent(() => ({ tokens: [] }));
  handleRelaySession(ws as any, () => agent, quiet);
  ws.receive(setup("inbound"));
  ws.receive({ type: "interrupt", utteranceUntilInterrupt: "Our hours are" });
  assert.deepEqual(agent.interrupts, ["Our hours are"]);
});

test("end_call hangs up after the goodbye is spoken", async () => {
  const ws = new FakeSocket();
  const agent = new ScriptedAgent(() => ({ tokens: ["Bye!"], result: { kind: "end_call", reason: "done" } }));
  handleRelaySession(ws as any, () => agent, { ...quiet, msPerChar: 0 });
  ws.receive(setup("inbound"));
  ws.receive({ type: "prompt", voicePrompt: "That's all, thanks", last: true });
  await tick(1600);

  const end = ws.sent.at(-1);
  assert.equal(end.type, "end");
  assert.deepEqual(JSON.parse(end.handoffData), { reason: "done" });

  ws.receive({ type: "prompt", voicePrompt: "wait", last: true });
  await tick();
  assert.equal(agent.heard.length, 1, "no turns after ending");
});

test("agent errors produce a spoken apology instead of silence", async () => {
  const ws = new FakeSocket();
  const agent: Agent = {
    respond: async () => {
      throw new Error("boom");
    },
    interrupt: () => {},
  };
  handleRelaySession(ws as any, () => agent, quiet);
  ws.receive(setup("inbound"));
  ws.receive({ type: "prompt", voicePrompt: "hello", last: true });
  await tick();
  assert.equal(ws.sent.at(-1).last, true);
  assert.match(ws.sent.at(-1).token, /trouble/);
});
