=== FeedHat – On-Page Screenshot Feedback ===
Contributors: Douple
Tags: feedback, bug report, screenshot, website feedback, client feedback
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See it. Mark it. Fix it — without leaving the page.

== Description ==

FeedHat adds a floating feedback button to your site. Visitors and clients can capture a screenshot of exactly what they're looking at, draw on it, add a written note, and send it — all without ever leaving the page they're on.

**Every piece of feedback is stored 100% inside your own WordPress database and uploads folder — nothing is ever sent to an external server.**

Website: https://douple.net/feedhat/  
Documentation: https://douple.net/feedhat/docs.html  
Demo: https://douple.net/feedhat/demo.html

= What's included =

* **Floating feedback button** — pick one of 6 positions (4 corners or centered on the left/right edge), 5 styles (icon + label, outlined, icon only, text only, edge tab), 3 sizes, a configurable distance from the edge, and a custom color and label, with a live preview in Settings.
* **Screenshot capture + annotation** — captures the current viewport automatically, then draw rectangles, arrows, freehand lines, numbered pin markers, or type text directly on the image, all in a single custom color. Numbered pins can include a written description stored with the feedback.
* **Move tool** — select and reposition (or recolor) anything you've already drawn before sending.
* **Written notes** — a simple text field for describing the issue.
* **Auto-captured context** — page URL, browser + version, OS, screen resolution, and submission time are attached automatically, so you never have to ask "what browser were you using?".
* **Admin management screen** — a dedicated list of every feedback entry with a screenshot thumbnail, the source page, browser/OS, and the note itself, right in wp-admin.
* **New / Resolved status** — mark feedback resolved directly from the list, no separate page needed.
* **Admin bell badge** — a red counter next to the "FeedHat" menu (just like the Comments badge) so new feedback never gets missed, even if an email notification doesn't arrive.
* **Admin toolbar notification** — a "Feedback" item in the wp-admin toolbar (front-end and wp-admin alike) listing the newest feedback, only shown while at least one item is new; can be turned off in Settings.
* **Targeting** — show the widget on the whole site, on selected pages/posts, or only when a visitor adds the fixed `?feedhat=1` parameter to the URL (handy for sharing a private review link).
* **Email notifications** — get emailed at one address whenever new feedback comes in, sent through WordPress's own `wp_mail()` — no third-party email service required.
* **Anti-spam controls** — a configurable per-visitor submission rate limit, an optional minimum-time-before-sending check, and an always-on honeypot field, all adjustable from Settings → Security.
* **Status tracking for reporters** — a private link, shown right after submitting, lets anyone check their feedback's current status later without logging in.
* **Settings screen** — configure everything above from a single wp-admin page, no code required.

= Optional add-on =

**FeedHat Pro** is a *separate* plugin (not bundled here). It adds advanced targeting (any post type, taxonomy, URL patterns), role/login-based visibility, multiple email recipients with routing rules, Slack/Discord/custom webhooks, a drag-and-drop Kanban board with internal team notes, Cloudflare Turnstile CAPTCHA, a widget language independent of the site's own, CSV export, and white-labeling for agencies.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/feedhat`, or install FeedHat directly through the "Plugins" screen in WordPress.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **FeedHat → Settings** in your wp-admin sidebar to choose where the button appears, its color/label, targeting, and email notifications.
4. Visit your site's front end — the feedback button should now be visible (unless targeting is set to specific pages, in which case visit one of those pages).

== Frequently Asked Questions ==

= How do I add FeedHat Pro? =

FeedHat Pro is a separate plugin — it is not included in this WordPress.org package and does not unlock anything already in this plugin. Install FeedHat first, then install FeedHat Pro and enter your license under **FeedHat → Account**.

= Where is my feedback data stored? =

Everything stays on your own WordPress install. Feedback entries are stored as a private custom post type in your WordPress database, and screenshots are saved to `/wp-content/uploads/feedhat/` — outside your Media Library, so they don't clutter it or show up to other users browsing your site's images. Nothing is ever sent to FeedHat's servers or any other third party.

= I turned on email notifications but the email never arrives — what should I check? =

FeedHat sends notifications through WordPress's own `wp_mail()` function — it doesn't use any third-party email API of its own. If the email doesn't arrive, the most common cause is that your server's default PHP mail is being blocked or marked as spam by your host or email provider. Check your spam folder first, then install a dedicated SMTP plugin (such as WP Mail SMTP or a similar plugin connected to a transactional email service) to have WordPress send mail through a proper mail server instead of PHP's default mail function.

== Screenshots ==

