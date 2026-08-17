<?php
/**
 * scoreboard-webhook.php
 *
 * Point the scoreboard vendor's "result webhook" / "match complete callback"
 * setting at this URL, e.g.:
 *   https://yourdomain.com/kurash/scoreboard-webhook.php
 *
 * This is the "Updating Fight order by scoreboard" step (item 9 of the plan) —
 * as soon as a fight ends on the mat, the scoreboard calls this URL and we
 * update our bracket automatically, no manual data entry needed.
 *
 * NOTE: this endpoint is intentionally NOT behind the admin/supervisor login
 * guard — the scoreboard device itself calls it, not a logged-in user.
 * It's protected instead by a shared secret key (see below). Replace the
 * verification logic once you know how the vendor signs/authenticates
 * its webhook calls (HMAC signature header, static token, IP allowlist, etc).
 */

header('Content-Type: application/json');
require_once('connection.php');
require_once('ScoreboardConnector.php');

// --- Simple shared-secret verification (placeholder until vendor spec is known) ---
$expectedToken = getenv('SCOREBOARD_WEBHOOK_SECRET') ?: 'CHANGE_ME';
$providedToken = $_SERVER['HTTP_X_SCOREBOARD_TOKEN'] ?? '';

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid webhook token']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload || empty($payload['play_code'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid payload']);
    exit;
}

try {
    $connector = new ScoreboardConnector($pdo);

    // Expected payload shape (adjust field names once vendor docs confirm them):
    // {
    //   "play_code": "12340001",
    //   "score_a": 10, "score_b": 0,
    //   "winner_side": "a",
    //   "win_type": "ippon"
    // }
    $result = [
        'score_a' => $payload['score_a'] ?? null,
        'score_b' => $payload['score_b'] ?? null,
        'winner_side' => $payload['winner_side'] ?? null,
        'win_type' => $payload['win_type'] ?? null,
    ];

    $ok = $connector->applyResultToMatch($payload['play_code'], $result);

    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'Match result applied' : 'Match not found for that play_code',
    ]);
} catch (Exception $e) {
    error_log('scoreboard-webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal error']);
}
