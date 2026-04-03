<?php
/**
 * SendFile – Anonymous ZIP File Sharing
 *
 * Single-file PHP 8.x web application.
 * No database · No logs · No tracking
 * Runs on Apache behind pfSense / HAProxy (HTTPS terminated upstream).
 */

declare(strict_types=1);

// ── Configuration ─────────────────────────────────────────────────────────────

define('UPLOAD_DIR',          __DIR__ . '/uploads/');
define('MAX_FILE_SIZE_BYTES', 2 * 1024 * 1024 * 1024); // 2 GB
define('FILE_EXPIRY_SECONDS', 2 * 24 * 60 * 60);        // 48 hours
define('TOKEN_LENGTH',        32);                        // hex chars (16 bytes)

// Suppress PHP errors from leaking into output or logs.
ini_set('display_errors', '0');
ini_set('log_errors',     '0');

// ── Utility Functions ─────────────────────────────────────────────────────────

function generate_token(): string
{
    return bin2hex(random_bytes(16));
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1_073_741_824) {
        return number_format($bytes / 1_073_741_824, 2) . ' GB';
    }
    if ($bytes >= 1_048_576) {
        return number_format($bytes / 1_048_576, 2) . ' MB';
    }
    if ($bytes >= 1_024) {
        return number_format($bytes / 1_024, 2) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Build the base URL, respecting X-Forwarded-Proto from HAProxy/pfSense.
 */
function base_url(): string
{
    $proto  = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https';
    $host   = $_SERVER['HTTP_HOST']               ?? 'localhost';
    $script = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return $proto . '://' . $host . $script;
}

// ── Cleanup: delete uploads older than 48 hours ───────────────────────────────

function delete_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $entries = scandir($dir);
    if ($entries === false) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            delete_directory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function cleanup_expired_uploads(): void
{
    if (!is_dir(UPLOAD_DIR)) {
        return;
    }
    $entries = scandir(UPLOAD_DIR);
    if ($entries === false) {
        return;
    }
    foreach ($entries as $token) {
        if ($token === '.' || $token === '..') {
            continue;
        }
        $tokenDir = UPLOAD_DIR . $token;
        if (!is_dir($tokenDir)) {
            continue;
        }
        $metaFile = $tokenDir . '/.meta';
        if (!file_exists($metaFile)) {
            delete_directory($tokenDir);
            continue;
        }
        $uploadedAt = (int) file_get_contents($metaFile);
        if ($uploadedAt === 0 || (time() - $uploadedAt) > FILE_EXPIRY_SECONDS) {
            delete_directory($tokenDir);
        }
    }
}

// ── Bootstrap: ensure uploads directory exists ────────────────────────────────

if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0750, true);
    @file_put_contents(UPLOAD_DIR . '.htaccess', "Require all denied\n");
}

cleanup_expired_uploads();

