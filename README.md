# Members-Only PDFs

A lightweight, non-branded WordPress plugin that protects selected PDF attachments so only logged-in users with specific roles can access them.  
Designed for small organizations, unions, and private communities that need fine-grained file-level access control — without requiring a full membership plugin.

---

## ✨ Features

- **Per-File Protection:** Toggle “Members-Only” on any PDF directly in the WordPress Media Library.
- **Per-File Role Access:** Choose one or more roles allowed to view each file (works with custom roles).
- **Protected URLs:** Pretty permalink format:

https://example.com/members-file/{id}/{Original-Filename}.pdf

- **Role Checkboxes:** Simple checkboxes (multi-select) for assigning viewing access.
- **Lock Icon in Media Library:** Visual indicator for protected PDFs (grid and modal views).
- **Automatic Folder Protection:**
- Files are moved to `/wp-content/uploads/members-only/`
- `.htaccess` is auto-generated to block direct access.
- **Configurable Redirects:**
- **Not Logged In:** Redirect to login, page, or custom URL.
- **Logged In but No Permission:** Redirect to page or custom URL instead of showing a 403.
- **Protected URL Display:** Easily copy the protected link from the Media edit screen.
- **“Repair Routes” Button:** One-click permalinks flush to fix 404 errors after migration.

---

## 🧰 Installation

1. Upload the plugin folder to:

/wp-content/plugins/members-only-pdfs/

2. Activate **Members-Only PDFs** from **Plugins → Installed Plugins**.
3. Go to **Settings → Permalinks → Save Changes** once to register routes.

---

## 🛠 Usage

### 1. Protect a PDF
- Go to **Media → Library → Edit** a PDF.
- Check **“Members-Only”**.
- Choose one or more **roles** that can view it.
- Copy the **Protected URL** to share with members.

### 2. Configure Redirects
Visit **Settings → Members-Only PDFs** to choose:

- When **user is not logged in**:
- 🔹 WordPress login screen
- 🔹 Specific Page
- 🔹 Custom URL
- When **user lacks permission**:
- 🔹 Do nothing (send 403)
- 🔹 Specific Page
- 🔹 Custom URL

Example: Create a page titled “Access Restricted” and select it for the forbidden redirect.

### 3. Repair Routes
If a protected link returns a 404:
- Go to **Settings → Members-Only PDFs → Repair Routes**, or  
- Visit **Settings → Permalinks → Save Changes**.

---

## 🔒 Security

- Protected PDFs are physically stored inside `/wp-content/uploads/members-only/`.
- `.htaccess` rules deny direct access (Apache/LiteSpeed).
- Nginx users should add this to their site config:
```nginx
location ^~ /wp-content/uploads/members-only/ {
   deny all;
   return 403;
}

	•	Logged-in users must have at least one of the allowed roles per file.
	•	Admins always bypass restrictions.
	•	Custom roles are fully supported.

⸻

🧩 Compatibility

✅ Tested with:
	•	WordPress 6.8+
	•	PHP 8.1–8.3
	•	Apache, LiteSpeed, and Nginx

✅ Works alongside:
	•	Classic Editor or Block Editor
	•	Custom roles from plugins like Members, User Role Editor, or WPFront.

⸻

🧑‍💻 Author

Author: Jason Cox
GitHub: jcjason12108-alt

⸻

📄 License

This project is licensed under the GPL-2.0-or-later license.

⸻
🧠 Quick Summary

Feature	Description
🔐 Members-Only Toggle	Enable protection on a per-PDF basis
👥 Role Access	Multi-role checkboxes (supports custom roles)
🚫 Forbidden Redirect	Send unauthorized users to a custom page
🔗 Pretty URLs	/members-file/{id}/{filename}
⚙️ .htaccess Guard	Auto-created on activation
🧭 Repair Routes	Fix 404s with one click
💡 Visual Cues	Lock icon in Media Grid
🪄 Developer-Friendly	Clean, non-branded, extensible PHP code


⸻

Members-Only PDFs — a simple, transparent way to keep your PDFs for members only.

This readme was generated using ChatGpt

