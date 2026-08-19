<?php
/**
 * Kurash Dashboard — the hub page linking every stage of the pipeline:
 * Register -> Weigh-in -> Draw -> Bracket -> Results.
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

$stmt = $pdocon->prepare("SELECT COUNT(*) FROM championregisterathletes WHERE champion_id = ? AND championsub_id = ?");
$stmt->execute([$champion_id, $championsub_id]);
$athleteCount = (int)$stmt->fetchColumn();

$stmt = $pdocon->prepare("SELECT COUNT(*) FROM championregisterathletes WHERE champion_id = ? AND championsub_id = ? AND weighin_status != 'pending'");
$stmt->execute([$champion_id, $championsub_id]);
$weighedCount = (int)$stmt->fetchColumn();

$stmt = $pdocon->prepare("SELECT COUNT(*) FROM championregisterathletes WHERE champion_id = ? AND championsub_id = ? AND corash_lotterynumber IS NOT NULL");
$stmt->execute([$champion_id, $championsub_id]);
$drawnCount = (int)$stmt->fetchColumn();

$stmt = $pdocon->prepare("SELECT COUNT(*) FROM championplaytablekurash WHERE champion_id = ? AND championsub_id = ?");
$stmt->execute([$champion_id, $championsub_id]);
$matchCount = (int)$stmt->fetchColumn();

$qs = 'champion_id=' . $champion_id . '&championsub_id=' . $championsub_id;
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - <?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        h2 { margin-top: 0; }
        .back-link { display: inline-block; margin-bottom: 16px; color: #1565c0; text-decoration: none; font-size: 14px; }
        .steps { display: flex; flex-direction: column; gap: 12px; margin-top: 20px; }
        .step { display: flex; align-items: center; justify-content: space-between; border: 1px solid #eee; border-radius: 8px; padding: 16px 20px; text-decoration: none; color: inherit; transition: box-shadow .15s; }
        .step:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .step-left { display: flex; align-items: center; gap: 14px; }
        .step-num { width: 32px; height: 32px; border-radius: 50%; background: #1565c0; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; flex-shrink: 0; }
        .step-title { font-weight: bold; font-size: 15px; }
        .step-desc { font-size: 13px; color: #888; }
        .step-status { font-size: 13px; color: #1565c0; font-weight: bold; white-space: nowrap; }
    </style>
</head>
<body>
<div class="container">
    <a class="back-link" href="championsubs-manage.php?champion_id=<?php echo $champion_id; ?>">&larr; Back to Age Categories</a>
    <h2><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></h2>
    <p style="color:#888; margin-top:-8px;"><?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?></p>

    <div class="steps">
        <a class="step" href="registration-kurash.php?<?php echo $qs; ?>">
            <div class="step-left">
                <div class="step-num">1</div>
                <div>
                    <div class="step-title">Athlete Registration</div>
                    <div class="step-desc">Register athletes with their NOC, gender, and weight category</div>
                </div>
            </div>
            <div class="step-status"><?php echo $athleteCount; ?> registered</div>
        </a>

        <a class="step" href="weighin-kurash.php?<?php echo $qs; ?>">
            <div class="step-left">
                <div class="step-num">2</div>
                <div>
                    <div class="step-title">Weigh-in</div>
                    <div class="step-desc">Confirm each athlete's actual weight against their declared category</div>
                </div>
            </div>
            <div class="step-status"><?php echo $weighedCount; ?> / <?php echo $athleteCount; ?> weighed</div>
        </a>

        <a class="step" href="draw-kurash.php?<?php echo $qs; ?>">
            <div class="step-left">
                <div class="step-num">3</div>
                <div>
                    <div class="step-title">Draw (Lottery)</div>
                    <div class="step-desc">Assign lottery numbers — manually or randomly — before generating the bracket</div>
                </div>
            </div>
            <div class="step-status"><?php echo $drawnCount; ?> / <?php echo $athleteCount; ?> drawn</div>
        </a>

        <a class="step" href="bracket-view-kurash.php?<?php echo $qs; ?>">
            <div class="step-left">
                <div class="step-num">4</div>
                <div>
                    <div class="step-title">Bracket</div>
                    <div class="step-desc">Generate and view the single-elimination bracket, round by round</div>
                </div>
            </div>
            <div class="step-status"><?php echo $matchCount; ?> matches</div>
        </a>

        <a class="step" href="champions-ranking-kurash.php?<?php echo $qs; ?>">
            <div class="step-left">
                <div class="step-num">5</div>
                <div>
                    <div class="step-title">Results / Rankings</div>
                    <div class="step-desc">Final standings (1st, 2nd, 3rd) per weight category</div>
                </div>
            </div>
            <div class="step-status">View &rarr;</div>
        </a>
    </div>
</div>
</body>
</html>
