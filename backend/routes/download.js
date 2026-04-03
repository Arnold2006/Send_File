// routes/download.js — Handles file download requests
//
// GET  /api/download/:groupId/info
//   Returns metadata (file list, sizes, expiry, password protection flag).
//
// POST /api/download/:groupId/verify
//   Verifies the password for a password-protected upload.
//   Body: { password: string }
//
// GET  /api/download/:groupId
//   Downloads all files as a ZIP archive (or single file directly if only one).
//   Query: ?password=... (if password protected)
//
// GET  /api/download/:groupId/:fileId
//   Downloads a single specific file by its ID.
//   Query: ?password=... (if password protected)

const express = require('express');
const path = require('path');
const fs = require('fs');
const archiver = require('archiver');
const bcrypt = require('bcrypt');
const db = require('../database');

const router = express.Router();

// ── Helper: fetch files for a group, check expiry ───────────────────────────
function getGroupFiles(groupId) {
  const now = Math.floor(Date.now() / 1000);
  const files = db
    .prepare('SELECT * FROM uploads WHERE upload_group = ? AND expiration > ?')
    .all(groupId, now);
  return files; // empty array → not found or expired
}

// ── Helper: verify password ──────────────────────────────────────────────────
async function checkPassword(files, password) {
  // All files in the same group share the same password hash.
  const hash = files[0].password_hash;
  if (!hash) return true; // not password-protected
  if (!password) return false;
  return bcrypt.compare(password, hash);
}

// ── Helper: update download stats ───────────────────────────────────────────
function recordDownload(groupId) {
  const now = Math.floor(Date.now() / 1000);
  db.prepare(`
    UPDATE uploads
    SET download_count = download_count + 1, last_accessed = ?
    WHERE upload_group = ?
  `).run(now, groupId);
}

// ── GET /api/download/:groupId/info ─────────────────────────────────────────
router.get('/:groupId/info', (req, res) => {
  const files = getGroupFiles(req.params.groupId);

  if (files.length === 0) {
    return res.status(404).json({ error: 'Download link not found or has expired.' });
  }

  return res.json({
    group_id: req.params.groupId,
    password_protected: files[0].password_hash !== null,
    expires_at: new Date(files[0].expiration * 1000).toISOString(),
    file_count: files.length,
    files: files.map((f) => ({
      id: f.id,
      name: f.original_name,
      size: f.size,
      download_count: f.download_count,
    })),
  });
});

// ── POST /api/download/:groupId/verify ──────────────────────────────────────
router.post('/:groupId/verify', async (req, res) => {
  const files = getGroupFiles(req.params.groupId);

  if (files.length === 0) {
    return res.status(404).json({ error: 'Download link not found or has expired.' });
  }

  const ok = await checkPassword(files, req.body.password);
  if (!ok) {
    return res.status(401).json({ error: 'Incorrect password.' });
  }

  return res.json({ ok: true });
});

// ── GET /api/download/:groupId — ZIP (or single file) ───────────────────────
router.get('/:groupId', async (req, res) => {
  const files = getGroupFiles(req.params.groupId);

  if (files.length === 0) {
    return res.status(404).json({ error: 'Download link not found or has expired.' });
  }

  // Password check — accept via query param or Authorization header.
  const password = req.query.password || req.headers['x-download-password'];
  const ok = await checkPassword(files, password);
  if (!ok) {
    return res.status(401).json({ error: 'Password required or incorrect.' });
  }

  recordDownload(req.params.groupId);

  if (files.length === 1) {
    // Single file — stream directly.
    const file = files[0];
    if (!fs.existsSync(file.stored_path)) {
      return res.status(410).json({ error: 'File no longer exists on disk.' });
    }
    res.setHeader('Content-Disposition', `attachment; filename="${encodeURIComponent(file.original_name)}"`);
    res.setHeader('Content-Type', 'application/octet-stream');
    return fs.createReadStream(file.stored_path).pipe(res);
  }

  // Multiple files — stream as ZIP.
  res.setHeader('Content-Disposition', `attachment; filename="download-${req.params.groupId}.zip"`);
  res.setHeader('Content-Type', 'application/zip');

  const archive = archiver('zip', { zlib: { level: 6 } });
  archive.on('error', (err) => {
    console.error('Archiver error:', err);
    // Headers already sent; just destroy the connection.
    res.destroy();
  });

  archive.pipe(res);

  for (const file of files) {
    if (fs.existsSync(file.stored_path)) {
      archive.file(file.stored_path, { name: file.original_name });
    }
  }

  await archive.finalize();
});

// ── GET /api/download/:groupId/:fileId — single file by ID ──────────────────
router.get('/:groupId/:fileId', async (req, res) => {
  const now = Math.floor(Date.now() / 1000);
  const file = db
    .prepare('SELECT * FROM uploads WHERE id = ? AND upload_group = ? AND expiration > ?')
    .get(req.params.fileId, req.params.groupId, now);

  if (!file) {
    return res.status(404).json({ error: 'File not found or has expired.' });
  }

  const password = req.query.password || req.headers['x-download-password'];
  const ok = await checkPassword([file], password);
  if (!ok) {
    return res.status(401).json({ error: 'Password required or incorrect.' });
  }

  if (!fs.existsSync(file.stored_path)) {
    return res.status(410).json({ error: 'File no longer exists on disk.' });
  }

  // Update stats.
  const ts = Math.floor(Date.now() / 1000);
  db.prepare('UPDATE uploads SET download_count = download_count + 1, last_accessed = ? WHERE id = ?')
    .run(ts, file.id);

  res.setHeader('Content-Disposition', `attachment; filename="${encodeURIComponent(file.original_name)}"`);
  res.setHeader('Content-Type', 'application/octet-stream');
  fs.createReadStream(file.stored_path).pipe(res);
});

module.exports = router;
