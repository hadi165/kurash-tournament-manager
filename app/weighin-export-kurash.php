<?php
/**
 * Exports the CONFIRMED (status = OK / 'pass') weigh-in list for one weight
 * category, as genuine CSV (so it round-trips through fgetcsv() when
 * re-uploaded later with the Draw No. column filled in), per spec:
 *  - filename: "{Gender} {WeightCategory}.csv" e.g. "Male -91.csv"
 *  - saved into /exports/weigh-in-list-confirmed/ on the server, AND
 *    downloaded to the browser
 *  - first row: "Gender / Weight Category" summary
 *  - second row: column headers
 *  - columns: Athlete's Name | Athlete's ID (IKA) | NOC+Flag | Bracket Title | Draw No.
 *  - Draw No. column is left EMPTY — filled in offline by the executive
 *    management, then re-uploaded via the Start button (Entries by Weight
 *    Categories page).
 */
require_once __DIR__ . '/boot.php';
require_once './control/pdo-connection.php';
require_once './bracket-helpers.php';
require_once './validate-online.php';

$champion_id = filter_input(INPUT_GET, 'champion_id', FILTER_VALIDATE_INT);
$championsub_id = filter_input(INPUT_GET, 'championsub_id', FILTER_VALIDATE_INT);
$weight = filter_input(INPUT_GET, 'weight', FILTER_VALIDATE_INT);

$stmt = $pdocon->prepare("
    SELECT * FROM championregisterathletes
    WHERE champion_id = ? AND championsub_id = ? AND corashweight = ? AND weighin_status = 'pass'
    ORDER BY fullname ASC
");
$stmt->execute([$champion_id, $championsub_id, $weight]);
$athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);


$weightLabel = $athletes[0]['corashweight_text'] ?? (string)$weight;
$genders = array_unique(array_map(fn($a) => $a['gender'], $athletes));
$genderLabel = count($genders) === 1
    ? ($genders[0] === 'M' ? 'Male' : ($genders[0] === 'F' ? 'Female' : 'Mixed'))
    : 'Mixed';

$bracketTitle = bracketTitleFromCount(count($athletes));
$fileBase = $genderLabel . ' ' . $weightLabel;
$fileNameSafe = preg_replace('/[^A-Za-z0-9 \-+]/', '_', $fileBase);

function buildCsvString($athletes, $genderLabel, $weightLabel, $bracketTitle): string
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['Gender / Weight Category', $genderLabel . ' / ' . $weightLabel]);
    fputcsv($fh, ["Athlete's Name", "Athlete's ID (IKA)", 'NOC+Flag', 'Bracket Title', 'Draw No.']);
    foreach ($athletes as $a) {
        fputcsv($fh, [$a['fullname'], $a['ika_id'] ?? '', $a['noc_code'], $bracketTitle, '']);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

$csvContent = buildCsvString($athletes, $genderLabel, $weightLabel, $bracketTitle);

// Save a copy into /exports/weigh-in-list-confirmed/
$exportDir = __DIR__ . '/../weigh-in-list-confirmed';
if (!is_dir($exportDir)) {
    mkdir($exportDir, 0777, true);
}
file_put_contents($exportDir . '/' . $fileNameSafe . '.csv', $csvContent);

// Also stream it to the browser as a download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileNameSafe . '.csv"');
echo $csvContent;
