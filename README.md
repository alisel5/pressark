# PressArk — AI Co-Pilot for WordPress

PressArk is an AI-powered assistant that lives inside your WordPress admin. Chat with it to edit pages, manage WooCommerce, run SEO audits, scan for security issues, build automations, and more — all through natural language.

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)](https://wordpress.org/plugins/pressark/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-green)](https://www.gnu.org/licenses/gpl-2.0.html)

## How It Works

PressArk adds a chat panel to every wp-admin page. Describe what you want — PressArk figures out the tools, previews the changes, and applies them only after you approve.

```
You:   "Create a blog post about our new running shoes, add a CTA, and optimize it for SEO"
PressArk:  Loads products → picks the right one → drafts the post → shows preview → waits for approval
```

Every write action goes through a **Preview → Approve → Execute** pipeline. Nothing changes on your site without your explicit OK.

## Features

### Content & Pages
- Edit posts, pages, and custom post types through chat
- Create new content with AI-generated drafts
- Block-level editing (insert, edit, reorder blocks)
- Metadata and excerpt management
- Media management (upload, attach, set featured images)
- Bulk find & replace across content

### WooCommerce
- Manage products, orders, customers, coupons, shipping, taxes, and payments
- Product variations, attributes, and reviews
- WooCommerce analytics and reporting
- Webhook and alert management

### SEO & Security
- Full SEO audit with actionable recommendations
- Security scanning for common vulnerabilities
- Content performance analysis

### Elementor
- Edit Elementor pages through chat
- Modify widgets and layouts via natural language

### Automations
- Schedule recurring AI tasks (daily SEO checks, weekly content audits)
- Cron-based execution with full audit trail

### Smart Context
- Content indexing for context-aware responses
- Site profile for tone and brand voice matching
- Conversation memory with checkpoint system

## AI Models

PressArk routes to the best model for each task automatically:

| Tier | Default Model | Also Available |
|------|--------------|----------------|
| Free | DeepSeek V3.2 | MiniMax M2.7 |
| Pro | Claude Sonnet 4.6 | Claude Haiku 4.5, Kimi K2.5, GLM-5, GPT-5.4 Mini |
| Team+ | Claude Sonnet 4.6 | Claude Opus 4.6, GPT-5.4, GPT-5.3 Codex |

**BYOK (Bring Your Own Key):** Connect your own OpenRouter, OpenAI, Anthropic, or DeepSeek API key and use any model your provider supports.

## Installation

1. Download the latest release from [Releases](../../releases) or install from WordPress.org
2. Upload to `/wp-content/plugins/pressark/` or install via **Plugins > Add New**
3. Activate the plugin
4. Open the PressArk chat panel (bottom-right of any admin page) and start chatting

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce 7.0+ (optional, for store features)

## External Services

PressArk connects to external AI services to process your requests:

- **PressArk AI Service** (`tokens.pressark.com`) — Routes AI requests, manages credits, and handles billing. [Privacy Policy](https://pressark.com/privacy-policy/) · [Terms](https://pressark.com/terms-of-service/)
- **OpenRouter** (`openrouter.ai`) — AI model provider gateway. [Privacy Policy](https://openrouter.ai/privacy)
- **Freemius** (`freemius.com`) — License management and checkout. [Privacy Policy](https://freemius.com/privacy/)

When using BYOK mode, requests go directly to your chosen provider instead of through the PressArk service.

## License

GPLv2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

## Links

- [Website](https://pressark.com)
- [Documentation](https://pressark.com/docs)
- [Support](https://pressark.com/contact)
