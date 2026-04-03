# Send_File

A self-hosted, WeTransfer-inspired file sharing application.  
**Stack:** React + Tailwind CSS · Node.js + Express · SQLite · PM2 · Nginx (AAPanel v8)

---

## Network Architecture

```
Browser (HTTPS)
      │
      ▼
pfSense Firewall  ──  port-forwards 443 → HAProxy
      │
      ▼
HAProxy  ←  SSL/TLS termination (Let's Encrypt cert lives here)
      │  plain HTTP (e.g. port 80 or a dedicated backend port)
      ▼
AAPanel v8 / Nginx  ←  this server, HTTP only, no SSL config needed
      │  proxy_pass → 127.0.0.1:3001
      ▼
Node.js / Express (PM2)  ←  HTTP only, never exposed directly to the internet
```

**HAProxy and pfSense handle all HTTPS.**  
Nginx in AAPanel is a plain HTTP reverse proxy — no SSL certificates are configured there.

---

## Features

| Feature | Detail |
|---|---|
| Drag & drop upload | Multi-file, up to 20 files × 2 GB each |
| Per-file progress bars | Real-time upload progress |
| Unique download links | One shareable URL per upload |
| Password protection | Optional bcrypt-hashed password per upload |
| Expiration control | 1 h – 7 days, auto-deleted by background cron |
| ZIP download | Multiple files bundled into a single ZIP |
| Download statistics | Count and last-accessed timestamp per file |
| Admin API | Monitor and delete uploads via secret key |
| Rate limiting | Per-IP upload and API request limits |

---

## Project Structure

```
Send_File/
├── backend/
│   ├── server.js               # Express entry point
│   ├── database.js             # SQLite schema & singleton connection
│   ├── cleanup.js              # Hourly cron — deletes expired files
│   ├── package.json
│   ├── .env.example            # Copy to .env and fill in values
│   ├── middleware/
│   │   └── rateLimiter.js
│   └── routes/
│       ├── upload.js           # POST /api/upload
│       ├── download.js         # GET  /api/download/:groupId[/:fileId]
│       └── admin.js            # GET  /api/admin/stats
├── frontend/
│   ├── public/index.html
│   ├── src/
│   │   ├── App.js
│   │   ├── index.js / index.css
│   │   └── components/
│   │       ├── UploadPage.jsx
│   │       ├── DownloadPage.jsx
│   │       └── ProgressBar.jsx
│   ├── package.json
│   ├── tailwind.config.js
│   └── postcss.config.js
├── uploads/                    # Created at runtime — do NOT commit
├── data/                       # SQLite DB — created at runtime
├── logs/                       # PM2 logs — created at runtime
├── ecosystem.config.js         # PM2 configuration
└── nginx.conf.template         # Nginx site config for AAPanel v8
```

---

## Deployment on AAPanel v8

### Prerequisites

Install the following from the **AAPanel Software Store**:
- **Nginx** (any recent version)
- **Node.js** ≥ 18 (via the Node.js version manager in AAPanel)
- **PM2** (install globally after Node.js: `npm install -g pm2`)

---

### 1 — Upload the project

Upload or clone the project to your server:

```bash
cd /www/wwwroot
git clone https://github.com/<your-user>/Send_File.git
cd Send_File
```

---

### 2 — Install dependencies

```bash
# Backend
cd backend
npm install --omit=dev
cd ..

# Frontend — install all deps (including devDeps needed for the build)
cd frontend
npm install
```

---

### 3 — Build the React frontend

```bash
cd /www/wwwroot/Send_File/frontend
npm run build
cd ..
```

The production-ready files are written to `frontend/build/`.

---

### 4 — Configure environment variables

```bash
cd /www/wwwroot/Send_File/backend
cp .env.example .env
nano .env          # or use AAPanel's file manager
```

Edit `.env`:

```dotenv
NODE_ENV=production
PORT=3001
CORS_ORIGIN=https://your-domain.com
ADMIN_API_KEY=change-me-to-a-long-random-string
```

---

### 5 — Create the AAPanel website

