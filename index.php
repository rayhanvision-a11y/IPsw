<?php
@ini_set('session.cookie_path', '/');
if (session_status() === PHP_SESSION_NONE) @session_start();
if (empty($_SESSION['loggedIn'])) {
    $queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: login.php' . $queryString);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>IPSW Master — Vision Technologies</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="public/css/style.css">
  <link rel="stylesheet" href="public/css/design.css?v=<?= filemtime(__DIR__.'/public/css/design.css') ?>">
</head>
<body class="dark-theme">

  <!-- HEADER -->
  <header class="app-header">
    <a href="index.php" class="logo-container" style="text-decoration: none;">
      <img src="https://visiontech.com.bd/logo/vision-logo-latest.svg" alt="Vision Technologies" class="vt-logo-img">
    </a>

    <nav class="nav-tabs" id="main-nav">
      <button class="nav-btn active" data-tab="releases" data-source="official">
        <i class="fa-brands fa-apple"></i> Official
        <span class="badge pulse-badge" id="badge-live">Live</span>
      </button>
      <button class="nav-btn" data-tab="releases" data-source="beta">
        <i class="fa-solid fa-flask"></i> Beta
      </button>
      <button class="nav-btn" data-tab="releases" data-source="all">
        <i class="fa-solid fa-layer-group"></i> All (Official &amp; Beta)
      </button>
      <button class="nav-btn" data-tab="analytics">
        <i class="fa-solid fa-chart-line"></i> Graphical Analytics
      </button>
      <button class="nav-btn" data-tab="settings">
        <i class="fa-solid fa-gear"></i> Settings &amp; Gmail
      </button>
    </nav>

    <div class="header-right">
      <!-- Header Release Date Filter -->
      <div class="header-release-filter" id="header-release-filter-wrap">
        <label class="hdr-filter-label-wrap" title="Check to show last release date & release dates list for selected data">
          <input type="checkbox" id="chk-header-release-info">
          <span class="hdr-filter-checkbox-custom"></span>
          <i class="fa-solid fa-calendar-check" style="color:#00f2fe;"></i>
          <span class="hdr-filter-title">Release Dates</span>
        </label>
        
        <div class="hdr-release-badge hidden" id="hdr-release-badge" title="Click to view release dates list">
          <i class="fa-solid fa-clock-rotate-left"></i>
          <span id="hdr-last-date-text">Last: —</span>
        </div>

        <div class="hdr-release-popover hidden" id="hdr-release-popover">
          <div class="hdr-popover-header">
            <span><i class="fa-solid fa-filter" style="color:#00f2fe;"></i> Release Filter Info</span>
            <button class="hdr-popover-close" id="hdr-popover-close"><i class="fa-solid fa-xmark"></i></button>
          </div>
          <div class="hdr-popover-body">
            <div class="hdr-info-block">
              <div class="hdr-info-title"><i class="fa-solid fa-clock" style="color:#f59e0b;"></i> Last Release Date</div>
              <div class="hdr-info-value" id="hdr-info-last-date">Loading date...</div>
            </div>
            <div class="hdr-info-block">
              <div class="hdr-info-title">
                <i class="fa-solid fa-calendar-days" style="color:#38bdf8;"></i> Release Dates (<span id="hdr-info-dates-count">0</span>)
              </div>
              <div class="hdr-dates-list" id="hdr-info-dates-list">
                <div class="hdr-empty-dates">No dates found for selected filter</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="gmail-badge" id="gmail-status-badge">
        <i class="fa-solid fa-envelope"></i>
        <span id="gmail-connected-count">0</span> Gmails Connected
      </div>
      <div class="status-indicator">
        <div class="dot green"></div>
        <span>IDM Ready</span>
      </div>
      <a href="logout.php" class="btn-logout">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
      </a>
    </div>
  </header>

  <!-- MAIN -->
  <div class="main-container">

    <!-- TAB: RELEASES -->
    <div class="tab-pane active" id="tab-releases">

      <!-- Feed Panel -->
      <div class="feed-panel">

        <!-- Panel Header -->
        <div class="feed-panel-header">
          <div class="feed-title-group">
            <div class="feed-icon"><i class="fa-brands fa-apple"></i></div>
            <div>
              <h2 class="feed-title">Firmware Releases Feed</h2>
              <p class="feed-subtitle">Real-time Apple IPSW &amp; OTA Update Stream</p>
            </div>
            <span class="live-badge"><span class="live-dot"></span> Live</span>
          </div>
          <div class="feed-header-actions">
            <button class="btn-feed-purple" id="btn-open-cache-exporter" title="Pre-fill Cache Server with IPSW links">
              <i class="fa-solid fa-server"></i> Cache Server Exporter
            </button>
            <button class="btn-feed-green" id="btn-export-all-releases">
              <i class="fa-solid fa-file-lines"></i> Export .txt List
            </button>
            <button class="btn-feed-teal" id="btn-send-all-idm">
              <i class="fa-solid fa-bolt"></i> 1-Click Send All to IDM
            </button>
            <button class="btn-icon" id="btn-refresh-releases" title="Refresh">
              <i class="fa-solid fa-rotate-right"></i>
            </button>
          </div>
        </div>

        <!-- Date Range Row -->
        <div class="feed-date-row">
          <div class="date-range-group">
            <i class="fa-solid fa-calendar-days" style="color:#00f2fe;"></i>
            <span class="date-label">FROM DATE:</span>
            <input type="date" id="rel-date-from" class="feed-date-input">
            <i class="fa-solid fa-arrow-right" style="color:#64748b;"></i>
            <span class="date-label">TO DATE:</span>
            <input type="date" id="rel-date-to" class="feed-date-input">
          </div>
          <div class="date-presets">
            <button class="preset-tag active" id="preset-2months"><i class="fa-solid fa-calendar-check"></i> Last 2 Months</button>
            <button class="preset-tag" id="preset-thismonth"><i class="fa-solid fa-calendar"></i> This Month</button>
            <button class="preset-tag" id="preset-latest"><i class="fa-solid fa-clock"></i> Latest Date</button>
            <button class="preset-tag" id="preset-showall"><i class="fa-solid fa-rotate-left"></i> Show All</button>
          </div>
        </div>

        <!-- OS Pills Row -->
        <div class="category-pills" id="category-pills">
          <button class="pill active" data-filter="all"><i class="fa-brands fa-apple"></i> All</button>
          <button class="pill" data-filter="iOS"><i class="fa-solid fa-mobile-screen"></i> iOS</button>
          <button class="pill" data-filter="macOS"><i class="fa-solid fa-laptop"></i> macOS</button>
          <button class="pill" data-filter="iPadOS"><i class="fa-solid fa-tablet-screen-button"></i> iPadOS</button>
          <button class="pill" data-filter="visionOS"><i class="fa-solid fa-vr-cardboard"></i> visionOS</button>
          <button class="pill" data-filter="watchOS"><i class="fa-solid fa-clock"></i> watchOS</button>
          <button class="pill" data-filter="tvOS"><i class="fa-solid fa-tv"></i> tvOS</button>
          <button class="pill" data-filter="audioOS"><i class="fa-solid fa-headphones"></i> audioOS</button>
        </div>

        <!-- Single Date & Device Filter Row -->
        <div class="feed-single-date-row">
          <div class="single-date-left">
            <i class="fa-solid fa-calendar-days" style="color:#64748b; font-size:0.85rem;"></i>
            <span class="date-label">Single Release Date:</span>
            <select id="rel-date-select" class="feed-date-select">
              <option value="all">All Release Dates (0 updates)</option>
            </select>
          </div>
          <div class="single-date-right" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <button class="btn-realtime-check" id="btn-feed-realtime-check" title="Real-time check latest release date & results">
              <i class="fa-solid fa-bolt-lightning" style="color:#00f2fe;"></i> Real-Time Check
            </button>
            <button class="btn-multi-date" id="btn-multi-date">
              <i class="fa-solid fa-list-check"></i> Select Dates
            </button>
            <button class="btn-device-filter" id="btn-device-filter-quick">
              <i class="fa-solid fa-mobile-screen-button"></i> Filter Devices (<span id="selected-device-count">All</span>)
            </button>
          </div>
        </div>

        <!-- Real-Time Release Check Result Panel (hidden by default) -->
        <div class="realtime-check-panel hidden" id="realtime-check-panel">
          <div class="rt-panel-header">
            <div class="rt-panel-title">
              <i class="fa-solid fa-circle-dot pulse-dot" style="color:#00f2fe;"></i>
              <span>Real-Time Release Dates Result</span>
            </div>
            <div class="rt-panel-actions" style="display:flex; align-items:center;">
              <span class="rt-status-time" id="rt-status-time">Checked just now</span>
              <button class="btn-xs" id="btn-rt-panel-close"><i class="fa-solid fa-xmark"></i> Close</button>
            </div>
          </div>
          <div class="rt-panel-body">
            <div class="rt-info-card">
              <div class="rt-card-label"><i class="fa-solid fa-clock" style="color:#f59e0b;"></i> Last Release Date</div>
              <div class="rt-card-value" id="rt-last-release-date">Checking...</div>
            </div>
            <div class="rt-info-card">
              <div class="rt-card-label">
                <i class="fa-solid fa-calendar-days" style="color:#38bdf8;"></i> Release Dates Breakdown (<span id="rt-dates-count">0</span> dates)
              </div>
              <div class="rt-dates-grid" id="rt-dates-grid">
                <div class="hdr-empty-dates">Click Real-Time Check to view dates</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Multi-date panel (hidden by default) -->
        <div class="multi-date-panel hidden" id="multi-date-panel">
          <div class="multi-date-header">
            <span>Select Dates:</span>
            <div>
              <button class="btn-xs" id="btn-multi-select-all">All</button>
              <button class="btn-xs" id="btn-multi-clear-all">None</button>
              <button class="btn-xs btn-xs-apply" id="btn-multi-apply">Apply</button>
            </div>
          </div>
          <div class="multi-date-list" id="multi-date-list"></div>
        </div>

        <!-- Status Bar -->
        <div class="feed-status-bar">
          <span class="status-badge-teal" id="feed-status-badge">SHOWING ALL DATES</span>
          <span class="feed-status-text" id="feed-status-text">Loading releases...</span>
          <span id="last-checked" style="font-size:0.75rem; color:#475569; margin-left:auto;"></span>
        </div>

      </div><!-- /.feed-panel -->

      <!-- Releases Grid -->
      <div class="releases-grid" id="releases-grid">
        <div class="loading-spinner">
          <i class="fa-solid fa-spinner fa-spin"></i>
          <p>Loading latest firmware releases...</p>
        </div>
      </div>

    </div><!-- /#tab-releases -->

    <!-- TAB: GRAPHICAL ANALYTICS -->
    <div class="tab-pane" id="tab-analytics">
      <!-- Analytics Header & Filter Toolbar -->
      <div class="analytics-header-panel">
        <div class="analytics-title-group">
          <div class="analytics-header-icon">
            <i class="fa-solid fa-chart-line"></i>
          </div>
          <div>
            <h2 class="analytics-main-title">Graphical Analytics &amp; Insights</h2>
            <p class="analytics-subtitle">Real-time Apple IPSW &amp; OTA Release Distribution Metrics</p>
          </div>
        </div>
        <div class="analytics-controls">
          <div class="analytics-filter-item">
            <label><i class="fa-solid fa-calendar-days" style="color:#3370b3;"></i> Timeframe:</label>
            <select id="analytics-timeframe" class="analytics-select">
              <option value="30">Last 30 Days</option>
              <option value="60">Last 60 Days</option>
              <option value="90">Last 90 Days</option>
              <option value="all" selected>All Time</option>
            </select>
          </div>
          <div class="analytics-filter-item">
            <label><i class="fa-solid fa-filter" style="color:#a855f7;"></i> Source:</label>
            <select id="analytics-source" class="analytics-select">
              <option value="all" selected>All Channels</option>
              <option value="official">Official Only</option>
              <option value="beta">Beta Only</option>
            </select>
          </div>
          <button class="btn-feed-green" id="btn-refresh-charts" title="Refresh chart metrics">
            <i class="fa-solid fa-rotate-right"></i> Refresh
          </button>
          <button class="btn-feed-teal" id="btn-export-analytics-csv" title="Export Analytics metrics to CSV">
            <i class="fa-solid fa-file-csv"></i> Export CSV
          </button>
        </div>
      </div>

      <!-- KPI Stat Cards Row -->
      <div class="analytics-kpi-grid">
        <div class="kpi-card">
          <div class="kpi-card-header">
            <span class="kpi-title">Total Cataloged Releases</span>
            <div class="kpi-icon blue"><i class="fa-solid fa-boxes-stacked"></i></div>
          </div>
          <div class="kpi-value" id="kpi-total-releases">0</div>
          <div class="kpi-subtext" id="kpi-total-sub">Across all platforms</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header">
            <span class="kpi-title">Official Public Releases</span>
            <div class="kpi-icon green"><i class="fa-brands fa-apple"></i></div>
          </div>
          <div class="kpi-value" id="kpi-official-releases">0</div>
          <div class="kpi-subtext" id="kpi-official-sub">0% of total catalog</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header">
            <span class="kpi-title">Beta Testing Releases</span>
            <div class="kpi-icon orange"><i class="fa-solid fa-flask"></i></div>
          </div>
          <div class="kpi-value" id="kpi-beta-releases">0</div>
          <div class="kpi-subtext" id="kpi-beta-sub">0% of total catalog</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header">
            <span class="kpi-title">Top Operating System</span>
            <div class="kpi-icon purple"><i class="fa-solid fa-crown"></i></div>
          </div>
          <div class="kpi-value" id="kpi-top-os">—</div>
          <div class="kpi-subtext" id="kpi-top-os-sub">Highest build count</div>
        </div>
      </div>

      <!-- Charts Grid Row (2x2) -->
      <div class="analytics-grid-v2">
        <div class="chart-card-v2">
          <div class="chart-card-header">
            <h3><i class="fa-brands fa-apple" style="color:#3370b3;"></i> OS Platform Distribution</h3>
            <span class="chart-tag">Share %</span>
          </div>
          <div class="chart-container-wrap">
            <canvas id="chart-os" height="240"></canvas>
          </div>
        </div>

        <div class="chart-card-v2">
          <div class="chart-card-header">
            <h3><i class="fa-solid fa-flask" style="color:#a855f7;"></i> Release Source Split</h3>
            <span class="chart-tag">Official vs Beta</span>
          </div>
          <div class="chart-container-wrap">
            <canvas id="chart-source" height="240"></canvas>
          </div>
        </div>

        <div class="chart-card-v2">
          <div class="chart-card-header">
            <h3><i class="fa-solid fa-chart-simple" style="color:#30d158;"></i> Platform Build Counts</h3>
            <span class="chart-tag">Releases by OS</span>
          </div>
          <div class="chart-container-wrap">
            <canvas id="chart-os-bar" height="240"></canvas>
          </div>
        </div>

        <div class="chart-card-v2">
          <div class="chart-card-header">
            <h3><i class="fa-solid fa-calendar-days" style="color:#fb923c;"></i> Release Cadence &amp; Velocity</h3>
            <span class="chart-tag" id="timeline-chart-tag">Daily Stream</span>
          </div>
          <div class="chart-container-wrap">
            <canvas id="chart-timeline" height="240"></canvas>
          </div>
        </div>
      </div>

      <!-- Insights Summary Banner -->
      <div class="analytics-insights-panel">
        <div class="insights-panel-header">
          <i class="fa-solid fa-lightbulb" style="color:#f59e0b; font-size:1.2rem;"></i>
          <h3>Key Analytics Highlights &amp; Insights</h3>
        </div>
        <div class="insights-grid" id="analytics-insights-grid">
          <div class="insight-box">
            <i class="fa-solid fa-bullseye" style="color:#3370b3;"></i>
            <span id="insight-text-1">Loading statistics...</span>
          </div>
          <div class="insight-box">
            <i class="fa-solid fa-chart-line" style="color:#30d158;"></i>
            <span id="insight-text-2">Calculating release velocity...</span>
          </div>
          <div class="insight-box">
            <i class="fa-solid fa-clock-rotate-left" style="color:#a855f7;"></i>
            <span id="insight-text-3">Analyzing build frequency...</span>
          </div>
        </div>
      </div>
    </div>

    <!-- TAB: SETTINGS & GMAIL -->
    <div class="tab-pane" id="tab-settings">
      <div class="pane-header">
        <div class="pane-title">
          <h2><i class="fa-solid fa-gear" style="color:#00f2fe;"></i> Settings &amp; Gmail</h2>
          <p>Configure system preferences, credentials, and email notifications</p>
        </div>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem; max-width:1100px;">

        <!-- System Settings -->
        <div class="settings-card">
          <h3 style="font-family:'Outfit',sans-serif; margin-bottom:1.5rem; color:#fff; font-size:1.1rem;">
            <i class="fa-solid fa-sliders" style="color:#00f2fe; margin-right:0.5rem;"></i> System Settings
          </h3>

          <div class="form-group">
            <label>Download Directory</label>
            <input type="text" id="set-download-dir" class="form-control" placeholder="C:\Users\...\Downloads\IPSWs">
            <span class="form-text">Where IDM saves IPSW files</span>
          </div>
          <div class="form-group">
            <label>IDM Executable Path</label>
            <input type="text" id="set-idm-path" class="form-control" placeholder="C:\Program Files (x86)\Internet Download Manager\IDMan.exe">
          </div>
          <div class="form-group">
            <label>Auto-Check Interval (minutes)</label>
            <input type="number" id="set-auto-check" class="form-control" placeholder="1440" min="5">
            <span class="form-text">How often to check for new releases (default: 1440 = 24h)</span>
          </div>
          <div class="form-group" style="display:flex; align-items:center; gap:1rem;">
            <label style="margin:0;">Email Alerts</label>
            <input type="checkbox" id="set-email-alerts" style="width:18px;height:18px; accent-color:#00f2fe;">
          </div>

          <button class="btn btn-primary" id="btn-save-system-settings" style="margin-top:0.5rem;">
            <i class="fa-solid fa-floppy-disk"></i> Save Settings
          </button>
        </div>

        <!-- Admin Credentials -->
        <div class="settings-card">
          <h3 style="font-family:'Outfit',sans-serif; margin-bottom:1.5rem; color:#fff; font-size:1.1rem;">
            <i class="fa-solid fa-user-shield" style="color:#00f2fe; margin-right:0.5rem;"></i> Admin Credentials
          </h3>
          <div class="form-group">
            <label>New Username</label>
            <input type="text" id="set-username" class="form-control" placeholder="Leave blank to keep current">
          </div>
          <div class="form-group">
            <label>New Password</label>
            <input type="password" id="set-password" class="form-control" placeholder="Leave blank to keep current">
          </div>
          <button class="btn btn-primary" id="btn-save-credentials">
            <i class="fa-solid fa-key"></i> Update Credentials
          </button>
        </div>

        <!-- Gmail Accounts (full width) -->
        <div class="settings-card" style="grid-column: 1 / -1;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
            <h3 style="font-family:'Outfit',sans-serif; color:#fff; font-size:1.1rem; margin:0;">
              <i class="fa-brands fa-google" style="color:#00f2fe; margin-right:0.5rem;"></i> Gmail Notification Accounts
            </h3>
            <button class="btn btn-secondary btn-sm" id="btn-add-gmail">
              <i class="fa-solid fa-plus"></i> Add Account
            </button>
          </div>

          <div id="gmail-accounts-list" style="display:flex; flex-direction:column; gap:0.75rem; margin-bottom:1.5rem;">
            <div style="text-align:center; padding:1rem; color:#64748b; font-size:0.9rem;">Loading accounts...</div>
          </div>

          <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:0.25rem;">
            <button class="btn btn-primary" id="btn-save-gmail">
              <i class="fa-solid fa-floppy-disk"></i> Save Gmail Settings
            </button>
            <button class="btn btn-secondary" id="btn-test-email">
              <i class="fa-solid fa-paper-plane"></i> Send Test Email
            </button>
          </div>
        </div>

      </div>
    </div><!-- /#tab-settings -->

  </div><!-- /.main-container -->

  <!-- MODAL: Firmware Details / Batch Download -->
  <div class="modal hidden" id="modal-firmware">
    <div class="modal-backdrop" id="modal-backdrop"></div>
    <div class="modal-content" style="max-width:780px; width:min(92vw, 780px);">
      <div class="modal-header">
        <h3 id="modal-title" style="font-family:'Outfit',sans-serif; font-size:1.2rem;">Firmware Details</h3>
        <button class="modal-close" id="modal-close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div id="modal-body"></div>
    </div>
  </div>

  <!-- MODAL: Cache Server Exporter -->
  <div class="modal hidden" id="modal-cache-exporter">
    <div class="modal-backdrop" id="modal-cache-backdrop"></div>
    <div class="modal-content modal-lg" style="max-width:750px;">
      <div class="modal-header">
        <h3 style="font-family:'Outfit',sans-serif; font-size:1.2rem; color:#fff; display:flex; align-items:center; gap:0.5rem;">
          <i class="fa-solid fa-server" style="color:#a855f7;"></i> Cache Server IPSW Exporter
        </h3>
        <button class="modal-close" id="modal-cache-close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="modal-body" style="padding:1.5rem;">
        <p style="font-size:0.85rem; color:#94a3b8; margin-bottom:1.2rem;">
          Pre-download IPSW files to your local Cache Server for super-fast LAN speeds. Deduplication is automatically applied to prevent duplicate downloads.
        </p>

        <!-- Step 1: Select Release / Build -->
        <div class="form-group" style="margin-bottom:1.2rem;">
          <label style="color:#e2e8f0; font-weight:600; margin-bottom:0.4rem; display:block;">
            <i class="fa-solid fa-cube" style="color:#00f2fe;"></i> Select Firmware Release Build:
          </label>
          <select id="cache-rel-select" class="form-control" style="background:#0f172a; color:#fff; border-color:#334155;">
            <option value="">Loading builds...</option>
          </select>
        </div>

        <!-- Step 2: Device Model Filter & Search -->
        <div class="form-group" style="margin-bottom:1.2rem;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.4rem;">
            <label style="color:#e2e8f0; font-weight:600; margin:0;">
              <i class="fa-solid fa-mobile-screen-button" style="color:#38bdf8;"></i> Target Device Models:
            </label>
            <div style="display:flex; gap:0.4rem;">
              <button class="btn-xs" id="btn-cache-dev-all">Select All</button>
              <button class="btn-xs" id="btn-cache-dev-none">Clear All</button>
            </div>
          </div>
          <input type="text" id="cache-dev-search" class="form-control" placeholder="Search devices e.g. iPhone 15, iPad Air, Mac M2..." style="background:#0f172a; color:#fff; border-color:#334155; margin-bottom:0.6rem;">
          
          <div id="cache-device-list" class="device-checkbox-grid" style="max-height:220px; overflow-y:auto; background:rgba(15,23,42,0.6); border:1px solid #334155; border-radius:8px; padding:0.75rem; display:grid; grid-template-columns:repeat(auto-fill, minmax(210px, 1fr)); gap:0.5rem;">
            <div style="color:#64748b; font-size:0.85rem; grid-column:1/-1; text-align:center;">Loading devices list...</div>
          </div>
        </div>

        <!-- Options -->
        <div style="display:flex; align-items:center; gap:1rem; margin-bottom:1.5rem;">
          <label style="font-size:0.88rem; color:#cbd5e1; display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
            <input type="checkbox" id="cache-signed-only" checked style="width:16px; height:16px; accent-color:#00f2fe;">
            <span><i class="fa-solid fa-shield-halved" style="color:#22c55e;"></i> Only Signed Firmwares</span>
          </label>
        </div>

        <!-- Export Buttons Grid -->
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap:0.75rem;">
          <button class="btn btn-primary" id="btn-cache-download-txt" style="background:linear-gradient(135deg,#00f2fe,#4facfe); border:none; color:#000; font-weight:700;">
            <i class="fa-solid fa-file-lines"></i> Download .txt List
          </button>
          <button class="btn btn-secondary" id="btn-cache-download-sh" style="background:linear-gradient(135deg,#a855f7,#7c3aed); border:none; color:#fff; font-weight:700;">
            <i class="fa-solid fa-terminal"></i> Linux Script (.sh)
          </button>
          <button class="btn btn-secondary" id="btn-cache-download-ef2" style="background:#1e293b; color:#38bdf8; border:1px solid #38bdf8;">
            <i class="fa-solid fa-file-code"></i> IDM Queue (.ef2)
          </button>
          <button class="btn btn-secondary" id="btn-cache-send-idm" style="background:#064e3b; color:#34d399; border:1px solid #059669;">
            <i class="fa-solid fa-bolt"></i> Send to Local IDM
          </button>
        </div>

      </div>
    </div>
  </div>

  <!-- TOAST CONTAINER -->
  <div class="toast-container" id="toast-container"></div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <script src="js/app.js?v=<?= filemtime(__DIR__.'/js/app.js') ?>"></script>
</body>
</html>
