<?php
/**
 * CSV export for Table 1 (opens fine in Excel — File > Open, or double-click).
 * Genuine CSV, not an HTML-table trick, because this file is later
 * re-uploaded (with a Draw No. column added) and parsed with fgetcsv().
 */
session_start();
require_once './control/pdo-connection.php';
require_once './bracket-helpers.php';
require_once './validate-online.php';

$champion_id = filter_input(INPUT_GET, 'champion_id', FILTER_VALIDATE_INT);
$championsub_id = filter_input(INPUT_GET, 'championsub_id', FILTER_VALIDATE_INT);

$stmt = $pdocon->prepare("
    SELECT * FROM championregisterathletes
    WHERE champion_id = ? AND championsub_id = ?
    ORDER BY corashweight ASC, fullname ASC
");
$stmt->execute([$champion_id, $championsub_id]);
$athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countsByWeight = [];
foreach ($athletes as $a) {
    $w = $a['corashweight'];
    $countsByWeight[$w] = ($countsByWeight[$w] ?? 0) + 1;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="athlete-information-table.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ["Athlete's Name", "Athlete's ID (IKA)", 'NOC', 'Gender', 'Weight Category', 'Bracket Title']);
foreach ($athletes as $a) {
    fputcsv($out, [
        $a['fullname'],
        $a['ika_id'] ?? '',
        $a['noc_code'],
        $a['gender'] === 'M' ? 'Male' : ($a['gender'] === 'F' ? 'Female' : ''),
        $a['corashweight_text'],
        bracketTitleFromCount($countsByWeight[$a['corashweight']] ?? 0),
    ]);
}
fclose($out);
