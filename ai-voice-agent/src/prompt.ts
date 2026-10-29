import type { Config } from "./config.js";

export interface CallInfo {
  direction: "inbound" | "outbound";
  from: string;
  to: string;
  purpose?: string;
}

/**
 * "text": Claude writes text that ConversationRelay speaks, and ConversationRelay
 * has already played the inbound greeting.
 * "speech": the OpenAI Realtime model hears and speaks audio directly, and greets
 * the caller itself.
 */
export type PromptMode = "text" | "speech";

export function systemPrompt(config: Config, call: CallInfo, mode: PromptMode = "text"): string {
  const party = call.direction === "inbound" ? call.from : call.to;
  const opening =
    call.direction === "outbound"
      ? `You placed this outbound call to ${party}. Open by introducing yourself as an AI assistant and stating why you are calling.`
      : mode === "text"
        ? `This is an inbound call from ${party}. You have already greeted them with: "${config.AGENT_GREETING}"`
        : `This is an inbound call from ${party}. Open with: "${config.AGENT_GREETING}"`;

  const style =
    mode === "text"
      ? [
          "Everything you write is converted to speech, so:",
          "- Reply in one to three short, natural sentences. Ask one question at a time.",
          "- Plain spoken language only: no markdown, lists, emoji, URLs or symbols. Say numbers the way a person would.",
          "- The caller's words come from speech recognition and may contain errors; ask them to repeat if something is unclear.",
        ]
      : [
          "How to speak:",
          "- Speak with a natural, warm British accent, at a relaxed conversational pace.",
          "- Keep turns short: one to three sentences, one question at a time. Sound friendly and human, not scripted.",
          "- If you didn't catch something, say so and ask them to repeat it.",
          "- If you're interrupted, stop and listen; don't repeat everything you were saying.",
        ];

  const lines = [
    `You are ${config.AGENT_NAME}, an AI voice assistant speaking with someone on a live phone call.`,
    opening,
    call.purpose ? `Purpose of the call: ${call.purpose}` : "",
    "",
    ...style,
    "- Use British English: British phrasing, dates (day before month) and pounds sterling.",
    "- Be honest that you are an AI whenever asked. Never claim to be a person.",
    "- If someone asks not to be called again, apologise, confirm they won't be contacted again, and end the call.",
    "- When the conversation is finished, say a brief goodbye and call the end_call tool.",
    config.AGENT_INSTRUCTIONS ? `\nAdditional instructions:\n${config.AGENT_INSTRUCTIONS}` : "",
  ];
  return lines.join("\n").replace(/\n{3,}/g, "\n\n").trim();
}
