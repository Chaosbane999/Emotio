import type { WebSocket } from "ws";
import type { Agent, TurnResult } from "./agent.js";
import type { CallInfo } from "./prompt.js";

/** Messages ConversationRelay sends us (subset we use). */
type Inbound =
  | {
      type: "setup";
      callSid: string;
      from: string;
      to: string;
      direction?: string;
      customParameters?: Record<string, string>;
    }
  | { type: "prompt"; voicePrompt: string; last?: boolean; lang?: string }
  | { type: "interrupt"; utteranceUntilInterrupt?: string; durationUntilInterruptMs?: number }
  | { type: "dtmf"; digit: string }
  | { type: "error"; description?: string };

export type AgentFactory = (call: CallInfo & { callSid: string }) => Agent;

export interface RelayOptions {
  /** Milliseconds to wait per spoken character before hanging up, so the goodbye isn't cut off. */
  msPerChar?: number;
  log?: (msg: string, extra?: unknown) => void;
}

/** Drives one ConversationRelay WebSocket session. */
export function handleRelaySession(ws: WebSocket, makeAgent: AgentFactory, opts: RelayOptions = {}): void {
  const log = opts.log ?? ((msg, extra) => console.log(`[relay] ${msg}`, extra ?? ""));
  const msPerChar = opts.msPerChar ?? 70;
  let agent: Agent | null = null;
  let callSid = "";
  let ending = false;
  let queue: Promise<void> = Promise.resolve();

  const send = (payload: object) => {
    if (ws.readyState === ws.OPEN) ws.send(JSON.stringify(payload));
  };

  const runTurn = (userText: string) => {
    if (!agent || ending) return;
    const current = agent;
    // A new prompt supersedes any reply still streaming.
    current.interrupt();
    queue = queue.then(async () => {
      if (ending) return;
      let spoken = "";
      let result: TurnResult;
      try {
        result = await current.respond(userText, {
          text: (delta) => {
            spoken += delta;
            send({ type: "text", token: delta, last: false });
          },
          done: () => send({ type: "text", token: "", last: true }),
        });
      } catch (err) {
        log(`agent error on ${callSid}`, err);
        send({ type: "text", token: "Sorry, I'm having trouble right now. Please try again in a moment.", last: true });
        return;
      }
      if (result.kind === "end_call") {
        ending = true;
        log(`ending ${callSid}: ${result.reason}`);
        const wait = Math.max(1500, spoken.length * msPerChar);
        setTimeout(() => send({ type: "end", handoffData: JSON.stringify({ reason: result.reason }) }), wait);
      }
    });
  };

  ws.on("message", (raw) => {
    let msg: Inbound;
    try {
      msg = JSON.parse(raw.toString());
    } catch {
      log("ignored non-JSON message");
      return;
    }

    switch (msg.type) {
      case "setup": {
        callSid = msg.callSid;
        const params = msg.customParameters ?? {};
        const direction = params.direction === "outbound" ? "outbound" : "inbound";
        agent = makeAgent({ callSid, direction, from: msg.from, to: msg.to, purpose: params.purpose });
        log(`session started ${callSid} (${direction}) ${msg.from} -> ${msg.to}`);
        // Inbound calls are greeted by ConversationRelay's welcomeGreeting; on
        // outbound calls the agent speaks first.
        if (direction === "outbound") runTurn("[The person you called has answered the phone.]");
        break;
      }
      case "prompt":
        if (msg.last !== false && msg.voicePrompt.trim()) runTurn(msg.voicePrompt);
        break;
      case "interrupt":
        agent?.interrupt(msg.utteranceUntilInterrupt ?? "");
        break;
      case "dtmf":
        runTurn(`[The caller pressed the "${msg.digit}" key.]`);
        break;
      case "error":
        log(`ConversationRelay error on ${callSid}`, msg.description);
        break;
    }
  });

  ws.on("close", () => {
    ending = true;
    agent?.interrupt();
    log(`session closed ${callSid}`);
  });
}
