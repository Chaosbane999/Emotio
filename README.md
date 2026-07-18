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

## Improvements shipped in this round

The following items from the review below have now been implemented, verified
with lint + load/contract tests + executed unit assertions:

- **Salt-change key detection & recovery.** `Crypto::key_fingerprint()` +
  `Settings::key_status()` distinguish *missing* from *unreadable* keys; the
  Settings screen shows a clear "re-enter your key" error when WordPress salts
  have changed. (13 crypto + 6 key-status assertions pass.)
- **Second provider — Perplexity** (`class-avm-perplexity-provider.php`), which
  returns *real* citations, registered alongside OpenAI. Proves the abstraction.
- **Atomic job claim + non-blocking batch lock** in the queue, killing the
  double-processing race (guarded `UPDATE … WHERE status='queued'`).
- **Filterable provider pricing** (`avm_provider_pricing`) and a **month-end
  usage/cost forecast** on the diagnostics screen.
- **Change detection surfaced** — a new *Changes* report (won/lost mentions,
  won/lost citations, recommendation shifts) via `ChangeService`.
- **Data-removal UI toggle** for opt-in uninstall wipe.
- **CI + quality tooling**: `composer.json`, PHPCS (WordPress-Extra), PHPStan
  (level 5 + WP extension), PHPUnit with a runnable `CryptoTest`, and a GitHub
  Actions workflow running lint/PHPCS/PHPStan/PHPUnit on PHP 7.4 & 8.2.

## How I would improve it (remaining roadmap)

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
- ✅ Perplexity provider shipped (real citations). **Still to do:** add a
  **provider capability map** consumed by scoring so a provider that can't cite
  doesn't silently drag the citation component (35%) to zero.

### 3. Queue & scheduling robustness
- ✅ Atomic guarded claim + non-blocking batch lock shipped. **Still to do:**
  make **Action Scheduler** the *primary* engine when present (admin-visible,
  battle-tested) and keep the custom table only as the fallback.

### 4. Cost transparency
- ✅ Filterable pricing + month-end forecast shipped. **Still to do:** a **hard
  pre-scan estimate + confirmation** for large manual scans (§16.2).

### 5. Reporting depth
- ✅ Change report shipped (§10.6/§13.5). **Still to do:** dedicated competitor
  & citation report screens (§13.3–13.4) and a **trend graph** from stored daily
  score snapshots (inline SVG, no external CDN).

### 6. Testing & CI
- ✅ PHPCS + PHPStan + PHPUnit + GitHub Actions scaffolded, with a runnable
  `CryptoTest`. **Still to do:** **golden-file tests for the analysis engine**
  against fixture answers (Brain Monkey to stub WP), so detection accuracy can't
  silently regress — this is the most important test to add next.

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

### Priority order for the next round
1. **LLM-assisted extraction + repeat testing** — accuracy is the whole value
   prop; this is the biggest remaining lever (needs a live API to validate).
2. Golden-file tests for the analysis engine (lock in accuracy before iterating).
3. Provider capability-aware scoring.
4. Trend snapshots + competitor/citation report screens.
5. Action Scheduler as primary queue engine.
