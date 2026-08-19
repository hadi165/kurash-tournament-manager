<?php
/**
 * mock-tournament.php — end-to-end acceptance test for the hardened MVP.
 *
 * Runs a whole competition against a live server over HTTP: signs in, checks
 * the guards on the bracket API, generates two brackets (one clean, one with a
 * bye), posts every result through the scoreboard webhook, and asserts that the
 * right four athletes end up on the podium.
 *
 * Usage (see tests/run.sh):
 *   KURASH_DB=... SCOREBOARD_WEBHOOK_SECRET=... php tests/mock-tournament.php http://127.0.0.1:8111
 */

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8111', '/');
$dbPath = getenv('KURASH_DB');
$webhookSecret = getenv('SCOREBOARD_WEBHOOK_SECRET');
$adminPassword = getenv('KURASH_TEST_PASSWORD');
$cookieJar = sys_get_temp_dir() . '/kurash-test-cookies.txt';
@unlink($cookieJar);

$passed = 0;
$failed = 0;

function ok(string $label): void
{
    global $passed;
    $passed++;
    echo "  \033[32m✓\033[0m {$label}\n";
}

function fail(string $label, $expected = null, $actual = null): void
{
    global $failed;
    $failed++;
    echo "  \033[31m✗\033[0m {$label}\n";
    if (func_num_args() > 1) {
        echo "      expected: " . var_export($expected, true) . "\n";
        echo "      actual:   " . var_export($actual, true) . "\n";
    }
}

function assertSame($expected, $actual, string $label): void
{
    $expected === $actual ? ok($label) : fail($label, $expected, $actual);
}

function section(string $name): void
{
    echo "\n\033[1m{$name}\033[0m\n";
}

/** @return array{status:int, body:string} */
function http(string $method, string $url, array $post = null, array $headers = [], string $rawBody = null): array
{
    global $cookieJar;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    } elseif ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        fwrite(STDERR, "HTTP {$method} {$url} failed: {$err}\n");
        exit(1);
    }

    return ['status' => $status, 'body' => $body];
}

function tokenFrom(string $html): ?string
{
    return preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : null;
}

// ---------------------------------------------------------------------------
// Seed a known tournament straight into the database.
// ---------------------------------------------------------------------------
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec("DELETE FROM championplaytablekurash");
$pdo->exec("DELETE FROM championregisterathletes");
$pdo->exec("DELETE FROM championsubs");
$pdo->exec("DELETE FROM champions");