// ── Route: POST /?action=upload ───────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'upload') {
    header('Content-Type: application/json; charset=utf-8');

    // --- Validate upload ----------------------------------------------------

    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file was received by the server.']);
        exit;
    }

    $f = $_FILES['file'];

    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload size limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload was blocked by a server extension.',
    ];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => $uploadErrors[$f['error']] ?? 'Unknown upload error.']);
        exit;
    }

    if ($f['size'] <= 0 || $f['size'] > MAX_FILE_SIZE_BYTES) {
        http_response_code(400);
        echo json_encode(['error' => 'File size must be between 1 byte and 2 GB.']);
        exit;
    }

    // Extension check
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        http_response_code(400);
        echo json_encode(['error' => 'Only .zip files are accepted.']);
        exit;
    }

    // MIME / magic-bytes check
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($f['tmp_name']);
    $okMimes  = [
        'application/zip',
        'application/x-zip',
        'application/x-zip-compressed',
        'application/octet-stream',
    ];
    if (!in_array($mime, $okMimes, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'File does not appear to be a valid ZIP archive.']);
        exit;
    }

    // --- Store file --------------------------------------------------------

    $token    = generate_token();
    $tokenDir = UPLOAD_DIR . $token;

    if (!@mkdir($tokenDir, 0750, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: could not create storage directory.']);
        exit;
    }

    // Sanitise filename (keep alphanumeric, dots, hyphens, underscores only)
    $origName = basename($f['name']);
    $safeName = preg_replace('/[^\w\-.]/', '_', $origName);
    $safeName = preg_replace('/\.{2,}/', '.', $safeName);
    if (empty($safeName) || $safeName === '_zip' || $safeName === '.zip') {
        $safeName = 'archive.zip';
    }
    // Ensure it ends with .zip
    if (strtolower(substr($safeName, -4)) !== '.zip') {
        $safeName .= '.zip';
    }

    $destPath = $tokenDir . '/' . $safeName;

    if (!move_uploaded_file($f['tmp_name'], $destPath)) {
        @rmdir($tokenDir);
        http_response_code(500);
        echo json_encode(['error' => 'Server error: could not move uploaded file.']);
        exit;
    }

    // Metadata: only the upload timestamp — no user information stored
    @file_put_contents($tokenDir . '/.meta',     (string) time());
    @file_put_contents($tokenDir . '/.filename', $safeName);
    // Block direct HTTP access to this directory
    @file_put_contents($tokenDir . '/.htaccess', "Require all denied\n");

    $expiresAt = time() + FILE_EXPIRY_SECONDS;

    echo json_encode([
        'success' => true,
        'token'   => $token,
        'name'    => $safeName,
        'size'    => format_bytes($f['size']),
        'expires' => date('F j, Y', $expiresAt),
        'url'     => base_url() . '/?d=' . $token,
    ]);
    exit;
}

// ── Route: GET /?d=TOKEN[&dl=1] ───────────────────────────────────────────────

$downloadToken = isset($_GET['d'])
    ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['d'])
    : null;

$pageMode = 'upload'; // default

$fileInfo = [];

