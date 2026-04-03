// ecosystem.config.js — PM2 process configuration for AAPanel v8
//
// Usage (from the project root):
//   pm2 start ecosystem.config.js
//   pm2 save          # persist across reboots
//   pm2 startup       # enable PM2 to launch on system boot
//
// AAPanel Node.js project manager can also import this file directly from the
// "Node Project" panel when you choose "pm2" as the process manager.

module.exports = {
  apps: [
    {
      // ── App identity ──────────────────────────────────────────────────────
      name: 'send-file',
      script: 'backend/server.js',

      // ── Working directory ─────────────────────────────────────────────────
      // PM2 resolves all paths relative to cwd, so set it to the project root.
      cwd: __dirname,

      // ── Instances & clustering ────────────────────────────────────────────
      // "max" uses all available CPU cores via Node.js cluster.
      // Use 1 for simplicity or if better-sqlite3 WAL is causing contention.
      instances: 1,
      exec_mode: 'fork', // use "cluster" only if you switch to an async DB driver

      // ── Environment variables ─────────────────────────────────────────────
      // PM2 merges these with the system environment.
      // Sensitive values should be placed in backend/.env (not committed to git).
      env: {
        NODE_ENV: 'production',
        PORT: 3001,
      },
      // env_production is activated with: pm2 start ecosystem.config.js --env production
      env_production: {
        NODE_ENV: 'production',
        PORT: 3001,
      },

      // ── Logs ─────────────────────────────────────────────────────────────
      out_file:   './logs/out.log',
      error_file: './logs/error.log',
      merge_logs: true,
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',

      // ── Auto-restart behaviour ────────────────────────────────────────────
      watch: false,            // set to true in dev, never in production
      autorestart: true,
      max_restarts: 10,
      restart_delay: 4000,     // ms between automatic restarts
      max_memory_restart: '512M',

      // ── Graceful shutdown ─────────────────────────────────────────────────
      kill_timeout: 5000,      // wait 5 s for in-flight requests before SIGKILL
    },
  ],
};
