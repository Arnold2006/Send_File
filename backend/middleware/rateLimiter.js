// middleware/rateLimiter.js — Express rate-limiting middleware
// Prevents abuse by capping how many requests a single IP can make in a window.

const rateLimit = require('express-rate-limit');

// General API limiter — applied to all routes.
const apiLimiter = rateLimit({
  windowMs: 15 * 60 * 1000, // 15 minutes
  max: 100,                  // max 100 requests per window per IP
  standardHeaders: true,     // Return rate-limit info in `RateLimit-*` headers
  legacyHeaders: false,
  message: { error: 'Too many requests, please try again later.' },
});

// Stricter limiter for upload endpoints — uploads are expensive operations.
const uploadLimiter = rateLimit({
  windowMs: 60 * 60 * 1000, // 1 hour
  max: 20,                   // max 20 uploads per hour per IP
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'Upload limit reached, please try again in an hour.' },
});

module.exports = { apiLimiter, uploadLimiter };
