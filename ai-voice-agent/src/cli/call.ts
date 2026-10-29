// Usage: npm run call -- +447700900123 "Confirm tomorrow's 3pm appointment"
// Uses the settings saved in /admin (DATA_DIR). The server must be running and
// reachable at PUBLIC_BASE_URL.
import { placeCall } from "../calls.js";
import { SettingsStore } from "../settings.js";

const [to, ...purpose] = process.argv.slice(2);
if (!to) {
  console.error('Usage: npm run call -- <+E164 number> ["purpose of the call"]');
  process.exit(1);
}

const call = await placeCall(new SettingsStore().config(), to, purpose.join(" ") || undefined);
console.log(`Calling ${to} — call SID ${call.sid} (${call.status})`);
