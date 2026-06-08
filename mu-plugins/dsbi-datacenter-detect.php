<?php
/**
 * Plugin Name: DSBI — Datacenter IP detection
 * Description: Maintains a table of published cloud/datacenter IP ranges (AWS,
 *              GCP, Oracle, DigitalOcean, Linode, Cloudflare), classifies
 *              tracker sessions whose IP falls in a range as bots
 *              (is_bot_detected=1, bot_reasons=datacenter:<provider>). Adds a
 *              small Asia-cloud city heuristic for providers that don't
 *              publish public ranges (Alibaba/Tencent scraper farms).
 *
 * WP-CLI:  wp dsbi-dc refresh          (re-fetch all provider ranges)
 *          wp dsbi-dc backfill [--apply] [--limit=N]  (classify existing sessions)
 *          wp dsbi-dc classify-recent  (classify sessions started in last 2h)
 *          wp dsbi-dc stats            (summary)
 */
defined('ABSPATH') || exit;

const DSBI_DC_T_CIDRS   = 'wp_dsbi_datacenter_cidrs';
const DSBI_DC_T_REFRESH = 'wp_dsbi_datacenter_refresh';

// City signatures we know are datacenters even though the provider doesn't
// publish IP ranges (Alibaba/Tencent China, Vietnam scraper farms, etc.).
// Format: "city::region::country"
const DSBI_DC_CITY_HEURISTICS = [
    'Changqiao::Shanghai::China',
    'Go Vap::Ho Chi Minh City (HCMC)::Vietnam',
    // Per business decision 2026-05-30: no expected real users from HCMC,
    // treat all city-labeled traffic as bot. Two label variants observed in
    // our geo cache for the same metro area.
    'Ho Chi Minh City::Ho Chi Minh::Vietnam',
    'Ho Chi Minh City::Ho Chi Minh City (HCMC)::Vietnam',
];

// ISP / org_name regex → provider label (added 2026-05-30 after audit
// showed ~16k unflagged sessions in 30 days matching obvious hosting
// patterns despite CIDR + city checks). Source-of-truth for the
// ISP-based classifier. Ordered most specific first — first match wins.
//
// Built from real audit of top ip-api.com org_name + isp values in our
// geo cache. Add new patterns as new providers surface. Remove only if
// they cause confirmed false positives on real human traffic.
const DSBI_DC_ISP_PATTERNS = [
    // — Major cloud + hosting (complements CIDR coverage; catches ranges they don't publish) —
    '/hetzner|datacrunch/i'                                                     => 'hetzner',
    '/\bovh\b|ovhcloud/i'                                                       => 'ovh',
    '/vultr|choopa/i'                                                           => 'vultr',
    '/ionos|1&1 ionos|1und1|1and1/i'                                            => 'ionos',
    '/glesys/i'                                                                 => 'glesys',
    '/scaleway|online sas/i'                                                    => 'scaleway',
    '/leaseweb/i'                                                               => 'leaseweb',
    '/contabo/i'                                                                => 'contabo',
    '/hostinger/i'                                                              => 'hostinger',
    '/m247(\s|$)|m247 ltd/i'                                                    => 'm247',
    '/datacamp/i'                                                               => 'datacamp',
    '/server\s*mania|b2 net solutions/i'                                        => 'servermania',
    '/colocrossing|colocat(el|ion)/i'                                           => 'colocation',
    '/unidata|techoff|trumvps|advin services|fast servers|whg hosting|gsl networks|fine group servers|alex largman|maniera service|unmanaged ltd|pptechnology|logicweb|servers australia/i' => 'small-vps',

    // — Commercial VPNs (consumer privacy networks) —
    '/nordvpn|surfshark|expressvpn|protonvpn|mullvad|cyberghost|hidemyass|privateinternetaccess|tunnelbear|windscribe|purevpn|webshare|hola networks|ivpn|vpn[-\s]consumer/i' => 'consumer-vpn',

    // — TOR exit / privacy relays —
    '/tor\s*exit|church of cyberology/i'                                        => 'tor-exit',

    // — Usenet / aggregators (never human-browsing traffic) —
    '/usenet|xs usenet|f\.n\.s\. holdings/i'                                    => 'usenet',

    // — Generic catch-all for hosting / datacenter words anywhere —
    '/\bhosting\b|hosting services|data ?center|colocation|server farm|dedicated server|cloud\s+(server|hosting|computing)|virtual private server|\bvps\b/i' => 'generic-hosting',
];

