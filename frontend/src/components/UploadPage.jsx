// UploadPage.jsx — Main file upload interface.
//
// Features:
//   - Drag-and-drop or click-to-browse file selection
//   - Per-file upload progress bars
//   - Optional password protection
//   - Expiration selector (1 h → 7 days)
//   - Copyable download link after successful upload

import React, { useCallback, useRef, useState } from 'react';
import axios from 'axios';
import ProgressBar from './ProgressBar';

const EXPIRATION_OPTIONS = [
  { label: '1 hour',   value: 1 },
  { label: '6 hours',  value: 6 },
  { label: '1 day',    value: 24 },
  { label: '3 days',   value: 72 },
  { label: '7 days',   value: 168 },
];

export default function UploadPage() {
  const [files, setFiles]               = useState([]);        // selected File objects
  const [progresses, setProgresses]     = useState({});        // { fileName: 0-100 }
  const [uploading, setUploading]       = useState(false);
  const [downloadUrl, setDownloadUrl]   = useState(null);
  const [copied, setCopied]             = useState(false);
  const [error, setError]               = useState(null);
  const [password, setPassword]         = useState('');
  const [expiration, setExpiration]     = useState(72);
  const [dragActive, setDragActive]     = useState(false);

  const inputRef = useRef(null);

  // ── File selection ─────────────────────────────────────────────────────────
  const addFiles = useCallback((newFiles) => {
    setFiles((prev) => {
      // Deduplicate by name+size.
      const existing = new Set(prev.map((f) => `${f.name}|${f.size}`));
      const unique = Array.from(newFiles).filter(
        (f) => !existing.has(`${f.name}|${f.size}`)
      );
      return [...prev, ...unique];
    });
  }, []);

  const handleDrop = useCallback(
    (e) => {
      e.preventDefault();
      setDragActive(false);
      if (e.dataTransfer.files.length > 0) {
        addFiles(e.dataTransfer.files);
      }
    },
    [addFiles]
  );

  const handleDragOver = (e) => {
    e.preventDefault();
    setDragActive(true);
  };

  const handleDragLeave = () => setDragActive(false);

  const handleFileInput = (e) => {
    if (e.target.files.length > 0) addFiles(e.target.files);
    // Reset input so the same file can be added again if needed.
    e.target.value = '';
  };

  const removeFile = (index) =>
    setFiles((prev) => prev.filter((_, i) => i !== index));

  // ── Upload ─────────────────────────────────────────────────────────────────
  const handleUpload = async () => {
    if (files.length === 0) return;
    setError(null);
    setUploading(true);
    setProgresses(Object.fromEntries(files.map((f) => [f.name, 0])));

    const formData = new FormData();
    files.forEach((f) => formData.append('files', f));
    formData.append('expiration_hours', expiration);
    if (password.trim()) formData.append('password', password.trim());

    try {
      // Track overall progress — we approximate per-file progress
      // by distributing the overall % across files proportionally.
      const totalSize = files.reduce((sum, f) => sum + f.size, 0);

      const response = await axios.post('/api/upload', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress(evt) {
          if (!evt.total) return;
          const overall = (evt.loaded / evt.total) * 100;

          // Distribute progress proportionally by file size.
          setProgresses((prev) => {
            const next = { ...prev };
            let bytesAccountedFor = (evt.loaded / evt.total) * totalSize;
            for (const f of files) {
              if (bytesAccountedFor >= f.size) {
                next[f.name] = 100;
                bytesAccountedFor -= f.size;
              } else {
                next[f.name] = Math.round((bytesAccountedFor / f.size) * 100);
                bytesAccountedFor = 0;
              }
            }
            // Ensure at least `overall` is reflected on the first file.
            if (files.length > 0) next[files[0].name] = Math.max(next[files[0].name], Math.round(overall));
            return next;
          });
        },
      });

      // Mark all as 100% complete.
      setProgresses(Object.fromEntries(files.map((f) => [f.name, 100])));

      const fullUrl = `${window.location.origin}/d/${response.data.group_id}`;
      setDownloadUrl(fullUrl);
    } catch (err) {
      setError(
        err.response?.data?.error || 'Upload failed. Please try again.'
      );
    } finally {
      setUploading(false);
    }
  };

  // ── Copy link ──────────────────────────────────────────────────────────────
  const copyLink = () => {
    if (!downloadUrl) return;
    navigator.clipboard.writeText(downloadUrl).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  };

  // ── Reset ──────────────────────────────────────────────────────────────────
  const reset = () => {
    setFiles([]);
    setProgresses({});
    setDownloadUrl(null);
    setCopied(false);
    setError(null);
    setPassword('');
    setExpiration(72);
  };

  // ── Render ─────────────────────────────────────────────────────────────────
  if (downloadUrl) {
    return (
      <div className="bg-white rounded-3xl shadow-2xl p-8 w-full max-w-lg text-center">
        <div className="text-5xl mb-4">🎉</div>
        <h2 className="text-2xl font-bold text-gray-800 mb-2">
          Files uploaded!
        </h2>
        <p className="text-gray-500 text-sm mb-6">
          Share the link below with your recipient.
        </p>

        <div className="flex items-center bg-gray-100 rounded-xl px-4 py-3 mb-4 gap-2">
          <span className="flex-1 text-gray-700 text-sm truncate">{downloadUrl}</span>
          <button
            onClick={copyLink}
            className="shrink-0 bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-4 py-2 rounded-lg transition"
          >
            {copied ? 'Copied!' : 'Copy'}
          </button>
        </div>

        <button
          onClick={reset}
          className="text-brand-600 hover:text-brand-700 text-sm font-medium underline"
        >
          Send more files
        </button>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-3xl shadow-2xl p-8 w-full max-w-lg">
      <h1 className="text-2xl font-bold text-gray-800 mb-1">Send files</h1>
      <p className="text-gray-500 text-sm mb-6">
        Up to 20 files · 2 GB each · No sign-up required
      </p>

      {/* Drag & Drop zone */}
      <div
        onDrop={handleDrop}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onClick={() => !uploading && inputRef.current?.click()}
        className={`border-2 border-dashed rounded-2xl p-8 text-center cursor-pointer transition-colors mb-6 ${
          dragActive
            ? 'border-brand-500 bg-brand-50'
            : 'border-gray-300 hover:border-brand-400 hover:bg-gray-50'
        } ${uploading ? 'pointer-events-none opacity-60' : ''}`}
      >
        <input
          ref={inputRef}
          type="file"
          multiple
          className="hidden"
          onChange={handleFileInput}
        />
        <div className="text-4xl mb-3">📁</div>
        <p className="text-gray-600 font-medium">
          {dragActive ? 'Drop files here' : 'Drag & drop files here'}
        </p>
        <p className="text-gray-400 text-sm mt-1">or click to browse</p>
      </div>

      {/* Selected file list */}
      {files.length > 0 && (
        <div className="mb-5">
          {files.map((f, i) => (
            uploading ? (
              <ProgressBar
                key={`${f.name}-${i}`}
                name={f.name}
                size={f.size}
                progress={progresses[f.name] ?? 0}
              />
            ) : (
              <div
                key={`${f.name}-${i}`}
                className="flex items-center justify-between text-sm text-gray-700 mb-2"
              >
                <span className="truncate max-w-xs">{f.name}</span>
                <div className="flex items-center gap-3 ml-2 shrink-0">
                  <span className="text-gray-400">{formatBytes(f.size)}</span>
                  <button
                    onClick={() => removeFile(i)}
                    className="text-red-400 hover:text-red-600 transition"
                    aria-label="Remove file"
                  >
                    ✕
                  </button>
                </div>
              </div>
            )
          ))}
        </div>
      )}

      {/* Options */}
      {!uploading && (
        <div className="flex flex-col sm:flex-row gap-3 mb-5">
          <input
            type="password"
            placeholder="Password (optional)"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="flex-1 rounded-xl border border-gray-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400"
          />
          <select
            value={expiration}
            onChange={(e) => setExpiration(Number(e.target.value))}
            className="rounded-xl border border-gray-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400"
          >
            {EXPIRATION_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                Expires in {o.label}
              </option>
            ))}
          </select>
        </div>
      )}

      {/* Error */}
      {error && (
        <p className="text-red-500 text-sm mb-4">{error}</p>
      )}

      {/* Upload button */}
      <button
        onClick={handleUpload}
        disabled={files.length === 0 || uploading}
        className={`w-full py-3 rounded-xl font-semibold text-white transition ${
          files.length === 0 || uploading
            ? 'bg-gray-300 cursor-not-allowed'
            : 'bg-brand-500 hover:bg-brand-600 active:scale-95'
        }`}
      >
        {uploading ? 'Uploading…' : `Upload ${files.length > 0 ? `(${files.length} file${files.length > 1 ? 's' : ''})` : ''}`}
      </button>
    </div>
  );
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}
