<?php
/**
 * Kurash bracket generator API.
 * Adapted from the Sanda bracket generator. Uses the `corash` weight/lottery
 * fields already present in championregisterathletes / championregisters,
 * and writes matches into `championplaytablekurash`.
 */
header('Content-Type: application/json');
require_once('connection.php');

function logDebug($message, $data = null) {
    error_log(date('Y-m-d H:i:s') . " - " . $message . ($data ? " - " . json_encode($data) : ""));
}

// Delete all previously generated matches for this champion/sub-category
function deleteExistingMatches($pdo, $champion_id, $championsub_id) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM championplaytablekurash
            WHERE champion_id = ? AND championsub_id = ?
        ");
        $stmt->execute([$champion_id, $championsub_id]);

        logDebug("Deleted existing Kurash matches", [
            'champion_id' => $champion_id,
            'championsub_id' => $championsub_id,
            'rows_affected' => $stmt->rowCount()
        ]);

        return true;
    } catch (PDOException $e) {
        logDebug("Error deleting existing Kurash matches", [
            'error' => $e->getMessage(),
            'champion_id' => $champion_id,
            'championsub_id' => $championsub_id
        ]);
        throw $e;
    }
}

// Bracket structures — identical seeding logic to Sanda (standard single-elimination seeding)
$firstRound = [
    4 => [
        ['1', '4'],
        ['3', '2'],
    ],
    8 => [
        ['1', '8'],
        ['4', '5'],
        ['2', '7'],
        ['3', '6'],
    ],
    16 => [
        ['1', '16'],
        ['9', '8'],
        ['5', '12'],
        ['13', '4'],
        ['2', '15'],
        ['10', '7'],
        ['6', '11'],
        ['14', '3'],
    ],
    32 => [
        ['1', '32'], ['16', '17'], ['9', '24'], ['8', '25'],
        ['5', '28'], ['12', '21'], ['13', '20'], ['4', '29'],
        ['2', '31'], ['15', '18'], ['10', '23'], ['7', '26'],
        ['6', '27'], ['11', '22'], ['14', '19'], ['3', '30'],
    ],
    64 => [
        ['1', '64'], ['32', '33'], ['17', '48'], ['16', '49'],
        ['9', '56'], ['24', '41'], ['25', '40'], ['8', '57'],
        ['5', '60'], ['28', '37'], ['21', '44'], ['12', '53'],
        ['13', '52'], ['20', '45'], ['29', '36'], ['4', '61'],
        ['2', '63'], ['31', '34'], ['18', '47'], ['15', '50'],
        ['10', '55'], ['23', '42'], ['26', '39'], ['7', '58'],
        ['6', '59'], ['27', '38'], ['22', '43'], ['11', '54'],
        ['14', '51'], ['19', '46'], ['30', '35'], ['3', '62']
    ]
];

$secondRound = [
    4 => [
        '1' => [['1', '4'], ['3', '2']],
    ],
    8 => [
        '1' => [['1', '8'], ['4', '5']],
        '2' => [['2', '7'], ['3', '6']],
    ],
    16 => [
        '1' => [['1', '16'], ['9', '8']],
        '2' => [['5', '12'], ['13', '4']],
        '3' => [['2', '15'], ['10', '7']],
        '4' => [['6', '11'], ['14', '3']],
    ],
    32 => [
        '1' => [['1', '32'], ['16', '17']],
        '2' => [['9', '24'], ['8', '25']],
        '3' => [['5', '28'], ['12', '21']],
        '4' => [['13', '20'], ['4', '29']],
        '5' => [['2', '31'], ['15', '18']],
        '6' => [['10', '23'], ['7', '26']],
        '7' => [['6', '27'], ['11', '22']],
        '8' => [['14', '19'], ['3', '30']],
    ],
    64 => [
        '1' => [['1', '64'], ['32', '33']],
        '2' => [['17', '48'], ['16', '49']],
        '3' => [['9', '56'], ['24', '41']],
        '4' => [['25', '40'], ['8', '57']],
        '5' => [['5', '60'], ['28', '37']],
        '6' => [['21', '44'], ['12', '53']],
        '7' => [['13', '52'], ['20', '45']],
        '8' => [['29', '36'], ['4', '61']],
        '9' => [['2', '63'], ['31', '34']],
        '10' => [['18', '47'], ['15', '50']],
        '11' => [['10', '55'], ['23', '42']],
        '12' => [['26', '39'], ['7', '58']],
        '13' => [['6', '59'], ['27', '38']],
        '14' => [['22', '43'], ['11', '54']],
        '15' => [['14', '51'], ['19', '46']],
        '16' => [['30', '35'], ['3', '62']],
    ]
];

