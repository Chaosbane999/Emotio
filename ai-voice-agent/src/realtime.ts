import type OpenAI from "openai";
import type {
  RealtimeClientEvent,
  RealtimeFunctionTool,
  RealtimeServerEvent,
  RealtimeSessionCreateRequest,
} from "openai/resources/realtime/realtime";
import { OpenAIRealtimeWS } from "openai/realtime/ws";
import type { WebSocket } from "ws";
import type { Config } from "./config.js";
import { systemPrompt, type CallInfo } from "./prompt.js";

/** The parts of an OpenAI Realtime connection the bridge uses; lets tests swap in a fake. */
export interface RealtimeConnection {
  send(event: RealtimeClientEvent): void;
  onOpen(fn: () => void): void;
  onEvent(fn: (event: RealtimeServerEvent) => void): void;
  onClose(fn: () => void): void;
  close(): void;
}

export function openRealtime(client: OpenAI, model: string, log: Log): RealtimeConnection {
  const rt = new OpenAIRealtimeWS({ model }, client);
  rt.on("error", (err) => log("OpenAI Realtime error", err.error ?? err.message));
  return {
    send: (event) => rt.send(event),
    onOpen: (fn) => rt.socket.on("open", fn),
    onEvent: (fn) => rt.on("event", fn),
    onClose: (fn) => rt.socket.on("close", fn),
    close: () => rt.close(),
  };
}

type Log = (msg: string, extra?: unknown) => void;

const END_CALL_TOOL: RealtimeFunctionTool = {
  type: "function",
  name: "end_call",
  description: "Hang up the phone call. Use only after you have said goodbye, or if the other party asks to end the call.",
  parameters: {
    type: "object",
    properties: { reason: { type: "string", description: "Short reason the call is ending" } },
    required: ["reason"],
    additionalProperties: false,
  },
};

export function sessionConfig(config: Config, call: CallInfo): RealtimeSessionCreateRequest {
  return {
    type: "realtime",
    model: config.OPENAI_REALTIME_MODEL,
    output_modalities: ["audio"],
    instructions: systemPrompt(config, call, "speech"),
    audio: {
      // Twilio phone audio is 8 kHz G.711 μ-law, which Realtime accepts and returns
      // directly, so audio passes straight through with no transcoding.
      input: {
        format: { type: "audio/pcmu" },
        noise_reduction: { type: "near_field" },
        turn_detection:
          config.OPENAI_TURN_DETECTION === "semantic_vad"
            ? { type: "semantic_vad", eagerness: "auto", create_response: true, interrupt_response: true }
            : { type: "server_vad", create_response: true, interrupt_response: true, silence_duration_ms: 500 },
      },
      output: {
        format: { type: "audio/pcmu" },
        voice: config.OPENAI_VOICE,
        speed: config.OPENAI_VOICE_SPEED,
      },
    },
    tools: [END_CALL_TOOL],
    tool_choice: "auto",
    ...(config.OPENAI_REASONING_EFFORT ? { reasoning: { effort: config.OPENAI_REASONING_EFFORT } } : {}),
  };
}

/** Twilio Media Streams messages (subset we use). */
type TwilioEvent =
  | { event: "connected" }
  | {
      event: "start";
      streamSid: string;
      start: { streamSid: string; callSid: string; customParameters?: Record<string, string> };
    }
  | { event: "media"; media: { payload: string; timestamp: string | number; track?: string } }
  | { event: "mark"; mark: { name: string } }
  | { event: "dtmf"; dtmf: { digit: string } }
  | { event: "stop" };

export interface BridgeDeps {
  config: Config;
  connect: () => RealtimeConnection;
  log?: Log;
  /** How long to wait for the goodbye to finish playing before hanging up anyway. */
  hangupTimeoutMs?: number;
}

const HANGUP_MARK = "hangup";

/**
 * Bridges one Twilio Media Stream (the phone call's audio) to one OpenAI Realtime
 * session. OpenAI handles listening, turn-taking and speaking; we relay audio,
 * keep Twilio's playback in sync when the caller interrupts, and hang up on end_call.
 */
