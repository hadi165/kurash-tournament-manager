<?php
/**
 * Draw / Bracket page — reached directly from the "Start" button in
 * Entries by Weight Categories (no upload screen in between).
 *
 * On load: looks in ../weigh-in-list-confirmed/ (outside app/) for a CSV
 * file named "{Gender} {WeightCategory}.csv" (the file produced by the
 * Weigh-in Excel export) and, if the Draw No. column has been filled in,
 * applies those numbers to the athletes (matched by IKA ID). If no
 * matching file is found, the "To Be Drawn" box still shows names, just
 * without draw numbers yet.
 *
 * "Draw" reveals names into the tree, one every 1.5s, using empty slots
 * as BYE where the bracket is bigger than the athlete count.
 *
 * "Save" writes an HTML snapshot (PDF stand-in, see chat note) into
 * ../primary-draw-result/ and a CSV mirroring the bracket into
 * ../related-to-fight-order/ — both OUTSIDE the app/ folder — named
 * "{WeightCategory} {Gender}-Draw".
 */
require_once __DIR__ . '/boot.php';
require_once './control/config.php';
require_once './control/DB.php';
require_once './control/pdo-connection.php';
require_once './control/class.php';
require_once './validate-online.php';
require_once './bracket-helpers.php';
require_once './bracket-tree-kurash.php';

csrf_verify(isset($_POST['save_draw']));

$dbcon = new DB();
$champion_id = filter_input(INPUT_GET, 'champion_id', FILTER_VALIDATE_INT);
$championsub_id = filter_input(INPUT_GET, 'championsub_id', FILTER_VALIDATE_INT);
$weight = filter_input(INPUT_GET, 'weight', FILTER_VALIDATE_INT);
$championInfo = $dbcon->getTableById('champions', $champion_id);
$championSubInfo = $dbcon->getTableById('championsubs', $championsub_id);

$weightIds = !empty($championSubInfo['corashweights']) ? explode('/', $championSubInfo['corashweights']) : [];
$weightTexts = !empty($championSubInfo['corashweights_text']) ? explode('/', $championSubInfo['corashweights_text']) : [];
$weightIndex = array_search((string)$weight, $weightIds, true);
$weightLabel = $weightIndex !== false ? ($weightTexts[$weightIndex] ?? $weight) : $weight;

