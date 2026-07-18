=== AI Visibility Monitor ===
Contributors: emotio
Tags: ai, seo, visibility, monitoring, generative-search
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor whether your brand, website and services appear in AI-generated search answers, track competitors, and get practical recommendations.

== Description ==

AI Visibility Monitor submits an approved set of commercially relevant questions to a supported AI provider (using your own API key) and analyses the answers for brand mentions, website citations, competitor appearances, recommendation strength and share of voice — then turns the findings into page-level recommendations.

The plugin is measurement-and-improvement focused. It does not claim to provide an official "ChatGPT ranking"; AI answers vary by platform, model, location, wording and time. Instead it provides repeatable monitoring and measurable results over time.

**Core loop:** Monitor → analyse → recommend → improve → rerun.

= Key features (MVP) =

* Guided onboarding wizard and suggested-prompt generation
* Brand + up to five competitors, up to 25 active prompts
* Modular AI provider layer (OpenAI at launch; interface designed for more)
* Secure, encrypted API-key storage (or define it in wp-config.php)
* Weekly / monthly scheduled monitoring with a background queue
* Brand, competitor and citation detection; recommendation classification
* AI Visibility Score with component breakdown and confidence indicator
* Dashboard widget and full reporting screens
* Website readiness checks feeding a technical-readiness score
* Query-usage and cost controls with a monthly allowance
* CSV export, diagnostics screen, and explicit data-removal on uninstall

== Frequently Asked Questions ==

= Do I need my own API key? =
Yes. During the MVP you connect your own supported provider account and the provider bills you directly for query usage.

= Will this slow down my website? =
No. The plugin never makes AI API calls on public page loads. All monitoring runs in a background queue or via manual admin actions.

= Is my API key safe? =
Keys are encrypted at rest, never exposed in front-end code, redacted from logs, and can be defined in wp-config.php so they never touch the database.

== Changelog ==

= 0.1.0 =
* Initial MVP scaffold: onboarding, prompts, competitors, monitoring queue, analysis, scoring, recommendations, reporting, diagnostics, security hardening.
