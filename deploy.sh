#!/usr/bin/env bash
# deploy.sh — Pull the latest code and fix file-system ownership / permissions.
#
# Run this script (as root or with sudo) from the project root after every
# deployment instead of running "sudo git pull" on its own.  It pulls the
# latest code and then transfers ownership of the writable directory to the
# web-server user so that PHP can store uploads without "permission denied"
# errors.
#
# Usage:
#   sudo bash deploy.sh                       # default web-server user (www)
#   sudo WEB_USER=www-data bash deploy.sh     # Debian / Ubuntu
#   sudo WEB_USER=apache     bash deploy.sh   # RHEL / CentOS / AlmaLinux
#
# The script must be run from the root directory of the Send_File project.

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
# Set WEB_USER to the user your web server runs as.
# Common values:
#   www       — FreeBSD, OpenBSD
#   www-data  — Debian, Ubuntu
#   apache    — RHEL, CentOS, AlmaLinux, Fedora
#   nginx     — some Nginx-only stacks
WEB_USER="${WEB_USER:-www}"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
info()  { echo "  --> $*"; }
error() { echo "ERROR: $*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Guards
# ---------------------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    error "This script must be run as root (use sudo)."
fi

if [[ ! -f "index.php" ]]; then
    error "Run this script from the Send_File project root directory."
fi

if ! id -u "${WEB_USER}" &>/dev/null; then
    error "Web-server user '${WEB_USER}' not found. Set WEB_USER= to the correct user."
fi

# ---------------------------------------------------------------------------
# Steps
# ---------------------------------------------------------------------------
echo "==> Pulling latest code..."
git pull

echo "==> Setting ownership of writable directories to ${WEB_USER}:${WEB_USER}..."
# Only the directory the web server needs write access to is changed.
# This avoids giving the web server unnecessary access to source files.
#
#   uploads/  — user-uploaded files
chown -R "${WEB_USER}:${WEB_USER}" uploads/

echo "==> Setting directory and file permissions..."
# Directories: owner read/write/execute, group and others read/execute (755)
chmod -R 755 uploads/

echo ""
echo "==> Deployment complete."
echo "    Web-server user : ${WEB_USER}"
echo "    Writable paths  : uploads/"
echo ""
