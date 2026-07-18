# AI Visibility Monitor

A commercial WordPress plugin that monitors whether a brand, website and
services appear in AI-generated search answers — tracking competitors,
citations, share of voice and change over time, and turning findings into
page-level recommendations.

> Core loop: **Monitor → analyse → recommend → improve → rerun.**

The plugin lives in [`ai-visibility-monitor/`](ai-visibility-monitor/).
Full docs: [`ai-visibility-monitor/docs/DOCUMENTATION.md`](ai-visibility-monitor/docs/DOCUMENTATION.md).

---

## What this build delivers

A working, installable MVP **foundation** implementing the full core loop and
the load-bearing architecture from the scope, verified with PHP lint + an
autoload/contract test + a crypto round-trip test.

| Area | Status |
|------|--------|
| Installable plugin, activation/deactivation, DB schema (10 indexed tables §19) | ✅ Implemented |
| Custom capabilities `avm_manage` / `avm_view` (§5) | ✅ |
| Onboarding wizard + suggested-prompt generation (§6.2, §7.1) | ✅ |
| Brand + competitor management, prompt CRUD/approve/run (§7) | ✅ |
| **Modular provider layer** + OpenAI provider (§8.2) | ✅ |
| **Replaceable query-executor seam** for the future managed service (§25, acceptance #18) | ✅ |
| Background queue: batching, dedupe, retry+backoff, allowance gating (§9, §16) | ✅ |
| Analysis engine: brand/competitor/citation detection, recommendation classification (§10) | ✅ Heuristic |
| AI Visibility Score + component breakdown + confidence (§11) | ✅ |
| Dashboard widget (cached only) + reporting screens (§12, §13) | ✅ |
| Recommendations engine + workflow statuses (§15) | ✅ |
| Website readiness checks feeding technical score (§14) | ✅ Basic |
| Secure API-key storage (AES-256-GCM or wp-config), nonces, caps, escaping, prepared queries (§17) | ✅ |
| CSV export, diagnostics screen, opt-in data removal (§20, §21, §17.4) | ✅ |
| Technical + admin documentation (§23 #29–30) | ✅ |

### Verification run in this build
- `php -l` on every PHP file — all pass.
- Autoloader + contract test — 31 classes load; `OpenAiProvider`,
  `LocalQueryExecutor`, repositories satisfy their interfaces/base.
- `Crypto` encrypt→decrypt round-trip, masking, and malformed-input handling.

Not yet runtime-tested inside a live WordPress install (no WP available in this
environment) — that is the recommended next step (see below).

---

## How I would improve it

### 1. Correctness & accuracy of detection (highest value)
- **The analysis engine is the product.** The current detector is deterministic
  string/URL matching — fast, cheap, transparent, but brittle (misses
  paraphrases, mis-attributes ambiguous names). Add an optional **second-pass
  LLM extraction** that returns structured JSON (brands, competitors, stance,
  citation→claim linkage) and reconcile it with the heuristic pass; store both
  and expose the confidence delta. This directly serves acceptance §29 #7–9.
- **Repeat-testing for stability (§24 phase 2).** A single answer is noisy. Run
  each priority prompt *N* times and report a *mention frequency* ("7 of 10")
  rather than a boolean — the spec already promises this framing (§2, §10).
- **Entity disambiguation.** Match on domain first, then name with word
  boundaries (done), but add per-brand allow/deny aliases and a review queue for
  "newly detected competitors" (§10.2) instead of silent misses.

### 2. Make the provider layer earn its abstraction
- Ship a **second provider early** (Perplexity — it returns real citations,
  which OpenAI Chat Completions does not). Nothing proves an abstraction like a
  second implementation, and citation quality is central to the score (35%).
- Add a **provider capability map** (supports_citations, supports_search) and
  degrade scoring gracefully when a provider can't cite, so the score isn't
  silently penalised by provider choice.

### 3. Queue & scheduling robustness
- Prefer **Action Scheduler** as the primary engine (it's battle-tested,
  admin-visible, and handles concurrency/locking) and keep the WP-Cron table
  queue only as a fallback. The code already detects it; lean on it harder.
- Add a **claim lock** (e.g. `status='processing' WHERE id=… AND status='queued'`
  as an atomic guarded update) to prevent double-processing when two cron
  contexts overlap — the current claim is a plain update and could race.

### 4. Cost transparency
- Token pricing is hard-coded and will drift. Move pricing to a **filterable
  config** (`apply_filters('avm_provider_pricing', …)`) and surface an
  **end-of-month forecast** (§16.1) on the dashboard, not just usage-to-date.
- Add a **hard pre-scan estimate + confirmation** for large manual scans (§16.2).

### 5. Reporting depth
- The competitor, citation and change reports (§13.3–13.5) are modelled in data
  but need dedicated screens and a **trend graph** (store daily score snapshots;
  render with a tiny inline SVG/Chart, no external CDN).
- **Change detection (§10.6)** is scaffolded in `last_two_for_prompt()` but not
  yet surfaced as "won/lost this period" — a high-signal, low-effort add.

### 6. Testing & CI
- Add **PHPUnit + WP test suite** (Brain Monkey for units) covering the analysis
  engine against fixture answers, the scoring maths, and crypto. Wire
  **PHPCS (WordPress-Extra) + PHPStan** in GitHub Actions. The heuristics
  especially need golden-file tests so accuracy doesn't regress.

### 7. Security hardening beyond the basics
- Add a **capability check + rate limit** on manual scans per user (§17.2 notes
  it; enforce it centrally).
- Provide the **wp-config data-removal toggle** in the UI, and a per-table
  "delete monitoring data" action with confirmation (§17.4).
- Consider **key rotation**: because encryption is keyed on WP salts, rotating
  salts invalidates stored keys — document this and add a re-encrypt routine.

### 8. Product/UX
- The AI Visibility Score is useful but should **always show its working**
  (done on the dashboard) *and* a plain-language "what changed and why" line.
- Add **empty-state coaching** (no prompts yet → wizard CTA) and inline
  provider-error explanations mapped from error codes (§21) to human text.

### Priority order if continuing
1. LLM-assisted extraction + repeat testing (accuracy is the whole value prop).
2. Second provider with real citations.
3. Action Scheduler + atomic job claim.
4. Trend snapshots + change/competitor/citation report screens.
5. PHPUnit/PHPCS/PHPStan CI.
