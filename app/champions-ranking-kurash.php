<?php
ob_start();
session_start();

include_once './control/config.php';
require_once './control/DB.php';
require_once './control/pdo-connection.php';
require_once './control/class.php';
require_once './validate-online.php';

$dbcon = new DB();
$getInfo = new DB();

$champion_id = filter_input(INPUT_GET, 'champion_id', FILTER_VALIDATE_INT);
$championsub_id = filter_input(INPUT_GET, 'championsub_id', FILTER_VALIDATE_INT);

$championInfo = $dbcon->getTableById('champions', $champion_id);
$championSubInfo = $dbcon->getTableById('championsubs', $championsub_id);

$weights = explode('/', $championSubInfo['corashweights']);
$weightsText = explode('/', $championSubInfo['corashweights_text']);
asort($weights);
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kurash Championship Rankings - <?php echo $championInfo['title']; ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
            direction: ltr;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .ranking-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .ranking-table th, .ranking-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: center;
        }
        .ranking-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        .weight-header {
            background: #e9ecef;
            font-weight: bold;
        }
        .rank-1 { background: #e3f2fd; }
        .rank-2 { background: #f5f5f5; }
        .rank-3 { background: #fff3e0; }
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .container {
                box-shadow: none;
            }
            .no-print {
                display: none;
            }
        }
        .weight-header td {
            font-weight: bold;
            font-size: 1.1em;
            padding: 15px !important;
        }

        .rank-1 td:first-child,
        .rank-2 td:first-child,
        .rank-3 td:first-child {
            width: 80px;
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2><?php echo $championInfo['title']; ?></h2>
            <h3>Kurash Rankings - Age Category: <?php echo $championSubInfo['subtitle']; ?></h3>
        </div>

        <table class="ranking-table">
            <thead>
                <tr>
                    <th>Weight</th>
                    <th>Rank</th>
                    <th>Full Name</th>
                    <th>Province</th>
                    <th>Coach</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($weights as $key => $weight):
                $stmt = $pdocon->prepare("
                    SELECT * FROM championplaytablekurash
                    WHERE champion_id = ?
                    AND championsub_id = ?
                    AND corashweight = ?
                    AND roundnumber = (
                        SELECT MAX(roundnumber)
                        FROM championplaytablekurash
                        WHERE champion_id = ?
                        AND championsub_id = ?
                        AND corashweight = ?
                    )
                    LIMIT 1
                ");
                $stmt->execute([$champion_id, $championsub_id, $weight, $champion_id, $championsub_id, $weight]);
                $finalMatch = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($finalMatch):
                    if ($finalMatch['winner_lotterynumber'] == $finalMatch['lotterynumber_a']) {
                        $first = [
                            'name' => $finalMatch['fullname_a'],
                            'board_id' => $finalMatch['boardid_a']
                        ];
                        $second = [
                            'name' => $finalMatch['fullname_b'],
                            'board_id' => $finalMatch['boardid_b']
                        ];
                    } else {
                        $first = [
                            'name' => $finalMatch['fullname_b'],
                            'board_id' => $finalMatch['boardid_b']
                        ];
                        $second = [
                            'name' => $finalMatch['fullname_a'],
                            'board_id' => $finalMatch['boardid_a']
                        ];
                    }

                    $stmt = $pdocon->prepare("
                        SELECT * FROM championplaytablekurash
                        WHERE champion_id = ?
                        AND championsub_id = ?
                        AND corashweight = ?
                        AND playnumber IN (?, ?)
                    ");
                    $stmt->execute([
                        $champion_id,
                        $championsub_id,
                        $weight,
                        $finalMatch['pre_playnumber_a'],
                        $finalMatch['pre_playnumber_b']
                    ]);
                    $thirdPlaces = $stmt->fetchAll(PDO::FETCH_ASSOC);

                ?>
                    <tr class="weight-header">
                        <td colspan="5" style="text-align: center; background: #e9ecef;">
                            <?php echo htmlspecialchars($weightsText[$key]); ?> kg
                        </td>
                    </tr>
                    <tr class="rank-1">
                        <td>Weight</td>
                        <td>1</td>
                        <td><?php echo $first['name']; ?></td>
                        <td><?php echo $dbcon->getTableById('boards', $first['board_id'])['title']; ?></td>
                        <td><?php echo $getInfo->getCoachName($champion_id, $first['board_id']); ?></td>
                    </tr>
                    <tr class="rank-2">
                        <td>Weight</td>
                        <td>2</td>
                        <td><?php echo $second['name']; ?></td>
                        <td><?php echo $dbcon->getTableById('boards', $second['board_id'])['title']; ?></td>
                        <td><?php echo $getInfo->getCoachName($champion_id, $second['board_id']); ?></td>
                    </tr>
                    <?php foreach ($thirdPlaces as $third):
                        $loser = $third['winner_lotterynumber'] == $third['lotterynumber_a'] ?
                            ['name' => $third['fullname_b'], 'board_id' => $third['boardid_b']] :
                            ['name' => $third['fullname_a'], 'board_id' => $third['boardid_a']];
                    ?>
                    <tr class="rank-3">
                        <td>Weight</td>
                        <td>3</td>
                        <td><?php echo $loser['name']; ?></td>
                        <td><?php echo $dbcon->getTableById('boards', $loser['board_id'])['title']; ?></td>
                        <td><?php echo $getInfo->getCoachName($champion_id, $loser['board_id']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="no-print" style="text-align: center; margin-top: 20px;">
            <button onclick="window.print()">Print Results</button>
        </div>
    </div>
</body>
</html>
