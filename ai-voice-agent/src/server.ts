import { createServer } from "node:http";
import { pathToFileURL } from "node:url";
import Anthropic from "@anthropic-ai/sdk";
import OpenAI from "openai";
import express, { type NextFunction, type Request, type Response } from "express";
import twilio from "twilio";
import { WebSocketServer } from "ws";
import { adminRouter } from "./admin.js";
import { ClaudeAgent } from "./agent.js";
import { placeCall } from "./calls.js";
import { mediaStreamUrl, missingSettings, relayUrl } from "./config.js";
import { systemPrompt } from "./prompt.js";
import { handleMediaStream, openRealtime } from "./realtime.js";
import { handleRelaySession } from "./relay.js";
import { SettingsStore } from "./settings.js";
import { callTwiml } from "./twiml.js";

export function createApp(store: SettingsStore) {
  const app = express();
  // Behind Traefik: use X-Forwarded-For for the client IP (login throttling).
  app.set("trust proxy", true);
  app.use("/admin", adminRouter(store));
  app.use(express.urlencoded({ extended: false }));
  app.use(express.json());

  /** Reject webhooks that weren't signed by Twilio with our auth token. */
  const twilioOnly = (req: Request, res: Response, next: NextFunction) => {
    const config = store.config();
    if (!config.VALIDATE_TWILIO_SIGNATURE) return next();
    const ok = twilio.validateRequest(
      config.TWILIO_AUTH_TOKEN,
      req.header("x-twilio-signature") ?? "",
      config.PUBLIC_BASE_URL + req.originalUrl,
      req.body ?? {},
    );
    if (!ok) return res.status(403).send("Invalid Twilio signature");
    next();
  };

  const apiKeyOnly = (req: Request, res: Response, next: NextFunction) => {
    const { API_KEY } = store.config();
    if (!API_KEY) return res.status(503).json({ error: "outbound API key not set (see /admin)" });
    if (req.header("authorization") !== `Bearer ${API_KEY}`) return res.status(401).json({ error: "unauthorized" });
    next();
  };

  app.get("/", (_req, res) => {
    res.type("text/plain").send(`AI voice agent (${store.config().VOICE_ENGINE}) is running.`);
  });

  app.get("/health", (_req, res) => {
    res.json({ ok: true, ready: missingSettings(store.config()).length === 0 });
  });

  // Point your Twilio number's "A call comes in" webhook here.
  app.post("/voice/inbound", twilioOnly, (req, res) => {
    const config = store.config();
    if (missingSettings(config).length) {
      const twiml = new twilio.twiml.VoiceResponse();
      twiml.say({ language: "en-GB" }, "Sorry, this line isn't set up yet. Please try again later.");
      return res.type("text/xml").send(twiml.toString());
    }
    res.type("text/xml").send(callTwiml(config, { direction: "inbound", from: req.body.From, to: req.body.To }));
  });

  // <Connect action>: the call's AI session ended (agent hung up or caller left).
  app.post("/voice/ended", twilioOnly, (req, res) => {
    console.log(`[voice] call ${req.body.CallSid} ended`, req.body.HandoffData ?? "");
    res.type("text/xml").send(new twilio.twiml.VoiceResponse().hangup().toString());
  });

  app.post("/voice/status", twilioOnly, (req, res) => {
    console.log(`[voice] ${req.body.CallSid} ${req.body.CallStatus}`);
    res.sendStatus(204);
  });

  // Place an outbound call: POST /calls {"to": "+447700900123", "purpose": "..."}
  app.post("/calls", apiKeyOnly, async (req, res) => {
    const { to, purpose } = req.body ?? {};
    if (typeof to !== "string") return res.status(400).json({ error: "`to` is required" });
    try {
      const call = await placeCall(store.config(), to, typeof purpose === "string" ? purpose : undefined);
      res.status(201).json({ sid: call.sid, status: call.status });
    } catch (err) {
      res.status(400).json({ error: err instanceof Error ? err.message : String(err) });
    }
  });

  return app;
}

export function start(store = new SettingsStore()) {
  const boot = store.config();
  const server = createServer(createApp(store));
  const wss = new WebSocketServer({ noServer: true });

  server.on("upgrade", (req, socket, head) => {
    const config = store.config();
    const url = new URL(req.url ?? "/", "http://localhost");
    // Twilio connects to the socket for whichever engine is configured.
    const socketUrl =
      url.pathname === "/media-stream" ? mediaStreamUrl(config) : url.pathname === "/relay" ? relayUrl(config) : null;
    if (!socketUrl) return socket.destroy();
    if (config.VALIDATE_TWILIO_SIGNATURE) {
      const signature = String(req.headers["x-twilio-signature"] ?? "");
      if (!twilio.validateRequest(config.TWILIO_AUTH_TOKEN, signature, socketUrl + url.search, {})) {
        socket.write("HTTP/1.1 403 Forbidden\r\n\r\n");
        return socket.destroy();
      }
    }
    wss.handleUpgrade(req, socket, head, (ws) => {
      if (url.pathname === "/media-stream") {
        const openai = new OpenAI({ apiKey: config.OPENAI_API_KEY });
        handleMediaStream(ws, {
          config,
          connect: () => openRealtime(openai, config.OPENAI_REALTIME_MODEL, console.error),
        });
      } else {
        const anthropic = new Anthropic({ apiKey: config.ANTHROPIC_API_KEY });
        handleRelaySession(ws, (call) => new ClaudeAgent(anthropic, config, systemPrompt(config, call)));
      }
    });
  });

  server.listen(boot.PORT, () => {
    console.log(`AI voice agent listening on :${boot.PORT}`);
    console.log(`  Admin:           ${boot.PUBLIC_BASE_URL}/admin`);
    console.log(`  Inbound webhook: ${boot.PUBLIC_BASE_URL}/voice/inbound`);
    if (store.setupCode) {
      console.log(`\n  First-time setup: open ${boot.PUBLIC_BASE_URL}/admin and enter setup code ${store.setupCode}\n`);
    }
    const missing = missingSettings(boot);
    if (missing.length) console.log(`  Not ready yet. Set in /admin: ${missing.join(", ")}`);
  });
  return server;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) start();
