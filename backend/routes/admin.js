// routes/admin.js — Optional admin endpoint for monitoring uploads.
//
// GET /api/admin/stats
//   Returns aggregate statistics about all uploads.
//   Protected by a simple API key set in the ADMIN_API_KEY environment variable.

const express = require('express');
const db = require('../database');

const router = express.Router();

// ── Simple API-key middleware ────────────────────────────────────────────────
function requireAdminKey(req, res, next) {
  const key = process.env.ADMIN_API_KEY;
  if (!key) {
    // No key configured → admin endpoint is disabled.
    return res.status(403).json({ error: 'Admin access is not configured.' });
  }
  const provided = req.headers['x-admin-key'] || req.query.key;
  if (provided !== key) {
    return res.status(401).json({ error: 'Invalid admin key.' });
  }
  next();
}

// ── GET /api/admin/stats ─────────────────────────────────────────────────────
router.get('/stats', requireAdminKey, (req, res) => {
  const now = Math.floor(Date.now() / 1000);

  const stats = db.prepare(`
    SELECT
      COUNT(*) AS total_files,
      SUM(size) AS total_bytes,
      SUM(download_count) AS total_downloads,
      SUM(CASE WHEN expiration > ? THEN 1 ELSE 0 END) AS active_files,
      SUM(CASE WHEN expiration <= ? THEN 1 ELSE 0 END) AS expired_files,
      SUM(CASE WHEN password_hash IS NOT NULL THEN 1 ELSE 0 END) AS password_protected
    FROM uploads
  `).get(now, now);

  const recentUploads = db.prepare(`
    SELECT id, original_name, size, expiration, download_count, upload_group, created_at
    FROM uploads
    ORDER BY created_at DESC
    LIMIT 20
  `).all();

  return res.json({ stats, recent_uploads: recentUploads });
});

// ── DELETE /api/admin/upload/:groupId ───────────────────────────────────────
const fs = require('fs');
const path = require('path');

router.delete('/upload/:groupId', requireAdminKey, (req, res) => {
  const files = db
    .prepare('SELECT * FROM uploads WHERE upload_group = ?')
    .all(req.params.groupId);

  if (files.length === 0) {
    return res.status(404).json({ error: 'Upload group not found.' });
  }

  const dirs = new Set();
  for (const file of files) {
    try {
      if (fs.existsSync(file.stored_path)) fs.unlinkSync(file.stored_path);
    } catch (_) {}
    dirs.add(path.dirname(file.stored_path));
  }

  for (const dir of dirs) {
    try {
      if (fs.existsSync(dir) && fs.readdirSync(dir).length === 0) {
        fs.rmdirSync(dir);
      }
    } catch (_) {}
  }

  db.prepare('DELETE FROM uploads WHERE upload_group = ?').run(req.params.groupId);

  return res.json({ deleted: files.length });
});

module.exports = router;
