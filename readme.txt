=== AI Provider for Open WebUI ===
Contributors:      pronomix
Tags:              ai, ai provider, wordpress ai, open webui, openwebui, llm, api, chatbot, content generation, pronomix
Requires at least: 6.7
Tested up to:      6.9
Stable tag:        1.3.5
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Provides Open WebUI as a provider for the WordPress AI Client.

== Description ==

AI Provider for Open WebUI connects the WordPress AI Client to your Open WebUI instance.

The plugin adds a dedicated connector and a focused settings page under **Settings > Open WebUI**.

Features:

* Connect to Open WebUI using a base URL and API key.
* Discover available models via `GET /api/models`.
* Generate text via `POST /api/chat/completions`.
* Select one preferred model for text, image, and vision requests (if the model supports these capabilities).
* Integrate with the existing AI connector management in WordPress.
* Translation-ready according to WordPress standards (text domain: `ai-provider-for-open-webui`).

Important:
Do not append `/api` to the configured URL. The plugin handles endpoint paths automatically.

== Installation ==

1. Install and activate the WordPress AI plugin (AI Client).
2. Upload this plugin to `/wp-content/plugins/ai-provider-for-open-webui/`.
3. Activate **AI Provider for Open WebUI**.
4. Open **Settings > Open WebUI**.
5. Configure:
* Open WebUI URL (for example `http://localhost:3000`)
* API key from Open WebUI (`Settings > Account`)
* Optional preferred model

== Screenshots ==

1. Open WebUI connector card in the AI plugin, including connection status.
2. Open WebUI settings page with URL, API key, and preferred model selection.

== Frequently Asked Questions ==

= Which URL should I enter? =

Use the base URL of your Open WebUI instance, for example:

* `http://localhost:3000` for local setups
* `https://ai.example.com` for hosted setups

Do not append `/api`.

= Where can I create an API key? =

In Open WebUI, open **Settings > Account** and create or copy your key.

= Why do I see “The AI plugin requires a valid AI Connector …”? =

This message appears when no valid connector is available for the AI plugin.
Check the following:

* The AI plugin is active.
* The Open WebUI URL is reachable.
* The API key is valid.
* The Open WebUI connector is connected.

= Can I use environment variables? =

Yes:

* `OPENWEBUI_BASE_URL`
* `OPENWEBUI_API_KEY`
* `OPENWEBUI_REQUEST_TIMEOUT`

== External Services ==

This plugin sends requests to your configured Open WebUI API endpoint when AI features are used.

Service:

* Open WebUI API at the URL you configure in plugin settings.

Data sent:

* Prompts and request context required for the selected AI feature.
* Requested model identifier.
* API key for authentication against your Open WebUI instance.

When data is sent:

* Only when AI features are actively used (for example generation, summarization, or alt text).

Responsibility:

* Actual downstream processing depends on your Open WebUI setup and any backend providers used by that setup.
* You are responsible for compliant operation and privacy configuration of your Open WebUI environment.

Project and branding information:

* https://docs.openwebui.com/brand/

== Branding and Rights ==

This plugin includes the official Open WebUI logo for provider identification.

Source and usage notes:

* `third-party-notices.txt`

== Changelog ==

= 1.3.5 - 2026-04-09 =

* Version auf 1.3.5 erhöht.
* GitHub-Deploy für WordPress.org-Assets auf `assets/` konfiguriert.

= 1.3.4 - 2026-04-09 =

* Version auf 1.3.4 erhöht.
* Such-Tags für WordPress.org optimiert (AI Provider, Open WebUI, Chatbot, Content Generation, pronomix).
* WordPress.org-Assets (Banner, Icons, Screenshots) ergänzt.

= 1.3.1 - 2026-04-08 =

* Präfixe für kollisionsrelevante Namen vereinheitlicht und Hauptdatei auf Slug-konformen Dateinamen umgestellt.
* WordPress.org-Verzeichnis-Assets aus dem Plugin-Paket entfernt.

= 1.3.0 - 2026-03-27 =

* Improved language inheritance by prioritizing source language from `<content>` blocks.
* Added robust multi-candidate title fallback when Open WebUI returns only one choice.
* Hardened alt text generation to prevent prompt echoes.
* Enforced shorter excerpt output with prompt guidance and server-side word limiting.
* Moved provider prompt constraints into dedicated template files.

= 1.2.0 - 2026-03-26 =

* Simplified configuration to a single preferred Open WebUI model.
* Added capability-based fallback handling for image and alt-text features.
* Improved model capability detection to avoid routing unsupported features.

= 1.1.0 - 2026-03-26 =

* Added preferred model selection with manual fallback input and model suggestions.
* Added request timeout handling (`OPENWEBUI_REQUEST_TIMEOUT`).
* Improved settings page behavior and connector synchronization.

= 1.0.0 - 2026-03-26 =

* Initial release.
* Registered Open WebUI as a provider for the WordPress AI Client.
* Added settings page for URL and API key.
* Added model discovery via the Open WebUI API.
