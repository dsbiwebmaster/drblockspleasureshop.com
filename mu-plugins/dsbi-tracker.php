<?php
/**
 * Plugin Name: DSBI Custom Tracker
 * Description: First-party visitor analytics. Tracks sessions, pageviews,
 *   traffic sources (Google organic / Ads / AEO / social / direct), device
 *   type, timezone, and IP. PHP-first (works without JS); JS in footer
 *   enriches with client-side fields. Self-contained, no external services.
 * Version: 1.0.0
 *
 * Architecture:
 *   - 2 custom tables: wp_dsbi_tracker_sessions (1 row / session) and
 *     wp_dsbi_tracker_pageviews (many rows / session)
 *   - Cookie `dsbi_sess` (30d) holds a UUID; localStorage mirrors it for
 *     persistence across cookie eviction
 *   - PHP runs on every page render via template_redirect, before output:
 *       * If cookie missing → mint UUID + insert session row + setcookie
 *       * Insert pageview row + increment session.pageviews_count
 *   - JS in footer reads cookie → POSTs to /wp-json/dsbi-tracker/v1/enrich
 *     to add timezone, viewport, js_enabled=1
 *   - "Bounce" is computed at read time (pageviews_count = 1, last_seen
 *     older than 30 min) — no separate column
 *
 * Privacy / opt-out:
 *   - DNT (Do Not Track) header respected: tracking is skipped if set
 *   - Logged-in admins are not tracked
 *   - IP stored as VARBINARY(16) via inet_pton, so v4+v6 supported
 *
 * Admin: Tools → DSBI Tracker shows recent sessions, top sources, bounce rate.
 */

defined('ABSPATH') || exit;

const DSBI_TRK_VERSION         = '1.6.0';
const DSBI_TRK_DB_VERSION      = '1.6.0';
// 2-hour idle window. After that, a hit with an existing session_uuid
// starts a NEW row (same uuid, incremented session_seq) rather than
// extending the previous chunk. See dsbi_trk_is_session_expired().
const DSBI_TRK_SESSION_TIMEOUT_SEC = 7200;
const DSBI_TRK_COOKIE          = 'dsbi_sess';
const DSBI_TRK_COOKIE_DAYS     = 30;
const DSBI_TRK_TABLE_SESSIONS  = 'dsbi_tracker_sessions';
const DSBI_TRK_TABLE_PAGEVIEWS = 'dsbi_tracker_pageviews';
const DSBI_TRK_TABLE_CLICKS    = 'dsbi_tracker_clicks';
const DSBI_TRK_TABLE_EVENTS    = 'dsbi_tracker_events';
const DSBI_TRK_TABLE_IP_GEO    = 'dsbi_tracker_ip_geo';
const DSBI_TRK_BOUNCE_AFTER_MIN = 30;
// Geo lookup: ip-api.com free tier (45 req/min, no API key). HTTP-only on
// the free tier — fine for server-side use. Cache hits avoid the call
// entirely so most batches do zero network requests.
const DSBI_TRK_GEO_ENDPOINT    = 'http://ip-api.com/json/';
const DSBI_TRK_GEO_FIELDS      = 'status,message,country,countryCode,region,regionName,city,zip,lat,lon,isp,org,query';
const DSBI_TRK_GEO_BATCH       = 100;       // sessions enriched per cron tick
const DSBI_TRK_GEO_THROTTLE_MS = 1500;      // sleep between API calls (45/min cap)

// ──────────────────────────────────────────────────────────────────────────────
// Schema — create tables on first load, refresh on version bump
// ──────────────────────────────────────────────────────────────────────────────

function dsbi_trk_table_sessions()  { global $wpdb; return $wpdb->prefix . DSBI_TRK_TABLE_SESSIONS;  }
function dsbi_trk_table_pageviews() { global $wpdb; return $wpdb->prefix . DSBI_TRK_TABLE_PAGEVIEWS; }
function dsbi_trk_table_clicks()    { global $wpdb; return $wpdb->prefix . DSBI_TRK_TABLE_CLICKS;    }
function dsbi_trk_table_events()    { global $wpdb; return $wpdb->prefix . DSBI_TRK_TABLE_EVENTS;    }
function dsbi_trk_table_ip_geo()    { global $wpdb; return $wpdb->prefix . DSBI_TRK_TABLE_IP_GEO;    }

function dsbi_trk_maybe_install() {
    if (get_option('dsbi_trk_db_version') === DSBI_TRK_DB_VERSION) return;

    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $sessions  = dsbi_trk_table_sessions();
    $pageviews = dsbi_trk_table_pageviews();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // 1.5.0 migration: session_uuid is no longer unique by itself —
    // it now identifies the visitor across visits, and each 2-hour-gap
    // chunk gets its own row distinguished by (session_uuid, session_seq).
    // dbDelta cannot drop a unique constraint, so we do it explicitly
    // before re-running dbDelta with the new schema.
    $prev_db_version = (string) get_option('dsbi_trk_db_version', '0');
    if (version_compare($prev_db_version, '1.5.0', '<')) {
        // Drop the legacy UNIQUE KEY session_uuid if present. Ignored if absent.
        $wpdb->query("ALTER TABLE $sessions DROP INDEX session_uuid");
    }

    dbDelta("CREATE TABLE $sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_uuid CHAR(36) NOT NULL,
        session_seq SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        started_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        estimated_session_seconds INT UNSIGNED DEFAULT NULL,
        ip VARBINARY(16) NULL,
        user_agent VARCHAR(512) NULL,
        device_type VARCHAR(20) NULL,
        timezone VARCHAR(64) NULL,
        tz_offset_min SMALLINT NULL,
        entry_url VARCHAR(2000) NULL,
        entry_title VARCHAR(500) NULL,
        entry_referer VARCHAR(2000) NULL,
        entry_source VARCHAR(40) NULL,
        utm_source VARCHAR(120) NULL,
        utm_medium VARCHAR(120) NULL,
        utm_campaign VARCHAR(120) NULL,
        utm_content VARCHAR(120) NULL,
        utm_term VARCHAR(120) NULL,
        gclid VARCHAR(200) NULL,
        gad_source VARCHAR(40) NULL,
        aeo_origin VARCHAR(64) NULL,
        referer_host VARCHAR(200) NULL,
        viewport_w SMALLINT NULL,
        viewport_h SMALLINT NULL,
        device_pixel_ratio DECIMAL(3,1) NULL,
        pageviews_count INT UNSIGNED DEFAULT 1,
        js_enabled TINYINT(1) DEFAULT 0,
        is_bot_detected TINYINT(1) DEFAULT NULL,
        confidence_score TINYINT UNSIGNED DEFAULT NULL,
        bot_reason_id VARCHAR(40) DEFAULT NULL,
        bot_reasons VARCHAR(255) DEFAULT NULL,
        classified_at DATETIME DEFAULT NULL,
        city VARCHAR(120) DEFAULT NULL,
        region VARCHAR(120) DEFAULT NULL,
        region_code VARCHAR(10) DEFAULT NULL,
        country VARCHAR(120) DEFAULT NULL,
        country_code CHAR(2) DEFAULT NULL,
        geo_lookup_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY session_visit (session_uuid, session_seq),
        KEY session_uuid_idx (session_uuid),
        KEY started_at (started_at),
        KEY entry_source (entry_source),
        KEY last_seen_at (last_seen_at),
        KEY is_bot_detected (is_bot_detected),
        KEY bot_reason_id (bot_reason_id),
        KEY country_code (country_code),
        KEY region_code (region_code)
    ) $charset");

    // IP geolocation cache. Keyed by the packed IP; one row per unique
    // address. Looked up by the geo-enrich cron before any external call
    // so we never hit the rate-limited free tier more than once per IP.
    $ip_geo = dsbi_trk_table_ip_geo();
    dbDelta("CREATE TABLE $ip_geo (
        ip VARBINARY(16) NOT NULL,
        ip_text VARCHAR(45) NOT NULL,
        city VARCHAR(120) DEFAULT NULL,
        region VARCHAR(120) DEFAULT NULL,
        region_code VARCHAR(10) DEFAULT NULL,
        country VARCHAR(120) DEFAULT NULL,
        country_code CHAR(2) DEFAULT NULL,
        zip VARCHAR(20) DEFAULT NULL,
        lat DECIMAL(8,5) DEFAULT NULL,
        lon DECIMAL(9,5) DEFAULT NULL,
        isp VARCHAR(200) DEFAULT NULL,
        org_name VARCHAR(200) DEFAULT NULL,
        lookup_status VARCHAR(20) DEFAULT NULL,
        lookup_error VARCHAR(200) DEFAULT NULL,
        lookup_at DATETIME NOT NULL,
        PRIMARY KEY (ip),
        KEY country_code (country_code),
        KEY lookup_at (lookup_at)
    ) $charset");

    dbDelta("CREATE TABLE $pageviews (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT UNSIGNED NOT NULL,
        viewed_at DATETIME NOT NULL,
        url VARCHAR(2000) NOT NULL,
        title VARCHAR(500) NULL,
        referer VARCHAR(2000) NULL,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY viewed_at (viewed_at)
    ) $charset");

    // Clicks table — one row per link click. Powers the heatmap.
    // dbDelta will ADD missing columns (e.g., area added in 1.2.0) on upgrade
    // without dropping data.
    $clicks = dsbi_trk_table_clicks();
    dbDelta("CREATE TABLE $clicks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT UNSIGNED NOT NULL,
        clicked_at DATETIME NOT NULL,
        page_url VARCHAR(2000) NOT NULL,
        link_url VARCHAR(2000) NULL,
        link_text VARCHAR(300) NULL,
        link_selector VARCHAR(300) NULL,
        link_id VARCHAR(200) NULL,
        link_class VARCHAR(300) NULL,
        area VARCHAR(20) NULL,
        is_external TINYINT(1) DEFAULT 0,
        x SMALLINT NULL,
        y INT NULL,
        viewport_w SMALLINT NULL,
        viewport_h SMALLINT NULL,
        scroll_y INT NULL,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY clicked_at (clicked_at),
        KEY area (area),
        KEY link_url_prefix (link_url(191)),
        KEY page_url_prefix (page_url(191))
    ) $charset");

    // Events table — generic interaction log. One row per event. event_type
    // varies (button_click, video_play, video_pause, video_complete,
    // slider_click, slider_change, form_submit, scroll_depth, search,
    // tel_intent, mailto_intent, etc.). value + extra are type-specific.
    $events = dsbi_trk_table_events();
    dbDelta("CREATE TABLE $events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT UNSIGNED NOT NULL,
        event_at DATETIME NOT NULL,
        event_type VARCHAR(40) NOT NULL,
        page_url VARCHAR(2000) NOT NULL,
        target_selector VARCHAR(300) NULL,
        target_text VARCHAR(300) NULL,
        target_id VARCHAR(200) NULL,
        target_class VARCHAR(300) NULL,
        target_url VARCHAR(2000) NULL,
        area VARCHAR(20) NULL,
        value VARCHAR(500) NULL,
        extra TEXT NULL,
        x SMALLINT NULL,
        y INT NULL,
        viewport_w SMALLINT NULL,
        viewport_h SMALLINT NULL,
        scroll_y INT NULL,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY event_at (event_at),
        KEY event_type (event_type),
        KEY page_url_prefix (page_url(191))
    ) $charset");

    update_option('dsbi_trk_db_version', DSBI_TRK_DB_VERSION);
}
add_action('plugins_loaded', 'dsbi_trk_maybe_install');


// ──────────────────────────────────────────────────────────────────────────────
// Helpers — UUID, IP, device detect, source classification
// ──────────────────────────────────────────────────────────────────────────────

function dsbi_trk_uuid_v4() {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/**
 * Look up the most recent session row for a given visitor UUID and
 * decide whether to attach to it or open a new "chunk" row.
 *
 * Returns ['session_id' => int, 'session_seq' => int, 'expired' => bool].
 * If the existing row is older than DSBI_TRK_SESSION_TIMEOUT_SEC, callers
 * should INSERT a fresh row with the SAME session_uuid but session_seq+1.
 * If no row exists yet, returns session_id=0 (caller mints fresh).
 *
 * Concurrent-tab safety: this is a read; whichever caller inserts first
 * wins the (session_uuid, session_seq) UNIQUE constraint. If a second
 * caller races on the same seq, its INSERT fails and the caller should
 * fall back to UPDATE on the existing row.
 */
function dsbi_trk_session_lookup($session_uuid) {
    global $wpdb;
    $sessions = dsbi_trk_table_sessions();
    if (!$session_uuid || strlen($session_uuid) !== 36) {
        return ['session_id' => 0, 'session_seq' => 0, 'expired' => false];
    }
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, last_seen_at, session_seq FROM $sessions
         WHERE session_uuid = %s
         ORDER BY id DESC LIMIT 1",
        $session_uuid
    ));
    if (!$row) return ['session_id' => 0, 'session_seq' => 0, 'expired' => false];
    $last = strtotime((string) $row->last_seen_at);
    $expired = ($last && (time() - $last) > DSBI_TRK_SESSION_TIMEOUT_SEC);
    return [
        'session_id'  => (int) $row->id,
        'session_seq' => (int) $row->session_seq,
        'expired'     => $expired,
    ];
}

function dsbi_trk_client_ip() {
    // CF first (we're behind Cloudflare); then standard proxy headers; then REMOTE_ADDR
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            $packed = @inet_pton($ip);
            if ($packed !== false) return $packed;
        }
    }
    return null;
}

