# AI Voice Agent

A phone agent that **answers inbound calls** and **places outbound calls**, built on Twilio
and set up for the UK. It has two engines; choose one with `VOICE_ENGINE`.

### `openai` (default): speech-to-speech, the ChatGPT-style voice

```
 Caller ⇄ Twilio number ⇄ Media Stream (raw call audio, /media-stream) ⇄ this server ⇄ OpenAI Realtime
```

The OpenAI Realtime model (`gpt-realtime-2.1`) hears the caller's voice and replies in its
own voice, with no separate speech-to-text and text-to-speech steps. That gives the most
natural conversation: it picks up tone, responds quickly, and stops the moment you talk
over it. Call audio passes straight through in the phone's own format, with no conversion.

### `claude`: Claude for the conversation, ElevenLabs for the voice

```
 Caller ⇄ Twilio number ⇄ ConversationRelay (speech-to-text + ElevenLabs voice) ⇄ /relay ⇄ Claude
```

Choose this for Claude's reasoning, or to use any ElevenLabs voice.

## Features

- **Inbound calls**: point a Twilio number at `/voice/inbound` and the agent greets callers.
- **Outbound calls**: `POST /calls` or `npm run call`, with an optional *purpose* the agent works toward.
- **Interruptions**: talk over the agent and it stops immediately. Playback is cut, and the
  model is told exactly how much of its reply you heard.
- **Hang-up tool**: the agent ends the call itself (`end_call`) once it has said goodbye.
- **Touch-tone (DTMF)** key presses are passed to the agent.
- **Security**: Twilio signature validation on webhooks and the audio WebSocket, plus a bearer key on `/calls`.

## Setup

1. **Install** (Node 20+):
   ```bash
   npm install
   cp .env.example .env
   ```
2. **Set `PUBLIC_BASE_URL` in `.env`.** Keys can go in `.env` too, or be entered later at
   `/admin` (see below).
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
| `VOICE_ENGINE` | `openai` (speech-to-speech) or `claude` (Claude + ElevenLabs) |
| `AGENT_NAME`, `AGENT_GREETING` | Persona and the opening line on inbound calls |
| `AGENT_INSTRUCTIONS` | Extra instructions (business info, policies, what to collect) |

**OpenAI engine**

| Env var | What it does |
|---|---|
| `OPENAI_REALTIME_MODEL` | `gpt-realtime-2.1` (default) or `gpt-realtime-2.1-mini` (cheaper) |
| `OPENAI_VOICE` | `marin` (female, default) or `cedar` (male) sound the most natural. Others: `alloy`, `ash`, `ballad`, `coral`, `echo`, `sage`, `shimmer`, `verse` |
| `OPENAI_VOICE_SPEED` | 0.25–1.5 |
| `OPENAI_TURN_DETECTION` | `semantic_vad` waits until the caller has finished their thought; `server_vad` reacts to silence |
| `OPENAI_REASONING_EFFORT` | `low` keeps replies quick; raise it for harder conversations |

The British accent comes from the instructions in `src/prompt.ts`, not from the voice
itself, so try a few voices to find the one that sounds best.

**Claude engine**

| Env var | What it does |
|---|---|
| `CLAUDE_MODEL`, `CLAUDE_EFFORT` | Defaults: `claude-opus-5-5`, `low` |
| `TTS_VOICE`, `TTS_MODEL` | ElevenLabs voice ID and model (default: "Alice", British, on Flash v2.5) |
| `TTS_SPEED`, `TTS_STABILITY`, `TTS_SIMILARITY` | Voice tuning; lower stability sounds more expressive |
| `TTS_PROVIDER`, `LANGUAGE` | Switch to Google/Amazon voices, or change language |

The Claude engine turns on Anthropic's server-side refusal fallback
(`fallbacks: "default"`), so a declined request is retried on a fallback model instead of
going silent.

## Endpoints