1. In AAPanel → **Website** → **Add Site**.
2. Set **Domain** to your domain (e.g. `files.example.com`).
3. Set **Root directory** to `/www/wwwroot/Send_File/frontend/build`.
4. Leave PHP version as **Pure static** (no PHP needed).
5. Click **Submit**.

---

### 6 — Configure Nginx

1. In AAPanel → **Website** → click **Settings** next to your site.
2. Open the **Configuration file** tab.
3. **Replace the entire contents** with the file `nginx.conf.template` from this repo.
4. Replace every occurrence of `files.example.com` with your actual domain.
5. Click **Save** — Nginx will reload automatically.

> **No SSL config goes here.** HAProxy on pfSense terminates TLS before traffic
> reaches this server. Nginx only needs to listen on HTTP port 80 and proxy API
> requests to Node.js.

> **client_max_body_size** is set to `2048M` to match the 2 GB per-file limit
> in multer. If you change the upload limit in one place, change it in both.

> **HAProxy heads-up:** make sure HAProxy is configured to pass
> `X-Forwarded-For` and `X-Forwarded-Proto: https` to Nginx, and that Nginx
> forwards them to the backend. The template already does this.

---

### 7 — Start the backend with PM2

```bash
cd /www/wwwroot/Send_File

# Create the logs directory PM2 expects.
mkdir -p logs

# Start the app.
pm2 start ecosystem.config.js

# Save the process list so it survives reboots.
pm2 save

# Tell PM2 to start on system boot (run the command it prints).
pm2 startup
```

Verify the backend is running (internal HTTP, not exposed to the internet):

```bash
pm2 status
curl http://127.0.0.1:3001/api/health
```

---

### 8 — Verify the full stack

Open `https://your-domain.com` in a browser — traffic flows through pfSense →
HAProxy (HTTPS) → Nginx (HTTP) → Node.js. You should see the upload interface.

---

## Managing the application

| Task | Command |
|---|---|
| View logs | `pm2 logs send-file` |
| Restart backend | `pm2 restart send-file` |
| Stop backend | `pm2 stop send-file` |
| Redeploy frontend | `cd frontend && npm run build` |
| Run cleanup manually | `node backend/cleanup.js` |
| View upload stats | `curl -H "x-admin-key: <key>" https://your-domain.com/api/admin/stats` |
| Delete an upload | `curl -X DELETE -H "x-admin-key: <key>" https://your-domain.com/api/admin/upload/<groupId>` |

---

## API Reference

### Upload

```
POST /api/upload
Content-Type: multipart/form-data

Fields:
  files[]            — one or more files (required)
  expiration_hours   — 1–168 (default: 72)
  password           — plain text, optional
```

Response `201`:
```json
{
  "group_id": "uuid",
  "download_url": "/api/download/<group_id>",
  "expires_at": "2024-01-01T00:00:00.000Z",
  "file_count": 3,
  "password_protected": false
}
```

### Download info

```
GET /api/download/:groupId/info
```

### Verify password

```
POST /api/download/:groupId/verify
Content-Type: application/json
{ "password": "secret" }
```

### Download all (ZIP or single file)

```
GET /api/download/:groupId[?password=secret]
```

### Download one file

```
GET /api/download/:groupId/:fileId[?password=secret]
```

### Health check

```
GET /api/health
```

---

## Security notes

- Executable MIME types (`.exe`, `.sh`, `.bat`, …) are blocked at upload time.
- Passwords are hashed with **bcrypt** (cost factor 10) before storage.
- Rate limiting: 20 uploads/hour and 100 API requests/15 min per IP.
- The admin endpoint is disabled unless `ADMIN_API_KEY` is set.
- Nginx blocks direct access to `.env`, `.log`, `.db`, and `.sqlite` files.
- The backend only listens on `127.0.0.1:3001` — it is never exposed directly to the internet.
- **TLS/SSL is terminated by HAProxy on pfSense** — Nginx and Node.js communicate over plain HTTP on the internal network only.
- HSTS should be configured on HAProxy (not Nginx) since HAProxy owns the TLS connection.

---

## License

MIT