1. The floating feedback button on the front end.
2. The feedback panel: automatic screenshot capture with drawing/annotation tools.
3. The feedback management screen in wp-admin.
4. The FeedHat Settings page.

== Changelog ==

= 2.1.0 =
* WordPress.org compliance: this plugin is fully functional with no storage quota, no page-targeting cap, and no licensing SDK. Pin notes are stored for every install. Screenshot compression is a Settings option anyone can turn off.
* Advanced features remain a separate FeedHat Pro plugin, not bundled here.
* Translation files are no longer bundled; translations will be handled on translate.wordpress.org after the plugin is published.

= 2.0.0 =
* Rebranded to FeedHat – On-Page Screenshot Feedback (slug and text domain `feedhat`) so the plugin name no longer collides with Microsoft Clarity. Existing settings migrate automatically. The Free review URL parameter is now `?feedhat=1`.

= 1.9.1 =
* Packaging cleanup so the WordPress.org listing contains only this plugin's own code.

= 1.9.0 =
* Added 10 more built-in translations: Spanish, French, German, Portuguese (Brazil), Italian, Japanese, Russian, Chinese (Simplified), Indonesian, and Dutch — on top of the existing English and Vietnamese. The widget automatically follows the site's language as before; Pro's language-override dropdown now offers all 12.

= 1.8.0 =
* Pin annotation tool: drop a numbered marker on the screenshot and optionally write a description for that pin, shown in wp-admin and included in CSV export.

= 1.7.4 =
* Fixed: uninstalling with "Delete all data" checked missed the Kanban board's one-time migration flag option, left behind in the database.

= 1.7.3 =
* Fixed: Kanban columns' cards started at different heights depending on whether that column had the "Closed in the last N days" note — all 4 columns now line up.
* Made Kanban cards more compact (smaller thumbnail, tighter padding and text) so more fit on screen at once.

= 1.7.2 =
* Settings → General now shows a "Feedback status tracking" section with a direct link to the auto-created tracking page — previously nothing in wp-admin mentioned this page exists or where to find it.

= 1.7.1 =
* Pro: the Kanban board's Resolved and Won't Fix columns now only show work closed in the last 30 days (sorted by when it was closed, not when it was originally submitted), instead of accumulating indefinitely and eventually crowding out New/In Progress. Older closed feedback is still fully available in the ordinary Feedback list.
* Pro: New/In Progress now show the oldest still-open items first, so nothing waiting a long time gets silently buried by newer submissions.

= 1.7.0 =
* Added a status-tracking link: every submission now gets a private link (shown right after sending) where the reporter can check its current status later, with no login or account needed. Uses an auto-created "Track Your Feedback" page — nothing to set up.

= 1.6.1 =
* Pro: the Kanban board now loads only each column's 100 most recent cards (with a link to the full list when there are more) instead of every feedback entry on the site at once — keeps it fast as a site accumulates feedback over time.
* Pro: the SLA reminder digest email is now capped at 50 overdue items (oldest first, with a note and a link to the rest) instead of listing every overdue entry unbounded.

= 1.6.0 =
* Redesigned the wp-admin Feedback Details view: status, submitted date, and reporter now sit in a quick-glance strip at the top instead of buried in a long table, and the remaining Browser/OS/Screen/Viewport/WordPress version fields are grouped under a "Page & environment" heading with icons for faster scanning.
* Redesigned the front-end feedback form's fields: lighter, more compact labels, a proper focus ring, and the Name/Email fields now sit side by side instead of stacked, since both are short and optional.

= 1.5.6 =
* The submit nonce now refreshes proactively the moment the panel opens, instead of only reactively after a first attempt fails — sending is unaffected either way, but this avoids the guaranteed-to-fail first request (visible as a 403 in the browser console) that a panel opened a while after page load used to always hit before recovering.

= 1.5.5 =
* Fixed: a feedback entry submitted by a logged-in visitor was always recorded as "Anonymous (not logged in)" instead of their actual account — WordPress's REST API doesn't trust a login cookie's identity without its own nonce, which this endpoint deliberately doesn't send (so anonymous visitors can submit at all); the visitor's identity is now resolved independently of that.

= 1.5.4 =
* Fixed: a screenshot with huge pixel dimensions (even at a small file size) could exhaust PHP's memory limit and crash the request — now rejected with a clean error before it's ever decoded.
* Fixed: if the screenshot storage folder couldn't be created or wasn't writable, saving could fail with a raw PHP warning instead of a clean error response.

= 1.5.3 =
* Fixed: uninstalling with "Delete all data" checked left the Pro CAPTCHA option (site/secret keys) behind — now cleaned up like every other setting.
* Pro: CSV export now includes the Title and Pin notes columns.

