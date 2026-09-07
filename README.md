# IPsw - Firmware Downloader & Management System

IPsw is a web application designed to automatically track, manage, and download Apple iOS/iPadOS firmware (IPSW) files with multi-device support, email alerts, and IDM integration.

---

## 🔐 Default Admin Credentials

To log into the system dashboard, use the following default credentials:

| Field | Value |
| :--- | :--- |
| **Username** | `admin` |
| **Password** | `vision2026` |

> ⚠️ **Security Note:** It is recommended to change these default credentials after initial setup in your `data/settings.json` or through the Settings dashboard.

---

## ✨ Features

- 📱 **Device & Release Tracking:** Browse and search Apple device models and their corresponding IPSW firmware versions.
- ⚡ **One-Click Download:** Easily trigger downloads or export download URLs for IDM (Internet Download Manager).
- 📧 **Email Alerts:** Configure Gmail SMTP accounts to receive notifications when new firmware releases are published.
- ⚙️ **Settings Management:** Customize download directories, auto-check intervals, notification preferences, and accounts.

---

## 🚀 Quick Start

1. **Requirements:**
   - Web Server (Apache / NGINX / Laragon)
   - PHP 7.4+

2. **Setup:**
   - Clone this repository into your web server directory (e.g. `www/IP` or `htdocs/IP`).
   - Access the site via your browser (`http://localhost/IP`).
   - Log in using `admin` / `vision2026`.
