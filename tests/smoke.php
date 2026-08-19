<?php
/**
 * smoke.php — request every page as a signed-in administrator and report
 * anything that errors. Catches the class of breakage that a sweeping change
 * (like replacing session_start() everywhere) tends to cause.
 *
 * Also checks that the database is not reachable over HTTP.
 */

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8112', '/');
$adminPassword = getenv('KURASH_TEST_PASSWORD');
$cookieJar = sys_get_temp_dir() . '/kurash-smoke-cookies.txt';
@unlink($cookieJar);

function get(string $url, bool $follow = false): array
{
    global $cookieJar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body];
}

// --- the database must not be downloadable ---------------------------------
// This is the check that matters: the database is protected by living outside
// the document root, which holds on every server. The .htaccess rules are a
// second layer for Apache/LiteSpeed only — php -S ignores .htaccess entirely,
// so it is reported here for information and does not fail the run.
echo "\033[1mWeb-root exposure\033[0m\n";
$leaks = 0;
foreach (['kurash.db', 'kurash.db-wal', 'kurash.db-shm', 'data/kurash.db', '../data/kurash.db'] as $path) {
    $r = get("{$baseUrl}/{$path}");
    if ($r['status'] === 200 && strlen($r['body']) > 0) {
        echo "  \033[31m✗\033[0m /{$path} is downloadable ({$r['status']}, " . strlen($r['body']) . " bytes)\n";
        $leaks++;
    } else {
        echo "  \033[32m✓\033[0m /{$path} not served ({$r['status']})\n";
    }
}

$ht = get("{$baseUrl}/.htaccess");
echo $ht['status'] === 200
    ? "  \033[33m•\033[0m /.htaccess readable under php -S — expected; Apache and LiteSpeed deny it by default\n"
    : "  \033[32m✓\033[0m /.htaccess not served ({$ht['status']})\n";

// --- sign in ----------------------------------------------------------------
$loginPage = get("{$baseUrl}/login.php");
preg_match('/name="_token" value="([a-f0-9]{64})"/', $loginPage['body'], $m);

$ch = curl_init("{$baseUrl}/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'username' => 'admin', 'password' => $adminPassword, '_token' => $m[1] ?? '',
    ]),
]);
curl_exec($ch);
curl_close($ch);

// --- every page -------------------------------------------------------------
echo "\n\033[1mPages (signed in)\033[0m\n";

$qs = 'champion_id=1&championsub_id=1';
$pages = [];
foreach (glob(__DIR__ . '/../app/*.php') as $file) {
    $name = basename($file);
    // Includes and endpoints that are not pages in their own right.
    if (in_array($name, [
        'boot.php', 'csrf.php', 'db-config.php', 'connection.php', 'setup.php',
        'validate-online.php', 'kurash-access-guard.php', 'sidebar-kurash.php',
        'page-header-kurash.php', 'bracket-helpers.php', 'bracket-tree-kurash.php',
        'medal-helpers.php', 'match-advance.php', 'ScoreboardConnector.php',
        'scoreboard-webhook.php', 'champion-create-table-kurash-api.php',
        'logout.php', 'file-download-kurash.php',
    ], true)) {
        continue;
    }
    $pages[] = $name . '?' . $qs . ($name === 'draw-reveal-kurash.php' ? '&weight=1' : '');
}

$bad = 0;
foreach ($pages as $page) {
    $r = get("{$baseUrl}/{$page}", true);
    $body = $r['body'];

    // php -S reports fatals as 200 with the message in the body when
    // display_errors is on, so look at the content too.
    $hasError = preg_match('/(Fatal error|Parse error|Uncaught \w*(Error|Exception)|Warning: )/i', $body, $em);

    if ($r['status'] >= 400 || $hasError) {
        $detail = $hasError ? trim($em[0]) : 'HTTP ' . $r['status'];
        echo "  \033[31m✗\033[0m " . str_pad(strtok($page, '?'), 34) . " {$detail}\n";
        if ($hasError && preg_match('/(Fatal error|Parse error|Uncaught).{0,180}/s', $body, $full)) {
            echo "        " . trim(preg_replace('/\s+/', ' ', strip_tags($full[0]))) . "\n";
        }
        $bad++;
    } else {
        echo "  \033[32m✓\033[0m " . str_pad(strtok($page, '?'), 34) . " {$r['status']}\n";
    }
}

echo "\n" . str_repeat('-', 58) . "\n";
printf("  %d pages checked, %d with errors, %d web-root leaks\n", count($pages), $bad, $leaks);
echo str_repeat('-', 58) . "\n";
exit(($bad === 0 && $leaks === 0) ? 0 : 1);
