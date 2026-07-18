# AI Visibility Monitor — Technical & Administrator Documentation

Version 0.1.0 (MVP scaffold)

This document covers (a) the plugin architecture for developers and (b) an
administrator guide. It maps directly to the project scope sections in
parentheses, e.g. (§8.2).

---

## Part 1 — Administrator guide

### 1. Installation
1. Upload the `ai-visibility-monitor` folder to `wp-content/plugins/` (or install the ZIP).
2. Activate **AI Visibility Monitor** from *Plugins*.
3. On first activation you are redirected to the **Setup** wizard.

Activation creates the custom database tables, adds the `avm_manage` and
`avm_view` capabilities to the administrator role, seeds default settings, and
schedules the background queue.

### 2. Onboarding (§6.2)
Enter your brand name, alternative names, domain, description, services,
sectors and geographic market. Leave *Generate suggested questions* ticked to
create draft prompts automatically. Nothing is monitored until you approve it.

### 3. Connect a provider (§6.2, §17.1)
Go to **AI Visibility → Settings**:
1. Choose a provider (OpenAI at launch) and model (`gpt-4o-mini` recommended for cost).
2. Paste your API key and click **Test connection**.
3. Set a **monthly query allowance** to cap spend (0 = unlimited).

For maximum security, instead define the key in `wp-config.php`:
```php
define( 'AVM_OPENAI_API_KEY', 'sk-...' );
```
When set this way the key is never written to the database and the field
becomes read-only.

### 4. Prompts (§7)
On **AI Visibility → Prompts** you can add, edit, approve, pause, duplicate,
delete, and manually run prompts. Only *approved* (active) prompts are
monitored, up to the plan limit (25 by default). Assign each a category,
priority (priority/supporting/discovery), frequency and optional geo context.

### 5. Competitors (§6.2)
Add up to five competitors with domain, category and priority. Competitor
appearances and cited competitor pages are detected automatically.

### 6. Monitoring
* **Scheduled:** an hourly job enqueues any prompt whose `next_run_at` is due;
  a 5-minute job processes the queue in small batches.
* **Manual:** click **Run** on any prompt (subject to the allowance).

On low-traffic sites WP-Cron may lag. The **Diagnostics** screen warns you and
recommends a real server cron:
```
*/5 * * * * curl -s https://your-site.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

### 7. Reading results
* **Dashboard** — AI Visibility Score, component breakdown, core rates, confidence.
* **Recommendations** — prioritised actions with evidence; update their status.
* **CSV export** — from the dashboard.

### 8. Removing data (§17.4)
Deactivation keeps all data. To permanently delete everything on uninstall,
tick **Settings → Data & privacy → On uninstall** before deleting the plugin.
Otherwise data is retained for reinstall.

### 9. If your API key stops working after a security change
If WordPress security salts in `wp-config.php` are regenerated, previously saved
API keys can no longer be decrypted. The Settings screen detects this and shows
a clear message asking you to re-enter the key. Defining the key as a
`wp-config.php` constant avoids this entirely.

---

## Part 2 — Developer architecture

### Layering and the migration seam (§4, §25)
The plugin is deliberately split so the query engine can later be swapped for
an Emotio-managed API without touching the UI or reporting:

```
Admin UI  ──►  QueryExecutorInterface  ──►  Provider  ──►  AI API
(views)        (LocalQueryExecutor)        (OpenAiProvider)
   ▲                    │
   └── Repositories ◄── AnalysisService / ScoringService / RecommendationsService
```

* **`Services\QueryExecutorInterface`** is the single seam (§25). The MVP ships
  `LocalQueryExecutor`; a future `RemoteQueryExecutor` calling the Emotio API is
  a drop-in replacement.
* **`Providers\ProviderInterface`** (§8.2) standardises auth, query, citation
  extraction, usage and error handling. Register new providers on the
  `avm_register_providers` hook via `ProviderRegistry::register()`.

### Directory map
```
ai-visibility-monitor.php        Bootstrap, constants, activation hooks
uninstall.php                    Opt-in data removal
includes/
  class-avm-autoloader.php       PSR-4-ish autoloader (class-avm-*.php)
  class-avm-plugin.php           Singleton orchestrator
  class-avm-activator.php        Tables, caps, defaults, schedule
  class-avm-deactivator.php      Unschedule (keeps data)
  class-avm-install.php          dbDelta schema (§19) + drop
  Support/                       Crypto, Logger, Settings, Capabilities
  Data/                          One repository per table (prepared queries)
  Providers/                     Interface, response DTO, registry, OpenAI
  Services/                      Executor seam, analysis, scoring, recs, generator, site analyzer
  Queue/                         Background queue + cost gating
  Admin/                         Controller, dashboard widget, ajax, views/
assets/                          admin.css, admin.js
```

### Data model (§19)
Ten indexed custom tables (`{$prefix}avm_*`): brands, competitors, prompts,
runs, results, citations, mentions, recommendations, logs, queue. Large result
histories never live in options or post meta (§18.4).

### Security model (§17)
* Every state-changing action verifies a nonce (`check_admin_referer`) and an
  AVM capability before writing.
* All input is sanitised; all output is escaped; all DB access uses `$wpdb`
  prepare/insert/update helpers.
* API keys are AES-256-GCM encrypted (key derived from WP salts) or read from a
  wp-config constant; masked in the UI; redacted from logs via `Logger::redact`.
* AI answers are treated as untrusted: HTML stripped (`wp_kses( …, [] )`),
  stored size capped, URLs validated with `esc_url_raw`.

### Extending
* **New provider:** implement `ProviderInterface`, register on
  `avm_register_providers`.
* **New metric:** extend `ScoringService::metrics()` (keep component weights summing to 1.0).
* **New recommendation rule:** add a branch in
  `RecommendationsService::generate_for_prompt()` guarded by `exists_open()`.

### Known limitations of this scaffold
See the project README section "How I'd improve it" and the inline `TODO`
notes. The analysis engine is deterministic/heuristic (no LLM-based extraction
yet). Citation quality depends on the provider: the **Perplexity** provider
returns real source URLs, whereas the **OpenAI** Chat Completions endpoint does
not, so for OpenAI citations are parsed heuristically from URLs in the answer
text.