// ──────────────────────────────────────────────────────────────────────────────
// Install schema (idempotent via dbDelta / IF NOT EXISTS)
// ──────────────────────────────────────────────────────────────────────────────

// Schema version — bump to re-run dbDelta. dbDelta only runs when the stored
// version differs from this constant; on every other request it's a no-op
// (no dbDelta call → no risk of error_log spam mid-response).
//
// PRIMARY KEY must be on its OWN line (not inline on the column) — dbDelta
// regex-parses CREATE TABLE and mis-emits ALTER TABLE CHANGE COLUMN that
// re-declares PRIMARY KEY, which MySQL rejects with "Multiple primary key
// defined". The inline form ran for weeks emitting that error on every
// request — including async-upload.php, where it polluted the response
// stream and caused "An error occurred in the upload" for editors.
const DSBI_DC_SCHEMA_VERSION = '2';

add_action('plugins_loaded', function () {
    if (get_option('dsbi_dc_schema_version') === DSBI_DC_SCHEMA_VERSION) return;
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE " . DSBI_DC_T_CIDRS . " (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        provider VARCHAR(32) NOT NULL,
        cidr VARCHAR(64) NOT NULL,
        start_ip VARBINARY(16) NOT NULL,
        end_ip   VARBINARY(16) NOT NULL,
        is_ipv6  TINYINT NOT NULL DEFAULT 0,
        added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        INDEX idx_provider (provider),
        INDEX idx_start (start_ip),
        INDEX idx_end (end_ip)
    ) $charset");
    dbDelta("CREATE TABLE " . DSBI_DC_T_REFRESH . " (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        provider VARCHAR(32) NOT NULL,
        ranges INT NOT NULL DEFAULT 0,
        success TINYINT NOT NULL DEFAULT 0,
        message TEXT,
        run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        INDEX idx_run (run_at)
    ) $charset");
    update_option('dsbi_dc_schema_version', DSBI_DC_SCHEMA_VERSION, false);
});

// ──────────────────────────────────────────────────────────────────────────────
// CIDR → 16-byte BINARY start/end. IPv4 is stored as IPv4-mapped IPv6
// (::ffff:a.b.c.d) so a single column type works for both families.
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Convert an IP to 16-byte v4-mapped IPv6 binary.
 * Accepts both:
 *  - printable strings ("1.2.3.4", "2001:db8::1") via inet_pton
 *  - raw VARBINARY (4 bytes = IPv4, 16 bytes = IPv6) as stored in wp_dsbi_tracker_sessions.ip
 */
function dsbi_dc_ip_to_v6mapped(string $ip): ?string {
    $len = strlen($ip);
    if ($len === 4) {
        return "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . $ip;
    }
    if ($len === 16) {
        // Already 16-byte binary; may be IPv6 or v4-mapped — pass through.
        return $ip;
    }
    // Fallback: parse as printable string
    $bin = @inet_pton($ip);
    if ($bin === false) return null;
    if (strlen($bin) === 4) $bin = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . $bin;
    return $bin;
}

function dsbi_dc_cidr_to_range(string $cidr): ?array {
    if (strpos($cidr, '/') === false) {
        $ip = $cidr; $prefix = (strpos($ip, ':') !== false) ? 128 : 32;
    } else {
        [$ip, $prefix] = explode('/', $cidr, 2);
        $prefix = (int) $prefix;
    }
    $is_v6 = (strpos($ip, ':') !== false);
    $bin = dsbi_dc_ip_to_v6mapped($ip);
    if ($bin === null) return null;
    if (!$is_v6) $prefix += 96;  // because IPv4 lives in the last 32 bits of the 128-bit mapped form
    $host_bits = 128 - $prefix;
    $start = $bin; $end = $bin;
    $byte = 15;
    while ($host_bits > 0 && $byte >= 0) {
        $b = min(8, $host_bits);
        $mask = (1 << $b) - 1;
        $start[$byte] = chr(ord($start[$byte]) & (~$mask & 0xFF));
        $end[$byte]   = chr(ord($end[$byte])   |  $mask);
        $host_bits -= $b;
        $byte--;
    }
    return ['start' => $start, 'end' => $end, 'is_ipv6' => $is_v6];
}

