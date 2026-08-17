<?php
/**
 * page-header-kurash.php
 * Include this right after <body...> (or at the top of the content area)
 * on every page after Welcome. Expects $championInfo (with 'title') to
 * already be loaded, and a local $formTitle string set before including.
 *
 * From the Registration page onward, also shows the competition name.
 * Pages before Registration (Welcome itself) don't include this at all.
 */
$formTitle = $formTitle ?? '';
?>
<div class="kurash-page-header">
    <div class="kurash-header-main">International KURASH Association</div>
    <?php if (!empty($championInfo['title'])): ?>
        <div class="kurash-header-competition">[<?php echo htmlspecialchars($championInfo['title']); ?>]</div>
    <?php endif; ?>
    <?php if ($formTitle !== ''): ?>
        <div class="kurash-header-formtitle"><?php echo htmlspecialchars($formTitle); ?></div>
    <?php endif; ?>
</div>
<style>
    .kurash-page-header { text-align: center; margin-bottom: 20px; }
    .kurash-header-main { font-size: 20px; font-weight: bold; }
    .kurash-header-competition { font-size: 15px; font-weight: bold; margin-top: 4px; }
    .kurash-header-formtitle { font-size: 13px; color: #555; margin-top: 2px; }
</style>
