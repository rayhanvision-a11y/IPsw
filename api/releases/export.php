<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['loggedIn']) && empty($_GET['buildid']) && empty($_GET['name'])) {
    http_response_code(401);
    exit('Unauthorized');
}

@ini_set('memory_limit', '512M');
@set_time_limit(120);

$buildid    = trim($_GET['buildid'] ?? '');
$os         = strtolower(trim($_GET['os'] ?? 'all'));
$relname    = trim($_GET['name'] ?? '');
$devicesArg = trim($_GET['devices'] ?? 'all');
$format     = strtolower(trim($_GET['format'] ?? 'txt'));
$signedOnly = !empty($_GET['signed_only']);

// Auto-resolve empty build ID
if (!$buildid && $relname) {
    $cacheFile = __DIR__ . '/../../data/releases_cache.json';
    if (file_exists($cacheFile)) {
        $allRels = json_decode(file_get_contents($cacheFile), true) ?? [];
        foreach ($allRels as $r) {
            if (trim($r['name'] ?? '') === $relname && !empty($r['buildid'])) {
                $buildid = $r['buildid'];
                break;
            }
        }
    }
    if (!$buildid) {
        $cleanVer = '';
        if (preg_match('/(?:iOS|iPadOS|macOS|watchOS|tvOS|visionOS)\s+([0-9\.]+)/i', $relname, $m)) {
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

if (!$buildid) { http_response_code(400); exit('buildid parameter is required'); }

// Selected device identifiers array (if specific devices were picked)
$selectedDevices = [];
if ($devicesArg !== 'all' && !empty($devicesArg)) {
    $selectedDevices = array_filter(array_map('trim', explode(',', $devicesArg)));
}

// ── cURL helpers ─────────────────────────────────────────────────────────────
if (!function_exists('curlOpts')) {
    function curlOpts(int $timeout = 8): array {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'IPSW-Master/1.0 (PHP)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];
    }
}

if (!function_exists('fetchJson')) {
    function fetchJson(string $url, int $timeout = 8): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, curlOpts($timeout));
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$body || $code < 200 || $code >= 300) return null;
        $d = json_decode($body, true);
        return is_array($d) ? $d : null;
    }
}

// Fetch many URLs in parallel, return [key => array|null]
if (!function_exists('fetchJsonMulti')) {
    function fetchJsonMulti(array $urls, int $timeout = 10): array {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, curlOpts($timeout));
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 1); } while ($running > 0);
        $results = [];
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($body && $code >= 200 && $code < 300) {
                $d = json_decode($body, true);
                $results[$key] = is_array($d) ? $d : null;
            } else {
                $results[$key] = null;
            }
        }
        curl_multi_close($mh);
        return $results;
    }
}

// ── OS prefix map ─────────────────────────────────────────────────────────────
$osPrefixes = [
    'ios'      => ['iPhone', 'iPod'],
    'ipados'   => ['iPad'],
    'macos'    => ['Mac', 'VirtualMac'],
    'watchos'  => ['Watch'],
    'tvos'     => ['AppleTV'],
    'audioos'  => ['AudioAccessory', 'HomePod'],
    'visionos' => ['RealityDevice'],
    'all'      => [],
];
$prefixes = $osPrefixes[$os] ?? [];

// ── Smart OS Auto-Correction ─────────────────────────────────────────────────
// If user clicks e.g. iPhone .txt on a macOS release card, auto-correct $os to match $relname
$relLower = strtolower($relname);
if ($os !== 'all') {
    if (str_contains($relLower, 'macos') && $os !== 'macos') {
        $os = 'macos';
        $prefixes = $osPrefixes['macos'];
    } elseif (str_contains($relLower, 'watchos') && $os !== 'watchos') {
        $os = 'watchos';
        $prefixes = $osPrefixes['watchos'];
    } elseif (str_contains($relLower, 'ipados') && $os !== 'ipados') {
        $os = 'ipados';
        $prefixes = $osPrefixes['ipados'];
    } elseif (str_contains($relLower, 'ios') && $os !== 'ios') {
        $os = 'ios';
        $prefixes = $osPrefixes['ios'];
    }
}