if ($downloadToken !== null) {
    if (strlen($downloadToken) !== TOKEN_LENGTH) {
        $pageMode = 'not_found';
    } else {
        $tokenDir     = UPLOAD_DIR . $downloadToken;
        $metaFile     = $tokenDir . '/.meta';
        $filenameFile = $tokenDir . '/.filename';

        if (!is_dir($tokenDir) || !file_exists($metaFile) || !file_exists($filenameFile)) {
            http_response_code(404);
            $pageMode = 'not_found';
        } else {
            $uploadedAt = (int) file_get_contents($metaFile);
            $expiresAt  = $uploadedAt + FILE_EXPIRY_SECONDS;

            if (time() > $expiresAt) {
                delete_directory($tokenDir);
                http_response_code(410);
                $pageMode = 'expired';
            } else {
                $filename = (string) file_get_contents($filenameFile);
                $filePath = $tokenDir . '/' . $filename;

                if (!file_exists($filePath)) {
                    http_response_code(404);
                    $pageMode = 'not_found';
                } elseif (isset($_GET['dl'])) {
                    // ── Serve file download ──────────────────────────────────
                    if (ob_get_level()) {
                        ob_end_clean();
                    }
                    $fileSize = filesize($filePath);
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                    header('Content-Length: ' . $fileSize);
                    header('Cache-Control: no-store, no-cache, must-revalidate');
                    header('Pragma: no-cache');
                    header('Expires: 0');
                    header('X-Content-Type-Options: nosniff');
                    readfile($filePath);
                    exit;
                } else {
                    $pageMode = 'download';
                    $fileInfo = [
                        'name'    => htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'),
                        'size'    => format_bytes((int) filesize($filePath)),
                        'expires' => date('F j, Y', $expiresAt),
                        'url'     => base_url() . '/?d=' . $downloadToken . '&dl=1',
                        'token'   => $downloadToken,
                    ];
                }
            }
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// HTML OUTPUT
// ═══════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SendFile – Anonymous ZIP Sharing</title>
  <meta name="robots" content="noindex, nofollow">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          animation: {
            'fade-in':    'fadeIn .4s ease-out',
            'slide-up':   'slideUp .4s ease-out',
            'pulse-slow': 'pulse 3s cubic-bezier(0.4,0,0.6,1) infinite',
          },
          keyframes: {
            fadeIn:  { from: { opacity: '0' },                    to: { opacity: '1' } },
            slideUp: { from: { opacity: '0', transform: 'translateY(24px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
          },
        },
      },
    };
  </script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; }
    .drop-active { border-color: #6366f1 !important; background-color: #eef2ff !important; }
    .progress-bar-inner { transition: width .3s ease; }
    .btn-primary {
      background: linear-gradient(135deg, #4f46e5, #7c3aed);
      transition: all .2s;
    }
    .btn-primary:hover { background: linear-gradient(135deg, #4338ca, #6d28d9); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(99,102,241,.35); }
    .btn-primary:active { transform: translateY(0); }
    .card { box-shadow: 0 25px 60px rgba(0,0,0,.25); }
  </style>
</head>
<body class="h-full bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 flex flex-col min-h-screen">

  <!-- Header -->
  <header class="py-6 px-8 flex items-center justify-between">
    <a href="/" class="flex items-center gap-2 group">
      <svg class="w-8 h-8 text-indigo-400 group-hover:text-indigo-300 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
      </svg>
      <span class="text-white font-semibold text-xl tracking-tight">SendFile</span>
    </a>
    <span class="text-slate-400 text-sm hidden sm:block">Anonymous · No registration · No tracking</span>
  </header>

  <!-- Main Content -->
  <main class="flex-1 flex items-center justify-center px-4 py-10">

<?php if ($pageMode === 'upload'): ?>
    <!-- ═══ UPLOAD PAGE ═══ -->
    <div class="w-full max-w-xl animate-slide-up">
      <div class="bg-white rounded-3xl card p-8 sm:p-10">

        <h1 class="text-2xl sm:text-3xl font-bold text-slate-800 mb-1">Send a ZIP file</h1>
        <p class="text-slate-500 mb-8 text-sm">Files are stored anonymously and deleted automatically after <strong>2 days</strong>.</p>

        <!-- Drop zone -->
        <div id="dropZone"
             class="border-2 border-dashed border-slate-300 rounded-2xl p-10 text-center cursor-pointer transition-all duration-200 hover:border-indigo-400 hover:bg-indigo-50"
             onclick="document.getElementById('fileInput').click()">

          <svg id="dzIcon" class="mx-auto mb-4 w-14 h-14 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l-3 3m3-3l3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.338-2.32 5.75 5.75 0 011.987 4.595A4.5 4.5 0 0117.25 19.5H6.75z"/>
          </svg>

          <p id="dzText" class="text-slate-600 font-medium">Drop your ZIP file here</p>
          <p class="text-slate-400 text-sm mt-1">or <span class="text-indigo-500 font-medium">click to browse</span></p>
          <p class="text-slate-400 text-xs mt-3">ZIP files only &nbsp;·&nbsp; max 2 GB</p>

          <input type="file" id="fileInput" accept=".zip,application/zip,application/x-zip-compressed" class="hidden">
        </div>

        <!-- File info preview -->
        <div id="filePreview" class="hidden mt-5 flex items-center gap-3 bg-indigo-50 border border-indigo-100 rounded-xl px-4 py-3">
          <svg class="w-8 h-8 text-indigo-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l3 3m0 0l3-3m-3 3v-7.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <div class="flex-1 min-w-0">
            <p id="previewName" class="text-slate-800 font-medium text-sm truncate"></p>
            <p id="previewSize" class="text-slate-500 text-xs"></p>
          </div>
          <button onclick="resetUpload()" class="text-slate-400 hover:text-slate-600 transition-colors ml-2 flex-shrink-0" title="Remove file">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Error message -->
        <div id="errorBox" class="hidden mt-4 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm"></div>

        <!-- Upload button -->
        <button id="uploadBtn" onclick="startUpload()"
                class="btn-primary w-full mt-6 py-3.5 rounded-xl text-white font-semibold text-base disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                disabled>
          Upload &amp; Get Link
        </button>

        <!-- Progress section -->
        <div id="progressSection" class="hidden mt-6">
          <div class="flex justify-between text-sm text-slate-600 mb-2">
            <span>Uploading…</span>
            <span id="progressPct">0%</span>
          </div>
          <div class="h-2.5 bg-slate-100 rounded-full overflow-hidden">
            <div id="progressBar" class="progress-bar-inner h-full rounded-full bg-gradient-to-r from-indigo-500 to-purple-500" style="width:0%"></div>
          </div>
          <p id="progressSpeed" class="text-xs text-slate-400 mt-2 text-right"></p>
        </div>

        <!-- Success section -->
        <div id="successSection" class="hidden mt-6">
          <div class="flex items-center gap-2 mb-4">
            <svg class="w-6 h-6 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-emerald-700 font-semibold">File uploaded successfully!</span>
          </div>

          <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1.5">Share this link</label>
          <div class="flex gap-2">
            <input id="shareLink" type="text" readonly
                   class="flex-1 min-w-0 border border-slate-200 rounded-xl px-3 py-2.5 text-sm text-slate-700 bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-300 cursor-text"
                   onclick="this.select()">
            <button onclick="copyLink()"
                    class="btn-primary flex-shrink-0 px-4 py-2.5 rounded-xl text-white text-sm font-medium flex items-center gap-1.5">
              <svg id="copyIcon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"/>
              </svg>
              <span id="copyLabel">Copy</span>
            </button>
          </div>

          <p id="successExpiry" class="text-xs text-slate-400 mt-3 text-center"></p>

          <button onclick="resetUpload()" class="mt-4 w-full py-2.5 rounded-xl border border-slate-200 text-slate-600 text-sm font-medium hover:bg-slate-50 transition-colors">
            Send another file
          </button>
        </div>

      </div><!-- /card -->

      <p class="text-center text-slate-500 text-xs mt-6">
        No account needed &nbsp;·&nbsp; No logs kept &nbsp;·&nbsp; Files auto-deleted after 2 days
      </p>
    </div>

<?php elseif ($pageMode === 'download'): ?>
    <!-- ═══ DOWNLOAD PAGE ═══ -->
    <div class="w-full max-w-md animate-slide-up">
      <div class="bg-white rounded-3xl card p-8 sm:p-10 text-center">

        <div class="w-16 h-16 bg-indigo-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
          <svg class="w-9 h-9 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
          </svg>
        </div>

        <h1 class="text-2xl font-bold text-slate-800 mb-1">You have a file</h1>
        <p class="text-slate-500 text-sm mb-7">Someone shared a file with you via SendFile.</p>

        <!-- File details -->
        <div class="bg-slate-50 rounded-2xl px-5 py-4 mb-7 text-left">
          <div class="flex items-start gap-3">
            <svg class="w-7 h-7 text-indigo-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
            </svg>
            <div class="min-w-0">
              <p class="font-semibold text-slate-800 truncate"><?= $fileInfo['name'] ?></p>
              <p class="text-slate-500 text-sm mt-0.5"><?= $fileInfo['size'] ?></p>
            </div>
          </div>
          <div class="mt-3 pt-3 border-t border-slate-200 flex items-center gap-1.5 text-xs text-slate-400">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Available until <?= $fileInfo['expires'] ?>
          </div>
        </div>

        <!-- Download button -->
        <a href="<?= $fileInfo['url'] ?>"
           class="btn-primary w-full inline-flex items-center justify-center gap-2 py-3.5 rounded-xl text-white font-semibold text-base">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
          </svg>
          Download ZIP
        </a>

        <p class="text-xs text-slate-400 mt-5">
          This download is anonymous. No personal data is collected or stored.
        </p>
      </div>

      <p class="text-center mt-4">
        <a href="/" class="text-slate-400 text-xs hover:text-slate-300 transition-colors">Send your own file →</a>
      </p>
    </div>

<?php elseif ($pageMode === 'expired'): ?>
    <!-- ═══ EXPIRED PAGE ═══ -->
    <div class="w-full max-w-sm animate-slide-up">
      <div class="bg-white rounded-3xl card p-10 text-center">
        <div class="w-16 h-16 bg-amber-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
          <svg class="w-9 h-9 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
        <h1 class="text-2xl font-bold text-slate-800 mb-2">Link expired</h1>
        <p class="text-slate-500 text-sm mb-6">This file was automatically deleted after 2 days.</p>
        <a href="/" class="btn-primary inline-block w-full py-3 rounded-xl text-white font-semibold text-sm">
          Send a new file
        </a>
      </div>
    </div>

<?php else: ?>
    <!-- ═══ 404 PAGE ═══ -->
    <div class="w-full max-w-sm animate-slide-up">
      <div class="bg-white rounded-3xl card p-10 text-center">
        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
          <svg class="w-9 h-9 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/>
          </svg>
        </div>
        <h1 class="text-2xl font-bold text-slate-800 mb-2">File not found</h1>
        <p class="text-slate-500 text-sm mb-6">The link may be invalid, or the file was already deleted.</p>
        <a href="/" class="btn-primary inline-block w-full py-3 rounded-xl text-white font-semibold text-sm">
          Go to SendFile
        </a>
      </div>
    </div>

<?php endif; ?>

  </main>

  <!-- Footer -->
  <footer class="py-5 text-center text-slate-600 text-xs">
    <p>No accounts &nbsp;·&nbsp; No database &nbsp;·&nbsp; No logs &nbsp;·&nbsp; Files deleted after 2 days</p>
  </footer>

<?php if ($pageMode === 'upload'): ?>
<script>
// ── State ──────────────────────────────────────────────────────────────────────
let selectedFile = null;
let uploading    = false;
let uploadStart  = 0;

// ── DOM refs ──────────────────────────────────────────────────────────────────
const dropZone       = document.getElementById('dropZone');
const fileInput      = document.getElementById('fileInput');
const filePreview    = document.getElementById('filePreview');
const previewName    = document.getElementById('previewName');
const previewSize    = document.getElementById('previewSize');
const errorBox       = document.getElementById('errorBox');
const uploadBtn      = document.getElementById('uploadBtn');
const progressSection = document.getElementById('progressSection');
const progressBar    = document.getElementById('progressBar');
const progressPct    = document.getElementById('progressPct');
const progressSpeed  = document.getElementById('progressSpeed');
const successSection = document.getElementById('successSection');
const shareLink      = document.getElementById('shareLink');
const successExpiry  = document.getElementById('successExpiry');

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtBytes(b) {
  if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
  if (b >= 1048576)    return (b / 1048576).toFixed(2)    + ' MB';
  if (b >= 1024)       return (b / 1024).toFixed(2)       + ' KB';
  return b + ' B';
}

function showError(msg) {
  errorBox.textContent = msg;
  errorBox.classList.remove('hidden');
}

function hideError() {
  errorBox.classList.add('hidden');
}

// ── File selection ────────────────────────────────────────────────────────────
function selectFile(file) {
  hideError();

  if (!file) return;

  if (!file.name.toLowerCase().endsWith('.zip')) {
    showError('Only .zip files are accepted.');
    return;
  }
  if (file.size > 2 * 1024 * 1024 * 1024) {
    showError('File exceeds the 2 GB limit.');
    return;
  }
  if (file.size === 0) {
    showError('File appears to be empty.');
    return;
  }

  selectedFile = file;
  previewName.textContent = file.name;
  previewSize.textContent = fmtBytes(file.size);
  filePreview.classList.remove('hidden');
  uploadBtn.disabled = false;
}

fileInput.addEventListener('change', () => {
  if (fileInput.files.length > 0) selectFile(fileInput.files[0]);
});

// ── Drag & Drop ───────────────────────────────────────────────────────────────
dropZone.addEventListener('dragover', (e) => {
  e.preventDefault();
  dropZone.classList.add('drop-active');
});
['dragleave', 'dragend'].forEach(ev =>
  dropZone.addEventListener(ev, () => dropZone.classList.remove('drop-active'))
);
dropZone.addEventListener('drop', (e) => {
  e.preventDefault();
  dropZone.classList.remove('drop-active');
  if (e.dataTransfer.files.length > 0) selectFile(e.dataTransfer.files[0]);
});

// ── Reset ─────────────────────────────────────────────────────────────────────
function resetUpload() {
  selectedFile = null;
  uploading    = false;
  fileInput.value = '';
  filePreview.classList.add('hidden');
  progressSection.classList.add('hidden');
  successSection.classList.add('hidden');
  progressBar.style.width = '0%';
  progressPct.textContent  = '0%';
  progressSpeed.textContent = '';
  uploadBtn.disabled = true;
  uploadBtn.textContent = 'Upload & Get Link';
  hideError();
}

// ── Upload ────────────────────────────────────────────────────────────────────
function startUpload() {
  if (!selectedFile || uploading) return;
  uploading = true;
  uploadStart = Date.now();

  hideError();
  uploadBtn.disabled = true;
  uploadBtn.textContent = 'Uploading…';
  progressSection.classList.remove('hidden');
  successSection.classList.add('hidden');

  const fd = new FormData();
  fd.append('file', selectedFile);

  const xhr = new XMLHttpRequest();
  xhr.open('POST', '/?action=upload', true);

  xhr.upload.addEventListener('progress', (e) => {
    if (!e.lengthComputable) return;
    const pct = Math.round((e.loaded / e.total) * 100);
    progressBar.style.width = pct + '%';
    progressPct.textContent  = pct + '%';

    const elapsed = (Date.now() - uploadStart) / 1000;
    if (elapsed > 0.5) {
      const speed = e.loaded / elapsed;
      const remaining = (e.total - e.loaded) / speed;
      progressSpeed.textContent = fmtBytes(Math.round(speed)) + '/s'
        + (remaining > 1 ? ' · ~' + Math.ceil(remaining) + 's left' : '');
    }
  });

  xhr.addEventListener('load', () => {
    uploading = false;
    let data;
    try { data = JSON.parse(xhr.responseText); } catch (_) {
      showError('Unexpected server response. Please try again.');
      uploadBtn.disabled = false;
      uploadBtn.textContent = 'Upload & Get Link';
      progressSection.classList.add('hidden');
      return;
    }

    if (!data.success) {
      showError(data.error || 'Upload failed.');
      uploadBtn.disabled = false;
      uploadBtn.textContent = 'Upload & Get Link';
      progressSection.classList.add('hidden');
      return;
    }

    // Success
    progressBar.style.width = '100%';
    progressPct.textContent  = '100%';
    progressSection.classList.add('hidden');

    shareLink.value = data.url;
    successExpiry.textContent = '⏱ This file will be automatically deleted on ' + data.expires;
    successSection.classList.remove('hidden');
  });

  xhr.addEventListener('error', () => {
    uploading = false;
    showError('Network error. Please check your connection and try again.');
    uploadBtn.disabled = false;
    uploadBtn.textContent = 'Upload & Get Link';
    progressSection.classList.add('hidden');
  });

  xhr.addEventListener('abort', () => {
    uploading = false;
    uploadBtn.disabled = false;
    uploadBtn.textContent = 'Upload & Get Link';
    progressSection.classList.add('hidden');
  });

  xhr.send(fd);
}

// ── Copy link ─────────────────────────────────────────────────────────────────
function copyLink() {
  const url = shareLink.value;
  if (!url) return;

  const copyLabel = document.getElementById('copyLabel');
  const copyIcon  = document.getElementById('copyIcon');

  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => {
      copyLabel.textContent = 'Copied!';
      setTimeout(() => { copyLabel.textContent = 'Copy'; }, 2000);
    });
  } else {
    shareLink.select();
    document.execCommand('copy');
    copyLabel.textContent = 'Copied!';
    setTimeout(() => { copyLabel.textContent = 'Copy'; }, 2000);
  }
}

// ── Keyboard shortcut ─────────────────────────────────────────────────────────
document.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !uploadBtn.disabled && !uploading) startUpload();
});
</script>
<?php endif; ?>

</body>
</html>
