import Anthropic from "@anthropic-ai/sdk";
import type { Config } from "./config.js";

type MessageParam = Anthropic.Beta.BetaMessageParam;

/** Where the agent's reply goes as it streams. */
export interface ReplySink {
  text(delta: string): void;
  /** The reply is complete (send `last: true`). */
  done(): void;
}

export type TurnResult = { kind: "reply" } | { kind: "end_call"; reason: string } | { kind: "interrupted" };

/** The surface the relay needs; lets tests swap in a fake agent. */
export interface Agent {
  respond(userText: string, sink: ReplySink): Promise<TurnResult>;
  interrupt(heardSoFar?: string): void;
}

const END_CALL_TOOL: Anthropic.Beta.BetaTool = {
  name: "end_call",
  description: "Hang up the phone call. Use only after you have said goodbye, or if the other party asks to end the call.",
  input_schema: {
    type: "object",
    properties: { reason: { type: "string", description: "Short reason the call is ending" } },
    required: ["reason"],
    additionalProperties: false,
  },
  strict: true,
  eager_input_streaming: true,
};

const REFUSAL_LINE = "Sorry, I can't help with that. Is there anything else I can do for you?";

/**
 * One phone conversation with Claude. History is append-only: interruptions are
 * recorded as a note on the next user turn instead of editing earlier turns.
 */
export class ClaudeAgent implements Agent {
  private readonly messages: MessageParam[] = [];
  private current: AbortController | null = null;
  private pendingNote = "";

  constructor(
    private readonly client: Anthropic,
    private readonly config: Config,
    private readonly system: string,
  ) {}

  async respond(userText: string, sink: ReplySink): Promise<TurnResult> {
    this.interrupt();
    this.addUser(this.pendingNote + userText);
    this.pendingNote = "";

    const controller = new AbortController();
    this.current = controller;
    let spoken = "";

    try {
      // A turn can need a second request only if a tool other than end_call is
      // added later; cap it so a misbehaving loop can't run up a bill mid-call.
      for (let step = 0; step < 3; step++) {
        const stream = this.client.beta.messages.stream(
          {
            model: this.config.CLAUDE_MODEL,
            max_tokens: 8000,
            system: this.system,
            messages: this.messages,
            tools: [END_CALL_TOOL],
            output_config: { effort: this.config.CLAUDE_EFFORT },
            betas: ["server-side-fallback-2026-07-01"],
            fallbacks: "default",
          },
          { signal: controller.signal },
        );
        stream.on("text", (delta) => {
          spoken += delta;
          sink.text(delta);
        });
        const message = await stream.finalMessage();
        this.messages.push({ role: "assistant", content: message.content });

        if (message.stop_reason === "refusal") {
          sink.text(REFUSAL_LINE);
          sink.done();
          return { kind: "reply" };
        }

        const endCall = message.content.find(
          (b): b is Anthropic.Beta.BetaToolUseBlock => b.type === "tool_use" && b.name === "end_call",
        );
        if (endCall && message.stop_reason === "tool_use") {
          sink.done();
          const input = endCall.input as { reason?: unknown };
          return { kind: "end_call", reason: typeof input.reason === "string" ? input.reason : "conversation finished" };
        }

        sink.done();
        return { kind: "reply" };
      }
      sink.done();
      return { kind: "reply" };
    } catch (err) {
      if (controller.signal.aborted) {
        // Record what we got out so the model knows what it already said.
        this.messages.push({ role: "assistant", content: spoken.trim() ? spoken : "…" });
        return { kind: "interrupted" };
      }
      throw err;
    } finally {
      if (this.current === controller) this.current = null;
    }
  }

  /** Caller started talking over the agent. */
  interrupt(heardSoFar?: string): void {
    this.current?.abort();
    this.current = null;
    if (heardSoFar !== undefined) {
      this.pendingNote = heardSoFar.trim()
        ? `[You were interrupted. The caller only heard: "${heardSoFar.trim()}"]\n`
        : "[You were interrupted before the caller heard your reply.]\n";
    }
  }

  private addUser(text: string): void {
    const last = this.messages.at(-1);
    if (last?.role === "user" && typeof last.content === "string") {
      last.content += `\n${text}`;
    } else {
      this.messages.push({ role: "user", content: text });
    }
  }
}