function dsbi_trk_device_type($ua) {
    if (!$ua) return null;
    if (preg_match('/iPad|Tablet|Kindle|Silk|PlayBook/i', $ua)) return 'tablet';
    if (preg_match('/Mobile|Android|iPhone|iPod|BlackBerry|Opera Mini|IEMobile/i', $ua)) return 'mobile';
    return 'desktop';
}

/**
 * Classify the traffic source.
 *
 * @return array{0:string,1:?string,2:?string}  [source, aeo_origin, referer_host]
 *   source ∈ {google_ads, google_organic, aeo, social, referral, direct, internal}
 *   aeo_origin is the specific AI host when source=aeo
 *   referer_host is the parsed referer hostname (for all source types)
 */
function dsbi_trk_classify_source($referer, $params) {
    $host = '';
    if ($referer) {
        $host = strtolower((string) (parse_url($referer, PHP_URL_HOST) ?: ''));
    }

    // ── Paid / Google Ads markers (explicit signal beats referer) ──
    if (!empty($params['gclid']) || !empty($params['gad_source'])) {
        return ['google_ads', null, $host ?: null];
    }
    $um = strtolower($params['utm_medium'] ?? '');
    if (in_array($um, ['cpc', 'ppc', 'paid', 'paid-search', 'paidsearch'], true)) {
        return ['google_ads', null, $host ?: null];
    }

    // ── AEO (AI search / answer engines) ──
    $aeo_hosts = [
        'chat.openai.com'      => 'chatgpt',
        'chatgpt.com'          => 'chatgpt',
        'perplexity.ai'        => 'perplexity',
        'www.perplexity.ai'    => 'perplexity',
        'claude.ai'            => 'claude',
        'gemini.google.com'    => 'gemini',
        'bard.google.com'      => 'gemini',
        'copilot.microsoft.com' => 'copilot',
        'you.com'              => 'you',
        'phind.com'            => 'phind',
        'kagi.com'             => 'kagi',
    ];
    foreach ($aeo_hosts as $needle => $label) {
        if ($host === $needle || strpos($host, '.' . $needle) !== false) {
            return ['aeo', $label, $host];
        }
    }

    // ── Google organic search ──
    if (preg_match('#^(www\.|m\.|images\.|news\.|)google\.(com|[a-z]{2,3})(\.[a-z]{2})?$#i', $host)) {
        return ['google_organic', null, $host];
    }

    // ── Other search engines (treat as referral with a note) ──
    if (preg_match('/^(www\.|)(bing\.com|duckduckgo\.com|yahoo\.com|yandex\.ru|baidu\.com|ecosia\.org|brave\.com)$/i', $host)) {
        return ['search', null, $host];
    }

    // ── Social ──
    $social = ['facebook.com', 'fb.com', 'm.facebook.com', 'l.facebook.com', 'twitter.com', 'x.com', 't.co',
        'instagram.com', 'l.instagram.com', 'linkedin.com', 'lnkd.in', 'reddit.com', 'old.reddit.com',
        'youtube.com', 'youtu.be', 'm.youtube.com', 'tiktok.com', 'pinterest.com', 'pin.it',
        'whatsapp.com', 'wa.me', 'telegram.org', 't.me', 'snapchat.com', 'discord.com'];
    foreach ($social as $h) {
        if ($host === $h || strpos($host, '.' . $h) !== false) {
            return ['social', null, $host];
        }
    }

    // ── Internal (same site, e.g. cross-link) ──
    $site_host = parse_url(home_url(), PHP_URL_HOST);
    if ($site_host && $host === $site_host) {
        return ['internal', null, $host];
    }

    // ── No referer → direct ──
    if (!$host) {
        return ['direct', null, null];
    }

    return ['referral', null, $host];
}

function dsbi_trk_should_skip() {
    // Headless requests / admin
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return true;
    if (defined('REST_REQUEST') && REST_REQUEST) return true;
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return true;
    if (function_exists('is_feed') && is_feed()) return true;
    if (function_exists('is_robots') && is_robots()) return true;
    if (function_exists('is_favicon') && is_favicon()) return true;

    // Logged-in admins (capability-based, not role, so editors still count)
    if (is_user_logged_in() && current_user_can('manage_options')) return true;

    // Honor Do Not Track
    if (!empty($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT'] === '1') return true;

    // Skip bots — light filter via UA (won't catch sophisticated ones but
    // covers the obvious crawlers cleanly).
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/bot|crawler|spider|crawling|HeadlessChrome|PhantomJS|Lighthouse|GTmetrix|Pingdom|UptimeRobot|facebookexternalhit/i', $ua)) return true;

    return false;
}


// ──────────────────────────────────────────────────────────────────────────────
// Main hit handler — fires on every page render before output
// ──────────────────────────────────────────────────────────────────────────────

function dsbi_trk_record_hit() {
    if (dsbi_trk_should_skip()) return;

    global $wpdb;
    $sessions  = dsbi_trk_table_sessions();
    $pageviews = dsbi_trk_table_pageviews();

    $now     = current_time('mysql');
    $url     = (is_ssl() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    $title   = function_exists('wp_get_document_title') ? wp_get_document_title() : '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Look up existing session by cookie UUID. The visitor UUID is the
    // STABLE identifier; we open a new (uuid, seq+1) row when the prior
    // chunk has been idle longer than DSBI_TRK_SESSION_TIMEOUT_SEC.
    $session_uuid = isset($_COOKIE[DSBI_TRK_COOKIE]) ? preg_replace('/[^a-f0-9-]/i', '', (string) $_COOKIE[DSBI_TRK_COOKIE]) : '';
    $lookup = dsbi_trk_session_lookup($session_uuid);
    $session_id  = $lookup['expired'] ? 0 : $lookup['session_id'];
    $next_seq    = $lookup['expired'] ? ($lookup['session_seq'] + 1) : 1;

    if (!$session_id) {
        // ── New session row (either brand-new visitor or rotation after gap) ──
        if (!$session_uuid || strlen($session_uuid) !== 36) {
            $session_uuid = dsbi_trk_uuid_v4();
        }

        $params = [];
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?: ''), $params);
        list($source, $aeo_origin, $referer_host) = dsbi_trk_classify_source($referer, $params);

        $wpdb->insert($sessions, [
            'session_uuid'  => $session_uuid,
            'session_seq'   => $next_seq,
            'started_at'    => $now,
            'last_seen_at'  => $now,
            'ip'            => dsbi_trk_client_ip(),
            'user_agent'    => mb_substr($ua, 0, 512),
            'device_type'   => dsbi_trk_device_type($ua),
            'entry_url'     => mb_substr($url, 0, 2000),
            'entry_title'   => mb_substr((string) $title, 0, 500),
            'entry_referer' => mb_substr($referer, 0, 2000),
            'entry_source'  => $source,
            'aeo_origin'    => $aeo_origin,
            'referer_host'  => $referer_host,
            'utm_source'    => isset($params['utm_source'])   ? mb_substr((string)$params['utm_source'], 0, 120)   : null,
            'utm_medium'    => isset($params['utm_medium'])   ? mb_substr((string)$params['utm_medium'], 0, 120)   : null,
            'utm_campaign'  => isset($params['utm_campaign']) ? mb_substr((string)$params['utm_campaign'], 0, 120) : null,
            'utm_content'   => isset($params['utm_content'])  ? mb_substr((string)$params['utm_content'], 0, 120)  : null,
            'utm_term'      => isset($params['utm_term'])     ? mb_substr((string)$params['utm_term'], 0, 120)     : null,
            'gclid'         => isset($params['gclid'])        ? mb_substr((string)$params['gclid'], 0, 200)        : null,
            'gad_source'    => isset($params['gad_source'])   ? mb_substr((string)$params['gad_source'], 0, 40)    : null,
            'pageviews_count' => 1,
            'js_enabled'    => 0,
        ]);
        $session_id = (int) $wpdb->insert_id;

        // Set cookie so subsequent pageviews stitch to this session.
        if (!headers_sent()) {
            $opts = [
                'expires'  => time() + DSBI_TRK_COOKIE_DAYS * DAY_IN_SECONDS,
                'path'     => defined('COOKIEPATH') ? (COOKIEPATH ?: '/') : '/',
                'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => false, // JS needs to read it
                'samesite' => 'Lax',
            ];
            // PHP 7.3+ accepts an options array; we're on 7.4 per CLAUDE.md.
            @setcookie(DSBI_TRK_COOKIE, $session_uuid, $opts);
            $_COOKIE[DSBI_TRK_COOKIE] = $session_uuid; // available within this request
        }
    } else {
        // ── Returning hit on existing session ──
        $wpdb->query($wpdb->prepare(
            "UPDATE $sessions SET pageviews_count = pageviews_count + 1,
                last_seen_at = %s,
                estimated_session_seconds = TIMESTAMPDIFF(SECOND, started_at, %s)
             WHERE id = %d",
            $now, $now, $session_id
        ));
    }

    $wpdb->insert($pageviews, [
        'session_id' => $session_id,
        'viewed_at'  => $now,
        'url'        => mb_substr($url, 0, 2000),
        'title'      => mb_substr((string) $title, 0, 500),
        'referer'    => mb_substr($referer, 0, 2000),
    ]);
}
add_action('template_redirect', 'dsbi_trk_record_hit', 5);


// ──────────────────────────────────────────────────────────────────────────────
// REST endpoint /hit — universal write path
//   * If session UUID is new/missing → creates a session (covers cached-page
//     hits where template_redirect doesn't run because W3TC serves HTML
//     from disk before PHP runs)
//   * If session UUID exists → enriches with JS-side fields (timezone, viewport,
//     js_enabled)
//   * Inserts a pageview row unless an identical one was inserted within the
//     last 10 seconds for the same session+url (dedup vs the PHP path)
// ──────────────────────────────────────────────────────────────────────────────

add_action('rest_api_init', function () {
    register_rest_route('dsbi-tracker/v1', '/hit', [
        'methods'             => 'POST',
        'callback'            => 'dsbi_trk_rest_hit',
        'permission_callback' => '__return_true',
    ]);
    // Back-compat: keep /enrich as an alias (any cached page still calling
    // the older endpoint name will continue to work).
    register_rest_route('dsbi-tracker/v1', '/enrich', [
        'methods'             => 'POST',
        'callback'            => 'dsbi_trk_rest_hit',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('dsbi-tracker/v1', '/click', [
        'methods'             => 'POST',
        'callback'            => 'dsbi_trk_rest_click',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('dsbi-tracker/v1', '/event', [
        'methods'             => 'POST',
        'callback'            => 'dsbi_trk_rest_event',
        'permission_callback' => '__return_true',
    ]);
    // Heatmap data — admin only (used by the overlay UI).
    register_rest_route('dsbi-tracker/v1', '/heatmap', [
        'methods'             => 'GET',
        'callback'            => 'dsbi_trk_rest_heatmap',
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);
});

/**
 * Heatmap data: aggregated click rows for a given page_url.
 * Returns rows sorted by click count desc, each including link_url, link_text,
 * link_selector, average x/y, and click count.
 */
function dsbi_trk_rest_heatmap(\WP_REST_Request $request) {
    global $wpdb;
    $clicks = dsbi_trk_table_clicks();

    $page_url = (string) $request->get_param('page_url');
    if (!$page_url) return ['ok' => false, 'error' => 'missing_page_url'];
    $days = max(1, min(365, (int) ($request->get_param('days') ?? 30)));
    $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

    // Aggregate by link target. Use AVG(x), AVG(y) as a fallback location
    // when we can't find the element in the DOM at render time.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT link_url,
                MAX(link_selector) AS link_selector,
                MAX(link_text) AS link_text,
                MAX(link_id) AS link_id,
                COUNT(*) AS clicks,
                COUNT(DISTINCT session_id) AS unique_sessions,
                ROUND(AVG(x)) AS avg_x,
                ROUND(AVG(y)) AS avg_y,
                ROUND(AVG(viewport_w)) AS avg_vw
         FROM $clicks
         WHERE page_url = %s AND clicked_at >= %s AND link_url IS NOT NULL
         GROUP BY link_url
         ORDER BY clicks DESC
         LIMIT 200",
        $page_url, $since
    ));

    $total_clicks = 0;
    foreach ($rows as $r) $total_clicks += (int) $r->clicks;

    return [
        'ok' => true,
        'page_url' => $page_url,
        'days' => $days,
        'total_clicks' => $total_clicks,
        'rows' => $rows,
    ];
}

function dsbi_trk_rest_hit(\WP_REST_Request $request) {
    global $wpdb;
    $sessions  = dsbi_trk_table_sessions();
    $pageviews = dsbi_trk_table_pageviews();

    // ── Light skip checks (REST runs outside template_redirect's WP context) ──
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!empty($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT'] === '1') {
        return ['ok' => false, 'skip' => 'dnt'];
    }
    if (is_user_logged_in() && current_user_can('manage_options')) {
        return ['ok' => false, 'skip' => 'admin'];
    }
    if (preg_match('/bot|crawler|spider|crawling|HeadlessChrome|PhantomJS|Lighthouse|GTmetrix|Pingdom|UptimeRobot|facebookexternalhit/i', $ua)) {
        return ['ok' => false, 'skip' => 'bot'];
    }

    $body = $request->get_json_params() ?: [];

    $uuid_in = preg_replace('/[^a-f0-9-]/i', '', (string) ($body['session_uuid'] ?? ''));
    if (strlen($uuid_in) !== 36) $uuid_in = '';

    $url     = mb_substr((string) ($body['url']     ?? ''), 0, 2000);
    $title   = mb_substr((string) ($body['title']   ?? ''), 0, 500);
    $referer = mb_substr((string) ($body['referer'] ?? $body['referrer'] ?? ''), 0, 2000);

    $tz      = mb_substr((string) ($body['timezone'] ?? ''), 0, 64);
    $tz_off  = isset($body['tz_offset_min']) ? (int) $body['tz_offset_min'] : null;
    if ($tz_off !== null && ($tz_off < -1440 || $tz_off > 1440)) $tz_off = null;
    $vw      = max(0, min(9999, (int) ($body['vw']  ?? 0)));
    $vh      = max(0, min(9999, (int) ($body['vh']  ?? 0)));
    $dpr     = (float) ($body['dpr'] ?? 0);
    if ($dpr <= 0 || $dpr > 10) $dpr = null;

    $now = current_time('mysql');

    // ── Look up existing session row by visitor uuid (most recent chunk) ──
    $lookup = dsbi_trk_session_lookup($uuid_in);
    $session_id = $lookup['expired'] ? 0 : $lookup['session_id'];
    $next_seq   = $lookup['expired'] ? ($lookup['session_seq'] + 1) : 1;

    if (!$session_id) {
        // ── Create new session row (new visitor OR rotation after 2h gap) ──
        // Same uuid is kept when rotating; only session_seq advances.
        $uuid = $uuid_in ?: dsbi_trk_uuid_v4();

        $params = [];
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?: ''), $params);
        list($source, $aeo_origin, $referer_host) = dsbi_trk_classify_source($referer, $params);

        $wpdb->insert($sessions, [
            'session_uuid'   => $uuid,
            'session_seq'    => $next_seq,
            'started_at'     => $now,
            'last_seen_at'   => $now,
            'ip'             => dsbi_trk_client_ip(),
            'user_agent'     => mb_substr($ua, 0, 512),
            'device_type'    => dsbi_trk_device_type($ua),
            'timezone'       => $tz ?: null,
            'tz_offset_min'  => $tz_off,
            'viewport_w'     => $vw ?: null,
            'viewport_h'     => $vh ?: null,
            'device_pixel_ratio' => $dpr,
            'entry_url'      => $url,
            'entry_title'    => $title,
            'entry_referer'  => $referer,
            'entry_source'   => $source,
            'aeo_origin'     => $aeo_origin,
            'referer_host'   => $referer_host,
            'utm_source'     => isset($params['utm_source'])   ? mb_substr((string)$params['utm_source'], 0, 120)   : null,
            'utm_medium'     => isset($params['utm_medium'])   ? mb_substr((string)$params['utm_medium'], 0, 120)   : null,
            'utm_campaign'   => isset($params['utm_campaign']) ? mb_substr((string)$params['utm_campaign'], 0, 120) : null,
            'utm_content'    => isset($params['utm_content'])  ? mb_substr((string)$params['utm_content'], 0, 120)  : null,
            'utm_term'       => isset($params['utm_term'])     ? mb_substr((string)$params['utm_term'], 0, 120)     : null,
            'gclid'          => isset($params['gclid'])        ? mb_substr((string)$params['gclid'], 0, 200)        : null,
            'gad_source'     => isset($params['gad_source'])   ? mb_substr((string)$params['gad_source'], 0, 40)    : null,
            'pageviews_count' => 1,
            'js_enabled'     => 1,
        ]);
        $session_id = (int) $wpdb->insert_id;

        if ($url) {
            $wpdb->insert($pageviews, [
                'session_id' => $session_id,
                'viewed_at'  => $now,
                'url'        => $url,
                'title'      => $title,
                'referer'    => $referer,
            ]);
        }

        return ['ok' => true, 'created' => true, 'session_uuid' => $uuid];
    }

    // ── Existing session: enrich + maybe insert pageview (dedup vs PHP path) ──
    $update = ['last_seen_at' => $now, 'js_enabled' => 1];
    if ($tz)              $update['timezone']           = $tz;
    if ($tz_off !== null) $update['tz_offset_min']      = $tz_off;
    if ($vw)              $update['viewport_w']         = $vw;
    if ($vh)              $update['viewport_h']         = $vh;
    if ($dpr !== null)    $update['device_pixel_ratio'] = $dpr;
    $wpdb->update($sessions, $update, ['id' => $session_id]);
    // estimated_session_seconds needs a SQL expression (TIMESTAMPDIFF), which
    // wpdb->update can't express. Done as a follow-up query.
    $wpdb->query($wpdb->prepare(
        "UPDATE $sessions SET estimated_session_seconds = TIMESTAMPDIFF(SECOND, started_at, %s) WHERE id = %d",
        $now, $session_id
    ));

    // Pageview dedup: skip insert if same session+url was recorded < 10 sec ago.
    // This handles the case where the PHP template_redirect path already
    // inserted a pageview before the JS endpoint fires.
    if ($url) {
        $dup = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $pageviews
             WHERE session_id = %d AND url = %s
               AND viewed_at >= DATE_SUB(%s, INTERVAL 10 SECOND)",
            $session_id, $url, $now
        ));
        if ($dup === 0) {
            $wpdb->insert($pageviews, [
                'session_id' => $session_id,
                'viewed_at'  => $now,
                'url'        => $url,
                'title'      => $title,
                'referer'    => $referer,
            ]);
            $wpdb->query($wpdb->prepare(
                "UPDATE $sessions SET pageviews_count = pageviews_count + 1 WHERE id = %d",
                $session_id
            ));
        }
    }

    return ['ok' => true, 'created' => false];
}


