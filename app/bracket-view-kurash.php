<?php
/**
 * Kurash Bracket View
 * Items 6-7 of the plan: generate the bracket (calls the existing
 * champion-create-table-kurash-api.php) and display it, round by round,
 * for each weight category.
 */
ob_start();
require_once __DIR__ . '/boot.php';

include_once './control/config.php';
require_once './control/DB.php';
require_once './control/pdo-connection.php';
require_once './control/class.php';
require_once './validate-online.php';

$dbcon = new DB();

$champion_id = filter_input(INPUT_GET, 'champion_id', FILTER_VALIDATE_INT);
$championsub_id = filter_input(INPUT_GET, 'championsub_id', FILTER_VALIDATE_INT);

$championInfo = $dbcon->getTableById('champions', $champion_id);
$championSubInfo = $dbcon->getTableById('championsubs', $championsub_id);

$weightIds = !empty($championSubInfo['corashweights']) ? explode('/', $championSubInfo['corashweights']) : [];
$weightTexts = !empty($championSubInfo['corashweights_text']) ? explode('/', $championSubInfo['corashweights_text']) : [];

// Load all generated matches for this championship/age-category, grouped by weight then round
$stmt = $pdocon->prepare("
    SELECT * FROM championplaytablekurash
    WHERE champion_id = ? AND championsub_id = ?
    ORDER BY corashweight ASC, roundnumber ASC, playnumber ASC
");
$stmt->execute([$champion_id, $championsub_id]);
$allMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

$matchesByWeight = [];
foreach ($allMatches as $m) {
    $matchesByWeight[$m['corashweight']][$m['roundnumber']][] = $m;
}

$roundNames = [1 => 'Round 1', 2 => 'Round 2', 3 => 'Quarterfinal', 4 => 'Semifinal', 5 => 'Final'];
function roundLabel($roundNumber, $totalRounds) {
    global $roundNames;
    // Label from the end, so the last round is always "Final" regardless of bracket size
    $fromEnd = $totalRounds - $roundNumber;
    $labels = ['Final', 'Semifinal', 'Quarterfinal'];
    return $labels[$fromEnd] ?? ('Round ' . $roundNumber);
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kurash Bracket - <?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        h2 { margin-top: 0; }
        .back-link { display: inline-block; margin-bottom: 16px; color: #1565c0; text-decoration: none; font-size: 14px; }
        .toolbar { margin-bottom: 24px; display: flex; gap: 10px; align-items: center; }
        .btn { background: #1565c0; color: #fff; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .btn:hover { background: #0d47a1; }
        .status { font-size: 13px; color: #888; }
        .weight-section { margin-bottom: 36px; }
        .weight-title { background: #e9ecef; padding: 10px 16px; font-weight: bold; border-radius: 6px 6px 0 0; }
        .rounds { display: flex; gap: 16px; overflow-x: auto; border: 1px solid #eee; border-top: none; padding: 16px; border-radius: 0 0 6px 6px; }
        .round-col { min-width: 220px; }
        .round-col h4 { margin: 0 0 10px; font-size: 13px; color: #555; text-transform: uppercase; }
        .match { border: 1px solid #ddd; border-radius: 6px; margin-bottom: 12px; overflow: hidden; font-size: 13px; }
        .match .side { padding: 8px 10px; border-bottom: 1px solid #eee; }
        .match .side:last-child { border-bottom: none; }
        .match .winner { background: #e8f5e9; font-weight: bold; }
        .match .bye { color: #aaa; font-style: italic; }
        .empty { padding: 40px; text-align: center; color: #888; }
    </style>
</head>
<body>
<div class="container">
    <a class="back-link" href="main-dashboard.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>">&larr; Back to Dashboard</a>
    <h2>Bracket</h2>
    <p><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?> — <?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?></p>

    <div class="toolbar">
        <button class="btn" id="generateBtn">Generate / Regenerate Bracket</button>
        <span class="status" id="statusText"></span>
    </div>

    <?php if (empty($allMatches)): ?>
        <div class="empty">No bracket generated yet. Make sure athletes have lottery numbers (Draw step), then click "Generate / Regenerate Bracket" above.</div>
    <?php endif; ?>

    <?php foreach ($weightIds as $idx => $wid): ?>
        <?php if (empty($matchesByWeight[$wid])) continue; ?>
        <div class="weight-section">
            <div class="weight-title"><?php echo htmlspecialchars($weightTexts[$idx] ?? $wid); ?> kg</div>
            <div class="rounds">
                <?php
                $rounds = $matchesByWeight[$wid];
                ksort($rounds);
                $totalRounds = max(array_keys($rounds));
                foreach ($rounds as $roundNum => $matches):
                ?>
                <div class="round-col">
                    <h4><?php echo roundLabel($roundNum, $totalRounds); ?></h4>
                    <?php foreach ($matches as $m): ?>
                        <div class="match">
                            <div class="side <?php echo ($m['winner_lotterynumber'] && $m['winner_lotterynumber'] == $m['lotterynumber_a']) ? 'winner' : ''; ?> <?php echo empty($m['fullname_a']) ? 'bye' : ''; ?>">
                                <?php echo htmlspecialchars($m['fullname_a'] ?: 'TBD'); ?>
                            </div>
                            <div class="side <?php echo ($m['winner_lotterynumber'] && $m['winner_lotterynumber'] == $m['lotterynumber_b']) ? 'winner' : ''; ?> <?php echo empty($m['fullname_b']) ? 'bye' : ''; ?>">
                                <?php echo htmlspecialchars($m['fullname_b'] ?: 'TBD'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
document.getElementById('generateBtn').addEventListener('click', async function () {
    const btn = this;
    const status = document.getElementById('statusText');
    btn.disabled = true;
    status.textContent = 'Generating...';

    // Parameters go in the body now, not the query string: the endpoint is
    // POST-only and CSRF-protected.
    const body = new URLSearchParams({
        champion_id: '<?php echo (int)$champion_id; ?>',
        championsub_id: '<?php echo (int)$championsub_id; ?>',
        _token: '<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>'
    });

    try {
        let res = await fetch('champion-create-table-kurash-api.php', { method: 'POST', body });
        let data = await res.json();

        // 409 means results already exist for this category. Make the operator
        // say out loud that they are discarding them.
        if (res.status === 409) {
            const ok = confirm(data.message + '\n\nErase those results and regenerate?');
            if (!ok) {
                status.textContent = 'Cancelled. Existing results kept.';
                btn.disabled = false;
                return;
            }
            body.append('confirm_discard_results', '1');
            res = await fetch('champion-create-table-kurash-api.php', { method: 'POST', body });
            data = await res.json();
        }

        if (data.status === 'success') {
            status.textContent = 'Bracket generated. Reloading...';
            setTimeout(() => window.location.reload(), 600);
        } else {
            status.textContent = 'Error: ' + data.message;
            btn.disabled = false;
        }
    } catch (e) {
        status.textContent = 'Request failed: ' + e.message;
        btn.disabled = false;
    }
});
</script>
</body>
</html>
