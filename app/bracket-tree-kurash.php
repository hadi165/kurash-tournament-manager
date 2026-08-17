<?php
/**
 * bracket-tree-kurash.php
 * Renders a single-elimination bracket as a real connected tree (nested
 * flex divs + CSS border connectors), not a flat numbered list.
 *
 * Include this, then call renderBracketTree($slots) where $slots is an
 * ordered array (bracket-size long) of ['num' => seedNumber, 'name' => ...
 * or null for BYE/unrevealed]. Leaf order must already be in the seeding
 * sequence (see $firstRound in champion-create-table-kurash-api.php) —
 * use bracketSeedOrder($bracketSize) below to get that order.
 */

// Same first-round seed pairings used by the bracket generator API, so the
// visual tree matches the actual match-ups.
function bracketSeedOrder(int $size): array
{
    $pairs = [
        4 => [['1','4'],['3','2']],
        8 => [['1','8'],['4','5'],['2','7'],['3','6']],
        16 => [['1','16'],['9','8'],['5','12'],['13','4'],['2','15'],['10','7'],['6','11'],['14','3']],
        32 => [
            ['1','32'],['16','17'],['9','24'],['8','25'],['5','28'],['12','21'],['13','20'],['4','29'],
            ['2','31'],['15','18'],['10','23'],['7','26'],['6','27'],['11','22'],['14','19'],['3','30'],
        ],
        64 => [
            ['1','64'],['32','33'],['17','48'],['16','49'],['9','56'],['24','41'],['25','40'],['8','57'],
            ['5','60'],['28','37'],['21','44'],['12','53'],['13','52'],['20','45'],['29','36'],['4','61'],
            ['2','63'],['31','34'],['18','47'],['15','50'],['10','55'],['23','42'],['26','39'],['7','58'],
            ['6','59'],['27','38'],['22','43'],['11','54'],['14','51'],['19','46'],['30','35'],['3','62'],
        ],
    ];
    if ($size === 2) return [1, 2];
    $flat = [];
    foreach ($pairs[$size] as $p) {
        $flat[] = (int)$p[0];
        $flat[] = (int)$p[1];
    }
    return $flat;
}

/**
 * Recursively renders a balanced sub-tree over $slots (must be a power-of-2
 * length slice of the leaf array, in seed order).
 */
function renderBracketTree(array $slots): string
{
    if (count($slots) === 1) {
        $s = $slots[0];
        $isBye = $s['bye'] ?? false;
        $displayName = ($s['name'] !== null) ? htmlspecialchars($s['name']) : '';
        $extra = ($s['name'] !== null && $s['noc']) ? ' (' . htmlspecialchars($s['noc']) . ')' : '';
        $byeAttr = $isBye ? ' data-bye="1"' : '';
        return '<div class="bnode"><div class="bslot" id="slot-' . $s['num'] . '"' . $byeAttr . '><span class="bslot-num">' . $s['num'] . '</span><span class="bslot-name">' . $displayName . $extra . '</span></div></div>';
    }

    $half = count($slots) / 2;
    $left = array_slice($slots, 0, $half);
    $right = array_slice($slots, $half);

    $html = '<div class="bnode">';
    $html .= '<div class="bnode-children">';
    $html .= renderBracketTree($left);
    $html .= renderBracketTree($right);
    $html .= '</div>';
    $html .= '<div class="bnode-stem"></div>';
    $html .= '<div class="bslot bslot-winner"></div>';
    $html .= '</div>';
    return $html;
}

function bracketTreeCss(): string
{
    return <<<CSS
    .bracket-tree { display: flex; overflow-x: auto; padding: 10px; }
    .bnode { display: flex; align-items: center; }
    .bnode-children { display: flex; flex-direction: column; justify-content: space-around; border-right: 2px solid #1565c0; margin-right: 14px; padding: 6px 0; }
    .bnode-children > .bnode { position: relative; margin: 4px 0; }
    .bnode-children > .bnode::after { content: ''; position: absolute; right: -14px; top: 50%; width: 14px; height: 2px; background: #1565c0; }
    .bnode-stem { width: 14px; height: 2px; background: #1565c0; margin-right: 2px; }
    .bslot { border: 1px solid #ccc; border-radius: 4px; padding: 6px 10px; font-size: 12px; background: #fff; min-width: 130px; display: flex; align-items: center; gap: 6px; white-space: nowrap; }
    .bslot-num { background: #1565c0; color: #fff; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .bslot-winner { background: #fff9c4; border-color: #fbc02d; min-width: 40px; min-height: 16px; justify-content: center; color: #999; font-size: 12px; }
    .bslot.filled { background: #e8f5e9; border-color: #66bb6a; }
    .bslot.bye .bslot-name { color: #bbb; font-style: italic; }
    CSS;
}