// ──────────────────────────────────────────────────────────────────────────────
// HTTP fetch + per-provider parsers (return ['cidrs'=>[], 'err'=>?])
// ──────────────────────────────────────────────────────────────────────────────

function dsbi_dc_fetch(string $url, int $timeout = 30) {
    $r = wp_remote_get($url, [
        'timeout' => $timeout,
        'headers' => ['User-Agent' => 'DSBI/1.0 (datacenter-detect)'],
        'sslverify' => true,
    ]);
    if (is_wp_error($r)) return $r;
    $code = (int) wp_remote_retrieve_response_code($r);
    if ($code !== 200) return new WP_Error('http', "HTTP $code from $url");
    return (string) wp_remote_retrieve_body($r);
}

function dsbi_dc_provider_aws(): array {
    $body = dsbi_dc_fetch('https://ip-ranges.amazonaws.com/ip-ranges.json');
    if (is_wp_error($body)) return ['err'=>$body];
    $j = json_decode($body, true);
    if (!$j) return ['err'=> new WP_Error('parse','bad AWS JSON')];
    $out = [];
    foreach (($j['prefixes'] ?? []) as $p) if (!empty($p['ip_prefix'])) $out[] = $p['ip_prefix'];
    foreach (($j['ipv6_prefixes'] ?? []) as $p) if (!empty($p['ipv6_prefix'])) $out[] = $p['ipv6_prefix'];
    return ['cidrs'=>$out];
}

function dsbi_dc_provider_gcp(): array {
    $body = dsbi_dc_fetch('https://www.gstatic.com/ipranges/cloud.json');
    if (is_wp_error($body)) return ['err'=>$body];
    $j = json_decode($body, true);
    if (!$j) return ['err'=> new WP_Error('parse','bad GCP JSON')];
    $out = [];
    foreach (($j['prefixes'] ?? []) as $p) {
        if (!empty($p['ipv4Prefix'])) $out[] = $p['ipv4Prefix'];
        if (!empty($p['ipv6Prefix'])) $out[] = $p['ipv6Prefix'];
    }
    return ['cidrs'=>$out];
}

function dsbi_dc_provider_oracle(): array {
    $body = dsbi_dc_fetch('https://docs.oracle.com/en-us/iaas/tools/public_ip_ranges.json');
    if (is_wp_error($body)) return ['err'=>$body];
    $j = json_decode($body, true);
    if (!$j) return ['err'=> new WP_Error('parse','bad Oracle JSON')];
    $out = [];
    foreach (($j['regions'] ?? []) as $r) {
        foreach (($r['cidrs'] ?? []) as $c) if (!empty($c['cidr'])) $out[] = $c['cidr'];
    }
    return ['cidrs'=>$out];
}

function dsbi_dc_provider_do(): array {
    $body = dsbi_dc_fetch('https://www.digitalocean.com/geo/google.csv');
    if (is_wp_error($body)) return ['err'=>$body];
    $out = [];
    foreach (explode("\n", $body) as $line) {
        $cidr = strtok(trim($line), ',');
        if ($cidr && strpos($cidr,'/')!==false) $out[] = $cidr;
    }
    return ['cidrs'=>$out];
}

function dsbi_dc_provider_linode(): array {
    $body = dsbi_dc_fetch('https://geoip.linode.com/');
    if (is_wp_error($body)) return ['err'=>$body];
    $out = [];
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if (!$line || $line[0] === '#') continue;
        $cidr = strtok($line, ',');
        if ($cidr && strpos($cidr,'/')!==false) $out[] = $cidr;
    }
    return ['cidrs'=>$out];
}

