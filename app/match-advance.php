<?php
/**
 * match-advance.php — carry a match winner into the next round.
 *
 * The bracket generator links rounds with pre_playnumber_a / pre_playnumber_b,
 * but before this file nothing ever traversed those links in the result
 * direction: copyWinnerInfo() only runs while the bracket is being generated,
 * which is before any fight has happened. The consequence was that round two
 * onward stayed permanently empty once real results started arriving.
 *
 * Shared by ScoreboardConnector (after a result lands) and by the bracket
 * generator (to resolve byes, which are decided the moment they are created).
 */

if (!function_exists('kurash_advance_winner')) {
    /**
     * @param array $match A full championplaytablekurash row, including the
     *                     winner_* columns already written.
     * @return bool True if a next-round slot was filled.
     */
    function kurash_advance_winner(PDO $pdo, array $match): bool
    {
        if (empty($match['winner_athleteid'])) {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT id, pre_playnumber_a, pre_playnumber_b
            FROM championplaytablekurash
            WHERE champion_id = ?
              AND championsub_id = ?
              AND corashweight = ?
              AND roundnumber = ?
              AND (pre_playnumber_a = ? OR pre_playnumber_b = ?)
            LIMIT 1
        ");
        $stmt->execute([
            $match['champion_id'],
            $match['championsub_id'],
            $match['corashweight'],
            (int)$match['roundnumber'] + 1,
            $match['playnumber'],
            $match['playnumber'],
        ]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$next) {
            return false; // that was the final
        }

        // Resolved to exactly 'a' or 'b' here, never taken from input, so it is
        // safe to interpolate into the column names below.
        $side = ((int)$next['pre_playnumber_a'] === (int)$match['playnumber']) ? 'a' : 'b';

        $upd = $pdo->prepare("
            UPDATE championplaytablekurash SET
                athleteid_{$side}     = ?,
                userid_{$side}        = ?,
                fullname_{$side}      = ?,
                boardid_{$side}       = ?,
                registerid_{$side}    = ?,
                lotterynumber_{$side} = ?
            WHERE id = ?
        ");
        $upd->execute([
            $match['winner_athleteid'],
            $match['winner_userid'],
            $match['winner_fullname'],
            $match['winner_boardid'],
            $match['winner_registerid'],
            $match['winner_lotterynumber'],
            $next['id'],
        ]);

        return true;
    }

    /**
     * Byes are decided at generation time, so their winners must be pushed
     * forward as soon as the bracket exists. Walks rounds in order, because a
     * bye in round one can produce a walkover into round two.
     */
    function kurash_advance_all_byes(PDO $pdo, int $championId, int $championsubId): int
    {
        $stmt = $pdo->prepare("
            SELECT * FROM championplaytablekurash
            WHERE champion_id = ? AND championsub_id = ?
              AND wintype = 'bye' AND winner_athleteid IS NOT NULL
            ORDER BY corashweight ASC, roundnumber ASC, playnumber ASC
        ");
        $stmt->execute([$championId, $championsubId]);

        $advanced = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $byeMatch) {
            if (kurash_advance_winner($pdo, $byeMatch)) {
                $advanced++;
            }
        }

        return $advanced;
    }
}