$pdo->exec("INSERT INTO champions (id, title) VALUES (1, 'Mock Kurash Championship')");
$pdo->exec("INSERT INTO championsubs (id, champion_id, subtitle, corashweights, corashweights_text)
            VALUES (1, 1, 'Men Senior', '1/2', '-66/-73')");

// Weight 1 (-66kg): eight athletes, a clean bracket with no byes.
// Weight 2 (-73kg): three athletes, so the four-slot bracket carries one bye.
$roster = [];
foreach ([1 => 8, 2 => 3] as $weight => $count) {
    for ($draw = 1; $draw <= $count; $draw++) {
        $name = sprintf('W%d Athlete %d', $weight, $draw);
        $stmt = $pdo->prepare("
            INSERT INTO championregisterathletes
                (champion_id, championsub_id, fullname, gender, noc_code, noc_name,
                 corashweight, corashweight_text, corash_lotterynumber, wushutype, board_id)
            VALUES (1, 1, ?, 'M', ?, 'Testland', ?, ?, ?, 'Kurash', 1)
        ");
        $stmt->execute([$name, 'TST', $weight, $weight === 1 ? '-66' : '-73', $draw]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE championregisterathletes SET ika_id = ? WHERE id = ?")
            ->execute(['IKA' . str_pad((string)$id, 6, '0', STR_PAD_LEFT), $id]);
        $roster[$weight][$draw] = ['id' => $id, 'name' => $name];
    }
}
echo "Seeded 11 athletes across 2 weight categories.\n";

// ---------------------------------------------------------------------------
section('Guards on the bracket generator');
// ---------------------------------------------------------------------------
$r = http('POST', "{$baseUrl}/champion-create-table-kurash-api.php", ['champion_id' => 1, 'championsub_id' => 1]);
assertSame(401, $r['status'], 'Unauthenticated POST is rejected with 401');

$before = (int)$pdo->query("SELECT COUNT(*) FROM championregisterathletes")->fetchColumn();
assertSame(11, $before, 'Unauthenticated call destroyed nothing');

// Sign in.
$loginPage = http('GET', "{$baseUrl}/login.php");
$token = tokenFrom($loginPage['body']);
$token !== null ? ok('Login form carries a CSRF token') : fail('Login form carries a CSRF token');

$r = http('POST', "{$baseUrl}/login.php", ['username' => 'admin', 'password' => $adminPassword, '_token' => $token]);
assertSame(302, $r['status'], 'Sign-in succeeds and redirects');

$r = http('GET', "{$baseUrl}/champion-create-table-kurash-api.php?champion_id=1&championsub_id=1");
assertSame(405, $r['status'], 'GET on the generator is rejected with 405');

$r = http('POST', "{$baseUrl}/champion-create-table-kurash-api.php", ['champion_id' => 1, 'championsub_id' => 1]);
assertSame(419, $r['status'], 'POST without a CSRF token is rejected with 419');

// ---------------------------------------------------------------------------
section('Bracket generation');
// ---------------------------------------------------------------------------
$r = http('POST', "{$baseUrl}/champion-create-table-kurash-api.php", [
    'champion_id' => 1, 'championsub_id' => 1, '_token' => $token,
]);
$gen = json_decode($r['body'], true);
assertSame(200, $r['status'], 'Authenticated POST with a token generates the bracket');
assertSame('success', $gen['status'] ?? null, 'Generator reports success');

$w1 = $pdo->query("SELECT COUNT(*) FROM championplaytablekurash WHERE corashweight = 1")->fetchColumn();
assertSame(7, (int)$w1, '-66kg (8 athletes) produced 7 matches');

$w2 = $pdo->query("SELECT COUNT(*) FROM championplaytablekurash WHERE corashweight = 2")->fetchColumn();
assertSame(3, (int)$w2, '-73kg (3 athletes) produced 3 matches');

$byeRow = $pdo->query("SELECT * FROM championplaytablekurash WHERE wintype = 'bye'")->fetch();
$byeRow !== false ? ok('The 3-athlete bracket contains a bye') : fail('The 3-athlete bracket contains a bye');

// The bye winner must already be sitting in the final before anyone fights.
$finalW2 = $pdo->query("SELECT * FROM championplaytablekurash WHERE corashweight = 2 AND roundnumber = 2")->fetch();
$byeAdvanced = !empty($finalW2['athleteid_a']) || !empty($finalW2['athleteid_b']);
$byeAdvanced ? ok('Bye winner was advanced into the next round') : fail('Bye winner was advanced into the next round');

// ---------------------------------------------------------------------------
section('Results through the scoreboard webhook');
// ---------------------------------------------------------------------------
// Deterministic rule: the lower draw number always wins.
$idToDraw = [];
foreach ($roster as $weight => $athletes) {
    foreach ($athletes as $draw => $a) {
        $idToDraw[$a['id']] = $draw;
    }
}

$badSecret = http('POST', "{$baseUrl}/scoreboard-webhook.php", null,
    ['Content-Type: application/json', 'X-Scoreboard-Token: wrong-secret'],
    json_encode(['play_code' => '0000']));
assertSame(401, $badSecret['status'], 'Webhook rejects a wrong shared secret');

$fightsRun = 0;
$roundsRun = 0;

while (true) {
    // Any match with both athletes present and no winner yet is ready to run.
    $ready = $pdo->query("
        SELECT * FROM championplaytablekurash
        WHERE winner_athleteid IS NULL
          AND athleteid_a IS NOT NULL AND athleteid_b IS NOT NULL
        ORDER BY roundnumber ASC, playnumber ASC
    ")->fetchAll();

    if (!$ready) {
        break;
    }
    $roundsRun++;

    foreach ($ready as $match) {
        $drawA = $idToDraw[$match['athleteid_a']] ?? 999;
        $drawB = $idToDraw[$match['athleteid_b']] ?? 999;
        $winnerSide = $drawA < $drawB ? 'a' : 'b';

        $res = http('POST', "{$baseUrl}/scoreboard-webhook.php", null,
            ['Content-Type: application/json', 'X-Scoreboard-Token: ' . $webhookSecret],
            json_encode([
                'play_code' => $match['playcode'],
                'score_a' => $winnerSide === 'a' ? 10 : 0,
                'score_b' => $winnerSide === 'b' ? 10 : 0,
                'winner_side' => $winnerSide,
                'win_type' => 'halal',
            ]));

        $decoded = json_decode($res['body'], true);
        if ($res['status'] !== 200 || ($decoded['status'] ?? null) !== 'success') {
            fail("Webhook accepted result for play_code {$match['playcode']}", 'success 200', $res['status'] . ' ' . $res['body']);
            break 2;
        }
        $fightsRun++;
    }

    if ($roundsRun > 10) {
        fail('Tournament terminated', 'at most 10 rounds', 'loop did not converge');
        break;
    }
}

// 7 matches at -66kg, plus 2 at -73kg. The third -73kg match is the bye, which
// is decided at generation time and never reaches the scoreboard.
assertSame(9, $fightsRun, 'All 9 contested fights were recorded through the webhook');

$synced = (int)$pdo->query("SELECT COUNT(*) FROM championplaytablekurash WHERE scoreboard_synced_at IS NOT NULL")->fetchColumn();
assertSame(9, $synced, 'scoreboard_synced_at was written on every one (the NOW() bug is gone)');

$byeUntouched = (int)$pdo->query("SELECT COUNT(*) FROM championplaytablekurash WHERE wintype = 'bye' AND scoreboard_synced_at IS NULL")->fetchColumn();
assertSame(1, $byeUntouched, 'The bye was never sent to a scoreboard');

$unfilled = (int)$pdo->query("
    SELECT COUNT(*) FROM championplaytablekurash
    WHERE roundnumber > 1 AND (athleteid_a IS NULL OR athleteid_b IS NULL)
")->fetchColumn();
assertSame(0, $unfilled, 'Every later-round slot was filled by advancement');

// ---------------------------------------------------------------------------
section('Medals');
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../app/medal-helpers.php';
$events = computeMedalEvents($pdo, 1);
assertSame(2, count($events), 'Both weight categories produced a decided event');

$byWeight = [];
foreach ($events as $ev) {
    $byWeight[(int)$ev['weight']] = $ev;
}

// -66kg, 8 athletes, lower draw always wins:
//   R1  1>8  4>5  2>7  3>6
//   SF  1>4  2>3
//   F   1>2       → gold 1, silver 2, bronze 3 and 4
$w1 = $byWeight[1];
assertSame('W1 Athlete 1', $w1['gold']['fullname'] ?? null, '-66kg gold is draw 1');
assertSame('W1 Athlete 2', $w1['silver']['fullname'] ?? null, '-66kg silver is draw 2');
$bronze1 = array_map(fn($b) => $b['fullname'], array_filter($w1['bronze']));
sort($bronze1);
assertSame(['W1 Athlete 3', 'W1 Athlete 4'], $bronze1, '-66kg bronzes are draws 3 and 4');

// -73kg, 3 athletes: draw 1 gets the bye, then beats the winner of 2 v 3.
$w2 = $byWeight[2];
assertSame('W2 Athlete 1', $w2['gold']['fullname'] ?? null, '-73kg gold is draw 1');
assertSame('W2 Athlete 2', $w2['silver']['fullname'] ?? null, '-73kg silver is draw 2');
$bronze2 = array_values(array_map(fn($b) => $b['fullname'], array_filter($w2['bronze'])));
assertSame(['W2 Athlete 3'], $bronze2, '-73kg bronze is draw 3');

// ---------------------------------------------------------------------------
section('Regeneration guard');
// ---------------------------------------------------------------------------
$r = http('POST', "{$baseUrl}/champion-create-table-kurash-api.php", [
    'champion_id' => 1, 'championsub_id' => 1, '_token' => $token,
]);
assertSame(409, $r['status'], 'Regenerating over decided fights is refused with 409');

$still = (int)$pdo->query("SELECT COUNT(*) FROM championplaytablekurash WHERE winner_athleteid IS NOT NULL")->fetchColumn();
$still > 0 ? ok('Recorded results survived the refused regeneration') : fail('Recorded results survived the refused regeneration');

$r = http('POST', "{$baseUrl}/champion-create-table-kurash-api.php", [
    'champion_id' => 1, 'championsub_id' => 1, '_token' => $token, 'confirm_discard_results' => 1,
]);
assertSame(200, $r['status'], 'Regenerating with explicit confirmation is allowed');

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 58) . "\n";
printf("  %d passed, %d failed\n", $passed, $failed);
echo str_repeat('-', 58) . "\n";
exit($failed === 0 ? 0 : 1);