// ──────────────────────────────────────────────────────────────────────────────
// REST endpoint /click — one row per link click on the site (heatmap source)
// ──────────────────────────────────────────────────────────────────────────────

function dsbi_trk_rest_click(\WP_REST_Request $request) {
    global $wpdb;
    $sessions = dsbi_trk_table_sessions();
    $clicks   = dsbi_trk_table_clicks();

    // Same lightweight skip checks as /hit.
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!empty($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT'] === '1') {
        return ['ok' => false, 'skip' => 'dnt'];
    }
    if (is_user_logged_in() && current_user_can('manage_options')) {
        return ['ok' => false, 'skip' => 'admin'];
    }
    if (preg_match('/bot|crawler|spider|crawling|HeadlessChrome|PhantomJS|Lighthouse|GTmetrix|Pingdom|UptimeRobot|facebookexternalhit/i', $ua)) {
        return ['ok' => false, 'skip' => 'bot'];
    }

    $body = $request->get_json_params() ?: [];

    $uuid = preg_replace('/[^a-f0-9-]/i', '', (string) ($body['session_uuid'] ?? ''));
    if (strlen($uuid) !== 36) return ['ok' => false, 'error' => 'bad_uuid'];

    $session_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $sessions WHERE session_uuid = %s ORDER BY id DESC LIMIT 1", $uuid
    ));
    if (!$session_id) return ['ok' => false, 'error' => 'unknown_session'];

    $page_url      = mb_substr((string) ($body['page_url']      ?? ''), 0, 2000);
    $link_url      = mb_substr((string) ($body['link_url']      ?? ''), 0, 2000);
    $link_text     = mb_substr(trim((string) ($body['link_text'] ?? '')), 0, 300);
    $link_selector = mb_substr((string) ($body['link_selector'] ?? ''), 0, 300);
    $link_id       = mb_substr((string) ($body['link_id']       ?? ''), 0, 200);
    $link_class    = mb_substr((string) ($body['link_class']    ?? ''), 0, 300);
    $area_in       = strtolower((string) ($body['area'] ?? ''));
    $area = in_array($area_in, ['header','nav','footer','sidebar','content','body'], true) ? $area_in : null;

    $x  = isset($body['x'])  ? max(-32768, min(32767, (int) $body['x']))  : null;
    $y  = isset($body['y'])  ? max(0, min(2147483647, (int) $body['y']))  : null;
    $vw = isset($body['vw']) ? max(0, min(9999, (int) $body['vw']))       : null;
    $vh = isset($body['vh']) ? max(0, min(9999, (int) $body['vh']))       : null;
    $sy = isset($body['scroll_y']) ? max(0, min(2147483647, (int) $body['scroll_y'])) : null;

    // External = different host from this site
    $is_external = 0;
    if ($link_url) {
        $lh = strtolower((string) (parse_url($link_url, PHP_URL_HOST) ?: ''));
        $sh = strtolower((string) (parse_url(home_url(), PHP_URL_HOST) ?: ''));
        if ($lh && $sh && $lh !== $sh) $is_external = 1;
    }

    $wpdb->insert($clicks, [
        'session_id'    => $session_id,
        'clicked_at'    => current_time('mysql'),
        'page_url'      => $page_url,
        'link_url'      => $link_url ?: null,
        'link_text'     => $link_text ?: null,
        'link_selector' => $link_selector ?: null,
        'link_id'       => $link_id ?: null,
        'link_class'    => $link_class ?: null,
        'area'          => $area,
        'is_external'   => $is_external,
        'x'             => $x,
        'y'             => $y,
        'viewport_w'    => $vw,
        'viewport_h'    => $vh,
        'scroll_y'      => $sy,
    ]);

    return ['ok' => true];
}


// ──────────────────────────────────────────────────────────────────────────────
// JS footer injection — reads cookie, POSTs hit + sets up click listener
// ──────────────────────────────────────────────────────────────────────────────

