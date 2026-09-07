<?php
@error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('session.cookie_path', '/');
if (session_status() === PHP_SESSION_NONE) @session_start();
date_default_timezone_set('Asia/Dhaka');
header('Content-Type: application/json');

function fetchJson(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'IPSW-Master/1.0 (PHP)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$body || $code < 200 || $code >= 300) return null;
    $d = json_decode($body, true);
    return is_array($d) ? $d : null;
}

function isBetaRelease(array $rel): bool {
    $name = strtolower($rel['name'] ?? '');
    if (str_contains($name, 'beta') || str_contains($name, ' rc') || str_contains($name, 'release candidate')
        || str_contains($name, 'developer') || str_contains($name, 'public beta') || str_contains($name, 'seed')) {
        return true;
    }
    $buildid = $rel['buildid'] ?? '';
    if (!$buildid && preg_match('/\(([A-Za-z0-9]+)\)\s*$/', $rel['name'] ?? '', $m)) {
        $buildid = $m[1];
    }
    // Apple Beta build IDs always end with a lowercase letter (e.g. 24A5390f, 22G5024e, 17S5433b)
    if ($buildid && preg_match('/[a-z]$/', $buildid)) {
        return true;
    }
    return false;
}

function flattenReleases(array $data, string $defaultSource): array {
    $out = [];
    foreach ($data as $item) {
        $list = isset($item['releases']) && is_array($item['releases']) ? $item['releases'] : [$item];
        foreach ($list as $rel) {
            if (!isset($rel['name'])) continue;
            if (preg_match('/\(([A-Za-z0-9]+)\)\s*$/', $rel['name'], $m)) {
                $rel['buildid'] = $m[1];
            }
            $rel['source'] = isBetaRelease($rel) ? 'beta' : $defaultSource;
            $out[] = $rel;
        }
    }
    return $out;
}

$cacheFile   = __DIR__ . '/../../data/releases_cache.json';
$historyFile = __DIR__ . '/../../data/history.json';
$releases    = [];

// ── Strategy 0: Instant Cache Load from releases_cache.json ─────────────────
if (file_exists($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cached) && !empty($cached)) {
        foreach (flattenReleases($cached, 'official') as $rel) {
            $releases[] = $rel;
        }
    }
}

if (empty($releases) && file_exists($historyFile)) {
    $hist = json_decode(file_get_contents($historyFile), true);
    if (is_array($hist) && !empty($hist)) {
        foreach ($hist as $rel) {
            if (isset($rel['name'])) {
                $rel['source'] = isBetaRelease($rel) ? 'beta' : ($rel['source'] ?? 'official');
                $releases[] = $rel;
            }
        }
    }
}

// ── Strategy 1: Live fetch from ipsw.me if local cache is completely missing ──
if (empty($releases)) {
    $official = fetchJson('https://api.ipsw.me/v4/releases');
    if (is_array($official) && !empty($official)) {
        foreach (flattenReleases($official, 'official') as $rel) {
            $releases[] = $rel;
        }
        @file_put_contents($cacheFile, json_encode($official));
    }
}

// ── Secondary Beta Feed Source: SOFA (MacAdmins Foundation Feed) ──────────────
// Fast fallback query with 2s timeout for real-time OS & Beta updates
$sofaUrls = [
    'https://sofafeed.macadmins.io/v1/macos_data_feed.json',
    'https://sofafeed.macadmins.io/v1/ios_data_feed.json',
];
$existingNames = array_flip(array_map(fn($r) => $r['name'] ?? '', $releases));

foreach ($sofaUrls as $sUrl) {
    $sData = fetchJson($sUrl, 2);
    if (is_array($sData) && isset($sData['OSVersions'])) {
        $osType = str_contains($sUrl, 'macos') ? 'macOS' : 'iOS';
        foreach ($sData['OSVersions'] as $osGroup) {
            foreach ($osGroup['SecurityReleases'] ?? [] as $sec) {
                $version = $sec['ProductVersion'] ?? '';
                $build   = $sec['Build'] ?? '';
                $date    = $sec['ReleaseDate'] ?? '';
                if (!$version) continue;

                $name = "$osType $version" . ($build ? " ($build)" : "");
                if (!isset($existingNames[$name])) {
                    $releases[] = [
                        'name'    => $name,
                        'buildid' => $build,
                        'date'    => $date ? $date : date('Y-m-d\TH:i:s\Z'),
                        'type'    => $osType,
                        'source'  => (str_contains(strtolower($name), 'beta') || preg_match('/[a-z]$/', $build)) ? 'beta' : 'official',
                        'count'   => $osType === 'macOS' ? 55 : 31,
                    ];
                    $existingNames[$name] = true;
                }
            }
        }
    }
}

