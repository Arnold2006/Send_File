// DownloadPage.jsx — Recipient's download view.
//
// Fetches metadata for the upload group from /api/download/:groupId/info.
// If password-protected, shows a password form first.
// Displays file list with individual download buttons and a "Download all" button.

import React, { useEffect, useState, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import axios from 'axios';

export default function DownloadPage() {
  const { groupId } = useParams();

  const [info, setInfo]               = useState(null);   // metadata from /info endpoint
  const [loading, setLoading]         = useState(true);
  const [error, setError]             = useState(null);
  const [password, setPassword]       = useState('');
  const [authenticated, setAuth]      = useState(false);
  const [authError, setAuthError]     = useState(null);
  const [authLoading, setAuthLoading] = useState(false);

  // ── Load metadata ──────────────────────────────────────────────────────────
  const loadInfo = useCallback(async () => {
    try {
      const { data } = await axios.get(`/api/download/${groupId}/info`);
      setInfo(data);
      // If not password-protected, mark as authenticated immediately.
      if (!data.password_protected) setAuth(true);
    } catch (err) {
      setError(err.response?.data?.error || 'Could not load download information.');
    } finally {
      setLoading(false);
    }
  }, [groupId]);

  useEffect(() => {
    loadInfo();
  }, [loadInfo]);

  // ── Password verification ──────────────────────────────────────────────────
  const handleVerify = async (e) => {
    e.preventDefault();
    setAuthLoading(true);
    setAuthError(null);
    try {
      await axios.post(`/api/download/${groupId}/verify`, { password });
      setAuth(true);
    } catch (err) {
      setAuthError(err.response?.data?.error || 'Incorrect password.');
    } finally {
      setAuthLoading(false);
    }
  };

  // ── Build download URL with optional password query param ─────────────────
  const buildUrl = (suffix = '') => {
    const base = `/api/download/${groupId}${suffix}`;
    return password ? `${base}?password=${encodeURIComponent(password)}` : base;
  };

  // ── Helpers ────────────────────────────────────────────────────────────────
  const formatBytes = (bytes) => {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
  };

  const formatDate = (iso) =>
    new Date(iso).toLocaleString(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });

  const isExpired = info && new Date(info.expires_at) < new Date();

  // ── Render ─────────────────────────────────────────────────────────────────
  if (loading) {
    return (
      <div className="bg-white rounded-3xl shadow-2xl p-10 text-center w-full max-w-md">
        <div className="animate-pulse text-gray-400 text-lg">Loading…</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-white rounded-3xl shadow-2xl p-10 text-center w-full max-w-md">
        <div className="text-5xl mb-4">😕</div>
        <h2 className="text-xl font-bold text-gray-800 mb-2">Oops!</h2>
        <p className="text-gray-500 text-sm">{error}</p>
      </div>
    );
  }

  // Password gate
  if (!authenticated) {
    return (
      <div className="bg-white rounded-3xl shadow-2xl p-8 w-full max-w-md">
        <div className="text-4xl mb-4 text-center">🔒</div>
        <h2 className="text-xl font-bold text-gray-800 mb-1 text-center">
          Password required
        </h2>
        <p className="text-gray-500 text-sm text-center mb-6">
          This download is protected. Enter the password to continue.
        </p>
        <form onSubmit={handleVerify} className="flex flex-col gap-4">
          <input
            type="password"
            placeholder="Password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="rounded-xl border border-gray-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400"
            required
            autoFocus
          />
          {authError && (
            <p className="text-red-500 text-sm">{authError}</p>
          )}
          <button
            type="submit"
            disabled={authLoading}
            className="w-full py-3 rounded-xl font-semibold text-white bg-brand-500 hover:bg-brand-600 transition disabled:bg-gray-300"
          >
            {authLoading ? 'Checking…' : 'Unlock'}
          </button>
        </form>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-3xl shadow-2xl p-8 w-full max-w-lg">
      {/* Header */}
      <div className="mb-6">
        <h2 className="text-2xl font-bold text-gray-800">
          {info.file_count === 1 ? '1 file' : `${info.file_count} files`} ready to download
        </h2>
        <p className={`text-sm mt-1 ${isExpired ? 'text-red-500' : 'text-gray-400'}`}>
          {isExpired
            ? 'This download has expired.'
            : `Expires on ${formatDate(info.expires_at)}`}
        </p>
      </div>

      {isExpired ? (
        <p className="text-gray-500 text-sm">
          The files associated with this link have been deleted.
        </p>
      ) : (
        <>
          {/* File list */}
          <ul className="divide-y divide-gray-100 mb-6">
            {info.files.map((file) => (
              <li key={file.id} className="flex items-center justify-between py-3 gap-4">
                <div className="min-w-0">
                  <p className="text-gray-800 text-sm font-medium truncate">
                    {file.name}
                  </p>
                  <p className="text-gray-400 text-xs">
                    {formatBytes(file.size)}
                    {file.download_count > 0 && (
                      <> · Downloaded {file.download_count}×</>
                    )}
                  </p>
                </div>
                <a
                  href={buildUrl(`/${file.id}`)}
                  download
                  className="shrink-0 text-xs font-semibold text-brand-600 hover:text-brand-700 underline transition"
                >
                  Download
                </a>
              </li>
            ))}
          </ul>

          {/* Download all button */}
          <a
            href={buildUrl()}
            download
            className="block w-full text-center py-3 rounded-xl font-semibold text-white bg-brand-500 hover:bg-brand-600 active:scale-95 transition"
          >
            {info.file_count === 1 ? 'Download file' : 'Download all as ZIP'}
          </a>
        </>
      )}
    </div>
  );
}