add_action('wp_footer', function () {
    if (dsbi_trk_should_skip()) return;
    $hit_url   = esc_url(rest_url('dsbi-tracker/v1/hit'));
    $click_url = esc_url(rest_url('dsbi-tracker/v1/click'));
    $event_url = esc_url(rest_url('dsbi-tracker/v1/event'));
    $cookie    = esc_js(DSBI_TRK_COOKIE);
    $days      = (int) DSBI_TRK_COOKIE_DAYS;
    ?>
<script id="dsbi-tracker-js">
/* DSBI Tracker — first-party session + pageview + click tracking.
   All operations are try/catch'd so this script can never break the page. */
(function () {
  var HIT_URL   = '<?php echo $hit_url; ?>';
  var CLICK_URL = '<?php echo $click_url; ?>';
  var EVENT_URL = '<?php echo $event_url; ?>';
  var COOKIE    = '<?php echo $cookie; ?>';
  var DAYS      = <?php echo $days; ?>;

  // ── cookie / uuid helpers ────────────────────────────────────────────
  function readCookie(n) {
    try {
      var m = document.cookie.match(new RegExp('(?:^|; )' + n.replace(/[.$?*|{}()[\]\\^]/g, '\\$&') + '=([^;]*)'));
      return m ? decodeURIComponent(m[1]) : '';
    } catch (e) { return ''; }
  }
  function setCookie(n, v) {
    try {
      var d = new Date(); d.setTime(d.getTime() + DAYS * 86400000);
      document.cookie = n + '=' + encodeURIComponent(v) + ';expires=' + d.toUTCString() +
        ';path=/;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
    } catch (e) {}
  }
  function uuidv4() {
    try {
      if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
      var b = new Uint8Array(16);
      (window.crypto || window.msCrypto).getRandomValues(b);
      b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
      var h = []; for (var i = 0; i < 16; i++) h.push(('0' + b[i].toString(16)).slice(-2));
      return h[0]+h[1]+h[2]+h[3]+'-'+h[4]+h[5]+'-'+h[6]+h[7]+'-'+h[8]+h[9]+'-'+h[10]+h[11]+h[12]+h[13]+h[14]+h[15];
    } catch (e) {
      // Last-resort low-entropy fallback (still 36 chars, still valid v4 layout)
      return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
      });
    }
  }
  function send(url, data) {
    try {
      var body = JSON.stringify(data);
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: 'application/json' });
        if (navigator.sendBeacon(url, blob)) return true;
      }
      fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: body,
        keepalive: true
      });
      return true;
    } catch (e) { return false; }
  }

  // ── Resolve session UUID — cookie → localStorage → mint ──────────────
  var uuid = readCookie(COOKIE);
  if (!uuid) { try { uuid = (localStorage && localStorage.getItem(COOKIE)) || ''; } catch (e) {} }
  var minted = false;
  if (!uuid) { uuid = uuidv4(); minted = true; }

  // Always set cookie + localStorage (cheap; ensures the cached-page case
  // gets a cookie so subsequent uncached requests stitch to the same session)
  setCookie(COOKIE, uuid);
  try { localStorage.setItem(COOKIE, uuid); } catch (e) {}

  // ── Fire the /hit (creates session if missing, otherwise enriches) ──
  if (!window.__dsbiTrkHit) {
    window.__dsbiTrkHit = 1;
    var tz = '';
    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) {}
    send(HIT_URL, {
      session_uuid:  uuid,
      url:           location.href,
      title:         document.title,
      referer:       document.referrer,
      timezone:      tz,
      tz_offset_min: -new Date().getTimezoneOffset(),
      vw:  window.innerWidth || 0,
      vh:  window.innerHeight || 0,
      dpr: window.devicePixelRatio || 1,
      minted_by_js:  minted ? 1 : 0
    });
  }

  // ── Click tracking — every <a> click anywhere on the page ──────────
  // Uses event delegation; defensive try/catch so a bad selector / weird
  // link never breaks navigation.
  function buildSelector(el) {
    try {
      if (!el || el === document) return '';
      var parts = [];
      var depth = 0;
      while (el && el.nodeType === 1 && depth < 4) {
        var tag = (el.tagName || '').toLowerCase();
        if (el.id) { parts.unshift(tag + '#' + el.id); break; }
        var cls = (el.className && typeof el.className === 'string')
          ? el.className.trim().split(/\s+/).filter(Boolean).slice(0, 2).join('.')
          : '';
        parts.unshift(tag + (cls ? '.' + cls : ''));
        el = el.parentNode; depth++;
      }
      return parts.join(' > ').slice(0, 300);
    } catch (e) { return ''; }
  }

  function detectArea(el) {
    try {
      var node = el;
      while (node && node !== document.body && node.nodeType === 1) {
        var tag = (node.tagName || '').toLowerCase();
        if (tag === 'header')  return 'header';
        if (tag === 'nav')     return 'nav';
        if (tag === 'footer')  return 'footer';
        if (tag === 'aside')   return 'sidebar';
        if (tag === 'main' || tag === 'article') return 'content';
        var role = node.getAttribute && node.getAttribute('role');
        if (role === 'banner')         return 'header';
        if (role === 'navigation')     return 'nav';
        if (role === 'contentinfo')    return 'footer';
        if (role === 'complementary')  return 'sidebar';
        if (role === 'main')           return 'content';
        var cls = (node.className && typeof node.className === 'string') ? node.className : '';
        if (/(^|\s)(menu|nav-menu|navigation|navbar|nav-icons|new_menu)(\s|$|-)/i.test(cls)) return 'nav';
        if (/(^|\s)(footer|site-footer|alt-footer)(\s|$|-)/i.test(cls))                     return 'footer';
        if (/(^|\s)(header|site-header)(\s|$|-)/i.test(cls))                                 return 'header';
        if (/(^|\s)(sidebar|widget-area)(\s|$|-)/i.test(cls))                                return 'sidebar';
        node = node.parentNode;
      }
      return 'body';
    } catch (e) { return null; }
  }

  function fireClickEvent(a, e, source) {
    // Shared payload builder for both click and pointerdown paths
    if (a.__dsbiTrkSent) return; // per-anchor dedup
    a.__dsbiTrkSent = 1;

    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
    if (href.indexOf('/wp-admin/') !== -1) return;

    var cls = '';
    try {
      cls = a.className && typeof a.className === 'string'
        ? a.className.trim().slice(0, 300) : '';
    } catch (e2) {}

    send(CLICK_URL, {
      session_uuid:  uuid,
      page_url:      location.href,
      link_url:      a.href || href,
      link_text:     (a.textContent || a.getAttribute('title') || '').trim().slice(0, 300),
      link_selector: buildSelector(a),
      link_id:       (a.id || '').slice(0, 200),
      link_class:    cls,
      area:          detectArea(a),
      x:             (e.clientX || 0) | 0,
      y:             ((e.clientY || 0) + (window.scrollY || 0)) | 0,
      vw:            window.innerWidth || 0,
      vh:            window.innerHeight || 0,
      scroll_y:      window.scrollY || 0,
      event_source:  source || 'click'
    });
  }

  function onClick(e) {
    try {
      // Only primary-button clicks (left click, no modifier)
      if (e.button && e.button !== 0) return;
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
      var a = e.target && (e.target.closest ? e.target.closest('a') : null);
      if (!a) return;
      fireClickEvent(a, e, 'click');
    } catch (err) { /* never block navigation */ }
  }

  function onPointerDown(e) {
    // Catches tel:/mailto:/sms: taps BEFORE the OS dialer interrupts.
    // The OS handoff on iOS Safari can cancel the click→beacon race so
    // we'd never see those clicks via the normal click handler. pointerdown
    // fires earlier and isn't affected.
    try {
      var a = e.target && (e.target.closest ? e.target.closest('a') : null);
      if (!a) return;
      var href = a.getAttribute('href') || '';
      // Only intercept protocols the OS will hijack:
      if (href.indexOf('tel:')    !== 0 &&
          href.indexOf('mailto:') !== 0 &&
          href.indexOf('sms:')    !== 0) return;
      fireClickEvent(a, e, 'pointerdown');
    } catch (err) {}
  }

  try {
    document.addEventListener('click', onClick, true /* capture so we see clicks even if other handlers stop propagation */);
    document.addEventListener('pointerdown', onPointerDown, true);
  } catch (e) {}

  // ── Generic event tracking ──────────────────────────────────────────
  // Sends a structured event to /event endpoint. Used for video,
  // sliders, buttons, forms, scroll milestones, etc.
  function sendEvent(type, target, value, extra) {
    try {
      var payload = {
        session_uuid: uuid,
        event_type:   type,
        page_url:     location.href,
        value:        value != null ? String(value).slice(0, 500) : null,
        extra:        extra || null,
        vw:           window.innerWidth || 0,
        vh:           window.innerHeight || 0,
        scroll_y:     window.scrollY || 0
      };
      if (target && target.nodeType === 1) {
        payload.target_selector = buildSelector(target);
        payload.target_id       = (target.id || '').slice(0, 200);
        try {
          payload.target_class = (target.className && typeof target.className === 'string')
            ? target.className.trim().slice(0, 300) : '';
        } catch (e) { payload.target_class = ''; }
        payload.target_text = (target.textContent || target.value || target.getAttribute('aria-label') || '').trim().slice(0, 300);
        payload.area = detectArea(target);
        try {
          var r = target.getBoundingClientRect();
          payload.x = (r.left + r.width / 2) | 0;
          payload.y = ((r.top + window.scrollY) + r.height / 2) | 0;
        } catch (e2) {}
      }
      send(EVENT_URL, payload);
    } catch (e) {}
  }

  // ── Button clicks (NOT covered by the <a> click handler) ────────────
  // Captures <button>, [role=button], and form submit buttons.
  try {
    document.addEventListener('click', function (e) {
      try {
        var t = e.target;
        if (!t || !t.closest) return;
        var btn = t.closest('button, [role="button"]');
        if (!btn) return;
        // Skip if it's inside an <a> already tracked by click handler
        if (btn.closest('a')) return;
        // Skip non-meaningful interaction targets (visual-only)
        var type = btn.tagName === 'BUTTON' ? (btn.type || 'button') : 'role-button';
        sendEvent('button_click', btn, type);
      } catch (e2) {}
    }, true);
  } catch (e) {}

  // ── Form submissions ────────────────────────────────────────────────
  try {
    document.addEventListener('submit', function (e) {
      try {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        var action = form.getAttribute('action') || location.href;
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        sendEvent('form_submit', form, action, { method: method, name: form.name || form.id || null });
      } catch (e2) {}
    }, true);
  } catch (e) {}

  // ── HTML5 <video> play / pause / ended ──────────────────────────────
  // Delegates from document so videos added later (via lazy loading,
  // sliders, etc.) are caught too.
  function attachVideoTracking(v) {
    try {
      if (v.__dsbiTrk) return;
      v.__dsbiTrk = 1;
      v.addEventListener('play',  function () { sendEvent('video_play',  v, v.currentSrc || v.src, { duration: v.duration || null, position: v.currentTime }); });
      v.addEventListener('pause', function () {
        // Skip pause events that happen at end of video — 'ended' covers that
        if (v.ended) return;
        sendEvent('video_pause', v, v.currentSrc || v.src, { position: v.currentTime, duration: v.duration || null });
      });
      v.addEventListener('ended', function () { sendEvent('video_complete', v, v.currentSrc || v.src, { duration: v.duration || null }); });
    } catch (e) {}
  }
  try {
    document.querySelectorAll('video').forEach(attachVideoTracking);
    // Catch videos added later (e.g., via lazy-loaded slider)
    var moBody = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes && m.addedNodes.forEach(function (node) {
          if (node.nodeType !== 1) return;
          if (node.tagName === 'VIDEO') attachVideoTracking(node);
          if (node.querySelectorAll) node.querySelectorAll('video').forEach(attachVideoTracking);
        });
      });
    });
    moBody.observe(document.body, { childList: true, subtree: true });
  } catch (e) {}

  // ── Slider / carousel interactions ─────────────────────────────────
  // Heuristic: any click on an element whose class or parent class matches
  // common slider library patterns (bonoboslider, slick, swiper, owl,
  // bx-controls, glide, splide, flickity, royalslider) is recorded as a
  // slider_click event. This is in addition to the link-click tracking.
  var SLIDER_CLASSES = /(bonoboslider|slick-|swiper-|owl-|bx-|glide__|splide__|flickity-|royalSlider|rsArrow|jcarousel)/i;
  function findSliderAncestor(el) {
    var node = el;
    while (node && node !== document.body && node.nodeType === 1) {
      var cls = (node.className && typeof node.className === 'string') ? node.className : '';
      if (SLIDER_CLASSES.test(cls)) return node;
      node = node.parentNode;
    }
    return null;
  }
  try {
    document.addEventListener('click', function (e) {
      try {
        var slider = findSliderAncestor(e.target);
        if (!slider) return;
        // Determine action — next/prev/dot/auto-rotate are common
        var cls = (e.target.className && typeof e.target.className === 'string') ? e.target.className : '';
        var action = 'slide';
        if (/next|forward|right/i.test(cls)) action = 'next';
        else if (/prev|previous|back|left/i.test(cls)) action = 'prev';
        else if (/dot|indicator|page/i.test(cls)) action = 'dot';
        sendEvent('slider_click', e.target, action, {
          slider_class: (slider.className || '').toString().slice(0, 200),
          slider_id:    slider.id || null
        });
      } catch (e2) {}
    }, true);
  } catch (e) {}

  // ── Scroll milestones (25/50/75/100%) — one event per session per page ─
  try {
    var milestones = [25, 50, 75, 100];
    var fired = {};
    function checkScroll() {
      try {
        var doc = document.documentElement;
        var top = window.scrollY || doc.scrollTop || 0;
        var height = doc.scrollHeight - doc.clientHeight;
        if (height <= 0) return;
        var pct = Math.min(100, Math.round((top / height) * 100));
        for (var i = 0; i < milestones.length; i++) {
          var m = milestones[i];
          if (pct >= m && !fired[m]) {
            fired[m] = 1;
            sendEvent('scroll_depth', null, m);
          }
        }
      } catch (e) {}
    }
    var scrollTimer;
    window.addEventListener('scroll', function () {
      clearTimeout(scrollTimer);
      scrollTimer = setTimeout(checkScroll, 250);
    }, { passive: true });
  } catch (e) {}
})();
</script>
    <?php
}, 100);


// ──────────────────────────────────────────────────────────────────────────────
// REST endpoint /event — generic interactions (video play, slider, button,
// form submit, scroll depth). One row per event.
// ──────────────────────────────────────────────────────────────────────────────

function dsbi_trk_rest_event(\WP_REST_Request $request) {
    global $wpdb;
    $sessions = dsbi_trk_table_sessions();
    $events   = dsbi_trk_table_events();

    // Skip checks (same shape as /click)
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!empty($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT'] === '1') {
        return ['ok' => false, 'skip' => 'dnt'];
    }
    if (is_user_logged_in() && current_user_can('manage_options')) {
        return ['ok' => false, 'skip' => 'admin'];
    }
    if (preg_match('/bot|crawler|spider|crawling|HeadlessChrome|PhantomJS|Lighthouse|GTmetrix|Pingdom|UptimeRobot|facebookexternalhit/i', $ua)) {
        return ['ok' => false, 'skip' => 'bot'];
    }

    $body = $request->get_json_params() ?: [];

    $uuid = preg_replace('/[^a-f0-9-]/i', '', (string) ($body['session_uuid'] ?? ''));
    if (strlen($uuid) !== 36) return ['ok' => false, 'error' => 'bad_uuid'];

    $session_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $sessions WHERE session_uuid = %s ORDER BY id DESC LIMIT 1", $uuid
    ));
    if (!$session_id) return ['ok' => false, 'error' => 'unknown_session'];

    $event_type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($body['event_type'] ?? '')));
    if (!$event_type) return ['ok' => false, 'error' => 'missing_event_type'];
    $event_type = substr($event_type, 0, 40);

    $area_in = strtolower((string) ($body['area'] ?? ''));
    $area = in_array($area_in, ['header','nav','footer','sidebar','content','body'], true) ? $area_in : null;

    // extra: serialise object → JSON string, capped at 2000 chars
    $extra = $body['extra'] ?? null;
    if (is_array($extra) || is_object($extra)) {
        $extra = wp_json_encode($extra);
    }
    $extra = $extra ? mb_substr((string) $extra, 0, 2000) : null;

    $x  = isset($body['x'])        ? max(-32768, min(32767, (int) $body['x']))   : null;
    $y  = isset($body['y'])        ? max(0, min(2147483647, (int) $body['y']))   : null;
    $vw = isset($body['vw'])       ? max(0, min(9999, (int) $body['vw']))        : null;
    $vh = isset($body['vh'])       ? max(0, min(9999, (int) $body['vh']))        : null;
    $sy = isset($body['scroll_y']) ? max(0, min(2147483647, (int) $body['scroll_y'])) : null;

    $wpdb->insert($events, [
        'session_id'      => $session_id,
        'event_at'        => current_time('mysql'),
        'event_type'      => $event_type,
        'page_url'        => mb_substr((string) ($body['page_url']        ?? ''), 0, 2000),
        'target_selector' => mb_substr((string) ($body['target_selector'] ?? ''), 0, 300)  ?: null,
        'target_text'     => mb_substr(trim((string) ($body['target_text'] ?? '')), 0, 300) ?: null,
        'target_id'       => mb_substr((string) ($body['target_id']       ?? ''), 0, 200)  ?: null,
        'target_class'    => mb_substr((string) ($body['target_class']    ?? ''), 0, 300)  ?: null,
        'target_url'      => mb_substr((string) ($body['target_url']      ?? ''), 0, 2000) ?: null,
        'area'            => $area,
        'value'           => mb_substr((string) ($body['value']           ?? ''), 0, 500)  ?: null,
        'extra'           => $extra,
        'x'               => $x,
        'y'               => $y,
        'viewport_w'      => $vw,
        'viewport_h'      => $vh,
        'scroll_y'        => $sy,
    ]);

    return ['ok' => true];
}