$stmt = $pdocon->prepare("
    SELECT * FROM championregisterathletes
    WHERE champion_id = ? AND championsub_id = ? AND corashweight = ?
    ORDER BY fullname ASC
");
$stmt->execute([$champion_id, $championsub_id, $weight]);
$athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$genderCode = $athletes[0]['gender'] ?? null;
$genderLabel = $genderCode === 'M' ? 'Male' : ($genderCode === 'F' ? 'Female' : 'Mixed');

// --- Import draw numbers from the confirmed weigh-in CSV (outside app/) ---
// This used to run on every page load, which meant a refresh silently rewrote
// draw numbers from whatever CSV happened to be on disk. It is now an explicit
// action behind a POST button: a GET must never change data.
$confirmedDir = __DIR__ . '/../weigh-in-list-confirmed';
$fileNameSafe = preg_replace('/[^A-Za-z0-9 \-+]/', '_', $genderLabel . ' ' . $weightLabel);
$confirmedFile = $confirmedDir . '/' . $fileNameSafe . '.csv';
$autoReadNote = null;
$autoReadType = 'warning';

$importRequested = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_draw_numbers']);
$confirmedFileExists = is_file($confirmedFile);

if ($importRequested && $confirmedFileExists) {
    $handle = fopen($confirmedFile, 'r');
    if ($handle) {
        fgetcsv($handle); // "Gender / Weight Category" row
        fgetcsv($handle); // column headers
        $applied = 0;
        $rowsSeen = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) continue;
            $rowsSeen++;
            $ikaId = trim($row[1] ?? '');
            $drawNo = (int)trim($row[count($row) - 1]);
            if ($ikaId === '' || $drawNo <= 0) continue;
            $upd = $pdocon->prepare("
                UPDATE championregisterathletes SET corash_lotterynumber = ?
                WHERE ika_id = ? AND champion_id = ? AND championsub_id = ? AND corashweight = ?
            ");
            $upd->execute([$drawNo, $ikaId, $champion_id, $championsub_id, $weight]);
            if ($upd->rowCount() > 0) $applied++;
        }
        fclose($handle);
        if ($applied > 0) {
            $autoReadNote = "✓ Found {$fileNameSafe}.csv and loaded {$applied} draw number(s).";
            $autoReadType = 'success';
        } elseif ($rowsSeen > 0) {
            $autoReadNote = "Found {$fileNameSafe}.csv, but the Draw No. column is still empty for all {$rowsSeen} athlete(s). Open it, fill in the numbers, save as CSV, then reload this page.";
        } else {
            $autoReadNote = "Found {$fileNameSafe}.csv, but it has no athlete rows.";
        }
        // Refresh athlete list with the newly-applied numbers
        $stmt->execute([$champion_id, $championsub_id, $weight]);
        $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($importRequested) {
    $autoReadNote = "No confirmed file found at weigh-in-list-confirmed/{$fileNameSafe}.csv — go to Weigh-in Form, filter this weight category, and click \"Export Excel (Confirmed List)\" first.";
} elseif ($confirmedFileExists) {
    $autoReadNote = "Found {$fileNameSafe}.csv. Click \"Import draw numbers\" to apply the Draw No. column to these athletes.";
    $autoReadType = 'success';
} else {
    $autoReadNote = "No confirmed file at weigh-in-list-confirmed/{$fileNameSafe}.csv yet — export the confirmed weigh-in list first, fill in the Draw No. column, then import it here.";
}

// --- Handle Save: write PDF-stand-in (HTML) + Fight-Order CSV ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_draw'])) {
    $baseName = preg_replace('/[^A-Za-z0-9 \-+]/', '_', $weightLabel . ' ' . $genderLabel) . '-Draw';

    $primaryDir = __DIR__ . '/../primary-draw-result';
    $fightOrderDir = __DIR__ . '/../related-to-fight-order';
    if (!is_dir($primaryDir)) mkdir($primaryDir, 0777, true);
    if (!is_dir($fightOrderDir)) mkdir($fightOrderDir, 0777, true);

    // PDF stand-in: a self-contained, print-ready HTML snapshot of this page's bracket
    $snapshotHtml = $_POST['page_snapshot'] ?? '';
    file_put_contents($primaryDir . '/' . $baseName . '.html', $snapshotHtml);

    // Fight-order CSV: one row per match across all rounds, real names in round 1,
    // "Winner of Match #n" placeholders in later rounds (filled in by hand later)
    $slotsForCsv = [];
    $bracketSize = bracketSizeFromCount(count($athletes));
    $seedOrder = bracketSeedOrder($bracketSize);
    $byNumber = [];
    foreach ($athletes as $a) {
        if ($a['corash_lotterynumber']) $byNumber[(int)$a['corash_lotterynumber']] = $a;
    }
    foreach ($seedOrder as $num) {
        $slotsForCsv[] = isset($byNumber[$num])
            ? ['num' => $num, 'name' => $byNumber[$num]['fullname'], 'noc' => $byNumber[$num]['noc_code']]
            : ['num' => $num, 'name' => null, 'bye' => true, 'noc' => null];
    }

    $csvRows = [];
    $matchCounter = 0;
    $phaseLabels = ['Final', 'Semi Final', '1/4 Final', '1/8 Final', '1/16 Final', '1/32 Final'];
    $totalRounds = (int)log(count($slotsForCsv), 2);
    function collectMatchRows(array $slots, int &$counter, array &$rows, int $totalRounds, array $phaseLabels): string
    {
        if (count($slots) === 1) {
            $s = $slots[0];
            return $s['name'] ?? ('BYE (#' . $s['num'] . ')');
        }
        $half = count($slots) / 2;
        $leftLabel = collectMatchRows(array_slice($slots, 0, $half), $counter, $rows, $totalRounds, $phaseLabels);
        $rightLabel = collectMatchRows(array_slice($slots, $half), $counter, $rows, $totalRounds, $phaseLabels);
        $counter++;
        $myLabel = 'Winner of Match #' . $counter;
        $level = (int)log(count($slots), 2);
        $distFromRoot = $totalRounds - $level;
        $phase = $phaseLabels[$distFromRoot] ?? ('Round ' . $level);
        $rows[] = [$counter, $phase, $leftLabel, $rightLabel, '', '']; // Match#, Phase, Blue, Green, Fight Number, Winner
        return $myLabel;
    }
    collectMatchRows($slotsForCsv, $matchCounter, $csvRows, $totalRounds, $phaseLabels);

    $fh = fopen($fightOrderDir . '/' . $baseName . '.csv', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['Gender / Weight Category', $genderLabel . ' / ' . $weightLabel]);
    fputcsv($fh, ['Match #', 'Phase', 'Blue', 'Green', 'Fight Number', 'Winner']);
    foreach ($csvRows as $r) fputcsv($fh, $r);
    fclose($fh);

    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'saved_as' => $baseName]);
    exit;
}