function dsbi_dc_provider_cloudflare(): array {
    $out = [];
    foreach (['https://www.cloudflare.com/ips-v4','https://www.cloudflare.com/ips-v6'] as $url) {
        $body = dsbi_dc_fetch($url);
        if (is_wp_error($body)) continue;
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line && strpos($line,'/')!==false) $out[] = $line;
        }
    }
    return ['cidrs'=>$out];
}

// ──────────────────────────────────────────────────────────────────────────────
// Refresh all providers — replace existing rows per-provider
// ──────────────────────────────────────────────────────────────────────────────

function dsbi_dc_refresh_all(): array {
    global $wpdb;
    $providers = [
        'aws'         => 'dsbi_dc_provider_aws',
        'gcp'         => 'dsbi_dc_provider_gcp',
        'oracle'      => 'dsbi_dc_provider_oracle',
        'digitalocean'=> 'dsbi_dc_provider_do',
        'linode'      => 'dsbi_dc_provider_linode',
        'cloudflare'  => 'dsbi_dc_provider_cloudflare',
    ];
    $summary = [];
    foreach ($providers as $name => $fn) {
        $r = $fn();
        if (!empty($r['err'])) {
            $msg = $r['err'] instanceof WP_Error ? $r['err']->get_error_message() : (string) $r['err'];
            $wpdb->insert(DSBI_DC_T_REFRESH, ['provider'=>$name,'ranges'=>0,'success'=>0,'message'=>$msg]);
            $summary[$name] = "FAIL: $msg";
            continue;
        }
        $wpdb->query($wpdb->prepare("DELETE FROM " . DSBI_DC_T_CIDRS . " WHERE provider=%s", $name));
        $count = 0;
        // Batch inserts for speed
        $values = []; $place = [];
        foreach (($r['cidrs'] ?? []) as $cidr) {
            $rng = dsbi_dc_cidr_to_range($cidr);
            if (!$rng) continue;
            $values = array_merge($values, [$name, $cidr, $rng['start'], $rng['end'], $rng['is_ipv6']?1:0]);
            $place[] = "(%s,%s,%s,%s,%d)";
            $count++;
            if (count($place) >= 200) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO " . DSBI_DC_T_CIDRS . " (provider,cidr,start_ip,end_ip,is_ipv6) VALUES " . implode(',', $place),
                    $values
                ));
                $values = []; $place = [];
            }
        }
        if ($place) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO " . DSBI_DC_T_CIDRS . " (provider,cidr,start_ip,end_ip,is_ipv6) VALUES " . implode(',', $place),
                $values
            ));
        }
        $wpdb->insert(DSBI_DC_T_REFRESH, ['provider'=>$name,'ranges'=>$count,'success'=>1,'message'=>'']);
        $summary[$name] = "ok: $count CIDRs";
    }
    return $summary;
}

// ──────────────────────────────────────────────────────────────────────────────
// Per-IP and per-city classifiers
// ──────────────────────────────────────────────────────────────────────────────

function dsbi_dc_classify_ip(string $ip): ?string {
    static $cache = [];
    if (isset($cache[$ip])) return $cache[$ip];
    global $wpdb;
    $bin = dsbi_dc_ip_to_v6mapped($ip);
    if ($bin === null) return $cache[$ip] = null;
    $hex = bin2hex($bin);
    $provider = $wpdb->get_var($wpdb->prepare(
        "SELECT provider FROM " . DSBI_DC_T_CIDRS . "
         WHERE start_ip <= UNHEX(%s) AND end_ip >= UNHEX(%s)
         ORDER BY start_ip DESC LIMIT 1",
        $hex, $hex
    ));
    return $cache[$ip] = ($provider ?: null);
}

function dsbi_dc_classify_city(?string $city, ?string $region, ?string $country): ?string {
    if (!$city) return null;
    $sig = trim((string)$city) . '::' . trim((string)$region) . '::' . trim((string)$country);
    return in_array($sig, DSBI_DC_CITY_HEURISTICS, true) ? 'asia-cloud-heuristic' : null;
}

/**
 * Match the geo cache's ISP / org_name against known hosting patterns.
 * Returns the matched provider label (e.g. "hetzner", "consumer-vpn") or
 * null when neither field hits any pattern.
 *
 * Cheap: just regex over short strings, no I/O, no allocations beyond
 * the string concat.
 */