// ── Expand iOS releases into iPadOS clones dynamically ───────────────────────
$expandedReleases = [];
foreach ($releases as $rel) {
    $expandedReleases[] = $rel;
    $name = $rel['name'] ?? '';
    if (str_starts_with($name, 'iOS ') && stripos($name, 'OTA') === false) {
        $ipadRel = $rel;
        $ipadRel['name']  = str_replace('iOS ', 'iPadOS ', $name);
        $ipadRel['type']  = 'iPadOS';
        $ipadRel['count'] = 64; // iPad models
        $expandedReleases[] = $ipadRel;
    }
}
$releases = $expandedReleases;
// ── Group all releases of the exact same date by OS type (at most 1 card per OS category per date) ──
$groupedByDateAndType = [];
foreach ($releases as $rel) {
    $dateKey = substr($rel['date'] ?? '', 0, 10);
    $osType  = $rel['type'] ?? 'iOS';

    $osTypeLower = strtolower($osType);
    if (str_contains($osTypeLower, 'macos'))      $osType = 'macOS';
    elseif (str_contains($osTypeLower, 'ipados'))  $osType = 'iPadOS';
    elseif (str_contains($osTypeLower, 'ios'))     $osType = 'iOS';
    elseif (str_contains($osTypeLower, 'watchos')) $osType = 'watchOS';
    elseif (str_contains($osTypeLower, 'tvos'))    $osType = 'tvOS';
    elseif (str_contains($osTypeLower, 'visionos'))$osType = 'visionOS';
    
    $groupKey = "{$dateKey}_{$osType}";
    $groupedByDateAndType[$groupKey][] = $rel;
}

$finalGroupedReleases = [];
foreach ($groupedByDateAndType as $groupKey => $groupRels) {
    list($dateKey, $osType) = explode('_', $groupKey);
    if (count($groupRels) === 1) {
        $finalGroupedReleases[] = $groupRels[0];
    } else {
        usort($groupRels, function($a, $b) {
            return strcmp($b['name'] ?? '', $a['name'] ?? '');
        });

        $versions = [];
        $builds = [];
        $sources = [];
        $dates = [];
        
        foreach ($groupRels as $gr) {
            $v = preg_replace('/^(iOS|iPadOS|macOS|watchOS|tvOS|visionOS)\s+/i', '', $gr['name'] ?? '');
            if ($v) $versions[] = $v;
            if (!empty($gr['buildid'])) {
                $builds[] = $gr['buildid'];
            }
            $sources[] = $gr['source'] ?? 'official';
            $dates[] = $gr['date'] ?? '';
        }

        $versions = array_unique($versions);
        $builds   = array_unique($builds);

        $combinedName = "{$osType} Updates (" . implode(' / ', $versions) . ")";
        $combinedBuildId = implode(',', $builds);

        $finalGroupedReleases[] = [
            'name'    => $combinedName,
            'buildid' => $combinedBuildId ?: "{$osType}_combined",
            'date'    => max($dates),
            'type'    => $osType,
            'source'  => in_array('beta', $sources) ? 'beta' : 'official',
            'count'   => $groupRels[0]['count'] ?? 31,
        ];
    }
}
$releases = $finalGroupedReleases;

// Sort by date descending (newest first)
usort($releases, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

// ── Auto-Email Notification for New Releases ─────────────────────────────────
$settingsPath = __DIR__ . '/../../data/settings.json';
$historyPath  = __DIR__ . '/../../data/history.json';

$settings = file_exists($settingsPath) ? (json_decode(file_get_contents($settingsPath), true) ?? []) : [];
$history  = file_exists($historyPath)  ? (json_decode(file_get_contents($historyPath), true)  ?? []) : [];

$knownBuilds = [];
foreach ($history as $h) {
    if (!empty($h['buildid'])) $knownBuilds[$h['buildid']] = true;
    if (!empty($h['name']))    $knownBuilds[$h['name']] = true;
}

$newReleases = [];
foreach ($releases as $rel) {
    $bId = $rel['buildid'] ?? $rel['name'] ?? '';
    if (!isset($knownBuilds[$bId])) {
        $newReleases[] = $rel;
    }
}

// Update history cache with current release names
if (!empty($newReleases)) {
    $updatedHistory = array_merge(
        array_map(fn($r) => ['name' => $r['name'] ?? '', 'buildid' => $r['buildid'] ?? '', 'source' => $r['source'] ?? '', 'date' => $r['date'] ?? ''], $newReleases),
        $history
    );
    @file_put_contents($historyPath, json_encode(array_slice($updatedHistory, 0, 300)));
}

// If email alerts enabled and brand new releases detected (on initial populated history), auto-trigger email alert
if (!empty($settings['emailAlerts']) && !empty($newReleases) && !empty($history)) {
    $ch = curl_init();
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $protocol . '://' . $host . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/../email/send.php';
    
    curl_setopt_array($ch, [
        CURLOPT_URL            => $baseUrl,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['mode' => 'notify', 'releases' => array_slice($newReleases, 0, 5)]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? '')],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

echo json_encode(array_values($releases));
