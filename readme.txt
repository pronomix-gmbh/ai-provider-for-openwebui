=== AI Provider for Open WebUI ===
Contributors:      pronomix
Tags:              ai, openwebui, llm, connector, api
Requires at least: 6.7
Tested up to:      6.9
Stable tag:        1.1.0
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Open WebUI provider for the WordPress AI Client.

== Description ==

This plugin adds Open WebUI as an AI provider for the WordPress AI Client.

It connects to Open WebUI using:

* Model discovery from `GET /api/models`
* Text generation through `POST /api/chat/completions`

The plugin includes a dedicated settings page under **Settings > Open WebUI** where you can configure:

* Open WebUI URL (for example `http://localhost:3000`)
* API key from Open WebUI (Settings > Account)
* Quick model list test in wp-admin

== Installation ==

1. Ensure the WordPress AI Client plugin is installed and activated.
2. Upload this plugin to `/wp-content/plugins/ai-provider-for-open-webui/`.
3. Activate the plugin in WordPress.
4. Open **Settings > Open WebUI** and configure URL and API key.

== Development ==

Install dependencies:

`composer install`

Run WordPress Coding Standards checks:

`composer lint`

Run Plugin Check (in CI via GitHub Actions):

`wordpress/plugin-check-action`

Run unit tests:

`composer test`

Create release ZIP:

`composer build`

Auto-fix fixable coding standard issues:

`composer lint:fix`

== Internationalization ==

Text domain: `ai-provider-for-open-webui`

Translation template:

`languages/ai-provider-for-open-webui.pot`

== Branding and Rights ==

This plugin includes the official Open WebUI logo for provider identification.

Asset source and usage notes:

`third-party-notices.txt`

== Frequently Asked Questions ==

= Support =

Bei Fragen oder Support:

`dev@pronomix.de`

= Which URL should I enter? =

Use your Open WebUI base URL, usually:

* `http://localhost:3000` for local setups
* `https://your-domain.tld` for hosted setups

Do not append `/api`; the plugin handles endpoint paths automatically.

= Where do I get the API key? =

In Open WebUI, go to **Settings > Account** and create/copy an API key.

= Can I use environment variables? =

Yes:

* `OPENWEBUI_BASE_URL`
* `OPENWEBUI_API_KEY`

== Changelog ==

= 1.1.0 - 2026-03-26 =

* Added preferred model selection with manual fallback input and model suggestions
* Added default request timeout handling for Open WebUI requests (including OPENWEBUI_REQUEST_TIMEOUT override)
* Improved Open WebUI settings page behavior and connector synchronization

= 1.0.0 - 2026-03-26 =

* Initial release
* Open WebUI provider registration for WordPress AI Client
* Settings page for URL and API key
* Model discovery via Open WebUI API
