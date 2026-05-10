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
define('DOWNLOAD_CHUNK_SIZE', 1048576);                   // bytes per read() during download (1 MiB)

// Set to your actual hostname to prevent Host-header injection attacks
// (e.g. 'sendfile.example.com').  Leave empty to fall back to HTTP_HOST,
// which is acceptable in trusted internal / dev environments only.
define('APP_HOST', '');

// Upload rate-limit: max uploads per IP address per rolling time window.
define('RATE_LIMIT_UPLOADS', 10);    // maximum uploads allowed
define('RATE_LIMIT_WINDOW',  3600);  // rolling window in seconds (1 hour)
define('RATE_LIMIT_DIR',     sys_get_temp_dir() . '/sendfile_rl/');

// Keep errors out of browser output; write to the server's default error log
// (configured via error_log in php.ini or the PHP-FPM pool config).
ini_set('display_errors', '0');
ini_set('log_errors',     '1');

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
 * Sanitise a raw HTTP_HOST value to a safe hostname[:port] string.
 * Accepts standard FQDNs (with optional port), IPv4 addresses, and IPv6
 * literals in brackets.  Returns 'localhost' for anything that doesn't match.
 */
function sanitize_http_host(string $raw): string
{
    // IPv6 literal with optional port: [::1] or [::1]:8080
    if (preg_match('/^\[([a-fA-F0-9:]+)\](?::(\d{1,5}))?$/', $raw, $m)) {
        return '[' . $m[1] . ']' . (isset($m[2]) && $m[2] !== '' ? ':' . $m[2] : '');
    }
    // Hostname or IPv4 with optional port
    if (preg_match('/^([a-zA-Z0-9.\-]+)(?::(\d{1,5}))?$/', $raw, $m)) {
        return $m[1] . (isset($m[2]) && $m[2] !== '' ? ':' . $m[2] : '');
    }
    return 'localhost';
}

/**
 * Build the base URL, respecting X-Forwarded-Proto from HAProxy/pfSense.
 * Proto is restricted to 'http'/'https'.  The host is taken from APP_HOST
 * when configured (prevents Host-header injection); otherwise HTTP_HOST is
 * sanitised via sanitize_http_host().
 */
function base_url(): string
{
    $rawProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http';
    $proto    = in_array($rawProto, ['http', 'https'], true) ? $rawProto : 'http';

    if (APP_HOST !== '') {
        $host = APP_HOST;
    } else {
        $host = sanitize_http_host($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

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

    // Prevent multiple concurrent requests from hammering the filesystem
    // with redundant cleanup scans at the same time.
    $lockFile = sys_get_temp_dir() . '/sendfile_cleanup.lock';
    $lockFp   = fopen($lockFile, 'c');
    if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        if ($lockFp !== false) {
            fclose($lockFp);
        }
        return; // Another process is already running cleanup; skip.
    }

    $entries = scandir(UPLOAD_DIR);
    if ($entries === false) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
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
        $metaContent = file_get_contents($metaFile);
        if ($metaContent === false) {
            delete_directory($tokenDir);
            continue;
        }
        $uploadedAt = (int) $metaContent;
        if ($uploadedAt === 0 || (time() - $uploadedAt) > FILE_EXPIRY_SECONDS) {
            delete_directory($tokenDir);
        }
    }

    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

// ── Rate-limit helper: max RATE_LIMIT_UPLOADS per IP per RATE_LIMIT_WINDOW ────

/**
 * Returns true if this IP is within the allowed upload rate, false if exceeded.
 * Uses an exclusive file lock to avoid race conditions between concurrent uploads.
 */
function check_upload_rate_limit(): bool
{
    if (!is_dir(RATE_LIMIT_DIR)) {
        if (!mkdir(RATE_LIMIT_DIR, 0700, true) && !is_dir(RATE_LIMIT_DIR)) {
            // Cannot create the rate-limit scratch directory; allow the upload
            // rather than blocking all uploads due to a configuration issue.
            return true;
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') {
        return true; // Cannot determine IP; allow rather than block everything.
    }

    // Store a hashed key — never write the raw IP address to disk.
    $key  = hash('sha256', $ip);
    $file = RATE_LIMIT_DIR . $key;
    $now  = time();

    $fp = fopen($file, 'c+');
    if ($fp === false) {
        return true; // Cannot open counter file; allow.
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return true;
    }

    $raw     = stream_get_contents($fp);
    $entries = [];
    if ($raw !== false && $raw !== '') {
        $entries = array_values(array_filter(
            array_map('intval', explode("\n", trim($raw))),
            fn(int $t): bool => $t > 0 && ($now - $t) < RATE_LIMIT_WINDOW
        ));
    }

    $allowed = count($entries) < RATE_LIMIT_UPLOADS;
    if ($allowed) {
        $entries[] = $now;
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, implode("\n", $entries));
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return $allowed;
}

// ── Bootstrap: ensure uploads directory exists ────────────────────────────────

if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0750, true);
}

