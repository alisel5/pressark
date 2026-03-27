=== PressArk ===
Contributors: pressark
Tags: ai, assistant, chat, woocommerce, content
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 5.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI co-pilot for WordPress. Manage your site through natural language chat in wp-admin with the PressArk service or your own supported AI provider key.

== Description ==

PressArk is an AI-powered assistant that lives inside your WordPress admin dashboard. Chat with it to edit pages, manage WooCommerce, analyze SEO, run security audits, build automations, and more through natural language.

PressArk is serviceware. Core AI features require either the bundled PressArk AI service or a supported Bring Your Own Key (BYOK) provider configuration. Review the "External Services" section below before enabling AI features.

**Core Features**

* Edit posts and pages through chat with a change preview system
* SEO analysis with actionable recommendations
* Security scanning for common vulnerabilities
* Create new posts and pages via natural language
* Update post metadata
* Action logging with undo support
* Content indexing for context-aware AI responses
* Site profile for tone and style matching

**WooCommerce Management**

* Manage products, orders, customers, coupons, shipping, taxes, and payments
* WooCommerce analytics and reporting
* Product variations, attributes, refunds, and reviews
* Webhook and alert management

**Elementor Integration**

* Edit Elementor pages through chat
* Modify Elementor widgets and layouts via natural language

**Workflow Engine**

* Deterministic multi-step content pipelines for common tasks
* Content editing, SEO fixing, and WooCommerce operations workflows
* Preview and confirm steps before applying changes
* Post-apply verification for workflow writes

**Automation and Diagnostics**

* Scheduled prompts with unattended automation policies and Telegram notifications
* Async task queue for background processing
* System diagnostics and site health tooling

**AI Provider Support**

* OpenRouter
* OpenAI
* Anthropic
* DeepSeek
* Google Gemini
* BYOK mode for direct provider usage

**Plans**

* Free - 100,000 credits per month, sampled tool access, no deep mode or automations
* Pro - 5,000,000 credits, premium AI models, up to 5 automations, 1 site
* Team - 15,000,000 credits, up to 15 automations, 5 sites
* Agency - 40,000,000 credits, up to 50 automations, 25 sites
* Enterprise - 100,000,000 credits, unlimited automations, unlimited sites

**Credit Packs**

* $5 = 800,000 credits
* $15 = 2,600,000 credits
* $30 = 6,000,000 credits

Readable source files for PressArk's distributed minified JS and CSS assets are bundled in this plugin package.

== Installation ==

1. Upload the `pressark` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Open PressArk from the admin menu
4. Choose either bundled PressArk service mode or BYOK mode
5. Complete onboarding before sending your first AI request

== External Services ==

This plugin connects to external third-party services. By using PressArk you acknowledge and agree to the terms of each service used.

PressArk is a Software as a Service plugin. The remote PressArk AI service provides the substantive AI metering, routing, and bundled-billing functionality for service mode. In BYOK mode, AI requests are sent directly to the provider you configure instead.

= Freemius SDK =

PressArk uses the Freemius SDK for licensing, plan management, billing, and opt-in usage analytics. The SDK communicates with Freemius servers to validate licenses, process upgrades, and handle checkout. Data transmitted includes your site URL, WordPress version, PHP version, plugin version, and license key. No site content is transmitted.

