import React from 'react';
import { BrowserRouter, Routes, Route } from 'react-router-dom';
import UploadPage from './components/UploadPage';
import DownloadPage from './components/DownloadPage';

// App.js — Top-level router.
// /              → Upload page (drag-and-drop file sender)
// /d/:groupId    → Download page (recipient view)

export default function App() {
  return (
    <BrowserRouter>
      {/* Full-page gradient background inspired by WeTransfer */}
      <div className="min-h-screen bg-gradient-to-br from-brand-600 via-brand-500 to-teal-400 flex flex-col">
        {/* Navbar */}
        <nav className="flex items-center justify-between px-6 py-4">
          <a href="/" className="text-white font-bold text-2xl tracking-tight">
            Send_File
          </a>
          <span className="text-white/70 text-sm hidden sm:block">
            Simple. Fast. Secure.
          </span>
        </nav>

        {/* Page content */}
        <main className="flex-1 flex items-center justify-center px-4 pb-12">
          <Routes>
            <Route path="/" element={<UploadPage />} />
            <Route path="/d/:groupId" element={<DownloadPage />} />
          </Routes>
        </main>

        <footer className="text-center text-white/50 text-xs pb-4">
          Files are automatically deleted after expiration.
        </footer>
      </div>
    </BrowserRouter>
  );
}
