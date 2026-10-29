# Deploy the AI voice agent to https://voice.emotioflow.com

Run this in **Claude Code on my own computer** (or Cowork), where Claude in Chrome is connected. I'm signed in to GitHub, Hostinger and Twilio in Chrome. A cloud session already tried this and failed: it had no Chrome tools, and its GitHub connection couldn't reach the `agiadaccount-oss` org.

## Before you start

1. Load the `hostinger-deploy` skill if it's available. It has the VPS details and the Docker Manager tips.
2. Load the Claude in Chrome tools in one go (`tabs_context_mcp`, `tabs_create_mcp`, `navigate`, `computer`, `read_page`, `find`, `form_input`, `javascript_tool`). Call `tabs_context_mcp` first and work in new tabs. **If the Chrome tools aren't there, stop and tell me. Don't look for a workaround.**
3. Check that `git` works in a terminal. If it doesn't, use the GitHub web UI fallback in step 2.

## Fixed facts (don't ask me for these)

| | |
|---|---|
| Source | `ai-voice-agent/` folder, branch `claude/hopeful-hawking-0uvl84`, repo `https://github.com/chaosbane999/emotio` |
| Target repo | `agiadaccount-oss/ai-voice-agent` (**public**, contents go at the repo root, branch `main`) |
| Domain / subdomain | `emotioflow.com` / `voice` |
| VPS IP | `145.223.88.132` |
| Docker Manager | https://hpanel.hostinger.com/vps/1565803/docker-manager |
| Compose project name | `ai-voice-agent` |
| App port | 8080 (settings stored in `/data`, on the `voice-data` volume) |

The folder already has a working `Dockerfile`, `.dockerignore`, `package-lock.json` and `docker-compose.yml`. **Use them as they are. Don't rewrite them.** A scan found no secrets in the folder, so making the repo public is fine.

## Secrets rule (strict)

The deployment needs **no secrets**. I'll enter every API key myself in the app's `/admin` page once it's live: OpenAI, Twilio SID, auth token and number, and the outbound API key. Never put keys in the compose YAML, Hostinger env vars, GitHub or the chat. Don't read, type or screenshot the keys.

## Steps

### 1. Create the repo
Go to https://github.com/organizations/agiadaccount-oss/repositories/new and create `ai-voice-agent`: **Public**, no README, no .gitignore, no licence. Skip this if it already exists and is empty.

### 2. Push the folder contents to the repo root on `main`
```
git clone -b claude/hopeful-hawking-0uvl84 https://github.com/chaosbane999/emotio.git
cd emotio
git subtree split --prefix=ai-voice-agent -b voice-agent
git push https://github.com/agiadaccount-oss/ai-voice-agent.git voice-agent:main
```
Fallback if git fails: upload every file and folder from `ai-voice-agent/` (including `src/`, `test/`, `package-lock.json` and the dotfiles) through GitHub's "Add file → Upload files". The files must sit at the repo root, not inside an `ai-voice-agent/` subfolder.
**Check:** on `main`, the root of `https://github.com/agiadaccount-oss/ai-voice-agent` shows `Dockerfile`, `docker-compose.yml`, `package.json`, `package-lock.json` and `src/`.

### 3. DNS
hPanel → Domains → emotioflow.com → DNS / Nameservers. Add an **A record**: Name `voice`, Points to `145.223.88.132`, TTL `3600`. Skip this if the record already exists with that IP. Run `nslookup voice.emotioflow.com` and confirm it returns `145.223.88.132` before expecting HTTPS to work.

### 4. Deploy
Open Docker Manager → **Compose** → **Deploy with .yaml**. Project name: `ai-voice-agent`. Paste the YAML below exactly; it's the repo's `docker-compose.yml`. Click Deploy and wait for **Running**.

Tips from the skill:
- Set the editor with `window.monaco.editor.getModels()[0].setValue(...)`, then check it with `getValue()`.
- Set the project name with React's native input setter (`Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set`), then dispatch an `input` event.

```yaml
# Deploys to https://voice.emotioflow.com behind the VPS's Traefik.
# No secrets here: API keys and phone settings are entered at
# https://voice.emotioflow.com/admin and saved on the voice-data volume.
# No `networks:` section — Traefik runs in host network mode on this VPS.
services:
  ai-voice-agent:
    build: https://github.com/agiadaccount-oss/ai-voice-agent.git
    labels:
      - traefik.enable=true
      - "traefik.http.routers.ai-voice-agent.rule=Host(`voice.emotioflow.com`)"
      - traefik.http.routers.ai-voice-agent.entrypoints=websecure
      - traefik.http.routers.ai-voice-agent.tls.certresolver=letsencrypt
      - traefik.http.services.ai-voice-agent.loadbalancer.server.port=8080
    environment:
      PUBLIC_BASE_URL: https://voice.emotioflow.com
      PORT: "8080"
      DATA_DIR: /data
    volumes:
      - voice-data:/data
    restart: unless-stopped

volumes:
  voice-data:
```

### 5. Verify
- `https://voice.emotioflow.com/` should return `AI voice agent (openai) is running.`
- `https://voice.emotioflow.com/health` should return `{"ok":true,"ready":false}`

If HTTPS fails at first, wait a few minutes for DNS and the Let's Encrypt certificate, then retry.

### 6. Admin setup (hand over to me)
1. In Docker Manager, open the `ai-voice-agent` container → **Logs**. Find the line `First-time setup: … enter setup code XXXXXXXX`.
2. Open https://voice.emotioflow.com/admin and enter that code.
3. **Stop and hand over to me.** I'll choose the admin password and enter the Twilio Account SID, Auth Token, Twilio number (+44…) and OpenAI API key, and tick "Generate a new random key" for the outbound API.
4. Carry on once the page shows **"Ready to make and take calls"**.

### 7. Twilio webhook
Twilio Console → Phone Numbers → Manage → Active numbers → my number → Voice configuration. Set "A call comes in" to **Webhook**, `https://voice.emotioflow.com/voice/inbound`, **HTTP POST**. Save.
The host must be exactly `voice.emotioflow.com`, because Twilio signatures are checked against `PUBLIC_BASE_URL`.

### 8. Test and report
Ask me to ring the number and to press the **Test call** button on `/admin`. Then report:
- the live URL
- the admin URL
- the Docker Manager project name (`ai-voice-agent`)
- anything left to do

UK compliance notes (PECR consent for automated marketing calls) are in the repo's README. Mention them.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `network … declared as external, but could not be found` | The compose file should have no `networks:` block. Check the paste. |
| Build fails fetching the git URL | The repo must be public and have the files at the root on `main` |
| Traefik 404 | Router name `ai-voice-agent` must match across all label lines, and the Host must be `voice.emotioflow.com` |
| Certificate error | DNS hasn't propagated yet. Wait and retry. |
| Container exits | Check Docker Manager → Logs |
| Twilio call fails with signature error | The webhook host or protocol doesn't match `https://voice.emotioflow.com` |

If a browser action fails 2–3 times, or a site asks for a permission I haven't granted, stop and ask me. Don't keep retrying.