* Service URLs: [https://wp.freemius.com](https://wp.freemius.com), [https://checkout.freemius.com](https://checkout.freemius.com)
* Provider: Freemius, Inc.
* Terms of Service: [https://freemius.com/terms/](https://freemius.com/terms/)
* Privacy Policy: [https://freemius.com/privacy/](https://freemius.com/privacy/)

= PressArk Token Bank =

The PressArk Token Bank manages credit tracking, reservation, and AI proxy routing for bundled-billing users. It sends your site domain, WordPress user ID, and credit-usage data. When AI proxy mode is active, chat messages and site context are routed through this service to the selected AI provider. It is not used when BYOK mode is enabled.

* Service URL: [https://tokens.pressark.com](https://tokens.pressark.com)
* Provider: PressArk
* Terms of Service: [https://pressark.com/terms](https://pressark.com/terms)
* Privacy Policy: [https://pressark.com/privacy](https://pressark.com/privacy)

= AI Providers =

Chat messages and site context are sent to the AI provider you select. In proxy mode, requests are routed through the PressArk Token Bank which forwards them to the provider. In BYOK mode, requests go directly to your chosen provider.

* OpenRouter - [https://openrouter.ai](https://openrouter.ai) - [Privacy Policy](https://openrouter.ai/privacy)
* OpenAI - [https://openai.com](https://openai.com) - [Privacy Policy](https://openai.com/policies/privacy-policy)
* Anthropic - [https://anthropic.com](https://anthropic.com) - [Privacy Policy](https://www.anthropic.com/privacy)
* DeepSeek - [https://deepseek.com](https://deepseek.com) - [Privacy Policy](https://deepseek.com/privacy)
* Google Gemini - [https://ai.google.dev](https://ai.google.dev) - [Privacy Policy](https://policies.google.com/privacy)

All API keys are encrypted at rest using Sodium authenticated encryption.

= Telegram Bot API (optional) =

If you enable Telegram notifications for scheduled automations, PressArk sends automation status messages to the Telegram Bot API using a chat ID you configure. Notifications may include summaries of actions performed.

* Service URL: [https://api.telegram.org](https://api.telegram.org)
* Privacy Policy: [https://telegram.org/privacy](https://telegram.org/privacy)

== Frequently Asked Questions ==

= What AI providers are supported? =

PressArk supports OpenRouter, OpenAI, Anthropic, DeepSeek, and Google Gemini.

= What are credits? =

Credits are PressArk's internal billing unit. They normalize usage across different AI models so costs are predictable regardless of provider.

= What data is sent externally? =

See the "External Services" section above for the full breakdown of each external connection, what data is sent, and links to the relevant privacy policies.

= Does PressArk require an external service? =

Yes. You must use either the bundled PressArk AI service or configure a supported provider key in BYOK mode. Freemius is also used for billing, licensing, and checkout flows.

= Can I use PressArk without any external PressArk services? =

Yes. Enable BYOK mode in Settings. AI requests go directly to your provider. Freemius is still contacted for license validation, but no site content is transmitted there.

= Does PressArk support WordPress multisite? =

PressArk is a per-site plugin. Activate it individually on each site in your network. Network-wide activation is not supported.

= Can I undo changes made by PressArk? =

Yes. Modifying actions are logged with their previous values and can be undone. Changes are previewed before applying so you can approve or reject them.

= What happens when I uninstall the plugin? =

All plugin data is removed, including custom database tables, plugin options, transients, user meta, and scheduled events.

== Changelog ==

= 5.1.0 =

* New: Updated AI model lineup — DeepSeek V3.2, MiniMax M2.7 (free), Claude Haiku 4.5, Kimi K2.5, GLM-5, GPT-5.4 Mini (pro), Claude Sonnet 4.6, Claude Opus 4.6, GPT-5.4, GPT-5.3 Codex (team+)
* New: Team+ model tier gate — premium models now exclusive to Team, Agency, and Enterprise plans
* New: Value ICU class for mid-tier models — better credit efficiency for pro users
* Improvement: Token-based context compression (258K ceiling) replaces ICU-based — model-agnostic, no more premature compression
* Improvement: AI compaction cooldown prevents repeated summarization calls burning credits
* Fix: Multi-card confirm actions now work correctly with partial settlement
* Fix: Context compression auto-continue with silent continuation UX
* Fix: Type safety for comment_ids and block attrs from AI models passing strings instead of arrays
* Fix: Custom model option now only available for BYOK users (removed from subscription plans)

= 5.0.5 =

* Compliance: Aligned the main plugin version and readme stable tag
* Compliance: Clarified serviceware and external-service requirements in the plugin description and readme
* Security: Sanitized automation nonces before verification
* Packaging: Slimmed the readme and moved the detailed release history to `changelog.txt`
* Fix: Updated the checkout image reference to an existing bundled asset

See `changelog.txt` for the full release history.
