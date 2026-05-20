# Changelog

All notable changes to this project will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.8.2] - 2026-05-20
### Improved
- Confirmed WordPress 7.0 and PHP 7.4 compatibility metadata.
- Hardened admin actions and attachment saving with capability checks, nonce validation, unslashing, and sanitized request handling.

## [1.8.1] - 2026-05-11
### Added
- Added Plugin Update Checker for automatic updates from GitHub.
- Added branch-only update checks against the GitHub `main` branch.
- Added optional GitHub token support through `PLUGIN_UPDATE_GITHUB_TOKEN`.
- Added a WordPress.org-style `readme.txt` with Jason Cox as the only listed contributor.

### Improved
- Updated plugin metadata and WordPress compatibility to 6.9.4.

## [1.8.0] - 2026-04-18
### Added
- Renamed the visible plugin name to Members-Only Media.
- Added support for documents: `doc`, `docx`, `xls`, `xlsx`, `ppt`, `pptx`, `odt`, `ods`, `odp`, `rtf`, `txt`, and `csv`.
- Added support for archives: `zip`, `gz`, `gzip`, `tar`, `tgz`, `rar`, and `7z`.
- Added Media Library filters for Locked Documents and Locked Archives.
- Added WordPress and PHP requirement headers.

### Improved
- Updated admin/settings copy from PDF-specific language to media-focused language.

## [1.7.0] - 2026-04-18
### Added
- Added support for protected images.
- Added support for protecting generated image sizes.
- Added protected URL handling for image thumbnails, intermediate sizes, and `srcset` sources.
- Added Media Library filters for Locked PDFs & Images and Locked Images.

### Improved
- Generalized protected file streaming from PDF-only responses to file-type aware responses.
- Added version-aware rewrite flushing for protected file routes.

## [1.6.2] - 2026-04-18
### Added
- Added a Locked PDFs filter to the WordPress Media Library dropdown.

### Improved
- Filtered locked PDFs server-side through the Media Library AJAX query instead of only hiding items client-side.

## [1.6.1] - 2026-04-18
### Added
- Added a Secured PDFs table in plugin settings.
- Added a one-click Unlock action that moves protected PDFs back into regular Media Library uploads.
- Added tracking for generated PDF preview files.

### Improved
- Made file protection and unprotection transactional so failed moves do not falsely mark files protected.
- Protected generated PDF preview images along with the original PDF.
- Hardened protected directory checks with real path validation.
- Allowed configured custom redirect URLs to work outside the current site host.
- Made role checkboxes submit real values, with the hidden CSV field kept as a fallback.
- Strengthened the Nginx direct-access warning.
- Cleaned generated zip packages by removing macOS metadata.

## [1.3.2] - 2025-10-11
### Added
- Custom Forbidden Redirect (configurable page or URL).
- Lock icon in Media Grid for protected PDFs.
- Protected URL visible in Media edit view.
- Repair Routes button to auto-flush permalinks.

### Improved
- More reliable rewrite registration and `.htaccess` handling.
- Streamlined admin settings and file movement logic.

### Fixed
- Intermittent lock icon display.
- 404s after migration.
