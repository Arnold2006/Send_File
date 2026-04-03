// cleanup.js — Background cron job that deletes expired uploads every hour.
//
// Run standalone:  node cleanup.js
// Or it is started automatically by server.js via node-cron.

const path = require('path');
const fs = require('fs');
const cron = require('node-cron');
const db = require('./database');

function runCleanup() {
  const now = Math.floor(Date.now() / 1000);
  console.log(`[cleanup] Running at ${new Date().toISOString()}`);

  // Find all groups where every file has expired.
  const expiredFiles = db
    .prepare('SELECT * FROM uploads WHERE expiration <= ?')
    .all(now);

  if (expiredFiles.length === 0) {
    console.log('[cleanup] No expired files found.');
    return;
  }

  // Collect unique upload group directories.
  const groupDirs = new Set();

  for (const file of expiredFiles) {
    // Delete the individual file from disk.
    try {
      if (fs.existsSync(file.stored_path)) {
        fs.unlinkSync(file.stored_path);
        console.log(`[cleanup] Deleted file: ${file.stored_path}`);
      }
    } catch (err) {
      console.error(`[cleanup] Could not delete file ${file.stored_path}:`, err.message);
    }

    // Track the parent directory so we can remove it if empty.
    groupDirs.add(path.dirname(file.stored_path));
  }

  // Remove empty group directories.
  for (const dir of groupDirs) {
    try {
      if (fs.existsSync(dir)) {
        const remaining = fs.readdirSync(dir);
        if (remaining.length === 0) {
          fs.rmdirSync(dir);
          console.log(`[cleanup] Removed empty directory: ${dir}`);
        }
      }
    } catch (err) {
      console.error(`[cleanup] Could not remove directory ${dir}:`, err.message);
    }
  }

  // Delete database records for expired files.
  const result = db
    .prepare('DELETE FROM uploads WHERE expiration <= ?')
    .run(now);

  console.log(`[cleanup] Deleted ${result.changes} database record(s).`);
}

// Schedule to run at the top of every hour.
cron.schedule('0 * * * *', runCleanup);

// Also run once immediately on startup so stale data is cleared on restart.
runCleanup();

module.exports = { runCleanup };
