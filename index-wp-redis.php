<?php

// -------------------------------------------------------------------------
// Config
// -------------------------------------------------------------------------
$wp_blog_header_path = dirname( __FILE__ ) . '/wp-blog-header.php';

// ------------------
// Toggle Debug/Cache
// ------------------
$debug = false;
$cache = true;
$website_ip = '127.0.0.1';

// ------------------
// Toggle Sockets
// : Optional - If true, set $redis_server to /socket/location
// ------------------
$sockets = false;
$redis_server = '127.0.0.1';

// ---------------------
// Select a Database
// : 0-16 (Default is 0)
// ----------------------
$redis_database = '2';

// ---------------------
// Flush Cache String
// : ?refresh=flush
// ----------------------
$secret_string  = 'flush';
$current_url    = getCleanUrl($secret_string);

// -----------------------
// Prefix Cached SSL Pages
// -----------------------
$is_ssl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? "ssl_" : "";
$redis_key = $is_ssl . md5($current_url);


// -------------------------------------------------------------------------
// Function Definitions
// -------------------------------------------------------------------------

// Timer to track the load time
$start = microtime();

function getMicroTime($time) {
    list($usec, $sec) = explode(" ", $time);
    return ((float) $usec + (float) $sec);
}

function refreshHasSecret($secret) {
    return isset($_GET['refresh']) && $_GET['refresh'] == $secret;
}

function requestHasSecret($secret) {
    return strpos($_SERVER['REQUEST_URI'],"refresh=${secret}")!==false;
}

function isRemotePageLoad($current_url, $website_ip) {
    return (isset($_SERVER['HTTP_REFERER'])
            && $_SERVER['HTTP_REFERER']== $current_url
            && $_SERVER['REQUEST_URI'] != '/'
            && $_SERVER['REMOTE_ADDR'] != $website_ip);
}

function handleCDNRemoteAddressing() {
    // Don't confuse the CloudFlare server
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
}

function getCleanUrl($secret) {
    $replace_keys = array("?refresh=${secret}","&refresh=${secret}");
    $url = "http://${_SERVER['HTTP_HOST']}${_SERVER['REQUEST_URI']}";
    $current_url = str_replace($replace_keys, '', $url);
    return $current_url;
}

handleCDNRemoteAddressing();

// This is standard in the default index.php file
if(!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', true);
}


// Failsafe, Display non-cached site.
if (!class_exists('Redis')) {
    // Fallback to No-cache so the site doesn't go down :)
    require dirname(__FILE__) . '/wp-blog-header.php';
    exit;
}

// -------------------------------------------------------------------------
// The Caching Operation
// -------------------------------------------------------------------------

// Connect to Redis
$redis = new Redis();
$redis->connect($redis_server);
$redis->select($redis_database);

// Either manual refresh cache by adding ?refresh=secret_string after the URL or somebody posting a comment

$clear_cache = [
    refreshHasSecret($secret_string),
    requestHasSecret($secret_string),
    isRemotePageLoad($current_url, $website_ip)
];

if (array_sum($clear_cache) > 0) {
    // Flush the Database. Not per-page.
    // Once one person hit's a page it's cached.
    $redis->flushDB();
    require $wp_blog_header_path;

    $unlimited = get_option('wp-redis-cache-debug', false);
    $seconds_cache_redis = get_option('wp-redis-cache-seconds', 43200);
}
// Page is cached: Display it
elseif ($redis->exists($redis_key))
{
    $cache  = true;
    $html_of_page = $redis->get($redis_key);
    echo $html_of_page;
}
// No Cache Exists: Collect Cache after Page Load
elseif ($_SERVER['REMOTE_ADDR'] != $website_ip && strstr($current_url, 'preview=true') == false) {

    $isPOST = ($_SERVER['REQUEST_METHOD'] === 'POST') ? 1 : 0;
    $loggedIn = preg_match("/wordpress_logged_in/", var_export($_COOKIE, true));

    if (!$isPOST && !$loggedIn)
    {
        ob_start();
        $level = ob_get_level();
        require $wp_blog_header_path;

        while (ob_get_level() > $level) {
            ob_end_flush();
        }

        $html_of_page = ob_get_clean(); // ob_get_clean also closes the OB
        echo $html_of_page;

        if (!is_numeric($seconds_cache_redis)) {
            $seconds_cache_redis = 43200;
        }

        // When a page displays after an "HTTP 404: Not Found" error occurs, do not cache
        // When the search was used, do not cache
        if ((!is_404()) and (!is_search())) {
            if ($unlimited) {
                $redis->set($redis_key, $html_of_page);
            } else {
                $redis->setex($redis_key, $seconds_cache_redis, $html_of_page);
            }
        }
    }
    else
    {
        // Either the user is logged in, or is posting a comment, show them uncached
        require $wp_blog_header_path;
    }

}
elseif ($_SERVER['REMOTE_ADDR'] != $website_ip && strstr($current_url, 'preview=true') == true)
{
    require $wp_blog_header_path;
}

$end  = microtime();
$time = (@getMicroTime($end) - @getMicroTime($start));

if ($debug)
{
    echo "<!-- Cache by Benjamin Adams -->\n";
    echo "<!-- Page Cached: $cache -->\n";
    echo "<~-- Page Load Time: " . round($time, 5) . " seconds -->\n";
    if (isset($seconds_cache_redis)) {
        echo "<!-- wp-redis-cache-seconds: $seconds_cache_redis -->\n";
    }
    echo "<!-- wp-redis-cache-secret: $secret_string -->\n";
    echo "<!-- wp-redis-cache-ip: $website_ip -->\n";
    if (isset($unlimited)) {
        echo "<!-- wp-redis-cache-unlimited: $unlimited -->\n";
    }
}
