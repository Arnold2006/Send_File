// database.js — SQLite database setup using better-sqlite3
// Creates and exports a singleton DB connection with the required schema.

const Database = require('better-sqlite3');
const path = require('path');
const fs = require('fs');

// Resolve the DB file relative to this module so it works regardless of cwd.
const DB_PATH = path.resolve(__dirname, '..', 'data', 'send_file.db');

// Ensure the data directory exists.
fs.mkdirSync(path.dirname(DB_PATH), { recursive: true });

const db = new Database(DB_PATH);

// Enable WAL mode for better concurrent read performance.
db.pragma('journal_mode = WAL');

// Create the uploads table if it doesn't already exist.
db.exec(`
  CREATE TABLE IF NOT EXISTS uploads (
    id            TEXT PRIMARY KEY,          -- unique upload identifier (UUID)
    original_name TEXT NOT NULL,             -- original file name provided by the user
    stored_path   TEXT NOT NULL,             -- absolute path to the stored file on disk
    size          INTEGER NOT NULL,          -- file size in bytes
    expiration    INTEGER NOT NULL,          -- expiry Unix timestamp (seconds)
    password_hash TEXT,                      -- bcrypt hash of password (NULL = no password)
    download_url  TEXT NOT NULL,             -- relative path used to build the public URL
    download_count INTEGER NOT NULL DEFAULT 0, -- number of times the file has been downloaded
    last_accessed INTEGER,                   -- Unix timestamp of last download
    upload_group  TEXT NOT NULL,             -- groups files uploaded together (same request)
    created_at    INTEGER NOT NULL           -- Unix timestamp when the record was created
  );

  -- Index to speed up the hourly cleanup query.
  CREATE INDEX IF NOT EXISTS idx_expiration ON uploads(expiration);

  -- Index to look up all files belonging to the same upload group.
  CREATE INDEX IF NOT EXISTS idx_upload_group ON uploads(upload_group);
`);

module.exports = db;