export function handleMediaStream(twilioWs: WebSocket, deps: BridgeDeps): void {
  const log = deps.log ?? ((msg, extra) => console.log(`[realtime] ${msg}`, extra ?? ""));
  const hangupTimeoutMs = deps.hangupTimeoutMs ?? 15_000;

  let streamSid = "";
  let callSid = "";
  let rt: RealtimeConnection | null = null;
  let rtOpen = false;
  let call: CallInfo | null = null;

  // Playback tracking, used to tell OpenAI how much of its reply the caller heard.
  let latestMediaMs = 0;
  let assistantItemId: string | null = null;
  let assistantStartMs: number | null = null;
  let pendingMarks = 0;

  let endReason: string | null = null;
  let hangingUp = false;

  const toTwilio = (msg: object) => {
    if (twilioWs.readyState === twilioWs.OPEN) twilioWs.send(JSON.stringify(msg));
  };

  const hangUp = () => {
    if (hangingUp) return;
    hangingUp = true;
    log(`ending ${callSid}: ${endReason ?? "hang up"}`);
    // Closing the stream makes Twilio continue to <Connect action>, which hangs up.
    twilioWs.close();
  };

  const sayFirst = () => {
    if (!rt || !call) return;
    rt.send({ type: "session.update", session: sessionConfig(deps.config, call) });
    const cue =
      call.direction === "outbound"
        ? "[The person you called has answered the phone.]"
        : "[The call has connected. Greet the caller.]";
    rt.send({
      type: "conversation.item.create",
      item: { type: "message", role: "user", content: [{ type: "input_text", text: cue }] },
    });
    rt.send({ type: "response.create" });
  };

  const handleOpenAI = (event: RealtimeServerEvent) => {
    switch (event.type) {
      case "response.output_audio.delta": {
        if (assistantItemId !== event.item_id) {
          assistantItemId = event.item_id;
          assistantStartMs = latestMediaMs;
        }
        toTwilio({ event: "media", streamSid, media: { payload: event.delta } });
        // A mark after each chunk tells us when Twilio has actually played it.
        toTwilio({ event: "mark", streamSid, mark: { name: "audio" } });
        pendingMarks++;
        break;
      }
      case "input_audio_buffer.speech_started": {
        // The caller is talking over the agent: stop playback now and tell OpenAI
        // how far through its reply they got, so it doesn't think it said the rest.
        if (pendingMarks > 0 && assistantItemId && assistantStartMs !== null) {
          rt?.send({
            type: "conversation.item.truncate",
            item_id: assistantItemId,
            content_index: 0,
            audio_end_ms: Math.max(0, latestMediaMs - assistantStartMs),
          });
          toTwilio({ event: "clear", streamSid });
        }
        pendingMarks = 0;
        assistantItemId = null;
        assistantStartMs = null;
        break;
      }
      case "response.function_call_arguments.done": {
        if (event.name === "end_call") {
          try {
            endReason = String(JSON.parse(event.arguments).reason ?? "conversation finished");
          } catch {
            endReason = "conversation finished";
          }
        }
        break;
      }
      case "response.done": {
        if (endReason && !hangingUp) {
          // Hang up once Twilio confirms the goodbye has finished playing.
          toTwilio({ event: "mark", streamSid, mark: { name: HANGUP_MARK } });
          setTimeout(hangUp, hangupTimeoutMs).unref?.();
        }
        break;
      }
      case "error":
        log(`OpenAI error on ${callSid}`, event.error);
        break;
    }
  };

  twilioWs.on("message", (raw) => {
    let msg: TwilioEvent;
    try {
      msg = JSON.parse(raw.toString());
    } catch {
      return;
    }

    switch (msg.event) {
      case "start": {
        streamSid = msg.start.streamSid;
        callSid = msg.start.callSid;
        const params = msg.start.customParameters ?? {};
        call = {
          direction: params.direction === "outbound" ? "outbound" : "inbound",
          from: params.from ?? "",
          to: params.to ?? "",
          purpose: params.purpose || undefined,
        };
        log(`call ${callSid} (${call.direction}) connected`);
        rt = deps.connect();
        rt.onEvent(handleOpenAI);
        rt.onClose(() => {
          log(`OpenAI session closed for ${callSid}`);
          hangUp();
        });
        rt.onOpen(() => {
          rtOpen = true;
          sayFirst();
        });
        break;
      }
      case "media":
        latestMediaMs = Number(msg.media.timestamp) || latestMediaMs;
        if (rtOpen && (msg.media.track ?? "inbound") === "inbound") {
          rt?.send({ type: "input_audio_buffer.append", audio: msg.media.payload });
        }
        break;
      case "mark":
        if (msg.mark.name === HANGUP_MARK) hangUp();
        else if (pendingMarks > 0) pendingMarks--;
        break;
      case "dtmf":
        if (rtOpen && !endReason) {
          rt?.send({
            type: "conversation.item.create",
            item: {
              type: "message",
              role: "user",
              content: [{ type: "input_text", text: `[The caller pressed the "${msg.dtmf.digit}" key.]` }],
            },
          });
          rt?.send({ type: "response.create" });
        }
        break;
      case "stop":
        log(`call ${callSid} stream stopped`);
        break;
    }
  });

  twilioWs.on("close", () => {
    hangingUp = true;
    rt?.close();
  });
}
