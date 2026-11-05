<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/thales_lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
require_once __DIR__ . '/localization.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function thales_fmt_qty(mixed $value): string
{
    return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
}

/**
 * Autocomplete (JSON, zelfde auth): ?suggest=<prefix>
 */
if (isset($_GET['suggest'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(thales_suggest((string) $_GET['suggest']));
    exit;
}

/**
 * Page load (alleen eigen cache, nooit live BC/SharePoint)
 */
$tab = ($_GET['tab'] ?? 'library') === 'test' ? 'test' : 'library';
$status = thales_status();
$run = $status['last_run'] ?? null;
$c = $status['counts'];
$statusLine = LOC('status.line',
    $run ? (new DateTimeImmutable((string) $run['at']))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('d-m-Y H:i') : LOC('status.never'),
    (string) ($run['status'] ?? '–'), $status['day_index'] ?? '–',
    (int) ($c['ok'] ?? 0), (int) ($c['skipped'] ?? 0), (int) ($c['error'] ?? 0), (int) ($c['pending'] ?? 0),
    (int) ($c['retry'] ?? 0), (int) ($c['given_up'] ?? 0));

$brandKey = (string) ($_GET['brand'] ?? '');
$typeKey = (string) ($_GET['type'] ?? '');
$itemNo = (string) ($_GET['item'] ?? '');
$component = trim((string) ($_GET['component'] ?? ''));
$query = trim((string) ($_GET['q'] ?? ''));
$lookup = $tab === 'test' && $component !== '' ? thales_component_lookup($component) : null;
$woComponent = $tab === 'test' && $component !== '' && $lookup === null ? thales_workorder_component($component) : null;
if ($woComponent !== null) { // werkorder ingevuld: component erbij opzoeken
    $lookup = thales_component_lookup($woComponent);
}
$results = $tab === 'test' && ($query !== '' || $lookup) ? thales_search($query, (string) ($lookup['motor']['brand_key'] ?? ''), (string) ($lookup['motor']['type_key'] ?? '')) : null;
?><!DOCTYPE html>
<html lang="<?= h(getHtmlLang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(LOC('app.title')) ?></title>
    <link rel="stylesheet" href="brand.css?v=<?= (int) @filemtime(__DIR__ . '/brand.css') ?>">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <?php renderLanguageSwitcherStyles(); ?>
    <style>
        body { margin: 0; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 16px 48px; }
        header img { max-height: 44px; }
        section { background: var(--kvt-panel-bg); border: 1px solid var(--kvt-line); border-radius: 10px; padding: 16px 20px; margin-top: 18px; }
        h1 { color: var(--kvt-perkins-blue); margin: 12px 0 4px; }
        h2, h3 { color: var(--kvt-perkins-blue); font-size: 1.1rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--kvt-line); }
        th { background: var(--kvt-page-bg); }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: var(--kvt-muted); font-size: .9rem; }
        .tabs a { display: inline-block; padding: 8px 16px; border-radius: 6px 6px 0 0; text-decoration: none; color: var(--kvt-main-blue); }
        .tabs a.active { background: var(--kvt-main-blue); color: #fff; }
        .types a, .brands a { margin-right: 10px; }
        .sel { font-weight: 700; }
        input[type=text] { padding: 6px; width: 280px; }
        button { background: var(--kvt-main-blue); color: #fff; border: 0; border-radius: 6px; padding: 7px 14px; cursor: pointer; }
        tr.active td { background: var(--kvt-row-ok); }
        .part-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
        .part-card { display: flex; flex-direction: column; gap: 4px; padding: 0 12px 10px; border: 1px solid var(--kvt-line); border-radius: 8px; overflow: hidden; text-decoration: none; color: inherit; background: var(--kvt-panel-bg); }
        .part-card:hover, .part-card:focus { border-color: var(--kvt-main-blue); }
        .part-card.active { background: var(--kvt-row-ok); border-color: var(--kvt-main-blue); }
        .part-bar { display: block; height: 6px; margin: 0 -12px 6px; }
        .part-name { color: var(--kvt-perkins-blue); word-break: break-word; }
        .part-name.muted { font-weight: 400; font-style: italic; }
        .part-no { font-variant-numeric: tabular-nums; }
        .part-stats { font-size: .8rem; }
        .part-sources { grid-column: 1 / -1; background: var(--kvt-row-ok); border-radius: 8px; padding: 10px 12px; }
        @media (max-width: 600px) { .part-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); } }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: flex-start; justify-content: center; padding: 4vh 12px; z-index: 1000; }
        .modal-overlay[hidden] { display: none; }
        .modal { position: relative; margin: 0; width: 100%; max-width: 900px; max-height: 92vh; overflow: auto; box-sizing: border-box; -webkit-overflow-scrolling: touch; }
        .modal h2 { padding-right: 40px; }
        .modal-close { background: var(--kvt-main-blue); color: #fff; border-radius: 6px; text-decoration: none; position: absolute; top: 10px; right: 10px; padding: 4px 11px; font-size: 1.2rem; line-height: 1; }
        @media (max-width: 600px) { .modal-overlay { padding: 0; } .modal { max-height: 100vh; height: 100%; border-radius: 0; padding: 12px; } th, td { padding: 5px 6px; } }
    </style>
</head>
<body>
<?php renderLanguageSwitcher(); ?>
<main>
    <header>
        <img src="logo-website.png" alt="KVT">
        <h1><?= h(LOC('app.title')) ?></h1>
        <p class="muted"><?= h(LOC('app.subtitle')) ?></p>
        <p class="muted"><?= h($statusLine) ?><?= $status['backfill_busy'] ? ' · ' . h(LOC('status.backfill')) : '' ?></p>
        <nav class="tabs">
            <a href="?tab=library" class="<?= $tab === 'library' ? 'active' : '' ?>"><?= h(LOC('tab.library')) ?></a>
            <a href="?tab=test" class="<?= $tab === 'test' ? 'active' : '' ?>"><?= h(LOC('tab.test')) ?></a>
        </nav>
    </header>

<?php if ($tab === 'library'): $brands = thales_brands(); ?>
    <section>
        <?php if ($brands === []): ?><p class="muted"><?= h(LOC('lib.empty')) ?></p><?php endif; ?>
        <?php foreach ($brands as $b): ?>
            <h3><?= h($b['brand']) ?></h3>
            <p class="types">
            <?php foreach ($b['types'] as $t): $sel = $b['brand_key'] === $brandKey && $t['type_key'] === $typeKey; ?>
                <a class="<?= $sel ? 'sel' : '' ?>" href="?<?= h(http_build_query(['tab' => 'library', 'brand' => $b['brand_key'], 'type' => $t['type_key']])) ?>"><?= h($t['type'] !== '' ? $t['type'] : LOC('lib.type_unknown')) ?></a>
            <?php endforeach; ?>
            </p>
        <?php endforeach; ?>
    </section>
    <?php if ($brandKey !== ''): $parts = thales_parts($brandKey, $typeKey); ?>
    <div class="modal-overlay" id="parts-modal">
    <section id="parts" class="modal" role="dialog" aria-modal="true" aria-labelledby="parts-title">
        <a class="modal-close" href="?tab=library" aria-label="<?= h(LOC('lib.close')) ?>" title="<?= h(LOC('lib.close')) ?>">&times;</a>
        <h2 id="parts-title"><?= h($brandKey) ?> · <?= h($typeKey !== '' ? $typeKey : LOC('lib.type_unknown')) ?></h2>
        <p class="muted"><?= h(LOC('lib.source', (string) ($parts[0]['brand_source'] ?? '–'))) ?> · <?= h(LOC('lib.pick')) ?></p>
        <div class="part-grid">
            <?php foreach ($parts as $p): $isActive = $p['item_no'] === $itemNo; $hue = crc32($p['item_no']) % 360; ?>
                <a class="part-card<?= $isActive ? ' active' : '' ?>" href="?<?= h(http_build_query(['tab' => 'library', 'brand' => $brandKey, 'type' => $typeKey, 'item' => $p['item_no']])) ?>">
                    <span class="part-bar" style="background: hsl(<?= (int) $hue ?>, 55%, 45%);"></span>
                    <strong class="part-name<?= $p['description'] === '' ? ' muted' : '' ?>"><?= h($p['description'] !== '' ? $p['description'] : LOC('lib.no_description')) ?></strong>
                    <span class="part-no"><?= h($p['item_no']) ?></span>
                    <span class="muted part-stats"><?= h(LOC('col.times')) ?>: <?= (int) $p['times_used'] ?> · <?= h(LOC('col.qty')) ?>: <?= h(thales_fmt_qty($p['qty_total'])) ?></span>
                </a>
                <?php if ($isActive): ?>
                <div class="part-sources"><strong><?= h(LOC('lib.sources')) ?> · <?= h($p['item_no']) ?>:</strong>
                    <ul><?php foreach (thales_part_sources($brandKey, $typeKey, $itemNo) as $src): ?>
                        <li><a href="<?= h($src['web_url']) ?>" target="_blank" rel="noopener"><?= h($src['wo']) ?> · <?= h($src['report_date']) ?></a> <span class="muted"><?= h($src['name']) ?></span></li>
                    <?php endforeach; ?></ul></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
    </div>
    <script>
    (function () {
        var overlay = document.getElementById('parts-modal');
        if (!overlay) { return; }
        var closeUrl = '?tab=library';
        document.body.style.overflow = 'hidden';
        function closeModal() {
            overlay.hidden = true;
            document.body.style.overflow = '';
            if (window.history && history.replaceState) { history.replaceState(null, '', closeUrl); }
        }
        overlay.querySelector('.modal-close').addEventListener('click', function (e) { e.preventDefault(); closeModal(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { closeModal(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) { closeModal(); } });
        var active = overlay.querySelector('.part-sources');
        if (active) { active.scrollIntoView({ block: 'nearest' }); }
    })();
    </script>
    <?php endif; ?>

<?php else: ?>
    <section>
        <form method="get">
            <input type="hidden" name="tab" value="test">
            <label><?= h(LOC('test.component')) ?><br><input type="text" name="component" value="<?= h($component) ?>" list="component-suggest" autocomplete="off"></label>
            <datalist id="component-suggest"></datalist>
            <button type="submit"><?= h(LOC('test.lookup')) ?></button>
            <?php if ($component !== '' && $lookup === null): ?><p class="muted"><?= h(LOC($woComponent !== null ? 'test.wo_component_unknown' : 'test.unknown', (string) $woComponent)) ?></p><?php endif; ?>
            <?php if ($lookup): $m = $lookup['motor']; ?>
                <p><?= $woComponent !== null ? h(LOC('test.wo', $component, $woComponent)) . '<br>' : '' ?><?= $lookup['role'] === 'SET' ? h(LOC('test.motor', $m['component_no'])) . '<br>' : '' ?>
                    <strong><?= h(LOC('test.brand')) ?>:</strong> <?= h($m['brand']) ?> ·
                    <strong><?= h(LOC('test.type')) ?>:</strong> <?= h($m['type'] !== '' ? $m['type'] : '–') ?>
                    <span class="muted">(<?= h(LOC('lib.source', $m['brand_source'])) ?>)</span></p>
            <?php endif; ?>
            <label><?= h(LOC('test.search')) ?><br><input type="text" name="q" value="<?= h($query) ?>" autofocus></label>
            <button type="submit"><?= h(LOC('button.search')) ?></button>
        </form>
    </section>
    <?php if ($results !== null): foreach (['used' => 'test.used', 'other' => 'test.other'] as $key => $title): if ($key === 'used' && !$lookup) { continue; } ?>
    <section>
        <h2><?= h(LOC($title)) ?></h2>
        <?php if ($results[$key] === []): ?><p class="muted"><?= h(LOC('test.none')) ?></p><?php else: ?>
        <table>
            <tr><th><?= h(LOC('col.item_no')) ?></th><th><?= h(LOC('col.description')) ?></th><th class="num"><?= h(LOC('col.times')) ?></th><th class="num"><?= h(LOC('col.score')) ?></th></tr>
            <?php foreach ($results[$key] as $r): ?>
                <tr><td><?= h($r['item_no']) ?></td><td><?= h($r['description']) ?></td><td class="num"><?= (int) $r['times_used'] ?></td><td class="num"><?= (int) round($r['score'] * 100) ?>%</td></tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </section>
    <?php endforeach; endif; ?>
    <script>
    (function () {
        var input = document.querySelector('input[name=component]'), list = document.getElementById('component-suggest'), timer = null, last = '';
        if (!input || !list || !window.fetch) { return; }
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var q = input.value.trim();
                if (q === last) { return; }
                last = q;
                if (q.length < 2) { list.innerHTML = ''; return; }
                fetch('?suggest=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (rows) {
                    list.innerHTML = '';
                    rows.forEach(function (row) { var o = document.createElement('option'); o.value = row.value; o.label = row.kind; list.appendChild(o); });
                }).catch(function () {});
            }, 200);
        });
    })();
    </script>
<?php endif; ?>
</main>
<?php renderLanguageSwitcherScript(); ?>
</body>
</html>
