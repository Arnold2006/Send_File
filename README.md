# SendFile

Anonymous, zero-registration ZIP file sharing.  
Built with PHP 8.x · Tailwind CSS · Vanilla JavaScript · Apache.

---

## Features

- **Single-file app** — everything lives in `index.php`
- **Anonymous** — no accounts, no database, no server-side logs
- **ZIP only** — validates both file extension and magic bytes
- **Up to 2 GB** — Apache / PHP limits pre-configured in `.htaccess`
- **Auto-deletion** — uploaded files are removed after 48 hours
- **HTTPS-ready** — designed to run behind pfSense + HAProxy (TLS terminated upstream)

---

## Requirements

| Component | Version |
|-----------|---------|
| PHP       | 8.0+    |
| Apache    | 2.4+    |
| Extensions| `fileinfo` (usually bundled) |

---

## Deployment

### 1. Upload files

Copy the repository contents to your Apache document root (e.g. `/var/www/html/sendfile/`):

```
index.php
.htaccess
uploads/
uploads/.htaccess
```

### 2. Apache VirtualHost

```apache
<VirtualHost *:80>
    ServerName sendfile.example.com
    DocumentRoot /var/www/html/sendfile

    <Directory /var/www/html/sendfile>
        AllowOverride All
        Require all granted
    </Directory>

    # Suppress access logs for privacy
    CustomLog /dev/null combined
    ErrorLog  /dev/null
</VirtualHost>
```

> **Note:** HTTPS must be terminated by HAProxy / pfSense upstream.  
> Apache only listens on port 80 internally.

### 3. Permissions

```bash
chown -R www-data:www-data /var/www/html/sendfile
chmod 750 /var/www/html/sendfile/uploads
```

### 4. PHP configuration (if `.htaccess` overrides are not available)

Add to `php.ini` or your pool's `php-fpm.conf`:

```ini
upload_max_filesize = 2048M
post_max_size       = 2200M
max_input_time      = 7200
max_execution_time  = 7200
```

---

## How it works

| Action | Mechanism |
|--------|-----------|
| **Upload** | `POST /?action=upload` — PHP stores the ZIP in `uploads/<token>/` |
| **Share**  | User receives a URL: `https://example.com/?d=<token>` |
| **Download** | `GET /?d=<token>&dl=1` — PHP streams the file via `readfile()` |
| **Cleanup** | Every request triggers a scan; files older than 48 h are deleted |

### Storage structure

```
uploads/
  <16-byte-hex-token>/
    .htaccess    ← blocks direct HTTP access
    .meta        ← Unix timestamp of upload (no user data)
    .filename    ← sanitised original filename
    archive.zip  ← the actual file
```

No database is used. The token is the only identifier.

---

## Privacy

- No IP addresses, user-agents, or any other data are stored.
- The `.meta` file contains only an integer Unix timestamp.
- Apache access logging should be disabled in the VirtualHost (`CustomLog /dev/null combined`).
- PHP error logging is disabled at runtime.

---

## Security notes

- Direct access to `uploads/` is blocked by both `.htaccess` rules and a `Rewrite` rule.
- File validation checks both the `.zip` extension and the MIME type (magic bytes via `ext-fileinfo`).
- Filenames are sanitised (only alphanumeric, `.`, `-`, `_` characters allowed).
- The download token is 32 hex characters (128-bit random, `random_bytes()`).
- Security headers (`X-Content-Type-Options`, `X-Frame-Options`, etc.) are set via `.htaccess`.
