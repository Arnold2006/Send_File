// server.js — Express application entry point
//
// HTTP only — TLS/SSL termination is handled by AAPanel's Nginx reverse proxy.
// Listens on PORT (default 3001).  Set PORT in your .env file if needed.

const express = require('express');
const cors = require('cors');
const path = require('path');

const { apiLimiter, uploadLimiter } = require('./middleware/rateLimiter');
const uploadRouter = require('./routes/upload');
const downloadRouter = require('./routes/download');
const adminRouter = require('./routes/admin');

// Start background cleanup cron job.
require('./cleanup');

const app = express();
const PORT = process.env.PORT || 3001;

// ── Middleware ───────────────────────────────────────────────────────────────

// Trust the first proxy (AAPanel's Nginx) so that express-rate-limit reads the
// real client IP from X-Forwarded-For rather than the loopback address.
app.set('trust proxy', 1);

app.use(cors({
  origin: process.env.CORS_ORIGIN || '*',
  methods: ['GET', 'POST', 'DELETE', 'OPTIONS'],
}));

app.use(express.json({ limit: '1mb' }));
app.use(express.urlencoded({ extended: true, limit: '1mb' }));

// Apply the general rate limiter to all API routes.
app.use('/api', apiLimiter);

// ── Routes ───────────────────────────────────────────────────────────────────
app.use('/api/upload', uploadLimiter, uploadRouter);
app.use('/api/download', downloadRouter);
app.use('/api/admin', adminRouter);

// Health-check endpoint for load-balancer / monitoring.
app.get('/api/health', (_req, res) => {
  res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

// ── Serve React frontend in production ───────────────────────────────────────
// When NODE_ENV=production the backend serves the built React app so a single
// process handles everything.  In development the React dev server proxies API
// calls to this backend.
if (process.env.NODE_ENV === 'production') {
  const frontendBuild = path.resolve(__dirname, '..', 'frontend', 'build');
  app.use(express.static(frontendBuild));
  // Return the React app for any unmatched route (client-side routing).
  app.get('*', (_req, res) => {
    res.sendFile(path.join(frontendBuild, 'index.html'));
  });
}

// ── Error handler ────────────────────────────────────────────────────────────
app.use((err, _req, res, _next) => {
  console.error('Unhandled error:', err);
  const status = err.status || 500;
  res.status(status).json({ error: err.message || 'Internal server error.' });
});

// ── Start ────────────────────────────────────────────────────────────────────
app.listen(PORT, () => {
  console.log(`Send_File backend listening on http://0.0.0.0:${PORT}`);
});