if (!function_exists('matchesOs')) {
    function matchesOs(string $id, array $prefixes, array $selectedDevices = []): bool {
        if (!empty($selectedDevices)) {
            return in_array($id, $selectedDevices, true);
        }
        if (empty($prefixes)) return true;
        foreach ($prefixes as $p) {
            if (stripos($id, $p) === 0) return true;
        }
        return false;
    }
}

// ── Normalise firmware list ───────────────────────────────────────────────────
if (!function_exists('normaliseFirmwares')) {
    function normaliseFirmwares(array $data): array {
        if (isset($data[0]) && is_array($data[0]) && isset($data[0]['url'])) {
            return $data;
        }
        if (isset($data['firmwares']) && is_array($data['firmwares'])) {
            return $data['firmwares'];
        }
        $out = [];
        foreach ($data as $key => $val) {
            if (is_string($key) && is_array($val) && isset($val['url'])) {
                $val['identifier'] = $key;
                $out[] = $val;
            }
        }
        return $out;
    }
}

// ── Extract version from release name: "iOS 18.5 (22F76)" → "18.5" ──────────
$version = '';
if (preg_match('/(\d+(?:\.\d+)+)\s*\(/', $relname, $m)) {
    $version = $m[1];
}

// Parse all build IDs to query
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

$rawFirmwares = [];
$buildCacheFile = __DIR__ . '/../../data/build_urls_cache.json';

foreach ($buildsToQuery as $bid) {
    $bidFirmwares = [];
    if (file_exists($buildCacheFile)) {
        $buildMap = json_decode(file_get_contents($buildCacheFile), true);
        if (is_array($buildMap) && !empty($buildMap[$bid])) {
            foreach ($buildMap[$bid] as $fw) {
                $id  = $fw['identifier'] ?? '';
                $url = $fw['url'] ?? '';
                if ($url && matchesOs($id, $prefixes, $selectedDevices)) {
                    if ($signedOnly && isset($fw['signed']) && !$fw['signed']) continue;
                    $bidFirmwares[] = $fw;
                }
            }
            if (empty($bidFirmwares)) {
                foreach ($buildMap[$bid] as $fw) {
                    $url = $fw['url'] ?? '';
                    if ($url) {
                        if ($signedOnly && isset($fw['signed']) && !$fw['signed']) continue;
                        $bidFirmwares[] = $fw;
                    }
                }
            }
        }
    }
    $rawFirmwares = array_merge($rawFirmwares, $bidFirmwares);
}

// ── Strategy 1.5: Direct Beta IPSW lookup from beta.ipswdl.com ───────────────
$hasOnlyZips = !empty($rawFirmwares) && empty(array_filter($rawFirmwares, fn($f) => str_ends_with(strtolower($f['url'] ?? ''), '.ipsw')));

if (empty($rawFirmwares) || $hasOnlyZips) {
    $devicesToQuery = !empty($selectedDevices) ? $selectedDevices : [];
    if (empty($devicesToQuery) && file_exists(__DIR__ . '/../../data/devices_cache.json')) {
        $devCache = json_decode(file_get_contents(__DIR__ . '/../../data/devices_cache.json'), true);
        if (is_array($devCache)) {
            foreach ($devCache as $d) {
                $id = is_array($d) ? ($d['identifier'] ?? '') : (string)$d;
                if ($id && matchesOs($id, $prefixes, [])) {
                    $devicesToQuery[] = $id;
                }
            }
        }
    }

    if (!empty($devicesToQuery)) {
        // Query beta.ipswdl.com in parallel
        $mh = curl_multi_init();
        $handles = [];
        foreach (array_slice($devicesToQuery, 0, 80) as $devId) {
            $ch = curl_init('https://beta.ipswdl.com/firmware/' . urlencode($devId) . '/' . urlencode($buildid));
            curl_setopt_array($ch, curlOpts(6));
            curl_multi_add_handle($mh, $ch);
            $handles[$devId] = $ch;
        }
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 1); } while ($running > 0);

        $betaIpsws = [];
        foreach ($handles as $devId => $ch) {
            $htmlStr = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($htmlStr && preg_match('/(https?:\/\/[^\s\'"<>]+cdn-apple\.com[^\s\'"<>]+\.ipsw)/i', $htmlStr, $ipm)) {
                $cdnUrl = $ipm[1];
                $betaIpsws[] = [
                    'identifier' => $devId,
                    'url'        => $cdnUrl,
                    'filename'   => basename($cdnUrl),
                    'size'       => 10000000000,
                    'signed'     => true,
                ];
            }
        }
        curl_multi_close($mh);

        if (!empty($betaIpsws)) {
            $rawFirmwares = $betaIpsws;
        }
    }
}