function dsbi_dc_classify_isp(?string $isp, ?string $org_name): ?string {
    if (!$isp && !$org_name) return null;
    $haystack = trim((string) $isp . ' ' . (string) $org_name);
    foreach (DSBI_DC_ISP_PATTERNS as $regex => $label) {
        if (@preg_match($regex, $haystack)) return $label;
    }
    return null;
}

/**
 * URL-shape classifier — catches sessions whose entry URL matches a
 * known bot-tool pattern. Zero false-positive risk on real users
 * because each pattern describes a URL no human would type.
 *
 * Tags returned (first match wins):
 *   'wp-login-scanner'      — `/X+wp-login.php` (concat, no slash)
 *   'wp-vuln-scanner'       — common WP exploit paths (xmlrpc, wp-config,
 *                              .env, .git, install.php, debug.log, etc.)
 *   'wp-path-concat-scanner'— wp-admin/wp-content/wp-includes glued onto
 *                              a path fragment without a slash
 */
function dsbi_dc_classify_entry_url(?string $entry_url): ?string {
    if (!$entry_url) return null;
    $path = parse_url((string) $entry_url, PHP_URL_PATH) ?? (string) $entry_url;

    // 1. wp-login.php concatenated onto a path fragment without a slash.
    if (preg_match('#^/[a-z][a-z0-9_-]*wp-login\.php#i', $path)) {
        return 'wp-login-scanner';
    }
    // 2. Same concat pattern for wp-admin/wp-content/wp-includes.
    if (preg_match('#^/[a-z][a-z0-9_-]+(wp-admin|wp-content|wp-includes)/#i', $path)) {
        return 'wp-path-concat-scanner';
    }
    // 3. Common WP vulnerability/config scanner paths. Each is a URL no
    //    real user navigates to directly — they're probed by scanners
    //    looking for misconfigured installs or leaked secrets.
    static $vuln_patterns = [
        '#^/xmlrpc\.php#i',                  // XML-RPC brute-force endpoint
        '#^/wp-config\.php#i',                // config-file leak attempt
        '#^/wp-config\.php\.bak#i',          // common backup-config name
        '#^/wp-admin/install\.php#i',        // installer scanner
        '#^/wp-content/debug\.log#i',         // WP_DEBUG_LOG leak attempt
        '#^/wp-content/uploads/.*\.(php|phtml|phps|php3|php4|php5)#i',  // shell-upload check
        '#^/\.env(\b|$)#i',                  // .env secret hunters
        '#^/\.env\.(bak|local|production)#i',
        '#^/\.git/#i',                       // exposed .git directories
        '#^/\.svn/#i',
        '#^/\.DS_Store#i',
        '#^/(phpinfo|info|test|adminer)\.php#i',
        '#^/(phpmyadmin|pma|myadmin)/#i',     // DB admin tool scanners
        '#^/wp-content/plugins/[a-z0-9_-]+/(readme|changelog)\.txt#i',  // plugin-version fingerprinters (single-page hits only)
    ];
    foreach ($vuln_patterns as $rx) {
        if (preg_match($rx, $path)) return 'wp-vuln-scanner';
    }
    return null;
}

/**
 * No-JS + browser-shaped UA classifier. Real users in 2026 have JS
 * enabled by default. A session whose JS-enrichment endpoint never
 * fired BUT whose User-Agent claims to be a full desktop browser
 * (Mozilla/Chrome/Firefox/Safari/Edge) is overwhelmingly a scripted
 * scraper using a browser UA for camouflage.
 *
 * Returns 'no-js-fake-browser' if matched. Excludes obvious non-browser
 * tools (curl/wget/python/Java) — those will be caught by the existing
 * ua_blacklist classifier.
 */