// ──────────────────────────────────────────────────────────────────────────────
// Bot detection / session classification
//
// Runs every 15 min via wp-cron. For each unclassified or stale session,
// computes a confidence_score (0-100; 100 = high-confidence human) plus a
// primary bot_reason_id and full bot_reasons list. is_bot_detected is the
// derived bool (confidence < 30).
//
// Reason codes (bot_reason_id):
//   ua_blacklist     — UA matches known crawler/scanner patterns we missed at intake
//   ua_typo          — UA contains misspellings ("Mozlila", "Bulid") = fake browser
//   no_cookie_burst  — same IP+UA has > 5 sessions in last 24h (cookies not preserved)
//   ip_burst         — same IP > 10 sessions in last 1h
//   no_engagement    — > 30 min old, only 1 pageview, no clicks/events
//   clean            — passed all checks, looks human
//   probable         — middle ground, no strong bot signals but no engagement either
// ──────────────────────────────────────────────────────────────────────────────

const DSBI_TRK_BOT_THRESHOLD = 30; // confidence below this = is_bot_detected=1

function dsbi_trk_classify_sessions($limit = 500) {
    global $wpdb;
    $sessions  = dsbi_trk_table_sessions();
    $clicks    = dsbi_trk_table_clicks();
    $events    = dsbi_trk_table_events();

    // Candidates: sessions that have never been classified, OR were classified
    // before they aged enough to be evaluated properly (re-evaluate sessions
    // that were < 30 min old at first classification).
    //
    // SKIP sessions that the sister `dsbi-datacenter-detect.php` cascade
    // has already flagged with a definitive tag (`bot:…` or `datacenter:…`
    // prefix in `bot_reasons`). Those flags are hard rules — wp-login
    // scanners, rotating-proxy fingerprints, viewport=0 headless tools, IP
    // in a known cloud-provider CIDR range, etc. Re-evaluating them with
    // the score model would inappropriately un-flag them when the +click
    // / +event / +multi_pv / +js bonuses push the score above 30 (which
    // happened: ~290 bot:wp-login-scanner sessions got un-flagged after
    // first apply on 2026-05-30 because they had click events recorded).
    //
    // The cascade owns those sessions; the score classifier owns the rest.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, session_uuid, ip, user_agent, started_at, last_seen_at,
                pageviews_count, js_enabled, entry_source, classified_at
         FROM $sessions
         WHERE (classified_at IS NULL OR classified_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
           AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           AND (bot_reasons IS NULL
                OR (bot_reasons NOT LIKE %s
                    AND bot_reasons NOT LIKE %s))
         ORDER BY id DESC LIMIT %d",
        '%bot:%', '%datacenter:%', $limit
    ));
    if (!$rows) return ['classified' => 0];

    // Pre-compute IP+UA frequency over last 24h (single query, much faster
    // than N+1).
    // NB: MySQL HEX() returns UPPERCASE, PHP bin2hex() returns lowercase.
    // Normalize to lowercase on both sides so the lookup keys match.
    $ipua_24h = [];
    $freq = $wpdb->get_results(
        "SELECT LOWER(HEX(ip)) AS ip_hex, user_agent, COUNT(*) AS n
         FROM $sessions
         WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         GROUP BY ip, user_agent"
    );
    foreach ($freq as $f) {
        $ipua_24h[$f->ip_hex . '|' . $f->user_agent] = (int) $f->n;
    }

    // Same for IP-only over last 1h (catches rotating-UA bots).
    $ip_1h = [];
    $freq2 = $wpdb->get_results(
        "SELECT LOWER(HEX(ip)) AS ip_hex, COUNT(*) AS n
         FROM $sessions
         WHERE started_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
         GROUP BY ip"
    );
    foreach ($freq2 as $f) {
        $ip_1h[$f->ip_hex] = (int) $f->n;
    }

    // Sessions with any click — these get a big confidence boost.
    $sids_with_click = [];
    $cr = $wpdb->get_col("SELECT DISTINCT session_id FROM $clicks");
    foreach ($cr as $sid) $sids_with_click[(int) $sid] = 1;
    $sids_with_event = [];
    $er = $wpdb->get_col("SELECT DISTINCT session_id FROM $events");
    foreach ($er as $sid) $sids_with_event[(int) $sid] = 1;

    // Pre-load ISP/org info from the geo cache for fast datacenter-IP
    // detection. Sessions whose IP hasn't been enriched yet just don't
    // get the datacenter signal — the geo cron will catch up.
    $ip_geo = dsbi_trk_table_ip_geo();
    $isp_by_ip = [];
    $isp_rows = $wpdb->get_results(
        "SELECT LOWER(HEX(ip)) AS ip_hex, isp, org_name FROM $ip_geo WHERE lookup_status = 'success'"
    );
    foreach ($isp_rows as $g) {
        $isp_by_ip[$g->ip_hex] = trim($g->isp . ' ' . $g->org_name);
    }
    // Datacenter / hosting ISPs we want to flag. Conservative on generic
    // words ("server") that might appear in residential ISP names.
    $dc_pattern = '/\b(' .
        'amazon|aws|google cloud|google llc|microsoft.*(corp|azure)|azure|' .
        'digitalocean|linode|vultr|hetzner|ovh|tencent|alibaba|leaseweb|' .
        'rackspace|datapacket|colocation|datacenter|data\s+center|' .
        'purevoltage|offshore lc|global transit|atlas-lease|shenzhen tencent|' .
        'choopa|m247|kaopu|hostinger|hivelocity|psychz|virmach|' .
        'cogentco|nforce|host\s*sailor|nexril' .
        ')\b/i';

    $now    = time();
    $count  = 0;

    foreach ($rows as $r) {
        $score   = 50;
        $reasons = [];
        $primary = null;

        $ua_lc = strtolower((string) $r->user_agent);

        // ── ua_blacklist ─────────────────────────────────────────
        if (preg_match('/bot|crawler|spider|scraper|scan|wget|curl|python|java\/|libwww|httpclient|headless/i', $r->user_agent ?? '')) {
            $score -= 60; $reasons[] = 'ua_blacklist'; $primary = $primary ?: 'ua_blacklist';
        }
        // ── ua_typo (fake browsers) ──────────────────────────────
        if (preg_match('/Mozlila|Bulid|Mozila|Compatibl[^e]|MSIE 6\.0;.*Trident/', $r->user_agent ?? '')) {
            $score -= 50; $reasons[] = 'ua_typo'; $primary = $primary ?: 'ua_typo';
        }
        // ── no_cookie_burst ─────────────────────────────────────
        $ipua_key = bin2hex((string) $r->ip) . '|' . $r->user_agent;
        $ipua_n = $ipua_24h[$ipua_key] ?? 0;
        if ($ipua_n >= 5) {
            $penalty = min(40, $ipua_n * 4);
            $score -= $penalty;
            $reasons[] = 'no_cookie_burst:' . $ipua_n;
            $primary = $primary ?: 'no_cookie_burst';
        }
        // ── ip_burst ─────────────────────────────────────────────
        $ip_n = $ip_1h[bin2hex((string) $r->ip)] ?? 0;
        if ($ip_n >= 10) {
            $penalty = min(30, $ip_n * 2);
            $score -= $penalty;
            $reasons[] = 'ip_burst:' . $ip_n;
            $primary = $primary ?: 'ip_burst';
        }
        // ── datacenter_ip (ISP/org matches known hosting providers) ─
        $isp_str = $isp_by_ip[bin2hex((string) $r->ip)] ?? '';
        if ($isp_str && preg_match($dc_pattern, $isp_str)) {
            $score -= 40;
            $reasons[] = 'datacenter_ip';
            $primary = $primary ?: 'datacenter_ip';
        }
        // ── no_engagement (old + no interaction) ─────────────────
        $age_min = (int) ((strtotime((string) $r->last_seen_at) - strtotime((string) $r->started_at)) / 60);
        $stale_min = (int) (($now - strtotime((string) $r->started_at)) / 60);
        $has_click = isset($sids_with_click[(int) $r->id]);
        $has_event = isset($sids_with_event[(int) $r->id]);

        if ($stale_min > 30 && (int) $r->pageviews_count <= 1 && !$has_click && !$has_event) {
            $score -= 15;
            $reasons[] = 'no_engagement';
            $primary = $primary ?: 'no_engagement';
        }

        // ── Positive signals (real human behavior) ───────────────
        if ($has_click) { $score += 50; $reasons[] = '+click'; }
        if ($has_event) { $score += 25; $reasons[] = '+event'; }
        if ((int) $r->pageviews_count >= 3) { $score += 15; $reasons[] = '+multi_pv'; }
        if ((int) $r->js_enabled === 1) { $score += 15; $reasons[] = '+js'; }

        // Clamp
        if ($score < 0)   $score = 0;
        if ($score > 100) $score = 100;

        if (!$primary) {
            $primary = ($score >= DSBI_TRK_BOT_THRESHOLD) ? 'clean' : 'probable';
        }
        $is_bot = ($score < DSBI_TRK_BOT_THRESHOLD) ? 1 : 0;

        $wpdb->update($sessions, [
            'is_bot_detected'  => $is_bot,
            'confidence_score' => $score,
            'bot_reason_id'    => mb_substr($primary, 0, 40),
            'bot_reasons'      => mb_substr(implode(',', $reasons), 0, 255),
            'classified_at'    => current_time('mysql'),
        ], ['id' => (int) $r->id]);
        $count++;
    }

    return ['classified' => $count];
}

// Schedule via wp-cron — runs every 15 min.
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['fifteen_minutes'])) {
        $schedules['fifteen_minutes'] = ['interval' => 900, 'display' => 'Every 15 min'];
    }
    return $schedules;
});
add_action('dsbi_trk_classify_cron', 'dsbi_trk_classify_sessions');
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('dsbi_trk_classify_cron')) {
        wp_schedule_event(time() + 60, 'fifteen_minutes', 'dsbi_trk_classify_cron');
    }
});
add_action('plugins_loaded', function () {
    if (!wp_next_scheduled('dsbi_trk_classify_cron')) {
        wp_schedule_event(time() + 60, 'fifteen_minutes', 'dsbi_trk_classify_cron');
    }
});


// ──────────────────────────────────────────────────────────────────────────────
// IP geolocation enrichment — back-fill city / region / country on sessions
//
// Free service: ip-api.com (45 req/min, no key). Cached per-IP in a local
// table so subsequent visits from the same IP cost zero network calls.
// Bots are SKIPPED (is_bot_detected = 1) — no point burning the rate-limit
// budget on the 95% of traffic we already know is automated.
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Look up a single IP. Cache-first; on miss, hits the free ip-api endpoint
 * and stores the result (success or failure) so we won't re-query soon.
 * Returns the cached row as an associative array, or null on hard failure.
 */
function dsbi_trk_geo_lookup($ip_packed) {
    global $wpdb;
    if (!$ip_packed) return null;
    $cache = dsbi_trk_table_ip_geo();

    // Cache check — return immediately if we've ever looked this up.
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $cache WHERE ip = %s", $ip_packed
    ), ARRAY_A);
    if ($row) return $row;

    $ip_text = inet_ntop($ip_packed);
    if (!$ip_text) return null;

    // Don't burn lookups on private / loopback / reserved ranges.
    $is_public = filter_var(
        $ip_text,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
    if (!$is_public) {
        $wpdb->insert($cache, [
            'ip' => $ip_packed, 'ip_text' => $ip_text,
            'lookup_status' => 'private', 'lookup_at' => current_time('mysql'),
        ]);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $cache WHERE ip = %s", $ip_packed), ARRAY_A);
    }

    $url = DSBI_TRK_GEO_ENDPOINT . rawurlencode($ip_text)
        . '?fields=' . rawurlencode(DSBI_TRK_GEO_FIELDS);

    $resp = wp_remote_get($url, [
        'timeout'    => 5,
        'user-agent' => 'dsbi-tracker/1.6 (+https://drsusanblockinstitute.com)',
    ]);

    if (is_wp_error($resp)) {
        $wpdb->insert($cache, [
            'ip' => $ip_packed, 'ip_text' => $ip_text,
            'lookup_status' => 'http_error',
            'lookup_error'  => mb_substr($resp->get_error_message(), 0, 200),
            'lookup_at'     => current_time('mysql'),
        ]);
        return null;
    }

    $body = wp_remote_retrieve_body($resp);
    $data = json_decode($body, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        $wpdb->insert($cache, [
            'ip' => $ip_packed, 'ip_text' => $ip_text,
            'lookup_status' => 'api_error',
            'lookup_error'  => mb_substr((string) ($data['message'] ?? 'unknown'), 0, 200),
            'lookup_at'     => current_time('mysql'),
        ]);
        return null;
    }

    $cache_row = [
        'ip'            => $ip_packed,
        'ip_text'       => $ip_text,
        'city'          => mb_substr((string) ($data['city']        ?? ''), 0, 120) ?: null,
        'region'        => mb_substr((string) ($data['regionName']  ?? ''), 0, 120) ?: null,
        'region_code'   => mb_substr((string) ($data['region']      ?? ''), 0, 10)  ?: null,
        'country'       => mb_substr((string) ($data['country']     ?? ''), 0, 120) ?: null,
        'country_code'  => mb_substr((string) ($data['countryCode'] ?? ''), 0, 2)   ?: null,
        'zip'           => mb_substr((string) ($data['zip']         ?? ''), 0, 20)  ?: null,
        'lat'           => isset($data['lat']) ? (float) $data['lat'] : null,
        'lon'           => isset($data['lon']) ? (float) $data['lon'] : null,
        'isp'           => mb_substr((string) ($data['isp']         ?? ''), 0, 200) ?: null,
        'org_name'      => mb_substr((string) ($data['org']         ?? ''), 0, 200) ?: null,
        'lookup_status' => 'success',
        'lookup_at'     => current_time('mysql'),
    ];
    $wpdb->insert($cache, $cache_row);
    return $cache_row;
}