// ── Strategy 2: walk device list and match buildid ───────────────────────────
$cacheFile  = __DIR__ . '/../../data/devices_cache.json';
$allDevices = null;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
    $allDevices = json_decode(file_get_contents($cacheFile), true);
}
if (!is_array($allDevices) || empty($allDevices)) {
    $allDevices = fetchJson('https://api.ipsw.me/v4/devices', 30);
    if (is_array($allDevices)) {
        @file_put_contents($cacheFile, json_encode($allDevices));
    }
}

if (empty($rawFirmwares) && is_array($allDevices)) {
    $matchedIds = [];
    foreach ($allDevices as $dev) {
        $id = is_array($dev) ? ($dev['identifier'] ?? '') : (string)$dev;
        if ($id && matchesOs($id, $prefixes, $selectedDevices)) {
            $matchedIds[] = $id;
        }
    }

    $existingUrls = array_flip(array_column($rawFirmwares, 'url'));

    $batches = array_chunk($matchedIds, 15);
    foreach ($batches as $batch) {
        $batchReqs = [];
        foreach ($batch as $id) {
            $batchReqs[$id . '_ipsw'] = 'https://api.ipsw.me/v4/device/' . urlencode($id) . '?type=ipsw';
            $batchReqs[$id . '_ota']  = 'https://api.ipsw.me/v4/device/' . urlencode($id) . '?type=ota';
        }
        $results = fetchJsonMulti($batchReqs, 15);
        foreach ($results as $reqKey => $devData) {
            if (!is_array($devData)) continue;
            $devId = explode('_', $reqKey)[0];
            $firmwares = $devData['firmwares'] ?? normaliseFirmwares($devData);
            foreach ($firmwares as $fw) {
                $fwBuild = $fw['buildid'] ?? $fw['build_id'] ?? '';
                if ($fwBuild === $buildid) {
                    $url = $fw['url'] ?? '';
                    if ($url && !isset($existingUrls[$url])) {
                        if ($signedOnly && isset($fw['signed']) && !$fw['signed']) continue;
                        $rawFirmwares[] = [
                            'identifier' => $devId,
                            'url'        => $url,
                            'filename'   => basename($url),
                            'size'       => $fw['filesize'] ?? 0,
                            'signed'     => $fw['signed'] ?? true,
                        ];
                        $existingUrls[$url] = true;
                    }
                }
            }
        }
        unset($results, $batchReqs);
        if (function_exists('gc_collect_cycles')) gc_collect_cycles();
    }
}

// ── Filter: Strictly 1 URL per Device Model (Prefer .ipsw over .zip) ───────
$deviceMap = [];
foreach ($rawFirmwares as $fw) {
    $devId = $fw['identifier'] ?? ('dev_' . count($deviceMap));
    $url   = $fw['url'] ?? '';
    if (!$url) continue;

    $isIpsw = str_ends_with(strtolower($url), '.ipsw');
    
    if (!isset($deviceMap[$devId])) {
        $deviceMap[$devId] = $fw;
    } else {
        $existingUrl    = $deviceMap[$devId]['url'] ?? '';
        $existingIsIpsw = str_ends_with(strtolower($existingUrl), '.ipsw');

        // If existing is .zip and current is .ipsw, upgrade to .ipsw!
        if (!$existingIsIpsw && $isIpsw) {
            $deviceMap[$devId] = $fw;
        } elseif ($existingIsIpsw === $isIpsw) {
            // Both same type: pick larger file size (full restore package)
            if (($fw['size'] ?? 0) > ($deviceMap[$devId]['size'] ?? 0)) {
                $deviceMap[$devId] = $fw;
            }
        }
    }
}

