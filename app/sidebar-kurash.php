<?php
/**
 * sidebar-kurash.php — shared dashboard sidebar.
 * Include this from inside <body>, after $champion_id / $championsub_id
 * are known in the including page. Expects those two variables to exist
 * (null is fine — items that need them just get disabled).
 */
$needsContext = empty($champion_id) || empty($championsub_id);
$qs = (!empty($champion_id) ? "champion_id={$champion_id}" : '') . (!empty($championsub_id) ? "&championsub_id={$championsub_id}" : '');

$sidebarItems = [
    ['label' => 'Championship Management', 'href' => 'champions-manage.php', 'always' => true],
    ['label' => "Athlete's Registration", 'href' => "registration-kurash.php?{$qs}"],
    ['label' => 'Weigh-in Form', 'href' => "weighin-kurash.php?{$qs}"],
    ['label' => 'Number of Entries by NOC', 'href' => "entries-by-noc-kurash.php?{$qs}"],
    ['label' => 'Number of Entries by Weight Categories', 'href' => "entries-by-weight-kurash.php?{$qs}"],
    ['label' => 'Fight Order', 'href' => "fight-order-kurash.php?{$qs}"],
    ['label' => 'Result', 'href' => "medal-list-kurash.php?{$qs}"],
    ['label' => 'Medal Standing', 'href' => "medal-standing-kurash.php?{$qs}"],
    ['label' => 'Archive', 'href' => "archive-kurash.php?{$qs}"],
];
$currentScript = basename($_SERVER['SCRIPT_NAME']);
?>
<style>
    body.with-sidebar { display: flex; min-height: 100vh; margin: 0; }
    .app-sidebar { width: 240px; background: #0d1b3d; color: #fff; padding: 20px 0; flex-shrink: 0; }
    .app-sidebar h3 { padding: 0 20px; font-size: 14px; opacity: .7; margin-bottom: 16px; }
    .app-sidebar a { display: block; padding: 11px 20px; color: #cfd8ff; text-decoration: none; font-size: 13px; border-left: 3px solid transparent; }
    .app-sidebar a:hover { background: rgba(255,255,255,.08); }
    .app-sidebar a.active { border-left-color: #4c8dff; background: rgba(255,255,255,.08); color: #fff; font-weight: bold; }
    .app-sidebar a.disabled { opacity: .3; pointer-events: none; }
    .app-sidebar .logout { margin-top: 24px; border-top: 1px solid rgba(255,255,255,.1); padding-top: 14px; }
    .app-main { flex: 1; padding: 20px; overflow-x: auto; }
    @media print { .app-sidebar { display: none; } }
</style>
<div class="app-sidebar">
    <h3>KURASH SYSTEM</h3>
    <?php foreach ($sidebarItems as $item): ?>
        <?php $isDisabled = empty($item['always']) && $needsContext; ?>
        <?php $isActive = strpos($item['href'], $currentScript) === 0; ?>
        <a href="<?php echo $isDisabled ? '#' : htmlspecialchars($item['href']); ?>" class="<?php echo $isDisabled ? 'disabled' : ''; ?> <?php echo $isActive ? 'active' : ''; ?>">
            <?php echo htmlspecialchars($item['label']); ?>
        </a>
    <?php endforeach; ?>
    <div class="logout">
        <a href="logout.php">Log Out</a>
    </div>
</div>