/**
 * Back-fill the city / region / country columns on sessions that don't
 * have them yet. Walks at most DSBI_TRK_GEO_BATCH sessions per call.
 * Skips bot-flagged sessions and rows without an IP. Cache lookups are
 * free; only cache misses count against the rate-limit budget.
 */
function dsbi_trk_geo_enrich_sessions($limit = null) {
    global $wpdb;
    $sessions = dsbi_trk_table_sessions();
    $limit = (int) ($limit ?? DSBI_TRK_GEO_BATCH);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, ip FROM $sessions
         WHERE geo_lookup_at IS NULL
           AND ip IS NOT NULL
           AND (is_bot_detected IS NULL OR is_bot_detected = 0)
           AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         ORDER BY id DESC LIMIT %d", $limit
    ));
    if (!$rows) return ['enriched' => 0, 'misses' => 0];

    $enriched = 0;
    $misses   = 0;
    $now = current_time('mysql');

    foreach ($rows as $r) {
        // Cache check first — counts as a miss only if we actually call out.
        $cache = dsbi_trk_table_ip_geo();
        $cached = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $cache WHERE ip = %s", $r->ip
        ), ARRAY_A);

        if (!$cached) {
            $cached = dsbi_trk_geo_lookup($r->ip);
            $misses++;
            // Throttle external API calls only — cached hits don't sleep.
            if ($misses < $limit) {
                usleep(DSBI_TRK_GEO_THROTTLE_MS * 1000);
            }
        }

        // Even if the lookup ultimately failed, mark the session so we
        // don't keep retrying. The cache row records the failure reason.
        $update = ['geo_lookup_at' => $now];
        if ($cached && ($cached['lookup_status'] ?? '') === 'success') {
            $update['city']         = $cached['city'];
            $update['region']       = $cached['region'];
            $update['region_code']  = $cached['region_code'];
            $update['country']      = $cached['country'];
            $update['country_code'] = $cached['country_code'];
        }
        $wpdb->update($sessions, $update, ['id' => (int) $r->id]);
        $enriched++;
    }
    return ['enriched' => $enriched, 'misses' => $misses];
}

add_action('dsbi_trk_geo_cron', 'dsbi_trk_geo_enrich_sessions');
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('dsbi_trk_geo_cron')) {
        wp_schedule_event(time() + 120, 'fifteen_minutes', 'dsbi_trk_geo_cron');
    }
});
add_action('plugins_loaded', function () {
    if (!wp_next_scheduled('dsbi_trk_geo_cron')) {
        wp_schedule_event(time() + 120, 'fifteen_minutes', 'dsbi_trk_geo_cron');
    }
});


// ──────────────────────────────────────────────────────────────────────────────
// Heatmap overlay — admin only, opt-in via ?dsbi_heatmap=1 query param
// Fetches aggregated clicks for the current URL and draws a heatmap on top
// of the live page. Auto-positions dots over the matched <a> elements.
// ──────────────────────────────────────────────────────────────────────────────

add_action('wp_footer', function () {
    if (!current_user_can('manage_options')) return;
    if (empty($_GET['dsbi_heatmap'])) return;
    $rest_url = esc_url(rest_url('dsbi-tracker/v1/heatmap'));
    $nonce    = wp_create_nonce('wp_rest');
    ?>
<style id="dsbi-heatmap-style">
  .__dsbi-heat {
    position: absolute;
    pointer-events: none;
    border-radius: 50%;
    z-index: 99998;
    transition: opacity 200ms;
  }
  .__dsbi-heat-num {
    position: absolute;
    pointer-events: none;
    background: #1f2937;
    color: #fff;
    font: bold 11px ui-monospace, monospace;
    padding: 2px 6px;
    border-radius: 99px;
    transform: translate(-50%, -50%);
    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
    z-index: 99999;
    white-space: nowrap;
  }
  #__dsbi-heat-legend {
    position: fixed;
    top: 12px;
    right: 12px;
    background: #1a202c;
    color: #e2e8f0;
    padding: 14px 16px;
    border-radius: 10px;
    font: 12px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    z-index: 100000;
    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
    max-width: 320px;
  }
  #__dsbi-heat-legend h4 { margin: 0 0 8px; color: #fff; font-size: 13px; }
  #__dsbi-heat-legend .stat { color: #94a3b8; font-size: 11px; margin: 2px 0; }
  #__dsbi-heat-legend .stat strong { color: #f1f5f9; }
  #__dsbi-heat-legend select, #__dsbi-heat-legend a {
    background: #2d3748; color: #fff; border: 1px solid #4a5568;
    padding: 4px 8px; border-radius: 4px; font-size: 11px;
    text-decoration: none; cursor: pointer;
  }
  #__dsbi-heat-legend .row { margin-top: 8px; display: flex; gap: 6px; align-items: center; }
  #__dsbi-heat-list { margin: 10px 0 0; padding: 8px 0 0; border-top: 1px solid rgba(255,255,255,0.1); max-height: 240px; overflow-y: auto; font-size: 11px; }
  #__dsbi-heat-list .item { display: flex; justify-content: space-between; padding: 3px 0; color: #cbd5e0; }
  #__dsbi-heat-list .item strong { color: #f1f5f9; }
</style>
<script id="dsbi-heatmap-js">
(function () {
  var REST = '<?php echo $rest_url; ?>';
  var pageUrl = location.href.split('?')[0].split('#')[0];
  var days = parseInt(new URLSearchParams(location.search).get('dsbi_heatmap_days') || '30', 10);

  function fmt(n) { return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function clear() {
    document.querySelectorAll('.__dsbi-heat, .__dsbi-heat-num').forEach(function (n) { n.remove(); });
  }

  function findElement(row) {
    // 1. Try by link URL (most reliable)
    if (row.link_url) {
      var anchors = document.querySelectorAll('a[href]');
      for (var i = 0; i < anchors.length; i++) {
        if (anchors[i].href === row.link_url) return anchors[i];
      }
    }
    // 2. Try by saved selector
    if (row.link_selector) {
      try {
        var el = document.querySelector(row.link_selector);
        if (el) return el;
      } catch (e) {}
    }
    // 3. Try by id
    if (row.link_id) {
      var byId = document.getElementById(row.link_id);
      if (byId) return byId;
    }
    return null;
  }

  function render(rows, total) {
    clear();
    if (!rows || !rows.length) return;
    var max = rows[0].clicks;
    var rendered = 0, missed = 0;

    rows.forEach(function (row) {
      var clicks = parseInt(row.clicks, 10);
      var intensity = max ? clicks / max : 0;

      var el = findElement(row);
      var x, y, w = 50, h = 50;
      if (el) {
        var r = el.getBoundingClientRect();
        x = r.left + window.scrollX + r.width / 2;
        y = r.top + window.scrollY + r.height / 2;
        w = h = Math.max(40, Math.min(160, 30 + clicks * 6));
        rendered++;
      } else if (row.avg_x && row.avg_y) {
        x = parseFloat(row.avg_x);
        y = parseFloat(row.avg_y);
        w = h = Math.max(40, 30 + clicks * 4);
        missed++;
      } else {
        return;
      }

      var dot = document.createElement('div');
      dot.className = '__dsbi-heat';
      var size = w;
      dot.style.left   = (x - size / 2) + 'px';
      dot.style.top    = (y - size / 2) + 'px';
      dot.style.width  = size + 'px';
      dot.style.height = size + 'px';
      // Color ramp: cold (blue) → warm (red) by intensity
      var hue = Math.round(240 - (240 * intensity)); // 240=blue → 0=red
      dot.style.background = 'radial-gradient(circle, hsla(' + hue + ',100%,50%,' + (0.55 * intensity + 0.15) + ') 0%, hsla(' + hue + ',100%,50%,0) 70%)';
      dot.title = clicks + ' clicks · ' + (row.link_text || row.link_url || '');
      document.body.appendChild(dot);

      var num = document.createElement('div');
      num.className = '__dsbi-heat-num';
      num.style.left = x + 'px';
      num.style.top  = y + 'px';
      num.textContent = clicks;
      document.body.appendChild(num);
    });

    var legend = document.getElementById('__dsbi-heat-legend');
    if (legend) {
      legend.querySelector('.stat-rendered').innerHTML = '<strong>' + rendered + '</strong> placed on element, <strong>' + missed + '</strong> by saved x/y';
    }
  }

  function load() {
    var url = REST + '?page_url=' + encodeURIComponent(pageUrl) + '&days=' + days;
    fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': '<?php echo esc_js($nonce); ?>' }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) {
          document.getElementById('__dsbi-heat-status').textContent = 'No data';
          return;
        }
        var legend = document.getElementById('__dsbi-heat-legend');
        legend.querySelector('.stat-total').innerHTML = '<strong>' + fmt(d.total_clicks) + '</strong> clicks · last <strong>' + d.days + '</strong> days';
        legend.querySelector('.stat-links').innerHTML = '<strong>' + d.rows.length + '</strong> distinct link targets';
        var list = document.getElementById('__dsbi-heat-list');
        list.innerHTML = '';
        d.rows.slice(0, 30).forEach(function (r) {
          var item = document.createElement('div');
          item.className = 'item';
          var label = (r.link_text || (r.link_url ? r.link_url.split('/').slice(3).join('/').slice(0, 40) : '?'));
          item.innerHTML = '<span>' + label.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</span><strong>' + r.clicks + '</strong>';
          list.appendChild(item);
        });
        render(d.rows, d.total_clicks);
      })
      .catch(function () {
        document.getElementById('__dsbi-heat-status').textContent = 'Error loading';
      });
  }

  // Build legend UI
  var legend = document.createElement('div');
  legend.id = '__dsbi-heat-legend';
  legend.innerHTML =
    '<h4>🔥 DSBI Heatmap</h4>' +
    '<div class="stat stat-total">loading…</div>' +
    '<div class="stat stat-links"></div>' +
    '<div class="stat stat-rendered"></div>' +
    '<div class="stat" id="__dsbi-heat-status"></div>' +
    '<div class="row">' +
      '<select id="__dsbi-heat-days">' +
        '<option value="1">last 1d</option>' +
        '<option value="7">last 7d</option>' +
        '<option value="30" selected>last 30d</option>' +
        '<option value="90">last 90d</option>' +
      '</select>' +
      '<a href="' + location.pathname + location.hash + '">Exit</a>' +
    '</div>' +
    '<div id="__dsbi-heat-list"></div>';
  document.body.appendChild(legend);
  legend.querySelector('#__dsbi-heat-days').value = String(days);
  legend.querySelector('#__dsbi-heat-days').addEventListener('change', function (e) {
    days = parseInt(e.target.value, 10) || 30;
    load();
  });

  // Re-render dots on resize/scroll (positions follow elements)
  var rerender;
  window.addEventListener('resize', function () { clearTimeout(rerender); rerender = setTimeout(load, 200); });

  load();
})();
</script>
    <?php
}, 110);


// ──────────────────────────────────────────────────────────────────────────────
// Admin page — Tools → DSBI Tracker
// ──────────────────────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_management_page(
        'DSBI Tracker',
        'DSBI Tracker',
        'manage_options',
        'dsbi-tracker',
        'dsbi_trk_render_admin'
    );
});