= 1.5.2 =
* Moved the Team tab to right after Targeting in Settings.

= 1.5.1 =
* Moved the Branding tab to right after General in Settings.

= 1.5.0 =
* Added 2 more button positions (Middle right / Middle left, centered on the side edge) and a 5th style, "Edge tab" (flush against the edge, vertical label on the Middle positions).
* Added a configurable "Distance from edge" (horizontal/vertical, in px) so the button can be nudged clear of another chat widget (Messenger, Zalo, Tawk.to…) already occupying that corner.

= 1.4.0 =
* Added 4 button styles (icon + label filled, icon + label outlined, icon only, text only) and 3 sizes (small/medium/large), with a live preview right in Settings.
* Pro: the Branding tab's logo field now has an "Upload logo" button (WordPress Media Library) supporting PNG, JPG, GIF, and SVG, instead of requiring a pasted URL.

= 1.3.0 =
* Pro: added Cloudflare Turnstile CAPTCHA, configurable from Settings → Security — verified server-side before a submission is ever saved.

= 1.2.0 =
* New Settings → Security tab: the submission rate limit (max submissions and time window) is now configurable instead of a fixed 3-per-10-minutes.
* Added an optional minimum-time-before-sending check — silently rejects submissions sent implausibly fast after the panel opens, catching simple bots without needing a CAPTCHA.

= 1.1.6 =
* The Send button now shows a spinner while submitting, and every field and drawing tool is locked for the duration of the request (and briefly after a successful send) so nothing can change mid-submit.

= 1.1.5 =
* Hardened the automatic stale-nonce recovery: the fresh-nonce request now explicitly bypasses the browser cache, so a retry can never be served a cached (and equally stale) nonce.

= 1.1.4 =
* Fixed: the new Title field now appears above "Your feedback" instead of below it.
* Fixed: the feedback panel could show a stray horizontal scrollbar.
* Fixed: the annotation toolbar is now centered instead of left-aligned.
* Fixed: toolbar tooltips were still getting covered by the screenshot preview below them; they now always render on top.

= 1.1.3 =
* Added an optional Title field to the feedback form — used as the entry's title in wp-admin instead of an auto-truncated snippet of the note.
* Pro: simplified the per-pin note popup to just a description field (title removed — the feedback form's own new Title field already covers that).
* Fixed: toolbar tooltips could get clipped at the top of the panel; they now show below the button instead of above.

= 1.1.2 =
* Pro: each pin can now have its own title/description, entered right where it's placed — shown as a numbered list under the screenshot in wp-admin.
* Redesigned the annotation toolbar: a compact rounded pill with icon-only buttons and hover tooltips instead of icon+label buttons.

= 1.1.1 =
* Pro: new "Pin" annotation tool in the screenshot toolbar — click to drop a numbered marker at an exact spot, alongside Rectangle/Arrow/Pen/Text.

= 1.0.3 =
* Fixed: the Kanban board's columns could still run off the edge of the screen on medium-width windows (e.g. a laptop with the admin sidebar expanded) — columns now wrap onto additional rows instead of relying on a single mobile breakpoint.

= 1.0.2 =
* Fixed: the Kanban board's columns could run off the edge of the screen on narrow/mobile viewports instead of stacking.
* Fixed: the Feedback Details screenshot + info layout didn't reliably stack into a single column on narrow/mobile viewports.

= 1.0.1 =
* Settings page reorganized into tabs (General, Targeting, Notifications, Team, Branding, Data) instead of one long scrolling form.
* Fixed: the automatic screenshot could sometimes capture the feedback panel itself instead of just the page behind it.
* Fixed: the feedback status shown in the admin list could be mislabeled when using Pro's Kanban board.

= 1.0.0 =
* Initial release.
* Floating feedback button with 4 corner positions, custom color and label.
* Automatic viewport screenshot capture with rectangle, arrow, freehand pen, text, and move/recolor annotation tools.
* Written feedback notes with auto-captured page URL, browser, OS, screen size, and submission time.
* Private custom post type storage with screenshots saved outside the Media Library.
* Admin feedback list with screenshot thumbnails, page/browser/OS columns, and one-click New/Resolved status toggling.
* Admin menu bell badge showing the count of new feedback.
* Admin toolbar "Feedback" notification with a list of the newest items, toggleable in Settings.
* Targeting by whole site, selected pages/posts, or a fixed `?feedhat=1` URL parameter.
* Single-recipient email notifications via `wp_mail()`.
* Settings page for configuring widget appearance, targeting, and email notifications.
* English and Vietnamese translations included.