// --- Build the leaf slot list for the tree ---
$bracketSize = bracketSizeFromCount(count($athletes));
$seedOrder = bracketSeedOrder($bracketSize);
$byNumber = [];
foreach ($athletes as $a) {
    if ($a['corash_lotterynumber']) $byNumber[(int)$a['corash_lotterynumber']] = $a;
}
$slots = [];
$revealList = [];
foreach ($seedOrder as $num) {
    if (isset($byNumber[$num])) {
        $a = $byNumber[$num];
        $slots[] = ['num' => $num, 'name' => null, 'noc' => $a['noc_code'], 'bye' => false];
        $revealList[] = ['num' => $num, 'name' => $a['fullname'], 'noc' => $a['noc_code']];
    } else {
        $slots[] = ['num' => $num, 'name' => null, 'noc' => null, 'bye' => true];
    }
}
$bracketTitle = bracketTitleFromCount(count($athletes));
shuffle($revealList); // reveal order should be unsorted, not alphabetical/seed order
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Draw - <?php echo htmlspecialchars($weightLabel); ?>kg</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 1300px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        .top-labels { text-align: center; margin-bottom: 10px; font-size: 14px; color: #555; }
        .toolbar { display: flex; gap: 10px; justify-content: center; margin: 16px 0; }
        .btn { background: #1565c0; color: #fff; border: none; padding: 10px 30px; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold; }
        .btn-save { background: #0d1b3d; }
        .btn:disabled { background: #ccc; }
        .cols { display: flex; gap: 30px; margin-top: 10px; }
        .left { width: 240px; flex-shrink: 0; }
        .left h4, .right h4 { text-align: center; }
        .to-be-drawn { border: 2px solid #0d1b3d; border-radius: 12px; padding: 16px; min-height: 420px; }
        .name-tag { background: #f0f4ff; border-radius: 4px; padding: 8px 10px; margin-bottom: 8px; font-size: 13px; }
        .right { flex: 1; overflow-x: auto; }
        .note { font-size: 12px; color: #888; text-align: center; margin-bottom: 10px; padding: 8px; border-radius: 4px; }
        .note-success { background: #e8f5e9; color: #1b5e20; }
        .note-warning { background: #fff3e0; color: #e65100; }
        <?php echo bracketTreeCss(); ?>
    </style>
</head>
<body>
<div class="container">
    <div class="top-labels">
        <div style="font-weight:bold; font-size:18px;">International KURASH Association</div>
        <div style="font-weight:bold;">[<?php echo htmlspecialchars($championInfo['title'] ?? ''); ?>]</div>
        <div><?php echo htmlspecialchars($genderLabel); ?> &nbsp; <?php echo htmlspecialchars($weightLabel); ?> kg</div>
    </div>

    <?php if ($autoReadNote): ?>
        <div class="note note-<?php echo $autoReadType; ?>"><?php echo htmlspecialchars($autoReadNote); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <?php if ($confirmedFileExists): ?>
            <form method="POST" action="" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="import_draw_numbers" value="1">
                <button type="submit" class="btn" style="background:#2e7d32;">IMPORT DRAW NUMBERS</button>
            </form>
        <?php endif; ?>
        <button class="btn" id="drawBtn">DRAW</button>
        <button class="btn btn-save" id="saveBtn">SAVE</button>
    </div>

    <div class="cols">
        <div class="left">
            <h4>To Be Drawn</h4>
            <div class="to-be-drawn" id="toBeDrawn">
                <?php foreach ($revealList as $i => $r): ?>
                    <div class="name-tag" data-index="<?php echo $i; ?>"><?php echo htmlspecialchars($r['name']); ?> (<?php echo htmlspecialchars($r['noc']); ?>)</div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="right">
            <h4><?php echo htmlspecialchars($bracketTitle); ?></h4>
            <div class="bracket-tree" id="bracketTree">
                <?php echo renderBracketTree($slots); ?>
            </div>
        </div>
    </div>
</div>

<script>
const revealList = <?php echo json_encode($revealList); ?>;
const drawBtn = document.getElementById('drawBtn');
const saveBtn = document.getElementById('saveBtn');

drawBtn.addEventListener('click', function () {
    drawBtn.disabled = true;

    // Reveal BYE slots right away, same moment the draw starts
    document.querySelectorAll('.bslot[data-bye="1"]').forEach(el => {
        el.classList.add('bye');
        el.querySelector('.bslot-name').textContent = 'BYE';
    });

    let i = 0;
    const interval = setInterval(() => {
        if (i >= revealList.length) { clearInterval(interval); return; }
        const item = revealList[i];
        const tag = document.querySelector('.name-tag[data-index="' + i + '"]');
        if (tag) tag.remove();
        const slotEl = document.getElementById('slot-' + item.num);
        if (slotEl) {
            slotEl.classList.add('filled');
            slotEl.querySelector('.bslot-name').textContent = item.name + ' (' + item.noc + ')';
        }
        i++;
    }, 1500);
});

saveBtn.addEventListener('click', async function () {
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';
    const snapshot = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Draw Result</title><style>' +
        document.querySelector('style').textContent +
        '</style></head><body>' + document.querySelector('.container').outerHTML + '</body></html>';

    const form = new URLSearchParams();
    form.append('save_draw', '1');
    form.append('page_snapshot', snapshot);
    form.append('_token', '<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>');

    try {
        const res = await fetch(window.location.href, { method: 'POST', body: form });
        const data = await res.json();
        if (data.status === 'success') {
            saveBtn.textContent = 'Saved ✓';
            const links = document.createElement('div');
            links.style.textAlign = 'center';
            links.style.marginTop = '10px';
            links.style.fontSize = '13px';
            links.innerHTML =
                '<a href="file-download-kurash.php?folder=primary&file=' + encodeURIComponent(data.saved_as + '.html') + '">Download saved draw sheet</a>' +
                ' &nbsp;|&nbsp; ' +
                '<a href="file-download-kurash.php?folder=fightorder&file=' + encodeURIComponent(data.saved_as + '.csv') + '">Open saved Fight-Order CSV</a>';
            saveBtn.insertAdjacentElement('afterend', links);
        } else {
            saveBtn.textContent = 'Save failed';
        }
    } catch (e) {
        saveBtn.textContent = 'Save failed';
    }
    setTimeout(() => { saveBtn.disabled = false; saveBtn.textContent = 'SAVE'; }, 2000);
});
</script>
</body>
</html>