function dsbi_trk_render_admin() {
    global $wpdb;
    $sessions  = dsbi_trk_table_sessions();
    $pageviews = dsbi_trk_table_pageviews();

    // Time window (default last 7 days)
    $days = max(1, min(90, (int) ($_GET['days'] ?? 7)));
    $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

    $totals = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COUNT(*) AS sessions_count,
            SUM(pageviews_count) AS pageviews_total,
            SUM(CASE WHEN pageviews_count = 1
                     AND TIMESTAMPDIFF(MINUTE, last_seen_at, NOW()) >= %d
                     THEN 1 ELSE 0 END) AS bounces
         FROM $sessions
         WHERE started_at >= %s",
        DSBI_TRK_BOUNCE_AFTER_MIN, $since
    ));

    $bounce_rate = ($totals && $totals->sessions_count > 0)
        ? round(100 * $totals->bounces / $totals->sessions_count, 1)
        : 0;

    $by_source = $wpdb->get_results($wpdb->prepare(
        "SELECT entry_source, COUNT(*) AS n,
                SUM(pageviews_count) AS pv,
                SUM(CASE WHEN pageviews_count = 1 THEN 1 ELSE 0 END) AS bounces
         FROM $sessions WHERE started_at >= %s
         GROUP BY entry_source ORDER BY n DESC",
        $since
    ));

    $by_aeo = $wpdb->get_results($wpdb->prepare(
        "SELECT aeo_origin, COUNT(*) AS n FROM $sessions
         WHERE started_at >= %s AND aeo_origin IS NOT NULL
         GROUP BY aeo_origin ORDER BY n DESC",
        $since
    ));

    $by_device = $wpdb->get_results($wpdb->prepare(
        "SELECT device_type, COUNT(*) AS n FROM $sessions
         WHERE started_at >= %s GROUP BY device_type ORDER BY n DESC",
        $since
    ));

    $top_entries = $wpdb->get_results($wpdb->prepare(
        "SELECT entry_url, COUNT(*) AS n FROM $sessions
         WHERE started_at >= %s
         GROUP BY entry_url ORDER BY n DESC LIMIT 20",
        $since
    ));

    $recent = $wpdb->get_results($wpdb->prepare(
        "SELECT id, session_uuid, started_at, last_seen_at, pageviews_count,
                entry_source, aeo_origin, referer_host, device_type, timezone,
                entry_url, ip, js_enabled
         FROM $sessions WHERE started_at >= %s
         ORDER BY started_at DESC LIMIT 50",
        $since
    ));

    // Click tracking — top clicked links + recent clicks
    $clicks_tbl = dsbi_trk_table_clicks();
    $top_clicks = $wpdb->get_results($wpdb->prepare(
        "SELECT link_url,
                MAX(link_text) AS link_text,
                COUNT(*) AS clicks,
                COUNT(DISTINCT session_id) AS unique_sessions,
                SUM(is_external) AS external_count
         FROM $clicks_tbl
         WHERE clicked_at >= %s AND link_url IS NOT NULL
         GROUP BY link_url
         ORDER BY clicks DESC
         LIMIT 25",
        $since
    ));
    $clicks_total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $clicks_tbl WHERE clicked_at >= %s", $since
    ));

    // Clicks grouped by area (header / nav / footer / etc) — answers
    // "which menu/area gets the most clicks?"
    $clicks_by_area = $wpdb->get_results($wpdb->prepare(
        "SELECT COALESCE(area, '(unknown)') AS area, COUNT(*) AS clicks
         FROM $clicks_tbl WHERE clicked_at >= %s
         GROUP BY area ORDER BY clicks DESC",
        $since
    ));

    // Events table summary (video plays, slider clicks, button clicks, etc.)
    $events_tbl = dsbi_trk_table_events();
    $events_by_type = $wpdb->get_results($wpdb->prepare(
        "SELECT event_type, COUNT(*) AS n, COUNT(DISTINCT session_id) AS unique_sessions
         FROM $events_tbl WHERE event_at >= %s
         GROUP BY event_type ORDER BY n DESC",
        $since
    ));
    $events_total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $events_tbl WHERE event_at >= %s", $since
    ));

    // ── Google Ads ROI ─────────────────────────────────────────────────
    // Funnel: ad click → landing page → phone tap. Campaign + keyword are
    // most reliably read from the landing-page URL itself (vt_campaign /
    // vt_keyword), since utm_campaign is often blank in the ad templates.
    // We aggregate by gad_campaignid extracted from the entry_url, falling
    // back to utm_campaign if absent.
    $ads_totals = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COUNT(DISTINCT s.id) AS sessions,
            COUNT(DISTINCT CASE WHEN c.link_url LIKE 'tel:%%' THEN s.id END) AS sessions_with_tap,
            SUM(CASE WHEN c.link_url LIKE 'tel:%%' THEN 1 ELSE 0 END) AS total_taps,
            COUNT(DISTINCT CASE WHEN c.link_url LIKE 'tel:%%' AND s.is_bot_detected = 0 THEN s.id END) AS human_sessions_with_tap
         FROM $sessions s
         LEFT JOIN $clicks_tbl c ON c.session_id = s.id
         WHERE s.entry_source = 'google_ads' AND s.started_at >= %s",
        $since
    ));

    // Per-campaign breakdown (extract vt_campaign from entry_url; falls
    // back to utm_campaign if the landing URL didn't carry it).
    $ads_by_campaign = $wpdb->get_results($wpdb->prepare(
        "SELECT
           CASE
             WHEN s.entry_url LIKE '%%vt_campaign=%%'
               THEN SUBSTRING_INDEX(SUBSTRING_INDEX(s.entry_url, 'vt_campaign=', -1), '&', 1)
             WHEN s.utm_campaign IS NOT NULL AND s.utm_campaign <> ''
               THEN s.utm_campaign
             ELSE '(unknown)'
           END AS campaign,
           COUNT(DISTINCT s.id) AS sessions,
           COUNT(DISTINCT CASE WHEN c.link_url LIKE 'tel:%%' THEN s.id END) AS taps,
           SUM(CASE WHEN c.link_url LIKE 'tel:%%' THEN 1 ELSE 0 END) AS total_taps
         FROM $sessions s
         LEFT JOIN $clicks_tbl c ON c.session_id = s.id
         WHERE s.entry_source = 'google_ads' AND s.started_at >= %s
         GROUP BY campaign
         ORDER BY taps DESC, sessions DESC LIMIT 20",
        $since
    ));

    // Per-keyword breakdown — same extraction trick on vt_keyword. Stored
    // url-encoded so we'll urldecode in the render.
    $ads_by_keyword = $wpdb->get_results($wpdb->prepare(
        "SELECT
           CASE
             WHEN s.entry_url LIKE '%%vt_keyword=%%'
               THEN SUBSTRING_INDEX(SUBSTRING_INDEX(s.entry_url, 'vt_keyword=', -1), '&', 1)
             WHEN s.utm_term IS NOT NULL AND s.utm_term <> ''
               THEN s.utm_term
             ELSE '(unknown)'
           END AS keyword,
           COUNT(DISTINCT s.id) AS sessions,
           COUNT(DISTINCT CASE WHEN c.link_url LIKE 'tel:%%' THEN s.id END) AS taps
         FROM $sessions s
         LEFT JOIN $clicks_tbl c ON c.session_id = s.id
         WHERE s.entry_source = 'google_ads' AND s.started_at >= %s
         GROUP BY keyword
         ORDER BY taps DESC, sessions DESC LIMIT 20",
        $since
    ));

    // Geo breakdown for the visualization panel — humans only.
    $by_country = $wpdb->get_results($wpdb->prepare(
        "SELECT country, country_code, COUNT(*) AS n
         FROM $sessions
         WHERE started_at >= %s
           AND (is_bot_detected IS NULL OR is_bot_detected = 0)
           AND country IS NOT NULL
         GROUP BY country, country_code ORDER BY n DESC LIMIT 15",
        $since
    ));
    $by_us_state = $wpdb->get_results($wpdb->prepare(
        "SELECT region, region_code, COUNT(*) AS n
         FROM $sessions
         WHERE started_at >= %s
           AND (is_bot_detected IS NULL OR is_bot_detected = 0)
           AND country_code = 'US' AND region IS NOT NULL
         GROUP BY region, region_code ORDER BY n DESC LIMIT 15",
        $since
    ));
    $geo_pending = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $sessions
         WHERE geo_lookup_at IS NULL AND ip IS NOT NULL
           AND (is_bot_detected IS NULL OR is_bot_detected = 0)
           AND started_at >= %s",
        $since
    ));

    // Most recent ad → phone-tap conversions for the audit table.
    $ads_recent_taps = $wpdb->get_results($wpdb->prepare(
        "SELECT c.clicked_at, c.link_url AS phone, s.gclid, s.device_type,
                s.entry_url, s.session_uuid, s.is_bot_detected
         FROM $sessions s
         JOIN $clicks_tbl c ON c.session_id = s.id
         WHERE s.entry_source = 'google_ads'
           AND s.started_at >= %s
           AND c.link_url LIKE 'tel:%%'
         ORDER BY c.clicked_at DESC LIMIT 20",
        $since
    ));

    // Tiny helper to pull a query-string param out of a URL for the render.
    $ads_qs = function ($url, $key) {
        $q = parse_url((string) $url, PHP_URL_QUERY);
        if (!$q) return '';
        parse_str($q, $p);
        return isset($p[$key]) ? rawurldecode((string) $p[$key]) : '';
    };

    // Top entry pages also benefit from a "heatmap" link.
    $top_pages_for_heatmap = $wpdb->get_results($wpdb->prepare(
        "SELECT page_url, COUNT(*) AS clicks
         FROM $clicks_tbl WHERE clicked_at >= %s
         GROUP BY page_url ORDER BY clicks DESC LIMIT 10",
        $since
    ));

    ?>
    <style>
      .dsbi-trk-wrap{font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
      .dsbi-trk-wrap h1{margin-bottom:8px}
      .dsbi-trk-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 24px}
      .dsbi-trk-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:14px}
      .dsbi-trk-card .num{font-size:28px;font-weight:700;line-height:1}
      .dsbi-trk-card .lbl{color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.04em;margin-top:6px}
      .dsbi-trk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-bottom:24px}
      .dsbi-trk-grid table{width:100%;background:#fff;border:1px solid #c3c4c7;border-radius:6px;border-collapse:separate;border-spacing:0;overflow:hidden}
      .dsbi-trk-grid th,.dsbi-trk-grid td{padding:8px 12px;text-align:left;border-bottom:1px solid #f0f0f1}
      .dsbi-trk-grid tr:last-child td{border-bottom:none}
      .dsbi-trk-grid th{background:#f6f7f7;font-weight:600;color:#1d2327}
      .dsbi-trk-grid h3{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#646970}
      .dsbi-trk-sessions{background:#fff;border:1px solid #c3c4c7;border-radius:6px;overflow:hidden}
      .dsbi-trk-sessions table{width:100%;border-collapse:collapse;font-size:12px}
      .dsbi-trk-sessions th,.dsbi-trk-sessions td{padding:6px 10px;text-align:left;border-bottom:1px solid #f0f0f1;vertical-align:top}
      .dsbi-trk-sessions th{background:#f6f7f7;font-weight:600;position:sticky;top:0}
      .dsbi-trk-sessions .src-google_organic{background:rgba(34,139,34,.1);color:#1a5e1a;padding:1px 6px;border-radius:99px;font-size:11px}
      .dsbi-trk-sessions .src-google_ads{background:rgba(245,158,11,.18);color:#92500a;padding:1px 6px;border-radius:99px;font-size:11px}
      .dsbi-trk-sessions .src-aeo{background:rgba(168,85,247,.15);color:#581c87;padding:1px 6px;border-radius:99px;font-size:11px}
      .dsbi-trk-sessions .src-direct{background:rgba(100,116,139,.15);color:#334155;padding:1px 6px;border-radius:99px;font-size:11px}
      .dsbi-trk-sessions .src-social{background:rgba(56,189,248,.18);color:#075985;padding:1px 6px;border-radius:99px;font-size:11px}
      .dsbi-trk-sessions .src-referral{background:rgba(244,114,182,.16);color:#9d174d;padding:1px 6px;border-radius:99px;font-size:11px}
      .dsbi-trk-sessions .src-internal{background:#eee;color:#444;padding:1px 6px;border-radius:99px;font-size:11px}
      .dsbi-trk-sessions .src-search{background:rgba(20,184,166,.18);color:#115e59;padding:1px 6px;border-radius:99px;font-size:11px}
      .dsbi-trk-sessions code{font-size:11px}
      .dsbi-trk-filter{margin:8px 0 16px}
      .dsbi-trk-filter a{display:inline-block;padding:4px 10px;margin-right:6px;text-decoration:none;background:#f0f0f1;border-radius:4px;color:#2c3338}
      .dsbi-trk-filter a.active{background:#2271b1;color:#fff}
      .dsbi-trk-empty{padding:16px;color:#646970;text-align:center}
    </style>

    <div class="wrap dsbi-trk-wrap">
      <h1>DSBI Custom Tracker</h1>
      <p style="color:#646970;margin:0 0 8px;">First-party session analytics for <code>drsusanblockinstitute.com</code>. Last <?php echo (int) $days; ?> days.</p>

      <div class="dsbi-trk-filter">
        <?php foreach ([1, 7, 30, 90] as $d): ?>
          <a href="?page=dsbi-tracker&days=<?php echo $d; ?>" class="<?php echo $d === $days ? 'active' : ''; ?>">Last <?php echo $d; ?>d</a>
        <?php endforeach; ?>
      </div>

      <div class="dsbi-trk-cards">
        <div class="dsbi-trk-card"><div class="num"><?php echo (int) ($totals->sessions_count ?? 0); ?></div><div class="lbl">Sessions</div></div>
        <div class="dsbi-trk-card"><div class="num"><?php echo (int) ($totals->pageviews_total ?? 0); ?></div><div class="lbl">Pageviews</div></div>
        <div class="dsbi-trk-card"><div class="num"><?php echo esc_html($bounce_rate); ?>%</div><div class="lbl">Bounce rate</div></div>
        <div class="dsbi-trk-card"><div class="num"><?php echo $totals && $totals->sessions_count ? number_format($totals->pageviews_total / $totals->sessions_count, 2) : '0'; ?></div><div class="lbl">PVs / session</div></div>
        <div class="dsbi-trk-card"><div class="num"><?php echo number_format($clicks_total); ?></div><div class="lbl">Link clicks</div></div>
        <div class="dsbi-trk-card"><div class="num"><?php echo number_format($events_total); ?></div><div class="lbl">Events</div></div>
      </div>

      <div class="dsbi-trk-grid">
        <div>
          <h3>By source</h3>
          <table>
            <tr><th>Source</th><th style="text-align:right">Sessions</th><th style="text-align:right">PVs</th><th style="text-align:right">Bounce</th></tr>
            <?php if (!$by_source): ?><tr><td class="dsbi-trk-empty" colspan="4">No data</td></tr><?php endif; ?>
            <?php foreach ($by_source as $r): $br = $r->n ? round(100 * $r->bounces / $r->n, 0) : 0; ?>
              <tr>
                <td><span class="src-<?php echo esc_attr($r->entry_source); ?>"><?php echo esc_html($r->entry_source ?: '(unknown)'); ?></span></td>
                <td style="text-align:right"><?php echo (int) $r->n; ?></td>
                <td style="text-align:right"><?php echo (int) $r->pv; ?></td>
                <td style="text-align:right"><?php echo $br; ?>%</td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>

        <div>
          <h3>AEO breakdown</h3>
          <table>
            <tr><th>AI engine</th><th style="text-align:right">Sessions</th></tr>
            <?php if (!$by_aeo): ?><tr><td class="dsbi-trk-empty" colspan="2">No AEO traffic yet</td></tr><?php endif; ?>
            <?php foreach ($by_aeo as $r): ?>
              <tr><td><?php echo esc_html($r->aeo_origin); ?></td><td style="text-align:right"><?php echo (int) $r->n; ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>

        <div>
          <h3>By device</h3>
          <table>
            <tr><th>Device</th><th style="text-align:right">Sessions</th></tr>
            <?php foreach ($by_device as $r): ?>
              <tr><td><?php echo esc_html($r->device_type ?: '(unknown)'); ?></td><td style="text-align:right"><?php echo (int) $r->n; ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>

        <div>
          <h3>Top entry pages</h3>
          <table>
            <tr><th>URL</th><th style="text-align:right">Sessions</th></tr>
            <?php foreach ($top_entries as $r): ?>
              <tr>
                <td style="word-break:break-all;font-size:11px"><?php echo esc_html(parse_url($r->entry_url, PHP_URL_PATH) ?: $r->entry_url); ?></td>
                <td style="text-align:right"><?php echo (int) $r->n; ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>

      <div class="dsbi-trk-grid">
        <div>
          <h3>By country (humans) <?php if ($geo_pending) : ?><span style="font-weight:normal;text-transform:none;letter-spacing:0;color:#92500a">· <?php echo (int) $geo_pending; ?> pending lookup</span><?php endif; ?></h3>
          <table>
            <tr><th>Country</th><th style="text-align:right">Sessions</th></tr>
            <?php if (!$by_country): ?><tr><td class="dsbi-trk-empty" colspan="2">No geo data yet — cron is enriching</td></tr><?php endif; ?>
            <?php foreach ($by_country as $r): ?>
              <tr>
                <td><?php echo esc_html($r->country); ?> <span style="color:#646970;font-size:11px">(<?php echo esc_html($r->country_code ?: '—'); ?>)</span></td>
                <td style="text-align:right"><?php echo (int) $r->n; ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>

        <div>
          <h3>By US state (humans)</h3>
          <table>
            <tr><th>State</th><th style="text-align:right">Sessions</th></tr>
            <?php if (!$by_us_state): ?><tr><td class="dsbi-trk-empty" colspan="2">No US sessions with geo yet</td></tr><?php endif; ?>
            <?php foreach ($by_us_state as $r): ?>
              <tr>
                <td><?php echo esc_html($r->region); ?> <span style="color:#646970;font-size:11px">(<?php echo esc_html($r->region_code ?: '—'); ?>)</span></td>
                <td style="text-align:right"><?php echo (int) $r->n; ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>

      <div class="dsbi-trk-grid">
        <div>
          <h3>Clicks by area (header / nav / footer / content)</h3>
          <table>
            <tr><th>Area</th><th style="text-align:right">Clicks</th></tr>
            <?php if (!$clicks_by_area): ?><tr><td class="dsbi-trk-empty" colspan="2">No click data yet</td></tr><?php endif; ?>
            <?php foreach ($clicks_by_area as $a): ?>
              <tr><td><?php echo esc_html($a->area); ?></td><td style="text-align:right"><?php echo (int) $a->clicks; ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
        <div>
          <h3>View heatmap on a page</h3>
          <table>
            <tr><th>Page</th><th style="text-align:right">Clicks</th><th>Open</th></tr>
            <?php if (!$top_pages_for_heatmap): ?><tr><td class="dsbi-trk-empty" colspan="3">No click data yet</td></tr><?php endif; ?>
            <?php foreach ($top_pages_for_heatmap as $p):
                $heat_url = add_query_arg('dsbi_heatmap', '1', $p->page_url);
                $path = parse_url($p->page_url, PHP_URL_PATH) ?: $p->page_url;
            ?>
              <tr>
                <td style="word-break:break-all;font-size:11px"><?php echo esc_html($path); ?></td>
                <td style="text-align:right"><?php echo (int) $p->clicks; ?></td>
                <td><a href="<?php echo esc_url($heat_url); ?>" target="_blank" rel="noopener">🔥 heatmap</a></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>

      <?php
        $ads_sessions  = (int) ($ads_totals->sessions ?? 0);
        $ads_tap_sess  = (int) ($ads_totals->sessions_with_tap ?? 0);
        $ads_taps      = (int) ($ads_totals->total_taps ?? 0);
        $ads_human_tap = (int) ($ads_totals->human_sessions_with_tap ?? 0);
        $ads_tap_rate  = $ads_sessions > 0 ? round(100 * $ads_tap_sess / $ads_sessions, 1) : 0;
      ?>
      <h2 style="margin-top:32px;font-size:18px;border-bottom:2px solid #f59e0b;padding-bottom:6px">📞 Google Ads → Phone-tap ROI</h2>
      <p style="color:#646970;margin:6px 0 12px">Sessions arriving with a <code>gclid</code> that subsequently tapped a <code>tel:</code> link. Captured by the <code>pointerdown</code> handler before iOS hands the call off to the dialer.</p>

      <div class="dsbi-trk-cards">
        <div class="dsbi-trk-card"><div class="num"><?php echo $ads_sessions; ?></div><div class="lbl">Ad sessions</div></div>
        <div class="dsbi-trk-card"><div class="num"><?php echo $ads_tap_sess; ?></div><div class="lbl">Sessions w/ phone tap</div></div>
        <div class="dsbi-trk-card"><div class="num"><?php echo esc_html($ads_tap_rate); ?>%</div><div class="lbl">Phone-tap rate</div></div>
        <div class="dsbi-trk-card"><div class="num"><?php echo $ads_taps; ?></div><div class="lbl">Total phone taps</div></div>
        <div class="dsbi-trk-card"><div class="num"><?php echo $ads_human_tap; ?></div><div class="lbl">Human taps (≠ bot)</div></div>
      </div>

      <div class="dsbi-trk-grid">
        <div>
          <h3>By campaign</h3>
          <table>
            <tr><th>Campaign</th><th style="text-align:right">Sessions</th><th style="text-align:right">Taps</th><th style="text-align:right">Rate</th></tr>
            <?php if (!$ads_by_campaign): ?><tr><td class="dsbi-trk-empty" colspan="4">No ad data in window</td></tr><?php endif; ?>
            <?php foreach ($ads_by_campaign as $r):
                $rate = (int) $r->sessions > 0 ? round(100 * (int) $r->taps / (int) $r->sessions, 1) : 0;
            ?>
              <tr>
                <td style="word-break:break-all;font-size:11px"><?php echo esc_html($r->campaign ?: '(unknown)'); ?></td>
                <td style="text-align:right"><?php echo (int) $r->sessions; ?></td>
                <td style="text-align:right"><?php echo (int) $r->taps; ?></td>
                <td style="text-align:right"><?php echo $rate; ?>%</td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>

        <div>
          <h3>By keyword</h3>
          <table>
            <tr><th>Keyword</th><th style="text-align:right">Sessions</th><th style="text-align:right">Taps</th></tr>
            <?php if (!$ads_by_keyword): ?><tr><td class="dsbi-trk-empty" colspan="3">No ad data in window</td></tr><?php endif; ?>
            <?php foreach ($ads_by_keyword as $r):
                $kw = rawurldecode((string) $r->keyword);
            ?>
              <tr>
                <td style="word-break:break-all;font-size:11px"><?php echo esc_html($kw ?: '(unknown)'); ?></td>
                <td style="text-align:right"><?php echo (int) $r->sessions; ?></td>
                <td style="text-align:right"><?php echo (int) $r->taps; ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>

      <h3 style="margin-top:8px;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#646970">Recent phone-tap conversions</h3>
      <div class="dsbi-trk-sessions" style="margin-bottom:24px">
        <table>
          <tr>
            <th>Time</th>
            <th>Device</th>
            <th>Keyword</th>
            <th>Campaign</th>
            <th>Phone tapped</th>
            <th>gclid</th>
          </tr>
          <?php if (!$ads_recent_taps): ?>
            <tr><td colspan="6" class="dsbi-trk-empty">No phone-tap conversions from Google Ads in window</td></tr>
          <?php endif; ?>
          <?php foreach ($ads_recent_taps as $r):
              $kw = $ads_qs($r->entry_url, 'vt_keyword');
              $camp = $ads_qs($r->entry_url, 'vt_campaign');
              $bot_badge = (int) $r->is_bot_detected === 1 ? ' <span style="color:#b91c1c;font-size:10px">(bot)</span>' : '';
          ?>
            <tr>
              <td><?php echo esc_html(mysql2date('M j H:i', $r->clicked_at)); ?><?php echo $bot_badge; ?></td>
              <td><?php echo esc_html($r->device_type ?: ''); ?></td>
              <td style="font-size:11px"><?php echo esc_html($kw ?: '—'); ?></td>
              <td style="font-size:11px"><?php echo esc_html($camp ?: '—'); ?></td>
              <td><code><?php echo esc_html($r->phone); ?></code></td>
              <td style="font-size:10px;color:#646970"><code title="<?php echo esc_attr($r->gclid); ?>"><?php echo esc_html(substr((string) $r->gclid, 0, 24)); ?>…</code></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <h2 style="margin-top:24px">Events (video plays, slider clicks, buttons, scroll milestones…)</h2>
      <div class="dsbi-trk-sessions">
        <table>
          <tr><th>Event type</th><th style="text-align:right">Count</th><th style="text-align:right">Unique sessions</th></tr>
          <?php if (!$events_by_type): ?><tr><td class="dsbi-trk-empty" colspan="3">No events recorded yet — start browsing the site to populate</td></tr><?php endif; ?>
          <?php foreach ($events_by_type as $e): ?>
            <tr>
              <td><code><?php echo esc_html($e->event_type); ?></code></td>
              <td style="text-align:right"><?php echo (int) $e->n; ?></td>
              <td style="text-align:right"><?php echo (int) $e->unique_sessions; ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <h2 style="margin-top:24px">Top clicked links</h2>
      <div class="dsbi-trk-sessions">
        <table>
          <tr>
            <th>Link URL</th>
            <th>Text</th>
            <th style="text-align:right">Clicks</th>
            <th style="text-align:right">Unique sessions</th>
            <th style="text-align:right">External</th>
          </tr>
          <?php if (!$top_clicks): ?><tr><td class="dsbi-trk-empty" colspan="5">No click data yet — start browsing the site to populate</td></tr><?php endif; ?>
          <?php foreach ($top_clicks as $c):
              $path = parse_url($c->link_url, PHP_URL_PATH) ?: $c->link_url;
              $host = parse_url($c->link_url, PHP_URL_HOST) ?: '';
              $site_host = parse_url(home_url(), PHP_URL_HOST) ?: '';
              $display = ($host && $host !== $site_host) ? $host . $path : $path;
          ?>
            <tr>
              <td style="word-break:break-all"><?php echo esc_html($display); ?></td>
              <td><?php echo esc_html($c->link_text); ?></td>
              <td style="text-align:right"><?php echo (int) $c->clicks; ?></td>
              <td style="text-align:right"><?php echo (int) $c->unique_sessions; ?></td>
              <td style="text-align:right"><?php echo (int) $c->external_count; ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <h2 style="margin-top:24px">Recent sessions</h2>
      <div class="dsbi-trk-sessions">
        <table>
          <tr>
            <th>Started</th>
            <th>Source</th>
            <th>From</th>
            <th>Entry</th>
            <th>Device</th>
            <th>TZ</th>
            <th>IP</th>
            <th style="text-align:right">PVs</th>
            <th>JS</th>
          </tr>
          <?php if (!$recent): ?><tr><td class="dsbi-trk-empty" colspan="9">No sessions in this window</td></tr><?php endif; ?>
          <?php foreach ($recent as $s):
              $is_bounce = ((int) $s->pageviews_count === 1) ? '·bounce' : '';
              $ip_human = $s->ip ? (@inet_ntop($s->ip) ?: '?') : '';
              $entry_path = parse_url($s->entry_url, PHP_URL_PATH) ?: $s->entry_url;
          ?>
            <tr>
              <td><?php echo esc_html(mysql2date('M j H:i', $s->started_at)); ?></td>
              <td><span class="src-<?php echo esc_attr($s->entry_source); ?>"><?php echo esc_html($s->entry_source); ?></span><?php echo $is_bounce ? '<br><small style="opacity:.7">'.$is_bounce.'</small>' : ''; ?></td>
              <td><?php echo esc_html($s->aeo_origin ?: $s->referer_host ?: '—'); ?></td>
              <td style="word-break:break-all"><?php echo esc_html($entry_path); ?></td>
              <td><?php echo esc_html($s->device_type ?: '—'); ?></td>
              <td><?php echo esc_html($s->timezone ?: '—'); ?></td>
              <td><code><?php echo esc_html($ip_human); ?></code></td>
              <td style="text-align:right"><?php echo (int) $s->pageviews_count; ?></td>
              <td><?php echo $s->js_enabled ? '✓' : '·'; ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
    <?php
    /**
     * Allow companion plugins (currently: dsbi-tracker-page-paths.php) to
     * inject additional panels below the main dashboard. Receives the
     * window the dashboard above was showing — companions should match it.
     *
     * Args: int $days, string $since ('Y-m-d H:i:s' in GMT).
     */
    do_action('dsbi_trk_dashboard_extras', $days, $since);
}
