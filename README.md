# SEO Health Widget

A WordPress plugin that adds an **SEO Health** widget to the client's dashboard. It shows how the site's Google search traffic is trending and flags on-site SEO problems, so issues get spotted before they show up in a monthly report.

Version 0.1.0.

## Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Setup](#setup)
- [Headless sites](#headless-sites)
- [Troubleshooting](#troubleshooting)
- [Who sees the widget](#who-sees-the-widget)
- [Customizing](#customizing)
- [What the audit doesn't catch](#what-the-audit-doesnt-catch)
- [Uninstalling](#uninstalling)
- [Code structure](#code-structure)
- [License](#license)

## Overview

### What the widget shows

**Google Search**, from Search Console:

- Clicks, impressions, click-through rate and average position, each compared with the previous period
- A trend line of daily clicks
- The top 5 search queries

You choose the comparison: last full month vs. the month before, or the last 28 days vs. the 28 days before. Search Console data arrives 2–3 days late, so the 28-day window ends 3 days ago, and on the 1st–3rd of each month the month view keeps comparing the previous pair of full months until last month's data is complete.

**Site audit**, run once a day on the site itself:

- Whether "Discourage search engines" is switched on, which hides the whole site from Google
- Pages set to noindex
- Pages with no meta description
- Broken internal links: links to deleted or unpublished content, or to URLs that return 404/410
- Orphaned pages: pages nothing links to, from content or menus

Each issue expands to list the affected pages, with links to edit them. If some links couldn't be checked (for example because the site blocks its own requests), the widget says so in a separate row, so a failed check is never mistaken for a clean result.

### How it works

- The plugin connects to Search Console with a Google **service account**, a Google login for software. Each client gets their own service account, added read-only to their Search Console property. Clients don't have to sign in or share their Google accounts.
- Search data is refreshed in the background twice a day, so the dashboard loads without waiting on Google. That's about 8 API calls a day per site. The Search Console API is free, and no Google billing account is needed.
- The link check works out most links from WordPress's own data (posts, pages, category/tag/author/date archives, uploaded files) and only requests the page over HTTP when it can't, up to 50 HTTP checks per audit.
- The audit reads data from whichever SEO plugin is active: Yoast SEO, Rank Math, SEOPress or All in One SEO.
- The audit runs on the site's own server, once a day. Large sites are checked in batches in the background, so it works on sites with thousands of pages.
- It works on standard WordPress sites and on headless sites, where a separate front end (Next, Nuxt, etc.) serves the public pages. See [Headless sites](#headless-sites).
- Apart from the Search Console requests, the only outside requests are link checks against the site's own public pages.

### Setup at a glance

1. Once: create a Google Cloud project.
2. For each site: create a service account and key, add it to the site's Search Console property, install the plugin, add the key and the property, then test.

The [Setup](#setup) section covers each part in detail. Setup takes about 5 minutes per site once the Google Cloud project exists.

## Requirements

- WordPress 6.0+
- PHP 7.4+ with the OpenSSL extension
- WP-Cron running (the audit runs once a day). If the site sets `DISABLE_WP_CRON`, make sure a server cron calls `wp-cron.php`. If [Action Scheduler](https://actionscheduler.org/) is installed (WooCommerce and many other plugins include it), the plugin uses it to run large audits faster.
- Unique security keys in `wp-config.php` (`AUTH_KEY` and `AUTH_SALT`). A pasted service account key is encrypted with them.
- Optional: Yoast SEO, Rank Math, SEOPress or All in One SEO. Without one, the noindex and meta description checks are skipped.

## Setup

### 1. One-time Google Cloud setup

1. In [Google Cloud Console](https://console.cloud.google.com/), create a project (e.g. "SEO Health"). No billing account is needed.
2. Go to **APIs & Services > Library** and enable the **Google Search Console API**.

### 2. Create a service account for the client

Use a separate service account for each client. If one client's site is ever compromised, its key can only read that client's data, not every client's.

1. In the project, go to **IAM & Admin > Service Accounts** and click **Create service account**. Name it after the client (e.g. `seo-health-acme`). It doesn't need any project roles.
2. Open the new account, go to **Keys > Add key > Create new key > JSON**. A key file downloads.
3. Keep the file out of email, chat and git. You'll put it on the client's server in step 5.

### 3. Give the service account access to the client's property

In the client's Search Console property, go to **Settings > Users and permissions > Add user**. Add the service account's email (`seo-health-acme@<project>.iam.gserviceaccount.com`) with **Restricted** permission, which is read-only.

### 4. Install the plugin

Clone the repository into the site's plugins folder:

```bash
git clone https://github.com/idrewlong/seo-heath-widget.git wp-content/plugins/seo-health-widget
```

Or download it and copy the files into `wp-content/plugins/seo-health-widget/`. Then activate **SEO Health Widget** under **Plugins**.

### 5. Configure it

Go to **Settings > SEO Health**:

| Setting                  | What to enter                                                                                                                                                       |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Property**             | Exactly as Search Console shows it: `sc-domain:example.com` or `https://www.example.com/`                                                                           |
| **Service account key**  | Leave blank if you use the key file (recommended, below). Otherwise paste the JSON key. It's stored encrypted and never shown again; paste a new one to replace it. |
| **Comparison period**    | Last full month vs. the month before (default), or rolling 28 days                                                                                                  |
| **Site type**            | **Standard** if WordPress serves the public site, **Headless** if a separate front end does                                                                         |
| **Front-end URL**        | Headless sites only: the public site URL, e.g. `https://www.example.com`. Add any extra front-end domains on new lines.                                             |
| **Max items scanned**    | Upper limit on published items checked per audit (default 5,000). Large sites are checked in batches.                                                               |

**Key file (recommended):** upload the key file to the server outside the web root, make it readable only by the web server user (e.g. `chmod 600` or `640`), and add this to `wp-config.php`. The key then never touches the database, and the paste field is hidden.

```php
define( 'SEOHW_SERVICE_ACCOUNT_FILE', '/home/site/private/seohw-key.json' );
```

### 6. Check it works

1. Click **Test connection**. You should see "Connected. Permission level: siteRestrictedUser" (or similar).
2. Click **Run audit now**. Small sites finish straight away. On large sites the audit continues in the background, and the settings page and widget show its progress.
3. Open the dashboard. The **SEO Health** widget should show search data and audit results.

## Troubleshooting

| Message                                                                    | Fix                                                                                                                                                                           |
| -------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Search Console API error (403) or (404)                                    | The property doesn't match Search Console exactly (check `sc-domain:` vs. URL, `www`, trailing slash), or the service account hasn't been added as a user.                    |
| Google token error: Invalid grant                                          | The key has been deleted or disabled in Google Cloud. Create a new key and paste it in.                                                                                       |
| The service account key is not valid JSON                                  | Paste the whole downloaded file, including the braces.                                                                                                                        |
| The file in SEOHW_SERVICE_ACCOUNT_FILE is missing or not readable          | Check the path in `wp-config.php` and that PHP can read the file.                                                                                                             |
| The PHP OpenSSL extension is required                                      | Ask the host to enable OpenSSL.                                                                                                                                               |
| The saved service account key can't be decrypted                           | The security keys in `wp-config.php` changed (for example after a migration or a security reset). Paste the key again, or switch to the key file.                             |
| Headless site: many links reported as returning 404                        | Check **Site type** is set to **Headless** and **Front-end URL** is the public site, not the WordPress back end.                                                              |
| "N internal links couldn't be checked" with "no response", 401, 403 or 429 | A security plugin, firewall (e.g. Cloudflare) or the host is blocking the site's requests to itself. Allow the `SEO Health Widget` user agent or the server's IP.             |
| "N internal links couldn't be checked" with "check limit reached"          | The audit used its 50 HTTP checks. Raise the limit with the `seohw_max_remote_checks` filter.                                                                                 |
| "Audit in progress" stays stuck for hours                                  | WP-Cron only runs when someone visits the site. On low-traffic sites, set up a server cron for `wp-cron.php`. After an hour without progress, the next daily run starts over. |
| "The first audit has not run yet" for more than a day                      | WP-Cron isn't running. Click **Run audit now** and check the site's cron setup.                                                                                               |

Search data is refreshed in the background twice a day. After changing settings, admins can click **Refresh search data** at the bottom of the widget to fetch it straight away.

## Headless sites

Set **Site type** to **Headless** and enter the public front-end URL. The audit then works like this:

- **Links:** links to the WordPress domain and to the front-end domain both count as internal. Links that don't match a WordPress post or page are checked against the front end, which is what visitors actually see. So a page that exists only in the front-end app isn't reported as broken.
- **"Discourage search engines":** on a headless site this setting only hides the WordPress back end, which is often intended. The widget shows it as information instead of an error.
- **Noindex and meta descriptions:** these are still read from the SEO plugin. Make sure the front end actually outputs them (for example via WPGraphQL for Yoast or the SEO plugin's REST fields).
- **Orphaned pages:** WordPress menus count as links. If the front end hard-codes its navigation, pages linked only from there will show as orphaned. Remove `page` from orphan checks with the `seohw_orphan_post_types` filter if that's noisy.
- **Search Console property:** use the public front-end domain, not the WordPress back-end domain.

## Who sees the widget

- Anyone who can `edit_posts` (editors, authors, admins).
- Only admins see setup links, raw API error messages and the **Refresh search data** link. Other users see a generic "temporarily unavailable" message on errors.

## Customizing

Add these filters in a theme or must-use plugin:

| Filter                    | Default                             | Purpose                                                                                                             |
| ------------------------- | ----------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `seohw_widget_capability` | `edit_posts`                        | Who sees the dashboard widget                                                                                       |
| `seohw_audit_post_types`  | all public types except attachments | What gets audited                                                                                                   |
| `seohw_orphan_post_types` | `['page']`                          | Which types are checked for orphans. Blog posts are reachable through archives, so they're off by default.          |
| `seohw_max_remote_checks` | `50`                                | HTTP checks per audit for links that don't resolve to a post                                                        |
| `seohw_audit_time_budget` | `20`                                | Seconds of audit work per request before handing off to the next. Lower it on hosts with short request time limits. |

For example, to show the widget to admins only:

```php
add_filter( 'seohw_widget_capability', fn() => 'manage_options' );
```

## What the audit doesn't catch

- A page counts as missing a meta description when the SEO plugin's per-page field is empty, even if the site outputs a templated one (such as the excerpt).
- Noindex set for a whole post type in the SEO plugin's global settings isn't detected, only per-page settings.
- Links are read from post content only. Links in page-builder layouts stored in post meta (Elementor, Divi) or in theme templates aren't scanned, so those pages can show as orphaned.
- Archive links (categories, tags, authors, dates) count as working when the archive exists in WordPress. On a headless site, that assumes the front end uses the same URLs as WordPress.
- On headless sites, a front end that returns a normal page (status 200) for every URL, including missing ones, hides broken links from the check. The front end should return a real 404 status for missing pages.

## Uninstalling

Deleting the plugin from **Plugins** removes its settings, saved key, cached data, audit results and scheduled tasks. If you used `SEOHW_SERVICE_ACCOUNT_FILE`, remove the key file and the `wp-config.php` line yourself.

## Code structure

| File                            | Class                | What it does                                                                                           |
| ------------------------------- | -------------------- | ------------------------------------------------------------------------------------------------------ |
| `seo-health-widget.php`         |                      | Plugin header, constants, loads the classes and registers activation/deactivation hooks                |
| `includes/class-settings.php`   | `SEOHW_Settings`     | **Settings > SEO Health** page, key storage, and the Test connection / Run audit now / Refresh actions |
| `includes/class-gsc-client.php` | `SEOHW_GSC_Client`   | Search Console API client: service account sign-in, date ranges, caching and background refresh        |
| `includes/class-audit.php`      | `SEOHW_Audit`        | The site audit, run in resumable batches                                                               |
| `includes/class-seo-meta.php`   | `SEOHW_SEO_Meta`     | Reads noindex and meta description data from Yoast, Rank Math, SEOPress or All in One SEO              |
| `includes/class-widget.php`     | `SEOHW_Widget`       | The dashboard widget                                                                                   |
| `assets/widget.css`             |                      | Widget styles                                                                                          |
| `uninstall.php`                 |                      | Removes the plugin's data when it's deleted                                                            |

Everything the plugin adds to WordPress uses the `seohw_` / `SEOHW_` prefix: options, filters, scheduled tasks, constants and class names.

## License

GPL-2.0-or-later, the same license as WordPress. See [LICENSE](LICENSE).
