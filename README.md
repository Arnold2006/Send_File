# Send_File

A self-hosted, WeTransfer-inspired file sharing application.  
**Stack:** React + Tailwind CSS · Node.js + Express · SQLite · PM2 · Nginx (AAPanel v8)

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
5. Replace the certificate paths if your Let's Encrypt certs are stored elsewhere  
   (AAPanel usually writes them to `/www/server/panel/vhost/cert/<domain>/`).
6. Click **Save** — Nginx will reload automatically.

> **client_max_body_size** is set to `2048M` in the template to match the  
> 2 GB per-file limit in the backend. Adjust both values together if needed.

---

### 7 — Enable SSL

1. In the same site **Settings** → **SSL** tab.
2. Choose **Let's Encrypt**, enter your email, click **Apply**.
3. AAPanel will obtain the certificate and update the Nginx config paths automatically.

---

### 8 — Start the backend with PM2

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

Verify the backend is running:

```bash
pm2 status
curl http://127.0.0.1:3001/api/health
```

---

### 9 — Verify the full stack

Open `https://your-domain.com` in a browser — you should see the upload interface.

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
- The backend only listens on `127.0.0.1:3001` — it is not exposed to the internet directly.

---

## License

MIT
