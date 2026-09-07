<?php
session_start();
date_default_timezone_set('Asia/Dhaka');
header('Content-Type: application/json');

if (empty($_SESSION['loggedIn'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$mode  = $input['mode'] ?? 'test'; // 'test' | 'notify'

$settingsPath = __DIR__ . '/../../data/settings.json';
$settings = file_exists($settingsPath) ? (json_decode(file_get_contents($settingsPath), true) ?? []) : [];

$accounts = $settings['gmailAccounts'] ?? [];
$sender   = null;
$recipients = [];

foreach ($accounts as $acc) {
    if (!empty($acc['enabled'])) {
        if (!empty($acc['isSender']) && !$sender) {
            $sender = $acc;
        }
        $recipients[] = $acc['email'] ?? '';
    }
}
$recipients = array_filter(array_unique($recipients));

if (!$sender || empty($sender['email']) || empty($sender['appPassword'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'No sender Gmail account configured. Set one account as Sender in Settings.']));
}
if (empty($recipients)) {
    http_response_code(400);
    exit(json_encode(['error' => 'No enabled Gmail recipients found.']));
}

// Helper to fetch firmware URLs for a release build (same source as export.php)
function getFirmwaresForBuild(string $buildid, string $relname): array {
    $buildsToQuery = [];
    if (str_ends_with($buildid, '_combined') || !$buildid) {
        if (preg_match('/\((.*?)\)/', $relname, $m)) {
            $versions = array_filter(array_map('trim', explode('/', $m[1])));
            $cacheFile = __DIR__ . '/../../data/releases_cache.json';
            if (file_exists($cacheFile)) {
                $allRels = json_decode(file_get_contents($cacheFile), true) ?? [];
                $targetOs = 'all';
                if (stripos($relname, 'macos') !== false)     $targetOs = 'macos';
                elseif (stripos($relname, 'ipados') !== false) $targetOs = 'ipados';
                elseif (stripos($relname, 'ios') !== false)    $targetOs = 'ios';
                elseif (stripos($relname, 'watchos') !== false)$targetOs = 'watchos';
                elseif (stripos($relname, 'tvos') !== false)   $targetOs = 'tvos';
                elseif (stripos($relname, 'visionos') !== false)$targetOs = 'visionos';

                foreach ($versions as $v) {
                    $cleanV = preg_replace('/^(iOS|iPadOS|macOS|watchOS|tvOS|visionOS)\s+/i', '', $v);
                    foreach ($allRels as $r) {
                        $rType = strtolower($r['type'] ?? '');
                        if (($targetOs === 'all' || $rType === $targetOs) && str_contains($r['name'], $cleanV) && !empty($r['buildid'])) {
                            $buildsToQuery[] = $r['buildid'];
                            break;
                        }
                    }
                }
            }
        }
    } else {
        $buildsToQuery = array_filter(array_map('trim', explode(',', $buildid)));
    }

    $deviceMap = [];
    $buildCacheFile = __DIR__ . '/../../data/build_urls_cache.json';

    foreach ($buildsToQuery as $bid) {
        if (file_exists($buildCacheFile)) {
            $buildMap = json_decode(file_get_contents($buildCacheFile), true);
            if (is_array($buildMap) && !empty($buildMap[$bid])) {
                foreach ($buildMap[$bid] as $fw) {
                    $url   = $fw['url'] ?? '';
                    $devId = $fw['identifier'] ?? ('dev_' . count($deviceMap));
                    if (!$url) continue;

                    $isIpsw = str_ends_with(strtolower($url), '.ipsw');
                    if (!isset($deviceMap[$devId])) {
                        $deviceMap[$devId] = [
                            'identifier' => $devId,
                            'url'        => $url,
                            'signed'     => $fw['signed'] ?? true,
                            'filesize'   => $fw['filesize'] ?? 0,
                        ];
                    } else {
                        $existingUrl    = $deviceMap[$devId]['url'] ?? '';
                        $existingIsIpsw = str_ends_with(strtolower($existingUrl), '.ipsw');
                        if (!$existingIsIpsw && $isIpsw) {
                            $deviceMap[$devId] = [
                                'identifier' => $devId,
                                'url'        => $url,
                                'signed'     => $fw['signed'] ?? true,
                                'filesize'   => $fw['filesize'] ?? 0,
                            ];
                        }
                    }
                }
            }
        }
    }

    $out = array_values($deviceMap);
    if (!empty($out)) return $out;

    if (!empty($buildsToQuery)) {
        $firstBid = $buildsToQuery[0];
        $version = '';
        if (preg_match('/(\d+(?:\.\d+)+)\s*\(/', $relname, $m)) {
            $version = $m[1];
        }
        
        $ch = curl_init();
        $url = $version ? "https://api.ipsw.me/v4/ipsw/" . urlencode($version) . "/" . urlencode($firstBid) : "https://api.ipsw.me/v4/releases";
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_USERAGENT      => 'IPSW-Master/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        
        $items = json_decode($body, true);
        if (is_array($items)) {
            $list = isset($items[0]) && isset($items[0]['url']) ? $items : ($items['firmwares'] ?? []);
            $out = [];
            foreach ($list as $fw) {
                $u = $fw['url'] ?? '';
                if ($u) {
                    $out[] = [
                        'identifier' => $fw['identifier'] ?? 'Device',
                        'url'        => $u,
                        'signed'     => $fw['signed'] ?? true,
                        'filesize'   => $fw['filesize'] ?? 0,
                    ];
                }
            }
            return $out;
        }
    }
    return [];
}

// ── Dynamic Base URL Calculation ──────────────────────────────────────────────
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script   = $_SERVER['SCRIPT_NAME'] ?? '/api/email/send.php';
$basePath = rtrim(preg_replace('#/api/email/send\.php$#i', '', $script), '/');
$siteBaseUrl = $protocol . '://' . $host . $basePath;

// ── Build email content ──────────────────────────────────────────────────────
if ($mode === 'test') {
    $subject  = 'IPSW Master — Test Email Notification Report';
    $dateStr  = date('j M Y (H:i)');
    $body     = "This is a test email report from IPSW Master.\nYour Gmail notification system is configured and working!\n\n— Vision Technologies Limited";
    $dashboardUrl = $siteBaseUrl . '/index.php';
    $exportUrl    = $siteBaseUrl . '/api/releases/export.php?format=txt';
    
    $htmlBody = '
    <div style="background:#ffffff; font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif; color:#1e293b; max-width:650px; margin:0 auto; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
        <!-- Header Banner -->
        <div style="background:#111827; padding:24px 20px; text-align:center; border-bottom:4px solid #ea580c;">
            <img src="https://visiontech.com.bd/logo/vision-logo-latest.svg" alt="Vision Technologies Limited" style="height:46px; width:auto; display:block; margin:0 auto 10px;">
            <div style="color:#94a3b8; font-size:13px; font-weight:600;">
                Dual-Source IPSW Release Report (<a href="https://ipsw.me" style="color:#38bdf8; text-decoration:none;">ipsw.me</a> &amp; <a href="https://ipsw.dev" style="color:#38bdf8; text-decoration:none;">ipsw.dev</a>)
            </div>
        </div>

        <div style="padding:24px;">
            <p style="font-size:15px; margin:0 0 14px;">Dear Sir,</p>
            <p style="font-size:14px; color:#334155; line-height:1.6; margin:0 0 16px;">
                Please find below the unified IPSW Firmware Release Notification Report from 
                <a href="https://ipsw.me" style="color:#2563eb; font-weight:bold; text-decoration:none;">ipsw.me</a> and 
                <a href="https://ipsw.dev" style="color:#2563eb; font-weight:bold; text-decoration:none;">ipsw.dev</a> 
                for the period of <b>' . $dateStr . '</b>, providing a dual-source overview of official &amp; developer firmware releases.
            </p>

            <!-- Status Bar -->
            <div style="background:#0b1329; padding:12px 18px; border-radius:8px; color:#ffffff; font-size:13px; font-weight:bold; margin-bottom:24px;">
                🍏 <a href="https://ipsw.me" style="color:#00f2fe; text-decoration:none;">IPSW.ME</a> Official: <span style="color:#ffffff;">1 builds</span> &nbsp;⚡&nbsp; 
                <a href="https://ipsw.dev" style="color:#c084fc; text-decoration:none;">IPSW.DEV</a> Developer: <span style="color:#ffffff;">0 builds</span>
            </div>

            <!-- SUMMARY OVERVIEW -->
            <div style="font-size:13px; font-weight:800; color:#1e293b; letter-spacing:0.05em; text-transform:uppercase; border-bottom:2px solid #ea580c; padding-bottom:6px; margin-bottom:14px;">
                SUMMARY OVERVIEW
            </div>

            <table style="width:100%; border-collapse:separate; border-spacing:10px; margin-bottom:24px; text-align:center;">
                <tr>
                    <td style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
                        <div style="font-size:28px; font-weight:800; color:#0f172a; line-height:1;">1</div>
                        <div style="font-size:10px; font-weight:700; color:#64748b; margin-top:6px; text-transform:uppercase;">TOTAL RELEASES</div>
                    </td>
                    <td style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
                        <div style="font-size:28px; font-weight:800; color:#16a34a; line-height:1;">1</div>
                        <div style="font-size:10px; font-weight:700; color:#16a34a; margin-top:6px; text-transform:uppercase;">SIGNED (INSTALLABLE)</div>
                    </td>
                    <td style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
                        <div style="font-size:28px; font-weight:800; color:#0284c7; line-height:1;">1</div>
                        <div style="font-size:10px; font-weight:700; color:#0284c7; margin-top:6px; text-transform:uppercase;">IDM BATCH READY</div>
                    </td>
                </tr>
            </table>

            <!-- CATEGORY BREAKDOWN -->
            <div style="font-size:13px; font-weight:800; color:#1e293b; letter-spacing:0.05em; text-transform:uppercase; border-bottom:2px solid #ea580c; padding-bottom:6px; margin-bottom:14px;">
                CATEGORY BREAKDOWN
            </div>

            <table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:24px; border:1px solid #e2e8f0;">
                <thead>
                    <tr style="background:#1f2937; color:#ffffff; text-align:left;">
                        <th style="padding:10px 14px;">Category</th>
                        <th style="padding:10px 14px; text-align:center;">Count</th>
                        <th style="padding:10px 14px; text-align:right;">% of Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 14px; color:#1e293b;">🍏 iOS Firmware Updates</td>
                        <td style="padding:10px 14px; text-align:center; font-weight:bold;">1</td>
                        <td style="padding:10px 14px; text-align:right; color:#64748b;">100%</td>
                    </tr>
                </tbody>
            </table>

            <!-- Callout Box -->
            <div style="border:1px dashed #cbd5e1; background:#f8fafc; border-radius:10px; padding:22px 16px; text-align:center;">
                <div style="font-size:14px; font-weight:700; color:#1e293b; margin-bottom:14px;">
                    1-Click Unified Dual-Source IDM Resource List (.txt)
                </div>
                <a href="' . htmlspecialchars($dashboardUrl) . '" target="_blank" style="display:inline-block; background:linear-gradient(135deg,#e04f1e,#ea580c); color:#ffffff; font-weight:bold; font-size:14px; padding:12px 24px; border-radius:8px; text-decoration:none; box-shadow:0 4px 12px rgba(224,79,30,0.3);">
                    📦 Open IPSW Master Dashboard
                </a>
            </div>
        </div>
        
        <div style="background:#f1f5f9; padding:14px; text-align:center; font-size:11px; color:#64748b; border-top:1px solid #e2e8f0;">
            Vision Technologies Limited · IPSW Master Automated Notification System
        </div>
    </div>';
} else {
    $releases = $input['releases'] ?? [];
    if (empty($releases) && !empty($input['release'])) {
        $releases = [$input['release']];
    }
    
    $totalReleases = count($releases);
    $firstRel      = $releases[0] ?? [];
    $relName       = $firstRel['name'] ?? 'Apple Firmware';
    $buildid       = $firstRel['buildid'] ?? '';
    if (!$buildid && preg_match('/\(([A-Za-z0-9]+)\)\s*$/', $relName, $m)) {
        $buildid = $m[1];
    }
    if (!$buildid && $relName) {
        $cacheFile = __DIR__ . '/../../data/releases_cache.json';
        if (file_exists($cacheFile)) {
            $allRels = json_decode(file_get_contents($cacheFile), true) ?? [];
            foreach ($allRels as $r) {
                if (trim($r['name'] ?? '') === $relName && !empty($r['buildid'])) {
                    $buildid = $r['buildid'];
                    break;
                }
            }
        }
        if (!$buildid) {
            $cleanVer = '';
            if (preg_match('/(?:iOS|iPadOS|macOS|watchOS|tvOS|visionOS)\s+([0-9\.]+)/i', $relName, $m)) {
                $cleanVer = $m[1];
            }
            if ($cleanVer) {
                $buildCacheFile = __DIR__ . '/../../data/build_urls_cache.json';
                if (file_exists($buildCacheFile)) {
                    $buildMap = json_decode(file_get_contents($buildCacheFile), true) ?? [];
                    foreach ($buildMap as $bid => $fws) {
                        foreach ($fws as $fw) {
                            $url = $fw['url'] ?? '';
                            if (str_contains($url, "_{$cleanVer}_") || str_contains($url, "/UniversalMac_{$cleanVer}_") || str_contains($url, "_{$cleanVer}")) {
                                $buildid = $bid;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }
    
    $dateStr = !empty($firstRel['date']) ? date('j M Y (H:i)', strtotime($firstRel['date'])) : date('j M Y (H:i)');
    
    // Categorize releases
    $officialCount = 0;
    $betaCount     = 0;
    $iosCount      = 0;
    $macCount      = 0;
    $ipadCount     = 0;
    $otherCount    = 0;
    $signedCount   = 0;
    
    foreach ($releases as $r) {
        $src = strtolower($r['source'] ?? 'official');
        if ($src === 'beta') $betaCount++; else $officialCount++;
        
        $n = strtolower($r['name'] ?? '');
        if (str_contains($n, 'iphone') || str_contains($n, 'ios')) $iosCount++;
        elseif (str_contains($n, 'mac')) $macCount++;
        elseif (str_contains($n, 'ipad')) $ipadCount++;
        else $otherCount++;
        
        $signedCount++;
    }
    
    $denom = max(1, $totalReleases);
    $iosPct   = round(($iosCount / $denom) * 100);
    $macPct   = round(($macCount / $denom) * 100);
    $ipadPct  = round(($ipadCount / $denom) * 100);
    $otherPct = round(($otherCount / $denom) * 100);
    
    $firmwares = $buildid ? getFirmwaresForBuild($buildid, $relName) : [];
    
    $subject = "IPSW Release Report: {$relName} [{$totalReleases} build(s)]";
    
    $plainLines = [
        "Dual-Source IPSW Release Report",
        "Release: {$relName}",
        "Date: {$dateStr}",
        "Official: {$officialCount} builds | Beta: {$betaCount} builds",
        "Total: {$totalReleases} releases",
    ];
    $body = implode("\n", $plainLines);

    $urlRowsHtml = '';
    if (!empty($firmwares)) {
        foreach ($firmwares as $fw) {
            $u = htmlspecialchars($fw['url']);
            $id = htmlspecialchars($fw['identifier']);
            $urlRowsHtml .= "<tr style='border-bottom:1px solid #f1f5f9;'><td style='padding:8px 12px; color:#1e293b; font-weight:600;'>{$id}</td><td style='padding:8px 12px;'><a href='{$u}' style='color:#ea580c; font-weight:bold; font-family:monospace; font-size:11px; word-break:break-all;'>{$u}</a></td></tr>";
        }
    }
    
    $dashboardUrl = $siteBaseUrl . '/index.php?buildid=' . urlencode($buildid);
    $exportUrl    = $siteBaseUrl . '/api/releases/export.php?buildid=' . urlencode($buildid) . '&name=' . urlencode($relName) . '&format=txt';

    $htmlBody = '
    <div style="background:#ffffff; font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif; color:#1e293b; max-width:650px; margin:0 auto; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
        <!-- Header Banner -->
        <div style="background:#111827; padding:24px 20px; text-align:center; border-bottom:4px solid #ea580c;">
            <img src="https://visiontech.com.bd/logo/vision-logo-latest.svg" alt="Vision Technologies Limited" style="height:46px; width:auto; display:block; margin:0 auto 10px;">
            <div style="color:#94a3b8; font-size:13px; font-weight:600;">
                Dual-Source IPSW Release Report (<a href="https://ipsw.me" style="color:#38bdf8; text-decoration:none;">ipsw.me</a> &amp; <a href="https://ipsw.dev" style="color:#38bdf8; text-decoration:none;">ipsw.dev</a>)
            </div>
        </div>

        <div style="padding:24px;">
            <p style="font-size:15px; margin:0 0 14px;">Dear Sir,</p>
            <p style="font-size:14px; color:#334155; line-height:1.6; margin:0 0 16px;">
                Please find below the unified IPSW Firmware Release Notification Report from 
                <a href="https://ipsw.me" style="color:#2563eb; font-weight:bold; text-decoration:none;">ipsw.me</a> and 
                <a href="https://ipsw.dev" style="color:#2563eb; font-weight:bold; text-decoration:none;">ipsw.dev</a> 
                for the period of <b>' . $dateStr . '</b>, providing a dual-source overview of official &amp; developer firmware releases.
            </p>

            <!-- Status Bar -->
            <div style="background:#0b1329; padding:12px 18px; border-radius:8px; color:#ffffff; font-size:13px; font-weight:bold; margin-bottom:24px;">
                🍏 <a href="https://ipsw.me" style="color:#00f2fe; text-decoration:none;">IPSW.ME</a> Official: <span style="color:#ffffff;">' . $officialCount . ' builds</span> &nbsp;⚡&nbsp; 
                <a href="https://ipsw.dev" style="color:#c084fc; text-decoration:none;">IPSW.DEV</a> Developer: <span style="color:#ffffff;">' . $betaCount . ' builds</span>
            </div>

            <!-- SUMMARY OVERVIEW -->
            <div style="font-size:13px; font-weight:800; color:#1e293b; letter-spacing:0.05em; text-transform:uppercase; border-bottom:2px solid #ea580c; padding-bottom:6px; margin-bottom:14px;">
                SUMMARY OVERVIEW
            </div>

            <table style="width:100%; border-collapse:separate; border-spacing:10px; margin-bottom:24px; text-align:center;">
                <tr>
                    <td style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
                        <div style="font-size:28px; font-weight:800; color:#0f172a; line-height:1;">' . $totalReleases . '</div>
                        <div style="font-size:10px; font-weight:700; color:#64748b; margin-top:6px; text-transform:uppercase;">TOTAL RELEASES</div>
                    </td>
                    <td style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
                        <div style="font-size:28px; font-weight:800; color:#16a34a; line-height:1;">' . $signedCount . '</div>
                        <div style="font-size:10px; font-weight:700; color:#16a34a; margin-top:6px; text-transform:uppercase;">SIGNED (INSTALLABLE)</div>
                    </td>
                    <td style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
                        <div style="font-size:28px; font-weight:800; color:#0284c7; line-height:1;">' . $totalReleases . '</div>
                        <div style="font-size:10px; font-weight:700; color:#0284c7; margin-top:6px; text-transform:uppercase;">IDM BATCH READY</div>
                    </td>
                </tr>
            </table>

            <!-- CATEGORY BREAKDOWN -->
            <div style="font-size:13px; font-weight:800; color:#1e293b; letter-spacing:0.05em; text-transform:uppercase; border-bottom:2px solid #ea580c; padding-bottom:6px; margin-bottom:14px;">
                CATEGORY BREAKDOWN
            </div>

            <table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:24px; border:1px solid #e2e8f0;">
                <thead>
                    <tr style="background:#1f2937; color:#ffffff; text-align:left;">
                        <th style="padding:10px 14px;">Category</th>
                        <th style="padding:10px 14px; text-align:center;">Count</th>
                        <th style="padding:10px 14px; text-align:right;">% of Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 14px; color:#1e293b;">🍏 iOS Firmware Updates</td>
                        <td style="padding:10px 14px; text-align:center; font-weight:bold;">' . $iosCount . '</td>
                        <td style="padding:10px 14px; text-align:right; color:#64748b;">' . $iosPct . '%</td>
                    </tr>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 14px; color:#1e293b;">💻 macOS Firmware Updates</td>
                        <td style="padding:10px 14px; text-align:center; font-weight:bold;">' . $macCount . '</td>
                        <td style="padding:10px 14px; text-align:right; color:#64748b;">' . $macPct . '%</td>
                    </tr>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 14px; color:#1e293b;">📱 iPadOS Firmware Updates</td>
                        <td style="padding:10px 14px; text-align:center; font-weight:bold;">' . $ipadCount . '</td>
                        <td style="padding:10px 14px; text-align:right; color:#64748b;">' . $ipadPct . '%</td>
                    </tr>
                    <tr>
                        <td style="padding:10px 14px; color:#1e293b;">⌚ visionOS / watchOS / Developer Betas</td>
                        <td style="padding:10px 14px; text-align:center; font-weight:bold;">' . $otherCount . '</td>
                        <td style="padding:10px 14px; text-align:right; color:#64748b;">' . $otherPct . '%</td>
                    </tr>
                </tbody>
            </table>

            ' . (!empty($urlRowsHtml) ? '
            <!-- DIRECT URLS TABLE -->
            <div style="font-size:13px; font-weight:800; color:#1e293b; letter-spacing:0.05em; text-transform:uppercase; border-bottom:2px solid #ea580c; padding-bottom:6px; margin-bottom:14px;">
                DIRECT IPSW DOWNLOAD LINKS
            </div>
            <table style="width:100%; border-collapse:collapse; font-size:12px; margin-bottom:24px; border:1px solid #e2e8f0;">
                <thead>
                    <tr style="background:#0f172a; color:#ffffff; text-align:left;">
                        <th style="padding:8px 12px;">Device</th>
                        <th style="padding:8px 12px;">Direct Download URL</th>
                    </tr>
                </thead>
                <tbody>' . $urlRowsHtml . '</tbody>
            </table>' : '') . '

            <!-- Interactive IDM Checklist Callout Box -->
            <div style="border:1px dashed #00f2fe; background:#0f172a; border-radius:10px; padding:22px 16px; text-align:center; margin-top:20px;">
                <div style="font-size:15px; font-weight:800; color:#ffffff; margin-bottom:12px;">
                    ⚡ Interactive URL Checklist &amp; 1-Click IDM Batch Exporter
                </div>
                <div style="margin-bottom:16px;">
                    <a href="' . htmlspecialchars($dashboardUrl) . '" target="_blank" style="display:inline-block; background:linear-gradient(135deg,#00f2fe,#0284c7); color:#090d16; font-weight:800; font-size:15px; padding:12px 24px; border-radius:8px; text-decoration:none; box-shadow:0 4px 14px rgba(0,242,254,0.3);">
                        ⚡ Open Interactive IDM Checklist (⚡ Send Checked to IDM)
                    </a>
                </div>
                <div>
                    <a href="' . htmlspecialchars($exportUrl) . '" target="_blank" style="display:inline-block; background:linear-gradient(135deg,#ea580c,#c2410c); color:#ffffff; font-weight:700; font-size:13px; padding:8px 16px; border-radius:6px; text-decoration:none;">
                        📦 Download Plain .txt List File
                    </a>
                </div>
                <div style="font-size:12px; color:#94a3b8; margin-top:12px; line-height:1.5;">
                    Click <b>⚡ Send Checked to IDM</b> or <b>📋 Copy Checked URLs</b> directly inside the interactive checklist modal.
                </div>
            </div>
        </div>
        
        <div style="background:#f1f5f9; padding:14px; text-align:center; font-size:11px; color:#64748b; border-top:1px solid #e2e8f0;">
            Vision Technologies Limited · IPSW Master Automated Notification System
        </div>
    </div>';
}

// ── Robust Multi-Port & Multi-Transport Gmail Send Engine ───────────────────
function sendGmailEmail(string $user, string $pass, string $from, array $to, string $subject, string $text, string $html): array {
    $cleanPass = str_replace(' ', '', trim($pass));
    $user      = trim($user);
    $from      = trim($from);

    // 1. Try Port 465 SSL first
    $res465 = smtpSendSingle('smtp.gmail.com', 465, 'ssl', $user, $cleanPass, $from, $to, $subject, $text, $html);
    if ($res465['ok']) return $res465;

    // 2. Fallback to Port 587 TLS/STARTTLS second
    $res587 = smtpSendSingle('smtp.gmail.com', 587, 'tls', $user, $cleanPass, $from, $to, $subject, $text, $html);
    if ($res587['ok']) return $res587;

    // 3. Fallback to server native mail() third (if online host blocks outbound SMTP)
    $mailRes = nativeMailSend($from, $to, $subject, $text, $html);
    if ($mailRes['ok']) return $mailRes;

    $err465 = $res465['error'] ?? '';
    $err587 = $res587['error'] ?? '';

    $msg = "Gmail Send Failed on Server:\n";
    if (str_contains($err465, '535') || str_contains($err587, '535') || stripos($err465, 'Auth failed') !== false) {
        $msg .= "• Google Authentication Rejected (535 Bad Credentials).\n  Please ensure you are using a 16-character Google App Password (not your main account password).\n  Generate App Password in Google Account -> Security -> 2-Step Verification -> App Passwords.";
    } elseif (str_contains($err465, 'Connect failed') && str_contains($err587, 'Connect failed')) {
        $msg .= "• Outbound SMTP Blocked by Server Firewall.\n  Your online host (cPanel/VPS) blocks outbound port 465 & 587.\n  Contact hosting support to unblock outbound TCP connections to smtp.gmail.com:465/587.";
    } else {
        $msg .= "• SSL 465: {$err465}\n• TLS 587: {$err587}";
    }

    return ['ok' => false, 'error' => $msg];
}

function smtpSendSingle(string $host, int $port, string $scheme, string $user, string $pass, string $from, array $to, string $subject, string $text, string $html): array {
    $errno = 0; $errstr = '';
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ]);

    $remote = ($scheme === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $sock = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return ['ok' => false, 'error' => "Connect failed on port {$port}: {$errstr} ({$errno})"];

    stream_set_timeout($sock, 12);

    $read = function() use ($sock) {
        $response = '';
        while ($line = fgets($sock, 512)) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    };

    $send = function(string $cmd) use ($sock, $read) {
        fwrite($sock, $cmd . "\r\n");
        return $read();
    };

    $banner = $read();
    if (substr($banner, 0, 3) !== '220') {
        fclose($sock);
        return ['ok' => false, 'error' => "Invalid Banner: " . trim($banner)];
    }

    $ehlo = $send('EHLO ' . (gethostname() ?: 'localhost'));
    if (substr($ehlo, 0, 3) !== '250') {
        fclose($sock);
        return ['ok' => false, 'error' => "EHLO failed: " . trim($ehlo)];
    }

    if ($scheme === 'tls') {
        $tlsRes = $send('STARTTLS');
        if (substr($tlsRes, 0, 3) !== '220') {
            fclose($sock);
            return ['ok' => false, 'error' => "STARTTLS failed: " . trim($tlsRes)];
        }
        $cryptoRes = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if (!$cryptoRes) {
            fclose($sock);
            return ['ok' => false, 'error' => "TLS encryption handshake failed"];
        }
        $ehlo2 = $send('EHLO ' . (gethostname() ?: 'localhost'));
    }

    $r = $send('AUTH LOGIN');
    if (substr($r, 0, 3) !== '334') {
        fclose($sock);
        return ['ok' => false, 'error' => "AUTH LOGIN failed: " . trim($r)];
    }

    $r = $send(base64_encode($user));
    if (substr($r, 0, 3) !== '334') {
        fclose($sock);
        return ['ok' => false, 'error' => "Username rejected: " . trim($r)];
    }

    $r = $send(base64_encode($pass));
    if (substr($r, 0, 3) !== '235') {
        fclose($sock);
        return ['ok' => false, 'error' => "Auth failed (535 Bad Credentials): " . trim($r)];
    }

    $r = $send("MAIL FROM:<{$from}>");
    if (substr($r, 0, 3) !== '250') {
        fclose($sock);
        return ['ok' => false, 'error' => "MAIL FROM failed: " . trim($r)];
    }

    foreach ($to as $addr) { 
        $r = $send("RCPT TO:<{$addr}>"); 
        if (substr($r, 0, 3) !== '250' && substr($r, 0, 3) !== '251') {
            fclose($sock);
            return ['ok' => false, 'error' => "RCPT TO <{$addr}> failed: " . trim($r)];
        }
    }

    $r = $send('DATA');
    if (substr($r, 0, 3) !== '354') {
        fclose($sock);
        return ['ok' => false, 'error' => "DATA initiation failed: " . trim($r)];
    }

    $boundary = md5(uniqid());
    $toHeader = implode(', ', $to);
    $msg  = "From: IPSW Master <{$from}>\r\n";
    $msg .= "Reply-To: {$from}\r\n";
    $msg .= "To: {$toHeader}\r\n";
    $msg .= "Subject: {$subject}\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
    $msg .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n";
    $msg .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n";
    $msg .= "--{$boundary}--\r\n";

    fwrite($sock, $msg . "\r\n.\r\n");
    $r = $read();
    $send('QUIT');
    fclose($sock);

    if (substr($r, 0, 3) !== '250') {
        return ['ok' => false, 'error' => "Send failed: " . trim($r)];
    }
    return ['ok' => true];
}

function nativeMailSend(string $from, array $to, string $subject, string $text, string $html): array {
    if (!function_exists('mail')) return ['ok' => false, 'error' => 'mail() disabled'];

    $boundary = md5(uniqid());
    $toHeader = implode(', ', $to);

    $headers  = "From: IPSW Master <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $body  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n";
    $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n";
    $body .= "--{$boundary}--\r\n";

    $ok = @mail($toHeader, $subject, $body, $headers, "-f {$from}");
    if ($ok) return ['ok' => true];

    return ['ok' => false, 'error' => 'Native mail() function failed'];
}

$result = sendGmailEmail(
    $sender['email'], $sender['appPassword'],
    $sender['email'],
    array_values($recipients),
    $subject, $body, $htmlBody
);

if ($result['ok']) {
    echo json_encode(['success' => true, 'sent_to' => array_values($recipients)]);
} else {
    http_response_code(500);
    echo json_encode(['error' => $result['error']]);
}
