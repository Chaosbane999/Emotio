import { EventEmitter } from "node:events";
import { loadConfig } from "../src/config.js";

export const config = loadConfig({
  TWILIO_ACCOUNT_SID: "ACtest",
  TWILIO_AUTH_TOKEN: "token",
  TWILIO_PHONE_NUMBER: "+15555550100",
  PUBLIC_BASE_URL: "https://agent.example.com/",
  API_KEY: "test-api-key",
  OPENAI_API_KEY: "sk-test",
});

/** Minimal stand-in for a ws WebSocket. */
export class FakeSocket extends EventEmitter {
  readonly OPEN = 1;
  readyState = 1;
  sent: any[] = [];
  closed = false;
  close() {
    this.closed = true;
    this.readyState = 3;
  }
  send(data: string) {
    this.sent.push(JSON.parse(data));
  }
  receive(msg: object) {
    this.emit("message", Buffer.from(JSON.stringify(msg)));
  }
}

export const tick = (ms = 0) => new Promise((r) => setTimeout(r, ms));
