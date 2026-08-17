<?php
/**
 * medal-helpers.php — derives medal winners from championplaytablekurash:
 * Gold = final round winner, Silver = final round loser,
 * Bronze x2 = losers of the semifinal round (if one exists).
 * Looks up NOC/gender by joining back to championregisterathletes via
 * the winner_athleteid / athleteid_a / athleteid_b columns.
 */
function computeMedalEvents(PDO $pdocon, int $champion_id): array
{
    // Every distinct (championsub_id, corashweight) with generated matches = one "event"
    $stmt = $pdocon->prepare("
        SELECT DISTINCT championsub_id, corashweight, corashweight_text
        FROM championplaytablekurash WHERE champion_id = ?
    ");
    $stmt->execute([$champion_id]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($events as $ev) {
        $championsub_id = $ev['championsub_id'];
        $weight = $ev['corashweight'];

        $stmt = $pdocon->prepare("SELECT MAX(roundnumber) FROM championplaytablekurash WHERE champion_id=? AND championsub_id=? AND corashweight=?");
        $stmt->execute([$champion_id, $championsub_id, $weight]);
        $maxRound = (int)$stmt->fetchColumn();
        if (!$maxRound) continue;

        $stmt = $pdocon->prepare("SELECT * FROM championplaytablekurash WHERE champion_id=? AND championsub_id=? AND corashweight=? AND roundnumber=?");
        $stmt->execute([$champion_id, $championsub_id, $weight, $maxRound]);
        $final = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$final || !$final['winner_athleteid']) continue; // final not decided yet

        $goldId = $final['winner_athleteid'];
        $silverId = ($final['athleteid_a'] == $goldId) ? $final['athleteid_b'] : $final['athleteid_a'];

        $bronzeIds = [];
        if ($maxRound > 1) {
            $stmt = $pdocon->prepare("SELECT * FROM championplaytablekurash WHERE champion_id=? AND championsub_id=? AND corashweight=? AND roundnumber=?");
            $stmt->execute([$champion_id, $championsub_id, $weight, $maxRound - 1]);
            $semis = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($semis as $sf) {
                if (!$sf['winner_athleteid']) continue;
                $loserId = ($sf['athleteid_a'] == $sf['winner_athleteid']) ? $sf['athleteid_b'] : $sf['athleteid_a'];
                if ($loserId) $bronzeIds[] = $loserId;
            }
        }

        $fetchAthlete = function ($id) use ($pdocon) {
            if (!$id) return null;
            $s = $pdocon->prepare("SELECT * FROM championregisterathletes WHERE id = ?");
            $s->execute([$id]);
            return $s->fetch(PDO::FETCH_ASSOC);
        };

        $results[] = [
            'championsub_id' => $championsub_id,
            'weight' => $weight,
            'weight_text' => $ev['corashweight_text'],
            'gold' => $fetchAthlete($goldId),
            'silver' => $fetchAthlete($silverId),
            'bronze' => array_map($fetchAthlete, $bronzeIds),
        ];
    }
    return $results;
}
