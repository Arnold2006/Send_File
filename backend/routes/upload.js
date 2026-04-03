// routes/upload.js — Handles file upload requests
// POST /api/upload
//   - Accepts multipart/form-data with one or more files.
//   - Optional fields: `password` (plain text), `expiration_hours` (number, default 72).
//   - Stores files under /uploads/<group_id>/ and persists metadata to SQLite.
//   - Returns a JSON object containing the group download URL.

const express = require('express');
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const { v4: uuidv4 } = require('uuid');
const bcrypt = require('bcrypt');
const db = require('../database');

const router = express.Router();

// ── Multer storage configuration ────────────────────────────────────────────
// Each upload group gets its own subdirectory so files with the same name
// from different uploads never collide.
const storage = multer.diskStorage({
  destination(req, _file, cb) {
    // Generate a unique group ID for this request on the first file.
    if (!req.uploadGroupId) {
      req.uploadGroupId = uuidv4();
    }
    const dir = path.resolve(__dirname, '..', '..', 'uploads', req.uploadGroupId);
    fs.mkdirSync(dir, { recursive: true });
    cb(null, dir);
  },
  filename(_req, file, cb) {
    // Sanitise the original filename to remove path traversal characters.
    const safe = path.basename(file.originalname).replace(/[^a-zA-Z0-9._-]/g, '_');
    cb(null, safe);
  },
});

// Validate MIME type against an allowlist — block executables, scripts, etc.
const BLOCKED_MIMETYPES = new Set([
  'application/x-msdownload',
  'application/x-executable',
  'application/x-sh',
  'application/x-bat',
  'text/x-shellscript',
]);

const fileFilter = (_req, file, cb) => {
  if (BLOCKED_MIMETYPES.has(file.mimetype)) {
    cb(new Error(`File type '${file.mimetype}' is not allowed.`));
  } else {
    cb(null, true);
  }
};

const upload = multer({
  storage,
  fileFilter,
  limits: {
    fileSize: 2 * 1024 * 1024 * 1024, // 2 GB per file
    files: 20,                          // max 20 files per upload
  },
});

// ── POST /api/upload ─────────────────────────────────────────────────────────
router.post('/', upload.array('files'), async (req, res) => {
  try {
    const files = req.files;

    if (!files || files.length === 0) {
      return res.status(400).json({ error: 'No files were uploaded.' });
    }

    // Parse and clamp expiration hours (1 h – 168 h / 7 days).
    let expirationHours = parseInt(req.body.expiration_hours, 10) || 72;
    expirationHours = Math.min(Math.max(expirationHours, 1), 168);

    const now = Math.floor(Date.now() / 1000);
    const expiration = now + expirationHours * 3600;
    const groupId = req.uploadGroupId;

    // Hash the password if provided.
    let passwordHash = null;
    if (req.body.password && req.body.password.trim() !== '') {
      passwordHash = await bcrypt.hash(req.body.password.trim(), 10);
    }

    // Persist each file to the database.
    const insertStmt = db.prepare(`
      INSERT INTO uploads
        (id, original_name, stored_path, size, expiration, password_hash, download_url, upload_group, created_at)
      VALUES
        (@id, @original_name, @stored_path, @size, @expiration, @password_hash, @download_url, @upload_group, @created_at)
    `);

    const insertMany = db.transaction((rows) => {
      for (const row of rows) insertStmt.run(row);
    });

    const rows = files.map((f) => ({
      id: uuidv4(),
      original_name: f.originalname,
      stored_path: f.path,
      size: f.size,
      expiration,
      password_hash: passwordHash,
      download_url: `/api/download/${groupId}`,
      upload_group: groupId,
      created_at: now,
    }));

    insertMany(rows);

    return res.status(201).json({
      group_id: groupId,
      download_url: `/api/download/${groupId}`,
      expires_at: new Date(expiration * 1000).toISOString(),
      file_count: files.length,
      password_protected: passwordHash !== null,
    });
  } catch (err) {
    console.error('Upload error:', err);
    // Attempt to clean up any partially written files.
    if (req.uploadGroupId) {
      const dir = path.resolve(__dirname, '..', '..', 'uploads', req.uploadGroupId);
      fs.rm(dir, { recursive: true, force: true }, () => {});
    }
    return res.status(500).json({ error: 'Upload failed. Please try again.' });
  }
});

module.exports = router;
