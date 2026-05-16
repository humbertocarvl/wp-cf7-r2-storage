# CF7 R2 Storage

A WordPress plugin that sends **Contact Form 7** file attachments directly to **Cloudflare R2** instead of storing them on your server.

> Plugin WordPress que envia os anexos do Contact Form 7 diretamente para o Cloudflare R2, evitando o uso de armazenamento no servidor.

---

## Features

- Uploads CF7 attachments to Cloudflare R2 on form submission
- Generates **presigned URLs** (time-limited, secure access to private buckets)
- Supports a **custom public URL** (R2.dev subdomain or custom domain) for permanent links
- Injects R2 download links into the notification email body
- Removes temporary files from the server after upload
- Choose **which forms** should have their attachments sent to R2
- Compatible with **CFDB7** (Contact Form CFDB7): stores R2 URLs in the database and fixes the display links in the admin panel
- No external libraries — uses native PHP cURL and AWS Signature Version 4

---

## Requirements

| Dependency | Version |
|---|---|
| WordPress | 6.0+ |
| PHP | 7.4+ |
| Contact Form 7 | Any recent version |
| Cloudflare R2 | Active bucket + API token |

**Optional:** [Contact Form CFDB7](https://wordpress.org/plugins/contact-form-cfdb7/) — fully supported.

---

## Installation

**Option A — Git (recommended)**

```bash
cd wp-content/plugins
git clone https://github.com/humbertocarvl/wp-cf7-r2-storage.git cf7-r2-storage
```

**Option B — ZIP download**

1. Go to the [Releases](https://github.com/humbertocarvl/wp-cf7-r2-storage/releases) page and download `cf7-r2-storage.zip`.
2. In WordPress Admin, go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate the plugin.

> The ZIP from the Releases page already contains the correct folder name. Do **not** use the *Code → Download ZIP* button — that ZIP requires renaming the folder to `cf7-r2-storage` before installing.

After installing:

3. Activate the plugin in **WordPress Admin → Plugins**.
4. Go to **Settings → CF7 R2 Storage** and fill in your credentials.

---

## Cloudflare R2 Setup

1. In the [Cloudflare Dashboard](https://dash.cloudflare.com/), go to **R2 Object Storage**.
2. Create a bucket (or use an existing one). It can be **private** — the plugin generates presigned URLs.
3. Go to **R2 Overview → Manage R2 API tokens** and create a token with **Object Read & Write** permission scoped to your bucket.
4. Copy the **Account ID**, **Access Key ID**, and **Secret Access Key**.

> **Para links permanentes sem expiração:** ative o "Subdomínio R2.dev" do bucket nas configurações do R2, copie a URL gerada e cole no campo "URL pública do bucket" nas configurações do plugin.

---

## Configuration

Go to **Settings → CF7 R2 Storage** (`/wp-admin/options-general.php?page=cf7-r2-storage`).

| Field | Description |
|---|---|
| Account ID | Your Cloudflare account ID (found on R2 Overview) |
| Access Key ID | R2 API token access key |
| Secret Access Key | R2 API token secret key (stored securely, never shown again) |
| Bucket Name | Exact name of your R2 bucket |
| Public bucket URL | *(Optional)* R2.dev URL or custom domain for permanent public links |
| Folder prefix | *(Optional)* Path prefix inside the bucket, e.g. `uploads/cf7` |
| Presigned link expiry | Time-to-live in seconds for download links. Default: `604800` (7 days). Set `0` to use the default. |
| Enabled forms | *(Optional)* Select specific CF7 forms to intercept. If none selected, **all forms** are intercepted. |

---

## How it works

```
CF7 form submitted
       │
       ▼  (priority 5)
handle_uploads()
  ├─ Upload each file to R2 via PUT (AWS Sig v4, UNSIGNED-PAYLOAD)
  ├─ Generate presigned GET URL
  └─ Inject R2 links into the email body
       │
       ▼  (priority 10 — CFDB7, if active)
CFDB7 saves submission
  ├─ cfdb7_before_file_copy → skips local copy
  └─ cfdb7_before_save_data → stores R2 URLs in DB
       │
       ▼  (priority 20)
delete_temp_files()
  └─ Removes temporary files from the server
```

---

## CFDB7 Compatibility

When the [CFDB7](https://wordpress.org/plugins/contact-form-cfdb7/) plugin is active, CF7 R2 Storage:

- Prevents CFDB7 from copying files to `cfdb7_uploads/` on the server
- Stores the presigned R2 URL as the file reference in the database
- Fixes the file links in the **CFDB7 admin view** via JavaScript so they open the correct R2 URL

---

## License

[GPL-2.0-or-later](LICENSE) © [humbertocarvl](https://github.com/humbertocarvl)