function generatePlayCode($pdo, $championsub_id) {
    $maxAttempts = 10;
    $attempts = 0;

    do {
        $randomNum = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $code = $championsub_id . $randomNum;

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM championplaytablekurash
            WHERE playcode = ?
        ");
        $stmt->execute([$code]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

        $attempts++;

        if (!$exists) {
            return $code;
        }
    } while ($attempts < $maxAttempts);

    throw new Exception("Could not generate unique play code after {$maxAttempts} attempts");
}

function getWeights($pdo, $championsub_id) {
    $stmt = $pdo->prepare("SELECT corashweights, corashweights_text FROM championsubs WHERE id = ?");
    $stmt->execute([$championsub_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        throw new Exception("No weights found for championsub_id: $championsub_id");
    }

    return [
        'ids' => explode('/', $result['corashweights']),
        'texts' => explode('/', $result['corashweights_text'])
    ];
}

function getAthletesByWeight($pdo, $champion_id, $championsub_id, $weight_id) {
    $stmt = $pdo->prepare("
        SELECT a.id, a.user_id, a.board_id, a.fullname, a.corash_lotterynumber,
               r.id as register_id
        FROM championregisterathletes a
        LEFT JOIN championregisters r ON r.board_id = a.board_id
            AND r.champion_id = a.champion_id
        WHERE a.champion_id = ?
        AND a.championsub_id = ?
        AND a.corashweight = ?
        AND a.corash_lotterynumber > 0
        AND a.corash_lotterynumber IS NOT NULL
        ORDER BY a.corash_lotterynumber ASC
    ");
    $stmt->execute([$champion_id, $championsub_id, $weight_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function determinePlayStructure($athleteCount) {
    if ($athleteCount <= 4) return ['rounds' => 2, 'bracket' => 4];
    if ($athleteCount <= 8) return ['rounds' => 3, 'bracket' => 8];
    if ($athleteCount <= 16) return ['rounds' => 4, 'bracket' => 16];
    if ($athleteCount <= 32) return ['rounds' => 5, 'bracket' => 32];
    return ['rounds' => 6, 'bracket' => 64];
}

function getFirstRoundPairs($bracket) {
    global $firstRound;
    return $firstRound[$bracket] ?? [];
}

function findAthleteByLotteryNumber($athletes, $lotteryNumber) {
    foreach ($athletes as $athlete) {
        if ($athlete['corash_lotterynumber'] == $lotteryNumber) {
            return $athlete;
        }
    }
    return null;
}

function createFirstRoundMatches($pdo, $weightData, $weight_id, $roundNumber, &$playNumber, $champion_id, $championsub_id) {
    $bracket = $weightData['structure']['bracket'];
    $firstRoundPairs = getFirstRoundPairs($bracket);

    foreach ($firstRoundPairs as $pair) {
        $athleteA = findAthleteByLotteryNumber($weightData['athletes'], $pair[0]);
        $athleteB = findAthleteByLotteryNumber($weightData['athletes'], $pair[1]);

        $matchData = [
            'playcode' => generatePlayCode($pdo, $championsub_id),
            'champion_id' => $champion_id,
            'championsub_id' => $championsub_id,
            'corashweight' => $weight_id,
            'corashweight_text' => $weightData['weight_text'],
            'roundnumber' => $roundNumber,
            'lotterynumber_a' => $pair[0],
            'lotterynumber_b' => $pair[1]
        ];

        if ($athleteA && $athleteB) {
            $matchData['playnumber'] = $playNumber++;
            $matchData['athleteid_a'] = $athleteA['id'];
            $matchData['athleteid_b'] = $athleteB['id'];
            $matchData['userid_a'] = $athleteA['user_id'];
            $matchData['userid_b'] = $athleteB['user_id'];
            $matchData['fullname_a'] = $athleteA['fullname'];
            $matchData['fullname_b'] = $athleteB['fullname'];
            $matchData['boardid_a'] = $athleteA['board_id'];
            $matchData['boardid_b'] = $athleteB['board_id'];
            $matchData['registerid_a'] = $athleteA['register_id'];
            $matchData['registerid_b'] = $athleteB['register_id'];
            $matchData['lotterynumber_a'] = $pair[0];
            $matchData['lotterynumber_b'] = $pair[1];
        }
        elseif ($athleteA) {
            $matchData['playnumber'] = $playNumber++;
            $matchData['athleteid_a'] = $athleteA['id'];
            $matchData['userid_a'] = $athleteA['user_id'];
            $matchData['fullname_a'] = $athleteA['fullname'];
            $matchData['fullname_b'] = 'Bye';
            $matchData['boardid_a'] = $athleteA['board_id'];
            $matchData['registerid_a'] = $athleteA['register_id'];
            $matchData['winner_athleteid'] = $athleteA['id'];
            $matchData['winner_userid'] = $athleteA['user_id'];
            $matchData['winner_boardid'] = $athleteA['board_id'];
            $matchData['winner_lotterynumber'] = $pair[0];
            $matchData['winner_fullname'] = $athleteA['fullname'];
            $matchData['winner_registerid'] = $athleteA['register_id'];
            $matchData['wintype'] = 'bye';
        }
        elseif ($athleteB) {
            $matchData['playnumber'] = $playNumber++;
            $matchData['athleteid_b'] = $athleteB['id'];
            $matchData['userid_b'] = $athleteB['user_id'];
            $matchData['fullname_a'] = 'Bye';
            $matchData['fullname_b'] = $athleteB['fullname'];
            $matchData['boardid_b'] = $athleteB['board_id'];
            $matchData['registerid_b'] = $athleteB['register_id'];
            $matchData['winner_athleteid'] = $athleteB['id'];
            $matchData['winner_userid'] = $athleteB['user_id'];
            $matchData['winner_boardid'] = $athleteB['board_id'];
            $matchData['winner_lotterynumber'] = $pair[1];
            $matchData['winner_fullname'] = $athleteB['fullname'];
            $matchData['winner_registerid'] = $athleteB['register_id'];
            $matchData['wintype'] = 'bye';
        }

        if ($athleteA || $athleteB) {
            insertMatch($pdo, $matchData);
        }
    }
}

// Helper function to find a match by lottery numbers
function findMatchByLotteryNumbers($matches, $number1, $number2) {
    foreach ($matches as $match) {
        if (($match['lotterynumber_a'] == $number1 && $match['lotterynumber_b'] == $number2) ||
            ($match['lotterynumber_a'] == $number2 && $match['lotterynumber_b'] == $number1)) {
            return $match;
        }
    }
    return null;
}

// Helper function to copy winner information forward into the next round
function copyWinnerInfo(&$matchData, $side, $sourceMatch) {
    $prefix = $side == 'a' ? 'athleteid_a' : 'athleteid_b';
    if ($sourceMatch['winner_lotterynumber']) {
        $matchData[$prefix] = $sourceMatch['winner_athleteid'];
        $matchData['userid_' . $side] = $sourceMatch['winner_userid'];
        $matchData['fullname_' . $side] = $sourceMatch['winner_fullname'];
        $matchData['boardid_' . $side] = $sourceMatch['winner_boardid'];
        $matchData['registerid_' . $side] = $sourceMatch['winner_registerid'];
        $matchData['lotterynumber_' . $side] = $sourceMatch['winner_lotterynumber'];
    }
}

function createNextRoundMatches($pdo, $weight_id, $roundNumber, &$playNumber, $champion_id, $championsub_id) {
    global $secondRound;

    // Get previous round matches
    $stmt = $pdo->prepare("
        SELECT *
        FROM championplaytablekurash
        WHERE champion_id = ? AND championsub_id = ? AND corashweight = ? AND roundnumber = ?
        ORDER BY playnumber ASC, id ASC
    ");
    $stmt->execute([$champion_id, $championsub_id, $weight_id, $roundNumber - 1]);
    $prevMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total number of athletes to determine bracket size
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM championregisterathletes
        WHERE champion_id = ? AND championsub_id = ? AND corashweight = ? AND corash_lotterynumber > 0 AND corash_lotterynumber IS NOT NULL
    ");
    $stmt->execute([$champion_id, $championsub_id, $weight_id]);
    $totalAthletes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $bracketSize = 4;
    if ($totalAthletes > 4) $bracketSize = 8;
    if ($totalAthletes > 8) $bracketSize = 16;
    if ($totalAthletes > 16) $bracketSize = 32;
    if ($totalAthletes > 32) $bracketSize = 64;

    if ($roundNumber == 2) {
        foreach ($secondRound[$bracketSize] as $group) {
            $match1Numbers = $group[0];
            $match2Numbers = $group[1];

            $match1 = findMatchByLotteryNumbers($prevMatches, $match1Numbers[0], $match1Numbers[1]);
            $match2 = findMatchByLotteryNumbers($prevMatches, $match2Numbers[0], $match2Numbers[1]);

            if ($match1 && $match2) {
                $matchData = [
                    'playcode' => generatePlayCode($pdo, $championsub_id),
                    'champion_id' => $champion_id,
                    'championsub_id' => $championsub_id,
                    'corashweight' => $weight_id,
                    'corashweight_text' => $match1['corashweight_text'],
                    'roundnumber' => $roundNumber,
                    'playnumber' => $playNumber++,
                    'pre_playnumber_a' => $match1['playnumber'],
                    'pre_playnumber_b' => $match2['playnumber']
                ];

                if ($match1['winner_lotterynumber']) {
                    copyWinnerInfo($matchData, 'a', $match1);
                }
                if ($match2['winner_lotterynumber']) {
                    copyWinnerInfo($matchData, 'b', $match2);
                }

                insertMatch($pdo, $matchData);
            }
        }
    } else {
        $matchPairs = array_chunk($prevMatches, 2);
        foreach ($matchPairs as $pair) {
            if (count($pair) == 2) {
                $matchData = [
                    'playcode' => generatePlayCode($pdo, $championsub_id),
                    'champion_id' => $champion_id,
                    'championsub_id' => $championsub_id,
                    'corashweight' => $weight_id,
                    'corashweight_text' => $pair[0]['corashweight_text'],
                    'roundnumber' => $roundNumber,
                    'playnumber' => $playNumber++,
                    'pre_playnumber_a' => $pair[0]['playnumber'],
                    'pre_playnumber_b' => $pair[1]['playnumber']
                ];

                if ($pair[0]['winner_lotterynumber']) {
                    copyWinnerInfo($matchData, 'a', $pair[0]);
                }
                if ($pair[1]['winner_lotterynumber']) {
                    copyWinnerInfo($matchData, 'b', $pair[1]);
                }

                insertMatch($pdo, $matchData);
            }
        }
    }
}

function createMatches($pdo, $champion_id, $championsub_id) {
    $weights = getWeights($pdo, $championsub_id);
    $playNumber = 1;
    $safetyCounter = 100;

    $weightStructures = [];
    foreach ($weights['ids'] as $index => $weight_id) {
        $athletes = getAthletesByWeight($pdo, $champion_id, $championsub_id, $weight_id);

        if (empty($athletes)) {
            logDebug("Skipping weight_id: no athletes", ['weight_id' => $weight_id]);
            continue;
        }

        $structure = determinePlayStructure(count($athletes));
        $weightStructures[$weight_id] = [
            'athletes' => $athletes,
            'structure' => $structure,
            'weight_text' => $weights['texts'][$index],
            'remaining_rounds' => $structure['rounds']
        ];
    }

    while (!empty($weightStructures) && $safetyCounter > 0) {
        $safetyCounter--;
        $maxRounds = max(array_column($weightStructures, 'remaining_rounds'));

        if ($maxRounds <= 0) {
            break;
        }

        foreach ($weightStructures as $weight_id => &$weightData) {
            if ($weightData['remaining_rounds'] == $maxRounds) {
                $currentRound = $weightData['structure']['rounds'] - $weightData['remaining_rounds'] + 1;

                if ($currentRound == 1) {
                    createFirstRoundMatches($pdo, $weightData, $weight_id, $currentRound, $playNumber, $champion_id, $championsub_id);
                } else {
                    createNextRoundMatches($pdo, $weight_id, $currentRound, $playNumber, $champion_id, $championsub_id);
                }

                $weightData['remaining_rounds']--;

                if ($weightData['remaining_rounds'] <= 0) {
                    unset($weightStructures[$weight_id]);
                }
            }
        }
    }

    if ($safetyCounter <= 0) {
        throw new Exception("Maximum iteration limit reached");
    }
}

function insertMatch($pdo, $matchData) {
    $fields = implode(', ', array_keys($matchData));
    $values = implode(', ', array_fill(0, count($matchData), '?'));

    try {
        $sql = "INSERT INTO championplaytablekurash ($fields) VALUES ($values)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($matchData));

        logDebug("Kurash match inserted successfully", [
            'match_data' => $matchData
        ]);
    } catch (PDOException $e) {
        logDebug("Error inserting Kurash match", [
            'error' => $e->getMessage(),
            'data' => $matchData
        ]);
        throw $e;
    }
}

// Main execution
try {
    $champion_id = filter_input(INPUT_GET, 'champion_id') ?? filter_input(INPUT_POST, 'champion_id');
    $championsub_id = filter_input(INPUT_GET, 'championsub_id') ?? filter_input(INPUT_POST, 'championsub_id');

    if (!$champion_id || !$championsub_id) {
        throw new Exception('Missing required parameters: champion_id and championsub_id');
    }

    if (!is_numeric($champion_id) || !is_numeric($championsub_id)) {
        throw new Exception('Invalid parameters: champion_id and championsub_id must be numeric');
    }

    logDebug("Starting Kurash match creation", [
        'champion_id' => $champion_id,
        'championsub_id' => $championsub_id
    ]);

    // Delete existing matches before creating new ones
    deleteExistingMatches($pdo, $champion_id, $championsub_id);

    // Continue with match creation
    createMatches($pdo, $champion_id, $championsub_id);

    echo json_encode([
        'status' => 'success',
        'message' => 'Kurash match brackets created successfully'
    ]);
} catch (Exception $e) {
    logDebug("Error occurred", ['message' => $e->getMessage()]);
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