// Run cleanup on ~1 % of requests to avoid a full scandir() on every hit.
// flock() inside cleanup_expired_uploads() ensures only one process runs it.
if (random_int(1, 100) === 1) {
    cleanup_expired_uploads();
}

// ── Security: HSTS sent on every response (defence-in-depth) ─────────────────
// HTTPS is terminated by HAProxy upstream; when the forwarded proto is 'https'
// we know the client connection is encrypted and we can assert HSTS.
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// CSP nonce — generated here, used in HTML output section below.
$cspNonce = bin2hex(random_bytes(16));

// ── Route: POST /?action=upload ───────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'upload') {
    header('Content-Type: application/json; charset=utf-8');

    // --- CSRF: if the browser sends an Origin header it must match this app ----
    // Modern browsers always include Origin on cross-origin POST requests.
    $originHeader = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($originHeader !== '') {
        $ownProto  = in_array($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '', ['http', 'https'], true)
                     ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http';
        $ownHost   = APP_HOST !== ''
                     ? APP_HOST
                     : sanitize_http_host($_SERVER['HTTP_HOST'] ?? 'localhost');
        $ownOrigin = $ownProto . '://' . $ownHost;
        if (rtrim($originHeader, '/') !== $ownOrigin) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden.']);
            exit;
        }
    }

    // --- Rate limiting --------------------------------------------------------
    if (!check_upload_rate_limit()) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many uploads. Please try again later.']);
        exit;
    }

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
    $safeName = preg_replace('/[^a-zA-Z0-9\-._]/', '_', $origName);
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
                // Re-apply upload-time sanitisation as defence-in-depth; trim()
                // prevents a trailing newline in the .filename file from breaking
                // file_exists() and exposing an incorrect 404.
                $filename = trim((string) file_get_contents($filenameFile));
                $filename = preg_replace('/[^a-zA-Z0-9\-._]/', '_', $filename);
                $filename = preg_replace('/\.{2,}/', '.', $filename);
                // Reject filenames that collapse to all dots (e.g. '...' → '.').
                if (trim($filename, '.') === '') {
                    $filename = '';
                }
                $filePath = $tokenDir . '/' . $filename;

                // Verify the resolved path stays within the token directory to
                // guard against any path-traversal via a tampered .filename file.
                $realTokenDir = realpath($tokenDir);
                $realFilePath = realpath($filePath);

                if (
                    empty($filename)
                    || $realTokenDir === false
                    || $realFilePath === false
                    || !str_starts_with($realFilePath, $realTokenDir . DIRECTORY_SEPARATOR)
                ) {
                    http_response_code(404);
                    $pageMode = 'not_found';
                } elseif (isset($_GET['dl'])) {
                    // ── Serve file download ──────────────────────────────────
                    // Disable PHP's own zlib output compression first, before
                    // any output buffer is touched.  ob_end_clean() will close
                    // the zlib buffer that zlib.output_compression creates, but
                    // calling ini_set here is a belt-and-suspenders measure that
                    // works even when the buffer cannot be removed.
                    ini_set('zlib.output_compression', '0');
                    // Clean every nested output buffer level (PHP ini + Apache/FPM
                    // can create more than one), so that echoed chunks go directly
                    // to the underlying stream and are not silently accumulated in
                    // an outer buffer that could exhaust PHP's memory limit.
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    $fileSize = filesize($filePath);
                    if ($fileSize === false) {
                        http_response_code(500);
                        exit;
                    }

                    // Prevent the PHP execution time limit from killing the
                    // script mid-transfer (large files on slow connections).
                    set_time_limit(0);

                    // RFC 6266-compliant Content-Disposition with UTF-8 filename
                    $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $filename);

                    // ── HTTP Range support (resumable downloads) ─────────────
                    $rangeStart = 0;
                    $rangeEnd   = $fileSize - 1;
                    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';

                    if ($rangeHeader !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $m)) {
                        $reqStart = $m[1] !== '' ? (int) $m[1] : null;
                        $reqEnd   = $m[2] !== '' ? (int) $m[2] : null;

                        if ($reqStart === null) {
                            // suffix-range: bytes=-N (last N bytes); $m[2] must be non-empty
                            if ($reqEnd === null || $reqEnd === 0) {
                                header('HTTP/1.1 416 Range Not Satisfiable');
                                header('Content-Range: bytes */' . $fileSize);
                                exit;
                            }
                            $rangeStart = max(0, $fileSize - $reqEnd);
                        } else {
                            $rangeStart = $reqStart;
                        }
                        if ($reqEnd !== null) {
                            $rangeEnd = min($reqEnd, $fileSize - 1);
                        }

                        if ($rangeStart > $rangeEnd || $rangeStart >= $fileSize) {
                            header('HTTP/1.1 416 Range Not Satisfiable');
                            header('Content-Range: bytes */' . $fileSize);
                            exit;
                        }

                        http_response_code(206);
                        header('Content-Range: bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $fileSize);
                    }

                    $sendLength = $rangeEnd - $rangeStart + 1;

                    header('Content-Type: application/zip');
                    header(
                        'Content-Disposition: attachment;'
                        . ' filename="' . str_replace('"', '\\"', $asciiName) . '";'
                        . ' filename*=UTF-8\'\'' . rawurlencode($filename)
                    );
                    header('Content-Length: ' . $sendLength);
                    header('Accept-Ranges: bytes');
                    // Do NOT send Content-Encoding: identity.  If PHP's
                    // zlib.output_compression or another layer is active it will
                    // have already set Content-Encoding: gzip; overriding that
                    // with "identity" makes the browser save gzip-compressed bytes
                    // as a ZIP file (corrupt).  mod_deflate buffering is prevented
                    // by the no-gzip / no-brotli env-vars set in .htaccess.
                    header('Cache-Control: no-store, no-cache, must-revalidate');
                    header('Pragma: no-cache');
                    header('Expires: 0');
                    header('X-Content-Type-Options: nosniff');

                    $fp = fopen($filePath, 'rb');
                    if ($fp === false) {
                        http_response_code(500);
                        exit;
                    }
                    if ($rangeStart > 0) {
                        if (fseek($fp, $rangeStart) !== 0) {
                            fclose($fp);
                            http_response_code(500);
                            exit;
                        }
                    }
                    $remaining = $sendLength;
                    while ($remaining > 0 && !feof($fp)) {
                        if (connection_aborted()) {
                            break;
                        }
                        $chunk = fread($fp, min(DOWNLOAD_CHUNK_SIZE, $remaining));
                        if ($chunk === false || $chunk === '') {
                            // false  = read error; '' = unexpected empty read (not
                            // EOF on a regular file, but guard it to avoid an
                            // infinite loop where $remaining never decreases).
                            break;
                        }
                        echo $chunk;
                        // Flush all output buffers to Apache/HAProxy immediately.
                        // Without this, data is held in PHP's buffer and HAProxy
                        // can time out the backend connection mid-transfer on
                        // large files, stopping the download at a random offset.
                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();
                        $remaining -= strlen($chunk);
                    }
                    fclose($fp);
                    exit;
                } else {
                    $pageMode = 'download';
                    $rawSize  = filesize($filePath);
                    $fileInfo = [
                        'name'    => htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'),
                        'size'    => format_bytes($rawSize !== false ? $rawSize : 0),
                        'expires' => date('F j, Y', $expiresAt),
                        'url'     => htmlspecialchars(base_url() . '/?d=' . $downloadToken . '&dl=1', ENT_QUOTES, 'UTF-8'),
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

// Content-Security-Policy — set here (HTML page responses only).
// Tailwind CSS is inlined with a per-request nonce; the CDN Play script is
// no longer loaded.  Google Fonts is allowed via domain in style-src.
header(
    "Content-Security-Policy: "
    . "default-src 'none'; "
    . "script-src 'nonce-{$cspNonce}'; "
    . "style-src 'nonce-{$cspNonce}' https://fonts.googleapis.com; "
    . "font-src https://fonts.gstatic.com; "
    . "img-src https://picsum.photos https://fastly.picsum.photos; "
    . "connect-src 'self'; "
    . "form-action 'self'; "
    . "base-uri 'self'; "
    . "frame-ancestors 'none';"
);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SendFile – Anonymous ZIP Sharing</title>
  <meta name="robots" content="noindex, nofollow">
  <!-- Google Fonts loaded via <link> (faster + no @import in inline style) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
  <style nonce="<?= $cspNonce ?>">
    /* Tailwind CSS v3.4.1 – generated from classes used in this file (no CDN) */
    /*! tailwindcss v3.4.1 | MIT License | https://tailwindcss.com*/*,:after,:before{box-sizing:border-box;border:0 solid #e5e7eb}:after,:before{--tw-content:""}:host,html{line-height:1.5;-webkit-text-size-adjust:100%;-moz-tab-size:4;-o-tab-size:4;tab-size:4;font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji;font-feature-settings:normal;font-variation-settings:normal;-webkit-tap-highlight-color:transparent}body{margin:0;line-height:inherit}hr{height:0;color:inherit;border-top-width:1px}abbr:where([title]){-webkit-text-decoration:underline dotted;text-decoration:underline dotted}h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}a{color:inherit;text-decoration:inherit}b,strong{font-weight:bolder}code,kbd,pre,samp{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace;font-feature-settings:normal;font-variation-settings:normal;font-size:1em}small{font-size:80%}sub,sup{font-size:75%;line-height:0;position:relative;vertical-align:initial}sub{bottom:-.25em}sup{top:-.5em}table{text-indent:0;border-color:inherit;border-collapse:collapse}button,input,optgroup,select,textarea{font-family:inherit;font-feature-settings:inherit;font-variation-settings:inherit;font-size:100%;font-weight:inherit;line-height:inherit;color:inherit;margin:0;padding:0}button,select{text-transform:none}[type=button],[type=reset],[type=submit],button{-webkit-appearance:button;background-color:initial;background-image:none}:-moz-focusring{outline:auto}:-moz-ui-invalid{box-shadow:none}progress{vertical-align:initial}::-webkit-inner-spin-button,::-webkit-outer-spin-button{height:auto}[type=search]{-webkit-appearance:textfield;outline-offset:-2px}::-webkit-search-decoration{-webkit-appearance:none}::-webkit-file-upload-button{-webkit-appearance:button;font:inherit}summary{display:list-item}blockquote,dd,dl,figure,h1,h2,h3,h4,h5,h6,hr,p,pre{margin:0}fieldset{margin:0}fieldset,legend{padding:0}menu,ol,ul{list-style:none;margin:0;padding:0}dialog{padding:0}textarea{resize:vertical}input::-moz-placeholder,textarea::-moz-placeholder{opacity:1;color:#9ca3af}input::placeholder,textarea::placeholder{opacity:1;color:#9ca3af}[role=button],button{cursor:pointer}:disabled{cursor:default}audio,canvas,embed,iframe,img,object,svg,video{display:block;vertical-align:middle}img,video{max-width:100%;height:auto}[hidden]{display:none}*,::backdrop,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:#3b82f680;--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: }.mx-auto{margin-left:auto;margin-right:auto}.mb-1{margin-bottom:.25rem}.mb-1\.5{margin-bottom:.375rem}.mb-2{margin-bottom:.5rem}.mb-4{margin-bottom:1rem}.mb-6{margin-bottom:1.5rem}.mb-7{margin-bottom:1.75rem}.mb-8{margin-bottom:2rem}.ml-2{margin-left:.5rem}.mt-0{margin-top:0}.mt-0\.5{margin-top:.125rem}.mt-1{margin-top:.25rem}.mt-2{margin-top:.5rem}.mt-3{margin-top:.75rem}.mt-4{margin-top:1rem}.mt-5{margin-top:1.25rem}.mt-6{margin-top:1.5rem}.block{display:block}.inline-block{display:inline-block}.flex{display:flex}.inline-flex{display:inline-flex}.hidden{display:none}.h-14{height:3.5rem}.h-16{height:4rem}.h-2{height:.5rem}.h-2\.5{height:.625rem}.h-3{height:.75rem}.h-3\.5{height:.875rem}.h-4{height:1rem}.h-5{height:1.25rem}.h-6{height:1.5rem}.h-7{height:1.75rem}.h-8{height:2rem}.h-9{height:2.25rem}.h-full{height:100%}.min-h-screen{min-height:100vh}.w-14{width:3.5rem}.w-16{width:4rem}.w-3{width:.75rem}.w-3\.5{width:.875rem}.w-4{width:1rem}.w-5{width:1.25rem}.w-6{width:1.5rem}.w-7{width:1.75rem}.w-8{width:2rem}.w-9{width:2.25rem}.w-full{width:100%}.min-w-0{min-width:0}.max-w-md{max-width:28rem}.max-w-sm{max-width:24rem}.max-w-xl{max-width:36rem}.flex-1{flex:1 1 0%}.flex-shrink-0{flex-shrink:0}.cursor-pointer{cursor:pointer}.cursor-text{cursor:text}.flex-col{flex-direction:column}.items-start{align-items:flex-start}.items-center{align-items:center}.justify-start{justify-content:flex-start}.justify-center{justify-content:center}.justify-between{justify-content:space-between}.gap-1{gap:.25rem}.gap-1\.5{gap:.375rem}.gap-2{gap:.5rem}.gap-3{gap:.75rem}.overflow-hidden,.truncate{overflow:hidden}.truncate{text-overflow:ellipsis;white-space:nowrap}.rounded-2xl{border-radius:1rem}.rounded-3xl{border-radius:1.5rem}.rounded-full{border-radius:9999px}.rounded-xl{border-radius:.75rem}.border{border-width:1px}.border-2{border-width:2px}.border-t{border-top-width:1px}.border-dashed{border-style:dashed}.border-indigo-100{--tw-border-opacity:1;border-color:rgb(224 231 255/var(--tw-border-opacity))}.border-red-200{--tw-border-opacity:1;border-color:rgb(254 202 202/var(--tw-border-opacity))}.border-slate-200{--tw-border-opacity:1;border-color:rgb(226 232 240/var(--tw-border-opacity))}.border-slate-300{--tw-border-opacity:1;border-color:rgb(203 213 225/var(--tw-border-opacity))}.bg-amber-100{--tw-bg-opacity:1;background-color:rgb(254 243 199/var(--tw-bg-opacity))}.bg-indigo-100{--tw-bg-opacity:1;background-color:rgb(224 231 255/var(--tw-bg-opacity))}.bg-indigo-50{--tw-bg-opacity:1;background-color:rgb(238 242 255/var(--tw-bg-opacity))}.bg-red-50{--tw-bg-opacity:1;background-color:rgb(254 242 242/var(--tw-bg-opacity))}.bg-slate-100{--tw-bg-opacity:1;background-color:rgb(241 245 249/var(--tw-bg-opacity))}.bg-slate-50{--tw-bg-opacity:1;background-color:rgb(248 250 252/var(--tw-bg-opacity))}.bg-gradient-to-r{background-image:linear-gradient(to right,var(--tw-gradient-stops))}.from-indigo-500{--tw-gradient-from:#6366f1 var(--tw-gradient-from-position);--tw-gradient-to:#6366f100 var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.to-purple-500{--tw-gradient-to:#a855f7 var(--tw-gradient-to-position)}.p-10{padding:2.5rem}.p-8{padding:2rem}.px-12{padding-left:3rem;padding-right:3rem}.px-3{padding-left:.75rem;padding-right:.75rem}.px-4{padding-left:1rem;padding-right:1rem}.px-5{padding-left:1.25rem;padding-right:1.25rem}.px-8{padding-left:2rem;padding-right:2rem}.py-10{padding-top:2.5rem;padding-bottom:2.5rem}.py-2{padding-top:.5rem;padding-bottom:.5rem}.py-2\.5{padding-top:.625rem;padding-bottom:.625rem}.py-3{padding-top:.75rem;padding-bottom:.75rem}.py-3\.5{padding-top:.875rem;padding-bottom:.875rem}.py-4{padding-top:1rem;padding-bottom:1rem}.py-5{padding-top:1.25rem;padding-bottom:1.25rem}.py-6{padding-top:1.5rem;padding-bottom:1.5rem}.pt-3{padding-top:.75rem}.text-left{text-align:left}.text-center{text-align:center}.text-right{text-align:right}.text-2xl{font-size:1.5rem;line-height:2rem}.text-base{font-size:1rem;line-height:1.5rem}.text-sm{font-size:.875rem;line-height:1.25rem}.text-xl{font-size:1.25rem;line-height:1.75rem}.text-xs{font-size:.75rem;line-height:1rem}.font-bold{font-weight:700}.font-medium{font-weight:500}.font-semibold{font-weight:600}.uppercase{text-transform:uppercase}.tracking-tight{letter-spacing:-.025em}.tracking-wide{letter-spacing:.025em}.text-amber-500{--tw-text-opacity:1;color:rgb(245 158 11/var(--tw-text-opacity))}.text-emerald-500{--tw-text-opacity:1;color:rgb(16 185 129/var(--tw-text-opacity))}.text-emerald-700{--tw-text-opacity:1;color:rgb(4 120 87/var(--tw-text-opacity))}.text-indigo-400{--tw-text-opacity:1;color:rgb(129 140 248/var(--tw-text-opacity))}.text-indigo-500{--tw-text-opacity:1;color:rgb(99 102 241/var(--tw-text-opacity))}.text-indigo-600{--tw-text-opacity:1;color:rgb(79 70 229/var(--tw-text-opacity))}.text-red-700{--tw-text-opacity:1;color:rgb(185 28 28/var(--tw-text-opacity))}.text-slate-200{--tw-text-opacity:1;color:rgb(226 232 240/var(--tw-text-opacity))}.text-slate-400{--tw-text-opacity:1;color:rgb(148 163 184/var(--tw-text-opacity))}.text-slate-500{--tw-text-opacity:1;color:rgb(100 116 139/var(--tw-text-opacity))}.text-slate-600{--tw-text-opacity:1;color:rgb(71 85 105/var(--tw-text-opacity))}.text-slate-700{--tw-text-opacity:1;color:rgb(51 65 85/var(--tw-text-opacity))}.text-slate-800{--tw-text-opacity:1;color:rgb(30 41 59/var(--tw-text-opacity))}.text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity))}.transition-all{transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-colors{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.duration-200{transition-duration:.2s}.hover\:border-indigo-400:hover{--tw-border-opacity:1;border-color:rgb(129 140 248/var(--tw-border-opacity))}.hover\:bg-indigo-50:hover{--tw-bg-opacity:1;background-color:rgb(238 242 255/var(--tw-bg-opacity))}.hover\:bg-slate-50:hover{--tw-bg-opacity:1;background-color:rgb(248 250 252/var(--tw-bg-opacity))}.hover\:text-slate-300:hover{--tw-text-opacity:1;color:rgb(203 213 225/var(--tw-text-opacity))}.hover\:text-slate-600:hover{--tw-text-opacity:1;color:rgb(71 85 105/var(--tw-text-opacity))}.focus\:outline-none:focus{outline:2px solid #0000;outline-offset:2px}.focus\:ring-2:focus{--tw-ring-offset-shadow:var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color);--tw-ring-shadow:var(--tw-ring-inset) 0 0 0 calc(2px + var(--tw-ring-offset-width)) var(--tw-ring-color);box-shadow:var(--tw-ring-offset-shadow),var(--tw-ring-shadow),var(--tw-shadow,0 0 #0000)}.focus\:ring-indigo-300:focus{--tw-ring-opacity:1;--tw-ring-color:rgb(165 180 252/var(--tw-ring-opacity))}.disabled\:transform-none:disabled{transform:none}.disabled\:cursor-not-allowed:disabled{cursor:not-allowed}.disabled\:opacity-50:disabled{opacity:.5}.group:hover .group-hover\:text-indigo-300{--tw-text-opacity:1;color:rgb(165 180 252/var(--tw-text-opacity))}@media (min-width:640px){.sm\:block{display:block}.sm\:p-10{padding:2.5rem}.sm\:text-3xl{font-size:1.875rem;line-height:2.25rem}}
    /* Custom animations (previously in tailwind.config – now plain CSS) */
    @keyframes fadeIn  { from { opacity: 0; }                              to { opacity: 1; } }
    @keyframes slideUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes pulse   { 0%,100% { opacity: 1; } 50% { opacity: .5; } }
    .animate-fade-in    { animation: fadeIn  .4s ease-out; }
    .animate-slide-up   { animation: slideUp .4s ease-out; }
    .animate-pulse-slow { animation: pulse   3s cubic-bezier(.4,0,.6,1) infinite; }
    /* App-specific styles */
    /* Background image from picsum.photos */
    body { font-family: 'Inter', sans-serif; background-image: url('https://picsum.photos/1920/1080?random=<?= rand() ?>'); background-size: cover; background-position: center; background-attachment: fixed; }
    .drop-active { border-color: #6366f1 !important; background-color: #eef2ff !important; }
    .progress-bar-inner { transition: width .3s ease; }
    .btn-primary {
      background: linear-gradient(135deg, #4f46e5, #7c3aed);
      transition: all .2s;
    }
    .btn-primary:hover { background: linear-gradient(135deg, #4338ca, #6d28d9); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(99,102,241,.35); }
    .btn-primary:active { transform: translateY(0); }
    .card { box-shadow: 0 25px 60px rgba(0,0,0,.25); background: rgba(255,255,255,0.72); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); }
    .outside-text { text-shadow: 0 1px 6px rgba(0,0,0,.55); }
  </style>
</head>
<body class="h-full flex flex-col min-h-screen">

  <!-- Header -->
  <header class="py-6 px-8 flex items-center justify-between">
    <a href="/" class="flex items-center gap-2 group">
      <svg class="w-8 h-8 text-indigo-400 group-hover:text-indigo-300 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
      </svg>
      <span class="text-white font-semibold text-xl tracking-tight">SendFile</span>
    </a>
    <span class="text-slate-200 text-sm hidden sm:block outside-text">Anonymous · No registration · No tracking</span>
  </header>

  <!-- Main Content -->
  <main class="flex-1 flex items-center justify-start px-12 py-10">

<?php if ($pageMode === 'upload'): ?>
    <!-- ═══ UPLOAD PAGE ═══ -->
    <div class="w-full max-w-xl animate-slide-up">
      <div class="rounded-3xl card p-8 sm:p-10">

        <h1 class="text-2xl sm:text-3xl font-bold text-slate-800 mb-1">Send a ZIP file</h1>
        <p class="text-slate-500 mb-8 text-sm">Files are stored anonymously and deleted automatically after <strong>2 days</strong>.</p>

        <!-- Drop zone -->
        <div id="dropZone"
             class="border-2 border-dashed border-slate-300 rounded-2xl p-10 text-center cursor-pointer transition-all duration-200 hover:border-indigo-400 hover:bg-indigo-50">

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
          <button id="removeFileBtn" class="text-slate-400 hover:text-slate-600 transition-colors ml-2 flex-shrink-0" title="Remove file">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Error message -->
        <div id="errorBox" class="hidden mt-4 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm"></div>

        <!-- Upload button -->
        <button id="uploadBtn"
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
                   class="flex-1 min-w-0 border border-slate-200 rounded-xl px-3 py-2.5 text-sm text-slate-700 bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-300 cursor-text">
            <button id="copyBtn"
                    class="btn-primary flex-shrink-0 px-4 py-2.5 rounded-xl text-white text-sm font-medium flex items-center gap-1.5">
              <svg id="copyIcon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"/>
              </svg>
              <span id="copyLabel">Copy</span>
            </button>
          </div>

          <p id="successExpiry" class="text-xs text-slate-400 mt-3 text-center"></p>

          <button id="resetBtn" class="mt-4 w-full py-2.5 rounded-xl border border-slate-200 text-slate-600 text-sm font-medium hover:bg-slate-50 transition-colors">
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
      <div class="rounded-3xl card p-8 sm:p-10 text-center">

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
      <div class="rounded-3xl card p-10 text-center">
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
      <div class="rounded-3xl card p-10 text-center">
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
  <footer class="py-5 text-center text-slate-200 text-xs outside-text">
    <p>No accounts &nbsp;·&nbsp; No database &nbsp;·&nbsp; No logs &nbsp;·&nbsp; Files deleted after 2 days</p>
  </footer>

<?php if ($pageMode === 'upload'): ?>
<script nonce="<?= $cspNonce ?>">
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

// ── Click handlers (wired here to satisfy CSP nonce; inline onclick is blocked)
dropZone.addEventListener('click', (e) => {
  if (e.target !== fileInput) fileInput.click();
});
document.getElementById('removeFileBtn').addEventListener('click', () => resetUpload());
uploadBtn.addEventListener('click', () => startUpload());
document.getElementById('copyBtn').addEventListener('click', () => copyLink());
document.getElementById('resetBtn').addEventListener('click', () => resetUpload());
shareLink.addEventListener('click', () => shareLink.select());

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