function dsbi_dc_classify_no_js(?int $js_enabled, ?string $user_agent): ?string {
    if ($js_enabled !== 0 && $js_enabled !== null) return null;  // js fired → not this signal
    if (!$user_agent) return null;
    $ua = (string) $user_agent;
    // Skip obvious non-browser tools — covered by other classifiers.
    if (preg_match('#curl|wget|python|java/|httpie|ruby|libwww|perl|go-http-client#i', $ua)) return null;
    // Require a credible-looking browser UA prefix.
    if (preg_match('#^Mozilla/5\.0\s.+(Chrome|Firefox|Safari|Edg|OPR)/#i', $ua)) {
        return 'no-js-fake-browser';
    }
    return null;
}

/**
 * Bot-shaped viewport classifier. Real browsers always report a
 * viewport. A session with viewport_w=0 or viewport_w<200 means the
 * "browser" had no display surface at all — a headless tool that
 * didn't initialize a proper window.
 *
 * Conservative: doesn't try to flag classic-default sizes like
 * 1024×768 because those CAN be legitimate on older devices.
 */
function dsbi_dc_classify_viewport(?int $viewport_w, ?int $viewport_h): ?string {
    if ($viewport_w === null && $viewport_h === null) return null;  // we never received it → skip
    if (($viewport_w !== null && $viewport_w > 0 && $viewport_w < 200) ||
        ($viewport_h !== null && $viewport_h > 0 && $viewport_h < 200)) {
        return 'tiny-viewport';
    }
    return null;
}

/**
 * Velocity classifier. Real humans rarely sustain more than ~1 page
 * every 2 seconds. A session with ≥4 pageviews where avg time-per-page
 * is < 2 seconds = scripted crawler.
 *
 * Needs both fields populated — sessions without timing data are skipped.
 */
function dsbi_dc_classify_velocity(?int $pageviews_count, ?int $estimated_seconds): ?string {
    if (!$pageviews_count || $pageviews_count < 4)  return null;  // not enough pages to judge
    if (!$estimated_seconds || $estimated_seconds < 1) return null;  // no timing data
    $per_page = $estimated_seconds / $pageviews_count;
    if ($per_page < 2.0) return 'crawl-velocity';
    return null;
}

/**
 * Build the set of "rotating-proxy" User-Agent strings — UAs that
 * appear across many distinct IPs in the recent window. Real users
 * don't share exact UA strings across 30+ different IPs (the
 * version+OS+device combos make UAs near-unique per device); the only
 * thing that does is a botnet/residential-proxy pool driving traffic
 * from a single bot script through many proxy IPs.
 *
 * Returns: set of UA strings (lowercased keys for fast lookup).
 *
 * Called once per backfill — caches result in a static so the inner
 * loop doesn't re-query.
 */
function dsbi_dc_load_rotating_uas(?string $since = null): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    global $wpdb;
    $w = "user_agent IS NOT NULL AND user_agent <> ''";
    if ($since) $w .= $wpdb->prepare(" AND started_at >= %s", $since);
    $rows = $wpdb->get_results("
        SELECT user_agent, COUNT(DISTINCT ip) AS uniq_ips
        FROM wp_dsbi_tracker_sessions
        WHERE $w
        GROUP BY user_agent
        HAVING uniq_ips >= 30
    ", ARRAY_A) ?: [];
    $set = [];
    foreach ($rows as $r) {
        $set[strtolower($r['user_agent'])] = (int) $r['uniq_ips'];
    }
    $cache = $set;
    return $set;
}

function dsbi_dc_classify_rotating_ua(?string $user_agent, array $rotating_set): ?string {
    if (!$user_agent) return null;
    $key = strtolower($user_agent);
    return isset($rotating_set[$key]) ? 'rotating-proxy-ua' : null;
}

// ──────────────────────────────────────────────────────────────────────────────
// Backfill / recent classify
// ──────────────────────────────────────────────────────────────────────────────

