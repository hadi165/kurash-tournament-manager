<?php
/**
 * ScoreboardConnector
 *
 * Adapter between our system and each court's Scoreboard device/API.
 * This file is intentionally the ONLY place that needs editing once you
 * have the real API documentation from the scoreboard manufacturer —
 * everything else in the system calls these three methods and doesn't
 * care how the transport works underneath (REST, WebSocket, etc).
 *
 * TODO (blocked on scoreboard API docs):
 *   - Confirm authentication method (API key header? token? none on local network?)
 *   - Confirm exact endpoint paths and payload field names
 *   - Confirm whether score results are PUSHED to us (webhook) or must be
 *     PULLED by us (polling). If it's a webhook, point the device at
 *     scoreboard-webhook.php (included below) instead of using pullScore().
 */

class ScoreboardConnector
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Look up the API connection details for a given court.
     */
    private function getCourtConfig(int $courtId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM kurashcourts WHERE id = ? AND is_active = 1");
        $stmt->execute([$courtId]);
        $court = $stmt->fetch(PDO::FETCH_ASSOC);
        return $court ?: null;
    }

    /**
     * Push a match's athlete info to the assigned court's scoreboard so it
     * displays the correct names/flags/timer before the fight starts.
     *
     * Called when a match's court_status moves to 'scheduled' or 'on_court'.
     */
    public function pushMatchToScoreboard(array $match): bool
    {
        $court = $this->getCourtConfig((int)$match['court_id']);
        if (!$court || empty($court['scoreboard_api_base_url'])) {
            error_log("ScoreboardConnector: no active court config for court_id={$match['court_id']}");
            return false;
        }

        $payload = [
            'fight_number'   => $match['playnumber'],
            'play_code'      => $match['playcode'],
            'weight_category'=> $match['corashweight_text'],
            'athlete_blue'   => [
                'name' => $match['fullname_a'],
                'noc'  => $match['boardid_a'], // TODO: map to actual NOC code once athlete NOC is joined in
            ],
            'athlete_white'  => [ // Kurash traditionally uses green/blue jackets; confirm color convention with the vendor
                'name' => $match['fullname_b'],
                'noc'  => $match['boardid_b'],
            ],
        ];

        // TODO: replace with the real endpoint + auth header once documented
        $ch = curl_init(rtrim($court['scoreboard_api_base_url'], '/') . '/match');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($court['scoreboard_api_key'] ?? ''),
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $success = ($httpCode >= 200 && $httpCode < 300);

        $stmt = $this->pdo->prepare("
            UPDATE championplaytablekurash
            SET scoreboard_synced_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$match['id']]);

        if (!$success) {
            error_log("ScoreboardConnector: push failed for match {$match['id']}, HTTP {$httpCode}, response: {$response}");
        }

        return $success;
    }

    /**
     * Actively poll a court's scoreboard for the current/latest score.
     * Use this ONLY if the scoreboard doesn't support webhooks — otherwise
     * prefer scoreboard-webhook.php, which is push-based and lower latency.
     */
    public function pullScoreFromScoreboard(int $courtId, string $playCode): ?array
    {
        $court = $this->getCourtConfig($courtId);
        if (!$court || empty($court['scoreboard_api_base_url'])) {
            return null;
        }

        // TODO: replace with the real endpoint once documented
        $ch = curl_init(rtrim($court['scoreboard_api_base_url'], '/') . '/match/' . urlencode($playCode) . '/score');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . ($court['scoreboard_api_key'] ?? ''),
            ],
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        return json_decode($response, true); // expected shape TBD from vendor docs
    }

    /**
     * Applies a score/result payload (however it arrived — webhook or poll)
     * to our own match table. This part IS ours to define, since it just
     * writes into our own schema.
     */
    public function applyResultToMatch(string $playCode, array $result): bool
    {
        $stmt = $this->pdo->prepare("SELECT * FROM championplaytablekurash WHERE playcode = ?");
        $stmt->execute([$playCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            return false;
        }

        // Expected $result keys — adjust once the real payload shape is known:
        // score_a, score_b, winner_side ('a'|'b'), win_type (e.g. 'points','ippon','disqualification')
        $winnerSide = $result['winner_side'] ?? null;
        $winnerFields = [];
        if ($winnerSide === 'a') {
            $winnerFields = [
                'winner_athleteid' => $match['athleteid_a'],
                'winner_userid' => $match['userid_a'],
                'winner_boardid' => $match['boardid_a'],
                'winner_lotterynumber' => $match['lotterynumber_a'],
                'winner_fullname' => $match['fullname_a'],
                'winner_registerid' => $match['registerid_a'],
            ];
        } elseif ($winnerSide === 'b') {
            $winnerFields = [
                'winner_athleteid' => $match['athleteid_b'],
                'winner_userid' => $match['userid_b'],
                'winner_boardid' => $match['boardid_b'],
                'winner_lotterynumber' => $match['lotterynumber_b'],
                'winner_fullname' => $match['fullname_b'],
                'winner_registerid' => $match['registerid_b'],
            ];
        }

        $stmt = $this->pdo->prepare("
            UPDATE championplaytablekurash
            SET score_a = ?, score_b = ?, wintype = ?,
                winner_athleteid = ?, winner_userid = ?, winner_boardid = ?,
                winner_lotterynumber = ?, winner_fullname = ?, winner_registerid = ?,
                court_status = 'completed', scoreboard_synced_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $result['score_a'] ?? null,
            $result['score_b'] ?? null,
            $result['win_type'] ?? null,
            $winnerFields['winner_athleteid'] ?? null,
            $winnerFields['winner_userid'] ?? null,
            $winnerFields['winner_boardid'] ?? null,
            $winnerFields['winner_lotterynumber'] ?? null,
            $winnerFields['winner_fullname'] ?? null,
            $winnerFields['winner_registerid'] ?? null,
            $match['id'],
        ]);

        return true;
    }
}
