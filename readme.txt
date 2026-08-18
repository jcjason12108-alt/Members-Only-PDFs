=== Members-Only Media ===
Contributors: Jason Cox
Plugin URI: https://github.com/jcjason12108-alt/Members-Only-PDFs
Tags: media, members, private files, pdf, access control
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.8.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protect selected Media Library files so only logged-in users with allowed roles can access them.

== Description ==

Members-Only Media protects selected PDFs, images, documents, and archives from public access. Protected files are moved into a guarded uploads folder and served through WordPress after login and role checks.

Features include per-file role access, protected URLs with original filenames, locked media filters, generated image/PDF preview protection, configurable login and forbidden redirects, and a repair button for rewrite routes.

== Installation ==

1. Upload the `members-only-pdfs` folder to `/wp-content/plugins/`.
2. Activate Members-Only Media from Plugins.
3. Go to Settings > Members-Only Media to configure redirects.

== Frequently Asked Questions ==

= Does this work without GitHub Releases? =

Yes. This plugin uses Plugin Update Checker against the `main` branch of the GitHub repository.

= Does Jason Cox remain the only contributor? =

Yes. Jason Cox is the only listed contributor.

== Changelog ==

= 1.8.3 =
* Updated the bundled Plugin Update Checker library from 5.6 to 5.7.
* Added explicit attribute escaping for custom redirect settings.

= 1.8.2 =
* Confirmed WordPress 7.0 and PHP 7.4 compatibility metadata.
* Hardened admin actions and attachment saving with capability checks, nonce validation, unslashing, and sanitized request handling.

= 1.8.1 =
* Added Plugin Update Checker for automatic updates from GitHub.
* Added branch-only GitHub update detection against the `main` branch.
* Added optional GitHub token support through `PLUGIN_UPDATE_GITHUB_TOKEN`.
* Updated plugin metadata and WordPress compatibility to 6.9.4.
* Added WordPress.org-style readme metadata with Jason Cox as the only contributor.

= 1.8.0 =
* Renamed the visible plugin name to Members-Only Media.
* Added support for documents and archives.
* Added Media Library filters for Locked Documents and Locked Archives.
* Added WordPress and PHP requirement headers.
