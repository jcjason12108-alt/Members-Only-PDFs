# Members-Only Media

A lightweight WordPress plugin that protects selected Media Library files so only logged-in users with specific roles can access them.

It is designed for small organizations, unions, private communities, and internal sites that need file-level access control without requiring a full membership plugin.

## Supported Media Types

Members-Only Media currently supports:

- PDFs: `pdf`
- Images: `jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`, `bmp`, `tif`, `tiff`, `ico`, `heic`, `heif`
- Documents: `doc`, `docx`, `xls`, `xlsx`, `ppt`, `pptx`, `odt`, `ods`, `odp`, `rtf`, `txt`, `csv`
- Archives: `zip`, `gz`, `gzip`, `tar`, `tgz`, `rar`, `7z`

Audio and video are not included yet because they need byte-range streaming support for reliable playback and seeking.

## Requirements

- WordPress 5.8 or newer
- PHP 7.4 or newer
- Local WordPress uploads storage under `wp-content/uploads`
- Apache/LiteSpeed, or an Nginx rule that blocks direct access to the protected folder

## Features

- Per-file protection from the WordPress Media Library
- Per-file role access with checkbox selection
- Support for custom WordPress roles
- Protected URLs in this format:

```text
https://example.com/members-file/{id}/{Original-Filename}
```

- Protected URL copy field in the Media edit screen
- Lock icon overlay for protected files in the Media Library grid
- Media Library filters for:
  - Locked Media
  - Locked PDFs
  - Locked Images
  - Locked Documents
  - Locked Archives
- Secured Media table in the plugin settings
- One-click unlock action that moves protected files back into regular uploads
- Generated image sizes are protected along with the original image
- PDF preview images are protected along with the original PDF
- Automatic `.htaccess` generation for Apache/LiteSpeed
- Configurable redirects for:
  - users who are not logged in
  - logged-in users who do not have permission
- Repair Routes button to flush rewrite rules after migrations or permalink issues

## How It Works

When a file is protected, the plugin moves the original file into:

```text
wp-content/uploads/members-only/
```

For images, generated sizes such as thumbnail, medium, and large files are moved too. For PDFs, generated preview images are also moved when WordPress has created them.

The original public Media Library URL is replaced with a protected route. When someone visits that protected route, WordPress checks:

1. Whether the visitor is logged in.
2. Whether the file is protected.
3. Whether the user has one of the allowed roles.
4. Whether the requested file exists inside the protected folder.

If access is allowed, WordPress streams the file. If access is denied, the plugin redirects or returns a `403` depending on the configured settings.

## Installation

1. Upload the plugin folder to:

```text
wp-content/plugins/members-only-pdfs/
```

The folder may retain the older `members-only-pdfs` slug for update continuity, even though the plugin name is now Members-Only Media.

2. Activate **Members-Only Media** from **Plugins -> Installed Plugins**.
3. Go to **Settings -> Members-Only Media** to configure redirects.
4. If protected links return `404`, use **Repair Routes** or visit **Settings -> Permalinks -> Save Changes**.

## Usage

### Protect a Media File

1. Go to **Media -> Library**.
2. Open a supported file.
3. Check **Members-Only**.
4. Select one or more roles that can view the file.
5. Save the media item.
6. Copy the **Protected URL** and share that URL instead of the original upload URL.

If no roles are selected, only administrators with `manage_options` can access the protected file.

### View Locked Files

In **Media -> Library**, use the media type dropdown to filter by:

- Locked Media
- Locked PDFs
- Locked Images
- Locked Documents
- Locked Archives

Protected files also show a lock icon in the Media Library grid.

### Unlock a File

Visit **Settings -> Members-Only Media** and use the **Secured Media** table.

Click **Unlock** next to a file to:

- remove protected status
- move the file back into regular uploads
- move generated image sizes or PDF previews back when available

## Redirect Settings

Visit **Settings -> Members-Only Media** to configure what happens when access is denied.

For visitors who are not logged in:

- send to the WordPress login screen
- send to a specific page
- send to a custom URL

For logged-in users who lack permission:

- return a `403 Forbidden`
- send to a specific page
- send to a custom URL

## Security Notes

On Apache and LiteSpeed, the plugin writes an `.htaccess` file inside:

```text
wp-content/uploads/members-only/
```

That file blocks direct browser access to protected files.

For Nginx, add a server rule like this:

```nginx
location ^~ /wp-content/uploads/members-only/ {
    deny all;
    return 403;
}
```

Important limitations:

- This plugin depends on the protected folder being blocked from direct web access.
- Nginx users must add the deny rule manually.
- Media offload plugins such as S3, Cloudflare R2, Bunny, or DigitalOcean Spaces may need custom integration.
- Hosts with read-only uploads folders or restricted file permissions may prevent files from being moved.
- SVG is intentionally not supported because it needs separate sanitization and security handling.
- Audio and video are intentionally not supported yet because they need range-request streaming.

## Compatibility

Expected to work with:

- WordPress 5.8+
- PHP 7.4+
- Apache and LiteSpeed
- Nginx with the required deny rule
- Classic Editor and Block Editor
- Custom roles from plugins such as Members, User Role Editor, or WPFront User Role Editor

Test carefully before relying on it with:

- WordPress multisite
- managed hosting with restricted filesystem access
- media offload/CDN plugins
- non-standard uploads paths

## Quick Summary

| Feature | Description |
| --- | --- |
| Members-Only Toggle | Enable protection per supported media file |
| Role Access | Allow one or more WordPress roles per file |
| Protected URLs | Serve files through `/members-file/{id}/{filename}` |
| Lock Icons | Show protected files in the Media Library grid |
| Locked Filters | Filter locked media by file group |
| Secured Media List | Review and unlock protected files from settings |
| Folder Guard | Auto-created `.htaccess` for Apache/LiteSpeed |
| Redirects | Configure login and forbidden behavior |
| Repair Routes | Flush rewrite rules from the plugin settings |

## Author

Jason Cox  
GitHub: [jcjason12108-alt](https://github.com/jcjason12108-alt)

## License

This project is licensed under the GPL-2.0-or-later license.



