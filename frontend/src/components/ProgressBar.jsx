// ProgressBar.jsx — Animated upload progress bar for a single file.
import React from 'react';

/**
 * Props:
 *   name     {string}  — file name
 *   progress {number}  — 0-100
 *   size     {number}  — file size in bytes
 */
export default function ProgressBar({ name, progress, size }) {
  const pct = Math.min(Math.max(Math.round(progress), 0), 100);
  const sizeLabel = formatBytes(size);

  return (
    <div className="mb-3">
      <div className="flex justify-between text-sm text-gray-700 mb-1">
        <span className="truncate max-w-xs font-medium">{name}</span>
        <span className="ml-2 text-gray-400 whitespace-nowrap">
          {sizeLabel} · {pct}%
        </span>
      </div>
      <div className="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
        <div
          className="bg-brand-500 h-2 rounded-full transition-all duration-200"
          style={{ width: `${pct}%` }}
        />
      </div>
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
