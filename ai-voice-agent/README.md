# AI Voice Agent

A phone agent that **answers inbound calls** and **places outbound calls**, powered by
Claude and Twilio.

```
 Caller ⇄ Twilio phone number ⇄ ConversationRelay (speech-to-text + text-to-speech)
                                        │  WebSocket (/relay)
                                        ▼
                              this server ⇄ Claude (streaming)
```

Twilio's [ConversationRelay](https://www.twilio.com/docs/voice/conversationrelay)
does the audio: it transcribes the caller, sends us text over a WebSocket, and speaks
our reply tokens as they stream back from Claude. That keeps latency low and the code
small. There's no audio handling in this repo.

## Features

- **Inbound calls**: point a Twilio number at `/voice/inbound` and the agent greets callers.
- **Outbound calls**: `POST /calls` or `npm run call`, with an optional *purpose* the agent works toward.
- **Barge-in**: callers can interrupt. The agent stops talking and knows what the caller actually heard.
- **Hang-up tool**: Claude ends the call itself (`end_call`) once the conversation is done.
- **Touch-tone (DTMF)** key presses are passed to the agent.
- **Security**: Twilio signature validation on webhooks and the WebSocket, plus a bearer key on `/calls`.

## Setup

1. **Install** (Node 20+):
   ```bash
   npm install
   cp .env.example .env
   ```
2. **Fill in `.env`**: your Anthropic API key, Twilio Account SID / Auth Token, and a
   Twilio phone number with Voice enabled.
3. **Expose the server publicly** (Twilio has to reach it), e.g. `ngrok http 8080`, and
   set `PUBLIC_BASE_URL` to the `https://…` URL.
4. **Run it:**
   ```bash
   npm run dev
   ```
5. **Take calls**: in the Twilio Console, open your phone number and set
   *A call comes in* to **Webhook**, `POST`, `https://<PUBLIC_BASE_URL>/voice/inbound`.
   Then call the number.
6. **Make calls:**
   ```bash
   npm run call -- +447700900123 "Confirm Jane's dentist appointment tomorrow at 3pm"
   # or over HTTP
   curl -X POST https://<PUBLIC_BASE_URL>/calls \
     -H "Authorization: Bearer $API_KEY" -H "Content-Type: application/json" \
     -d '{"to": "+447700900123", "purpose": "Confirm the appointment"}'
   ```
   On a Twilio trial account you can only call numbers you have verified.

## Customizing the agent

| Env var | What it does |
|---|---|
| `AGENT_NAME`, `AGENT_GREETING` | Persona and the first line on inbound calls |
| `AGENT_INSTRUCTIONS` | Extra instructions (business info, policies, what to collect) |
| `CLAUDE_MODEL` | Defaults to `claude-opus-5-5` |
| `CLAUDE_EFFORT` | `low` (default) keeps replies fast on a live call |
| `TTS_VOICE`, `TTS_MODEL` | ElevenLabs voice ID and model (default: "Alice", British, on Flash v2.5) |
| `TTS_SPEED`, `TTS_STABILITY`, `TTS_SIMILARITY` | Voice tuning; lower stability sounds more expressive |
| `TTS_PROVIDER`, `LANGUAGE` | Switch to Google/Amazon voices, or change language |

The system prompt lives in `src/prompt.ts`. To give the agent more abilities (book
appointments, look up orders, transfer to a human), add tools next to `end_call` in
`src/agent.ts`.

The agent turns on Anthropic's server-side refusal fallback
(`fallbacks: "default"`), so a declined request is retried on a fallback model
instead of going silent. Remove it from `src/agent.ts` if you don't want that.

## Endpoints

| Route | Caller | Purpose |
|---|---|---|
| `POST /voice/inbound` | Twilio | Incoming-call webhook → ConversationRelay TwiML |
| `WS /relay` | Twilio | ConversationRelay session (the conversation itself) |
| `POST /voice/ended` | Twilio | Relay session finished → hang up |
| `POST /voice/status` | Twilio | Outbound call status updates (logged) |
| `POST /calls` | You | Place an outbound call (`Authorization: Bearer $API_KEY`) |
| `GET /health` | Anyone | Liveness check |

## Development

```bash
npm run typecheck
npm test        # TwiML, prompt, and relay protocol tests (no network needed)
npm run build && npm start
```

## Project layout

```
src/
  server.ts   HTTP routes + WebSocket upgrade (entry point)
  relay.ts    ConversationRelay protocol: setup / prompt / interrupt / dtmf → agent
  agent.ts    Claude conversation: streaming replies, interruptions, end_call tool
  prompt.ts   System prompt for voice
  twiml.ts    TwiML that connects a call to ConversationRelay
  calls.ts    Outbound calls via the Twilio REST API
  config.ts   Env var validation
  cli/call.ts `npm run call` helper
```

## UK compliance notes

This is not legal advice. Check current ICO guidance, or ask a solicitor, before you
make outbound calls at scale.

- **Outbound marketing calls need prior consent.** Under PECR, calls made by an
  automated calling system to deliver marketing need the person's specific prior
  consent (regulation 19). An AI agent should be treated as automated, so the lighter
  live-call rules (TPS screening and opt-out) are not enough. Keep records of consent.
- **Service calls are different.** Calls that aren't marketing, such as appointment
  reminders or delivery updates to existing customers, generally fall outside PECR's
  marketing rules. Don't slip promotional content into them. Answering inbound calls is
  not restricted by PECR.
- **Say it's an AI, and who is calling.** The prompt makes the agent introduce itself as
  an AI on outbound calls, and the default greeting does the same on inbound calls.
  Set `AGENT_INSTRUCTIONS` with your business name. Present a valid caller ID: the call
  uses `TWILIO_PHONE_NUMBER`, so don't withhold it.
- **"Don't call me again" must be honoured.** The agent confirms and hangs up. Add the
  number to your own suppression list (not yet automated here).
- **UK GDPR.** Call transcripts pass through Twilio (speech-to-text) and Anthropic
  (Claude). Name them as processors in your privacy notice, have a lawful basis, and
  tell callers how their data is used. This app does not record audio or store
  transcripts. If you add recording, tell callers at the start of the call.
- **Ofcom persistent-misuse rules** apply to abandoned and silent calls. The agent speaks
  as soon as an outbound call is answered, so calls aren't silent.

## Other notes

- The agent waits ~70 ms per spoken character before hanging up so its goodbye isn't
  cut off (`msPerChar` in `src/relay.ts`).