| Route | Caller | Purpose |
|---|---|---|
| `POST /voice/inbound` | Twilio | Incoming-call webhook → ConversationRelay TwiML |
| `WS /media-stream` | Twilio | Call audio, bridged to OpenAI Realtime (`openai` engine) |
| `WS /relay` | Twilio | ConversationRelay text session (`claude` engine) |
| `POST /voice/ended` | Twilio | Relay session finished → hang up |
| `POST /voice/status` | Twilio | Outbound call status updates (logged) |
| `GET /admin` | You | Admin settings: keys, voice, agent, test call |
| `POST /calls` | You | Place an outbound call (`Authorization: Bearer <key from /admin>`) |
| `GET /health` | Anyone | Liveness check |

## Admin settings

Keys and settings are managed at **`/admin`** (e.g. https://voice.emotioflow.com/admin),
not in env vars or files you edit:

- Twilio Account SID, Auth Token and phone number
- OpenAI API key and voice, or the Anthropic key for the Claude engine
- Agent name, greeting and instructions
- The outbound-calling API key (one click generates it)
- A **test call** button

**First run:** the server prints a one-time **setup code** in its logs. Open `/admin`, enter
the code, and choose an admin password. Or set `ADMIN_PASSWORD` to skip the code.

Settings are saved in `DATA_DIR/settings.json`, readable only by the app (file mode 0600),
on a Docker volume so they survive redeploys. Secrets are never sent back to the browser:
the form only shows their last four characters. The admin login uses a signed HttpOnly,
SameSite=Strict cookie, blocks cross-site form posts, and locks out an IP for 15 minutes
after 10 failed attempts.

## Deploying to voice.emotioflow.com

The app ships with a `Dockerfile` and a `docker-compose.yml` for the Hostinger VPS (HTTPS
via Traefik; WebSockets pass straight through). **The deploy needs no secrets.**

1. **Code:** push this folder to a **public** repo `agiadaccount-oss/ai-voice-agent`.
   The compose file builds straight from it, and it contains no secrets.
2. **Subdomain:** in hPanel, go to Domains → emotioflow.com → DNS and add an A record:
   `voice` → `145.223.88.132`.
3. **Deploy:** in hPanel Docker Manager, choose Compose → Deploy with .yaml, name the
   project `ai-voice-agent`, and paste `docker-compose.yml`.
4. **Set up:** copy the setup code from Docker Manager → Logs, open
   https://voice.emotioflow.com/admin, create the admin password, and enter your keys.
5. **Twilio:** set your number's *A call comes in* webhook to
   `https://voice.emotioflow.com/voice/inbound` (HTTP POST). The admin page shows this URL.

## Development

```bash
npm run typecheck
npm test        # TwiML, config, and both engines' call protocols (no network needed)
npm run build && npm start
```

## Project layout

```
src/
  server.ts   HTTP routes + WebSocket upgrade (entry point)
  admin.ts    /admin pages: setup, login, settings form, test call
  settings.ts Saved settings (DATA_DIR/settings.json) layered over env vars
  realtime.ts OpenAI engine: Twilio Media Stream ⇄ OpenAI Realtime bridge, barge-in, end_call
  relay.ts    Claude engine: ConversationRelay protocol (setup / prompt / interrupt / dtmf)
  agent.ts    Claude engine: streaming replies, interruptions, end_call tool
  prompt.ts   System prompt (text mode for Claude, speech mode for OpenAI)
  twiml.ts    TwiML that connects a call to the configured engine
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
- **UK GDPR.** Call audio passes through Twilio and OpenAI (or, on the Claude engine,
  Twilio's speech-to-text and Anthropic). Name them as processors in your privacy notice, have a lawful basis, and
  tell callers how their data is used. This app does not record audio or store
  transcripts. If you add recording, tell callers at the start of the call.
- **Ofcom persistent-misuse rules** apply to abandoned and silent calls. The agent speaks
  as soon as an outbound call is answered, so calls aren't silent.

## Other notes

- On the OpenAI engine, the agent hangs up once Twilio confirms the goodbye has finished
  playing. On the Claude engine, it waits ~70 ms per spoken character (`msPerChar` in
  `src/relay.ts`).