function dsbi_dc_backfill(int $limit = 0, bool $apply = false, ?string $since = null): array {
    global $wpdb;
    $w = "(s.is_bot_detected = 0 OR s.is_bot_detected IS NULL)";
    if ($since) $w .= $wpdb->prepare(" AND s.started_at >= %s", $since);
    $lim = $limit > 0 ? "LIMIT " . (int) $limit : "";

    // LEFT JOIN the geo cache so the ISP/org classifier has data to work
    // with. Sessions without a geo lookup yet are still caught by CIDR + city.
    // Extra fields used by the v2 classifiers (2026-05-30 PM extension):
    //   - js_enabled + user_agent → no-js-fake-browser
    //   - viewport_w + viewport_h → tiny-viewport
    //   - pageviews_count + estimated_session_seconds → crawl-velocity
    //   - user_agent (cross-session) → rotating-proxy-ua
    $rows = $wpdb->get_results("
        SELECT s.id, s.ip, s.city, s.region, s.country, s.entry_url,
               s.js_enabled, s.user_agent,
               s.viewport_w, s.viewport_h,
               s.pageviews_count, s.estimated_session_seconds,
               g.isp, g.org_name
        FROM wp_dsbi_tracker_sessions s
        LEFT JOIN wp_dsbi_tracker_ip_geo g ON g.ip = s.ip
        WHERE $w
        ORDER BY s.id DESC
        $lim
    ", ARRAY_A);

    // Cross-session pre-pass: identify UAs appearing across 30+ distinct IPs.
    // Scoped to the same window as the backfill so we're catching active
    // rotating-proxy operations, not historical noise.
    $rotating_uas = dsbi_dc_load_rotating_uas($since);

    $flagged = 0; $by_provider = [];
    $by_source = [
        'cidr'=>0, 'city'=>0, 'isp'=>0, 'url'=>0,
        'no-js'=>0, 'viewport'=>0, 'velocity'=>0, 'rotating-ua'=>0,
    ];
    $sample = [];
    foreach ($rows as $r) {
        $provider = null;
        $source   = null;
        // 1. CIDR (most specific — exact IP range lookup)
        if ($r['ip']) {
            $provider = dsbi_dc_classify_ip($r['ip']);
            if ($provider) $source = 'cidr';
        }
        // 2. City heuristic (Asia-cloud hotspots that don't publish CIDRs)
        if (!$provider) {
            $provider = dsbi_dc_classify_city($r['city'], $r['region'], $r['country']);
            if ($provider) $source = 'city';
        }
        // 3. ISP / org_name from geo cache (broadest — covers Hetzner, OVH,
        //    VPN/VPS providers, TOR exits, etc. without per-provider CIDR fetches)
        if (!$provider) {
            $provider = dsbi_dc_classify_isp($r['isp'] ?? null, $r['org_name'] ?? null);
            if ($provider) $source = 'isp';
        }
        // 4. Entry-URL pattern (wp-login, wp-vuln-scanner, wp-path-concat).
        if (!$provider) {
            $provider = dsbi_dc_classify_entry_url($r['entry_url'] ?? null);
            if ($provider) $source = 'url';
        }
        // 5. JS-disabled + browser-shaped UA (scripted scraper using
        //    browser UA for camouflage but never running the JS enrichment).
        if (!$provider) {
            $provider = dsbi_dc_classify_no_js(
                $r['js_enabled'] !== null ? (int) $r['js_enabled'] : null,
                $r['user_agent'] ?? null
            );
            if ($provider) $source = 'no-js';
        }
        // 6. Tiny / zero viewport (headless tool that didn't init a window).
        if (!$provider) {
            $provider = dsbi_dc_classify_viewport(
                isset($r['viewport_w']) ? (int) $r['viewport_w'] : null,
                isset($r['viewport_h']) ? (int) $r['viewport_h'] : null
            );
            if ($provider) $source = 'viewport';
        }
        // 7. Crawl velocity (≥4 pages averaging < 2 seconds each).
        if (!$provider) {
            $provider = dsbi_dc_classify_velocity(
                isset($r['pageviews_count']) ? (int) $r['pageviews_count'] : null,
                isset($r['estimated_session_seconds']) ? (int) $r['estimated_session_seconds'] : null
            );
            if ($provider) $source = 'velocity';
        }
        // 8. Rotating-proxy UA (same UA across 30+ distinct IPs in window).
        if (!$provider) {
            $provider = dsbi_dc_classify_rotating_ua($r['user_agent'] ?? null, $rotating_uas);
            if ($provider) $source = 'rotating-ua';
        }
        if (!$provider) continue;

        $flagged++;
        $by_provider[$provider] = ($by_provider[$provider] ?? 0) + 1;
        $by_source[$source]     = ($by_source[$source] ?? 0) + 1;
        if (count($sample) < 8) {
            $sample[] = [
                'id'=>(int)$r['id'],'ip'=>$r['ip'],'city'=>$r['city'],'country'=>$r['country'],
                'isp'=>$r['isp'] ?? null,'org_name'=>$r['org_name'] ?? null,
                'entry_url'=>$r['entry_url'] ?? null,
                'provider'=>$provider,'source'=>$source,
            ];
        }
        if ($apply) {
            // Behavioral classifiers (everything past ISP) use the bot:
            // prefix — URL shape, JS state, viewport, velocity, and
            // rotating-UA aren't network-origin signals. CIDR / city / ISP
            // still use datacenter: prefix (network-origin).
            $prefix = in_array($source, ['cidr','city','isp'], true) ? 'datacenter' : 'bot';
            $tag = "{$prefix}:{$provider}";
            $wpdb->query($wpdb->prepare("
                UPDATE wp_dsbi_tracker_sessions
                SET is_bot_detected = 1,
                    bot_reasons = CONCAT(IFNULL(bot_reasons,''),
                        CASE WHEN bot_reasons IS NULL OR bot_reasons='' THEN '' ELSE ',' END, %s),
                    classified_at = NOW()
                WHERE id = %d
            ", $tag, (int) $r['id']));
        }
    }
    return [
        'scanned'     => count($rows),
        'flagged'     => $flagged,
        'by_provider' => $by_provider,
        'by_source'   => $by_source,
        'sample'      => $sample,
        'applied'     => $apply,
    ];
}

function dsbi_dc_classify_recent(): array {
    // Sessions started in the last 2h that haven't been flagged yet.
    $since = gmdate('Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS);
    return dsbi_dc_backfill(0, true, $since);
}

// ──────────────────────────────────────────────────────────────────────────────
// WP-CLI
// ──────────────────────────────────────────────────────────────────────────────

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('dsbi-dc', function ($args, $assoc) {
        global $wpdb;
        $sub = $args[0] ?? 'stats';
        if ($sub === 'refresh') {
            $r = dsbi_dc_refresh_all();
            foreach ($r as $p => $m) WP_CLI::log("$p: $m");
            return;
        }
        if ($sub === 'backfill') {
            $r = dsbi_dc_backfill((int)($assoc['limit'] ?? 0), !empty($assoc['apply']));
            WP_CLI::log(wp_json_encode($r, JSON_PRETTY_PRINT));
            return;
        }
        if ($sub === 'classify-recent') {
            WP_CLI::log(wp_json_encode(dsbi_dc_classify_recent(), JSON_PRETTY_PRINT));
            return;
        }
        // stats
        $by = $wpdb->get_results("SELECT provider, COUNT(*) n FROM " . DSBI_DC_T_CIDRS . " GROUP BY provider ORDER BY n DESC", ARRAY_A);
        WP_CLI::log("CIDRs by provider:");
        foreach ($by as $row) WP_CLI::log(sprintf("  %-14s %d", $row['provider'], $row['n']));
        $total_flagged = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_dsbi_tracker_sessions WHERE bot_reasons LIKE 'datacenter:%' OR bot_reasons LIKE '%,datacenter:%'");
        WP_CLI::log("Sessions flagged datacenter: $total_flagged");
    });
}

// ──────────────────────────────────────────────────────────────────────────────
// WP-cron: weekly refresh + 15-min classify-recent  (system cron is more
// reliable; this is the fallback)
// ──────────────────────────────────────────────────────────────────────────────

add_action('dsbi_dc_weekly_refresh', 'dsbi_dc_refresh_all');
add_action('dsbi_dc_classify_recent_hook', 'dsbi_dc_classify_recent');
if (!wp_next_scheduled('dsbi_dc_weekly_refresh'))     wp_schedule_event(time()+60,  'weekly', 'dsbi_dc_weekly_refresh');
if (!wp_next_scheduled('dsbi_dc_classify_recent_hook')) wp_schedule_event(time()+60, 'hourly', 'dsbi_dc_classify_recent_hook');