$rawFirmwares = array_values($deviceMap);
$urls = array_column($rawFirmwares, 'url');

// ── File Naming ──────────────────────────────────────────────────────────────
$osLabels = [
    'ios' => 'iPhone', 'macos' => 'macOS', 'ipados' => 'iPad',
    'watchos' => 'watchOS', 'tvos' => 'tvOS', 'audioos' => 'audioOS',
    'visionos' => 'visionOS', 'all' => 'AllDevices',
];
$osLabel   = !empty($selectedDevices) ? 'CustomDevices' : ($osLabels[$os] ?? $os);
$cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $relname ?: $buildid);

// ── Stream Output according to $format ──────────────────────────────────────

if ($format === 'sh') {
    // Linux Shell Script for Cache Server Auto-Downloader
    $filename = 'download_cache_' . $cleanName . '_' . $buildid . '.sh';
    header('Content-Type: text/x-shellscript; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    echo "#!/bin/bash\n";
    echo "# ==========================================================================\n";
    echo "# IPSW Master — Cache Server Pre-Download Script\n";
    echo "# Release   : " . ($relname ?: 'Build ' . $buildid) . "\n";
    echo "# Build     : $buildid | Devices: $osLabel | Unique Files: " . count($urls) . "\n";
    echo "# Generated : " . gmdate('Y-m-d H:i:s') . " UTC\n";
    echo "# Usage     : chmod +x $filename && ./$filename\n";
    echo "# ==========================================================================\n\n";
    echo "SAVE_DIR=\"./ipsw_cache_" . $buildid . "\"\n";
    echo "mkdir -p \"\$SAVE_DIR\"\n";
    echo "cd \"\$SAVE_DIR\"\n\n";
    echo "echo \"[+] Starting IPSW pre-download for Cache Server (" . count($urls) . " unique files)...\"\n\n";

    foreach ($urls as $u) {
        $fn = basename($u);
        echo "# Downloading: $fn\n";
        echo "if command -v aria2c &> /dev/null; then\n";
        echo "    aria2c -c -x 16 -s 16 -k 1M \"$u\"\n";
        echo "else\n";
        echo "    wget -c \"$u\"\n";
        echo "fi\n\n";
    }

    echo "echo \"[+] All IPSW files downloaded to local cache directory!\"\n";
    exit;
}

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode($rawFirmwares);
    exit;
}

if ($format === 'ef2') {
    // IDM Import File (.ef2 format)
    $filename = 'idm_queue_' . $cleanName . '_' . $buildid . '.ef2';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    echo "<\n";
    foreach ($urls as $u) {
        echo "<\n";
        echo "url=$u\n";
        echo ">\n";
    }
    echo ">\n";
    exit;
}

// Default: Plain Text (.txt)
$filename = $osLabel . '_' . $cleanName . '_' . $buildid . '.txt';
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

echo "# ==========================================================================\n";
echo "# IPSW Master — Vision Technologies Cache Server Exporter\n";
echo "# Release     : " . ($relname ?: 'Build ' . $buildid) . "\n";
echo "# Build ID    : $buildid\n";
echo "# Filter      : OS: $osLabel | Devices Selected: " . (!empty($selectedDevices) ? count($selectedDevices) : 'All') . "\n";
echo "# Total URLs  : " . count($urls) . " (1 URL per device model)\n";
if ($os === 'macos' || stripos($relname, 'macos') !== false) {
    echo "# Note        : macOS uses 1 Universal IPSW file for all Apple Silicon Mac models.\n";
}
echo "# Generated   : " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "# ==========================================================================\n\n";

if (empty($urls)) {
    echo "# No download URLs found for the selected date/devices. This build may not be indexed by ipsw.me yet.\n";
} else {
    echo implode("\n", $urls) . "\n";
}

