import { createServer } from "node:http";
import { pathToFileURL } from "node:url";
import Anthropic from "@anthropic-ai/sdk";
import express, { type NextFunction, type Request, type Response } from "express";
import twilio from "twilio";
import { WebSocketServer } from "ws";
import { ClaudeAgent } from "./agent.js";
import { placeCall } from "./calls.js";
import { loadConfig, relayUrl, type Config } from "./config.js";
import { systemPrompt } from "./prompt.js";
import { handleRelaySession } from "./relay.js";
import { conversationRelayTwiml } from "./twiml.js";

export function createApp(config: Config) {
  const app = express();
  app.use(express.urlencoded({ extended: false }));
  app.use(express.json());

  /** Reject webhooks that weren't signed by Twilio with our auth token. */
  const twilioOnly = (req: Request, res: Response, next: NextFunction) => {
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
    if (req.header("authorization") !== `Bearer ${config.API_KEY}`) return res.status(401).json({ error: "unauthorized" });
    next();
  };

  app.get("/health", (_req, res) => {
    res.json({ ok: true });
  });

  // Point your Twilio number's "A call comes in" webhook here.
  app.post("/voice/inbound", twilioOnly, (_req, res) => {
    res.type("text/xml").send(conversationRelayTwiml(config, { direction: "inbound" }));
  });

  // <Connect action>: the relay session ended (agent hung up or caller left).
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
      const call = await placeCall(config, to, typeof purpose === "string" ? purpose : undefined);
      res.status(201).json({ sid: call.sid, status: call.status });
    } catch (err) {
      res.status(400).json({ error: err instanceof Error ? err.message : String(err) });
    }
  });

  return app;
}

export function start(config: Config = loadConfig()) {
  const anthropic = new Anthropic();
  const server = createServer(createApp(config));
  const wss = new WebSocketServer({ noServer: true });

  server.on("upgrade", (req, socket, head) => {
    const url = new URL(req.url ?? "/", "http://localhost");
    if (url.pathname !== "/relay") return socket.destroy();
    if (config.VALIDATE_TWILIO_SIGNATURE) {
      const signature = String(req.headers["x-twilio-signature"] ?? "");
      const fullUrl = relayUrl(config) + url.search;
      if (!twilio.validateRequest(config.TWILIO_AUTH_TOKEN, signature, fullUrl, {})) {
        socket.write("HTTP/1.1 403 Forbidden\r\n\r\n");
        return socket.destroy();
      }
    }
    wss.handleUpgrade(req, socket, head, (ws) => {
      handleRelaySession(ws, (call) => new ClaudeAgent(anthropic, config, systemPrompt(config, call)));
    });
  });

  server.listen(config.PORT, () => {
    console.log(`AI voice agent listening on :${config.PORT}`);
    console.log(`  Inbound webhook: ${config.PUBLIC_BASE_URL}/voice/inbound`);
    console.log(`  Relay socket:    ${relayUrl(config)}`);
  });
  return server;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) start();
