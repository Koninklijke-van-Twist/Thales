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


/** Alleen modal-HTML (overlay + inhoud) voor full page én ?partial=1. */
function thales_render_parts_modal(string $brandKey, string $typeKey, string $itemNo): void
{
    $parts = thales_parts($brandKey, $typeKey);
    ?>
    <div class="modal-overlay" id="parts-modal">
    <section id="parts" class="modal" role="dialog" aria-modal="true" aria-labelledby="parts-title">
        <a class="modal-close" href="?tab=library" aria-label="<?= h(LOC('lib.close')) ?>" title="<?= h(LOC('lib.close')) ?>">&times;</a>
        <h2 id="parts-title"><?= h($brandKey) ?> · <?= h($typeKey !== '' ? $typeKey : LOC('lib.type_unknown')) ?></h2>
        <p class="muted"><?= h(LOC('lib.source', (string) ($parts[0]['brand_source'] ?? '–'))) ?> · <?= h(LOC('lib.pick')) ?></p>
        <div class="part-grid">
            <?php foreach ($parts as $p): $isActive = $p['item_no'] === $itemNo; $hue = thales_part_hue((string) $p['description'], (string) $p['item_no']); ?>
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
    <?php
}


/**
 * Autocomplete (JSON, zelfde auth): ?suggest= | ?suggest_parts=&brand=&type= | ?suggest_tasks=
 * Modal-fragment (HTML, zelfde auth): ?tab=library&brand=&type=&item=&partial=1
 */
if (isset($_GET['suggest'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(thales_suggest((string) $_GET['suggest']));
    exit;
}
if (isset($_GET['suggest_parts'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(thales_suggest_parts(
        (string) $_GET['suggest_parts'],
        (string) ($_GET['brand'] ?? ''),
        (string) ($_GET['type'] ?? ''),
        20,
        (string) ($_GET['task'] ?? '')
    ));
    exit;
}
if (isset($_GET['suggest_tasks'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(thales_suggest_tasks((string) $_GET['suggest_tasks']));
    exit;
}

/**
 * Page load (alleen eigen cache, nooit live BC/SharePoint)
 */
$tab = ($_GET['tab'] ?? 'library') === 'test' ? 'test' : 'library';
try {
    $status = thales_status();
} catch (Throwable $e) {
    // SQLite-lock of migratie-race tijdens nightly: geen harde 500.
    $status = ['last_run' => null, 'day_index' => '–', 'counts' => [], 'items_count' => 0, 'items_synced_on' => null];
    error_log('Thales page status: ' . $e->getMessage());
}
$run = $status['last_run'] ?? null;
$c = $status['counts'] ?? [];
$statusLine = LOC('status.line',
    $run ? (new DateTimeImmutable((string) $run['at']))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('d-m-Y H:i') : LOC('status.never'),
    (string) ($run['status'] ?? '–'), $status['day_index'] ?? '–',
    (int) ($c['ok'] ?? 0), (int) ($c['skipped'] ?? 0), (int) ($c['error'] ?? 0), (int) ($c['pending'] ?? 0),
    (int) ($c['retry'] ?? 0), (int) ($c['given_up'] ?? 0));
$statusLine .= ' · ' . LOC('status.items', (int) ($status['items_count'] ?? 0), (string) ($status['items_synced_on'] ?? LOC('status.never')));

$brandKey = (string) ($_GET['brand'] ?? '');
$typeKey = (string) ($_GET['type'] ?? '');
$itemNo = (string) ($_GET['item'] ?? '');
$component = trim((string) ($_GET['component'] ?? ''));
$query = trim((string) ($_GET['q'] ?? ''));
$taskCode = strtoupper(trim((string) ($_GET['task'] ?? '')));
$lookup = $tab === 'test' && $component !== '' ? thales_component_lookup($component) : null;
$woHits = $tab === 'test' && $component !== '' && $lookup === null ? thales_workorder_components($component) : [];
foreach ($woHits as $i => $hit) { // werkorder ingevuld: component(en) erbij opzoeken, alle bedrijven
    $woHits[$i]['lookup'] = $hit['component_no'] !== '' ? thales_component_lookup($hit['component_no']) : null;
    $lookup ??= $woHits[$i]['lookup']; // onderdelen van de eerste gevonden
    if ($taskCode === '' && trim((string) ($hit['task_code'] ?? '')) !== '') {
        $taskCode = strtoupper(trim((string) $hit['task_code']));
    }
}
$unknown = LOC('test.unknown_value');
$motorText = static fn(array $m): string => LOC('test.brand') . ': ' . (trim((string) $m['brand']) !== '' && $m['brand_key'] !== '?' ? $m['brand'] : $unknown)
    . ' · ' . LOC('test.type') . ': ' . (trim((string) $m['type']) !== '' ? $m['type'] : $unknown);
$motorBrand = (string) ($lookup['motor']['brand_key'] ?? '');
$motorType = (string) ($lookup['motor']['type_key'] ?? '');
$taskOdds = $tab === 'test' && $taskCode !== '' && $motorBrand !== '' && $motorType !== ''
    ? thales_task_odds($taskCode, $motorBrand, $motorType)
    : null;
$results = null;
if ($tab === 'test' && ($query !== '' || ($lookup && $taskCode === ''))) {
    $results = thales_search($query, $motorBrand, $motorType, 50, $taskCode);
}
// Met taakcode: zoekbalk blijft beschikbaar naast de kanskaarten (zonder taak ≈ oude gedrag).
if ($tab === 'test' && $taskCode !== '' && $query !== '') {
    $results = thales_search($query, $motorBrand, $motorType, 50, $taskCode);
}

/** Fragment: alleen de parts-modal (zelfde auth), voor openen zonder herladen. */
if (isset($_GET['partial']) && (string) $_GET['partial'] === '1') {
    header('Content-Type: text/html; charset=utf-8');
    if ($brandKey === '') {
        http_response_code(400);
        echo '';
        exit;
    }
    thales_render_parts_modal($brandKey, $typeKey, $itemNo);
    exit;
}
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
        .type-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px; }
        .type-card { padding: 0 8px 6px; gap: 2px; }
        .type-card .part-bar { height: 4px; margin: 0 -8px 4px; }
        .type-card .part-name { font-size: .9rem; }
        .part-sources { grid-column: 1 / -1; background: var(--kvt-row-ok); border-radius: 8px; padding: 10px 12px; }
        @media (max-width: 600px) { .part-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); } }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: flex-start; justify-content: center; padding: 4vh 12px; z-index: 1000; }
        .modal-overlay[hidden] { display: none; }
        .modal { position: relative; margin: 0; width: 100%; max-width: 900px; max-height: 92vh; overflow: auto; box-sizing: border-box; -webkit-overflow-scrolling: touch; }
        .modal h2 { padding-right: 40px; }
        .modal-close { background: var(--kvt-main-blue); color: #fff; border-radius: 6px; text-decoration: none; position: absolute; top: 10px; right: 10px; padding: 4px 11px; font-size: 1.2rem; line-height: 1; }
        @media (max-width: 600px) { .modal-overlay { padding: 0; } .modal { max-height: 100vh; height: 100%; border-radius: 0; padding: 12px; } th, td { padding: 5px 6px; } }

        .test-layout { display: grid; grid-template-columns: 1fr minmax(220px, 280px); gap: 16px; align-items: stretch; }
        @media (max-width: 800px) { .test-layout { grid-template-columns: 1fr; } }
        .nodig-col { align-self: stretch; min-height: 100%; display: flex; flex-direction: column; border-radius: 10px; box-sizing: border-box; }
        .nodig-col.drag-over { outline: 2px dashed var(--kvt-main-blue); outline-offset: -2px; background: var(--kvt-row-ok); }
        .nodig-panel { position: sticky; top: 12px; }
        .nodig-list { list-style: none; margin: 8px 0 0; padding: 0; }
        .nodig-list li { display: flex; flex-wrap: wrap; gap: 4px 8px; align-items: flex-start; padding: 0 10px 8px; border: 1px solid var(--kvt-line); border-radius: 6px; margin-bottom: 6px; background: #fff; overflow: hidden; position: relative; }
        .nodig-list .part-bar { display: block; width: calc(100% + 20px); height: 6px; margin: 0 -10px 6px; flex: 0 0 auto; }
        .nodig-list .nodig-meta { flex: 1; min-width: 0; }
        .nodig-list button.nodig-remove { background: transparent; color: var(--kvt-danger); padding: 2px 8px; margin-left: auto; }
        .nodig-list .nodig-qty-wrap { display: flex; align-items: center; gap: 4px; margin-left: auto; }
        .nodig-list .nodig-qty { width: 4.5rem; padding: 2px 4px; }
        .odds-card { cursor: grab; position: relative; }
        .odds-card .odds-add { position: absolute; top: 10px; right: 8px; background: var(--kvt-main-blue); color: #fff; border: 0; border-radius: 50%; width: 28px; height: 28px; line-height: 28px; padding: 0; font-size: 1.1rem; z-index: 2; }
        .odds-qty-pop { position: absolute; top: 42px; right: 8px; z-index: 6; background: #fff; border: 1px solid var(--kvt-line); border-radius: 8px; padding: 8px; box-shadow: 0 4px 12px rgba(0,0,0,.15); display: flex; gap: 6px; align-items: center; }
        .odds-qty-pop input { width: 4.5rem; padding: 4px 6px; }
        .odds-qty-pop button { padding: 4px 10px; border-radius: 6px; }
        .odds-pct { font-size: 1.25rem; font-weight: 700; color: var(--kvt-perkins-blue); }
        form.test-form label { display: inline-block; margin-right: 12px; margin-bottom: 8px; vertical-align: top; }
        .ac-wrap { position: relative; display: inline-block; }
        .ac-menu { position: absolute; left: 0; right: 0; top: 100%; z-index: 40; margin: 2px 0 0; padding: 4px 0; list-style: none;
            max-height: 240px; overflow: auto; background: #fff; border: 1px solid var(--kvt-line); border-radius: 6px;
            box-shadow: 0 4px 14px rgba(0,0,0,.12); min-width: 220px; }
        .ac-menu[hidden] { display: none; }
        .ac-menu li { padding: 6px 10px; cursor: pointer; font-size: 0.92rem; line-height: 1.25; }
        .ac-menu li[aria-selected="true"], .ac-menu li:hover { background: var(--kvt-row-ok); }
        .ac-menu .ac-sub { display: block; color: var(--kvt-muted, #666); font-size: 0.82rem; }
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

<?php if ($tab === 'library'):
    try {
        $brands = thales_brands();
    } catch (Throwable $e) {
        $brands = [];
        error_log('Thales brands: ' . $e->getMessage());
    }
?>
    <section>
        <?php if ($brands === []): ?><p class="muted"><?= h(LOC('lib.empty')) ?></p><?php endif; ?>
        <?php foreach ($brands as $b): ?>
            <h3><?= h($b['brand']) ?></h3>
            <div class="part-grid type-grid">
            <?php foreach ($b['types'] as $t): $sel = $b['brand_key'] === $brandKey && $t['type_key'] === $typeKey; $hue = crc32($b['brand_key'] . '|' . $t['type_key']) % 360; ?>
                <a class="part-card type-card<?= $sel ? ' active' : '' ?>" href="?<?= h(http_build_query(['tab' => 'library', 'brand' => $b['brand_key'], 'type' => $t['type_key']])) ?>">
                    <span class="part-bar" style="background: hsl(<?= (int) $hue ?>, 55%, 45%);"></span>
                    <strong class="part-name<?= $t['type'] === '' ? ' muted' : '' ?>"><?= h($t['type'] !== '' ? $t['type'] : LOC('lib.type_unknown')) ?></strong>
                    <span class="muted part-stats"><?= h(LOC('lib.parts_count', (string) (int) $t['parts'])) ?></span>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </section>
    <?php if ($brandKey !== ''): thales_render_parts_modal($brandKey, $typeKey, $itemNo); endif; ?>
    <script>
    (function () {
        var closeUrl = '?tab=library';
        var mount = document.querySelector('main');
        if (!mount || !window.fetch) { return; }

        function overlay() { return document.getElementById('parts-modal'); }

        function bindOverlay(el) {
            if (!el || el.dataset.bound === '1') { return; }
            el.dataset.bound = '1';
            document.body.style.overflow = 'hidden';
            var closer = el.querySelector('.modal-close');
            if (closer) {
                closer.addEventListener('click', function (e) { e.preventDefault(); closeModal(); });
            }
            el.addEventListener('click', function (e) { if (e.target === el) { closeModal(); } });
            var active = el.querySelector('.part-sources');
            if (active) { active.scrollIntoView({ block: 'nearest' }); }
        }

        function closeModal() {
            var el = overlay();
            if (!el) { return; }
            el.hidden = true;
            document.body.style.overflow = '';
            if (window.history && history.replaceState) { history.replaceState(null, '', closeUrl); }
        }

        function openHtml(html, url, push) {
            var wrap = document.createElement('div');
            wrap.innerHTML = String(html).trim();
            var next = wrap.firstElementChild;
            if (!next) { return; }
            var cur = overlay();
            if (cur) { cur.replaceWith(next); } else { mount.appendChild(next); }
            next.hidden = false;
            bindOverlay(next);
            if (push && window.history && history.pushState) { history.pushState({ thalesModal: 1 }, '', url); }
        }

        function loadModal(url, push) {
            var u = url;
            if (u.indexOf('partial=') === -1) {
                u += (u.indexOf('?') >= 0 ? '&' : '?') + 'partial=1';
            }
            return fetch(u, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
                .then(function (r) { if (!r.ok) { throw new Error('modal'); } return r.text(); })
                .then(function (html) { openHtml(html, url, push); });
        }

        function isModifiedClick(e) {
            return e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey;
        }

        document.addEventListener('click', function (e) {
            if (isModifiedClick(e)) { return; }
            var typeA = e.target.closest && e.target.closest('a.type-card');
            if (typeA && mount.contains(typeA)) {
                e.preventDefault();
                loadModal(typeA.getAttribute('href'), true).catch(function () { location.href = typeA.href; });
                return;
            }
            var partA = e.target.closest && e.target.closest('#parts-modal a.part-card');
            if (partA) {
                e.preventDefault();
                loadModal(partA.getAttribute('href'), true).catch(function () { location.href = partA.href; });
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var el = overlay();
                if (el && !el.hidden) { closeModal(); }
            }
        });

        window.addEventListener('popstate', function () {
            var params = new URLSearchParams(location.search);
            if (params.get('brand')) {
                loadModal(location.pathname + location.search, false).catch(function () { location.reload(); });
            } else {
                var el = overlay();
                if (el) {
                    el.hidden = true;
                    document.body.style.overflow = '';
                }
            }
        });

        var existing = overlay();
        if (existing) { bindOverlay(existing); }
    })();
    </script>

<?php else: ?>
    <div class="test-layout">
    <div class="test-main">
    <section>
        <form method="get" class="test-form">
            <input type="hidden" name="tab" value="test">
            <label><?= h(LOC('test.component')) ?><br>
                <span class="ac-wrap"><input type="text" name="component" id="component-input" value="<?= h($component) ?>" autocomplete="off" aria-autocomplete="list" aria-controls="component-ac" aria-expanded="false">
                <ul class="ac-menu" id="component-ac" role="listbox" hidden></ul></span></label>
            <label><?= h(LOC('test.task')) ?><br>
                <span class="ac-wrap"><input type="text" name="task" id="task-input" value="<?= h($taskCode) ?>" autocomplete="off" placeholder="<?= h(LOC('test.task_hint')) ?>" aria-autocomplete="list" aria-controls="task-ac" aria-expanded="false">
                <ul class="ac-menu" id="task-ac" role="listbox" hidden></ul></span></label>
            <button type="submit"><?= h(LOC('test.lookup')) ?></button>
            <?php if ($component !== '' && $lookup === null && $woHits === []): ?><p class="muted"><?= h(LOC('test.unknown')) ?></p><?php endif; ?>
            <?php foreach ($woHits as $hit): $wo = $hit['wo_no'] . ($hit['company'] !== '' ? ' (' . $hit['company'] . ')' : ''); ?>
                <p><?php if ($hit['component_no'] === ''): ?><?= h(LOC('test.wo_no_component', $wo)) ?>
                <?php elseif ($hit['lookup'] === null): ?><?= h(LOC('test.wo', $wo, $hit['component_no'])) ?><br><span class="muted"><?= h(LOC('test.unknown')) ?></span>
                <?php else: $m = $hit['lookup']['motor']; ?><?= h(LOC('test.wo', $wo, $hit['component_no'])) ?>
                    <?php if (trim((string) ($hit['task_code'] ?? '')) !== ''): ?> · <?= h(thales_task_label((string) $hit['task_code'])) ?><?php endif; ?><br>
                    <?= $hit['lookup']['role'] === 'SET' ? h(LOC('test.motor', $m['component_no'])) . '<br>' : '' ?><?= h($motorText($m)) ?>
                    <span class="muted">(<?= h(LOC('lib.source', $m['brand_source'])) ?>)</span><?php endif; ?></p>
            <?php endforeach; ?>
            <?php if ($lookup && $woHits === []): $m = $lookup['motor']; ?>
                <p><?= $lookup['role'] === 'SET' ? h(LOC('test.motor', $m['component_no'])) . '<br>' : '' ?><?= h($motorText($m)) ?>
                    <span class="muted">(<?= h(LOC('lib.source', $m['brand_source'])) ?>)</span></p>
            <?php endif; ?>
            <label><?= h(LOC('test.search')) ?><br>
                <span class="ac-wrap"><input type="text" name="q" id="part-input" value="<?= h($query) ?>" autocomplete="off"<?= $taskCode === '' ? ' autofocus' : '' ?> aria-autocomplete="list" aria-controls="part-ac" aria-expanded="false">
                <ul class="ac-menu" id="part-ac" role="listbox" hidden></ul></span></label>
            <button type="submit"><?= h(LOC('button.search')) ?></button>
        </form>
    </section>

    <?php if ($taskOdds !== null): ?>
    <section>
        <h2><?= h(LOC('test.odds_title')) ?><?php if ($taskCode !== ''): ?> · <?= h(thales_task_label($taskCode)) ?><?php endif; ?></h2>
        <?php
            $motorLabel = trim($motorBrand . ' ' . $motorType);
            if ($lookup) {
                $mb = trim((string) ($lookup['motor']['brand'] ?? ''));
                $mt = trim((string) ($lookup['motor']['type'] ?? ''));
                if ($mb !== '' && ($lookup['motor']['brand_key'] ?? '') !== '?') {
                    $motorLabel = trim($mb . ' ' . ($mt !== '' ? $mt : $motorType));
                } elseif ($mt !== '') {
                    $motorLabel = trim($motorBrand . ' ' . $mt);
                }
            }
        ?>
        <?php if ($taskOdds['tasks'] === 0): ?>
            <p class="muted"><?= h(LOC('test.odds_none', $taskCode, $motorLabel)) ?></p>
        <?php else: ?>
            <p class="muted"><?= h(LOC(
                $taskOdds['level'] === 'low' ? 'test.odds_level_low' : 'test.odds_level_motor',
                $taskOdds['tasks'],
                $taskCode,
                $motorLabel
            )) ?></p>
            <div class="part-grid" id="odds-grid">
            <?php foreach ($taskOdds['parts'] as $p):
                $modalQs = http_build_query(array_filter([
                    'tab' => 'library',
                    'brand' => $motorBrand !== '' ? $motorBrand : null,
                    'type' => $motorType !== '' ? $motorType : null,
                    'item' => $p['item_no'],
                ], static fn($v) => $v !== null && $v !== ''));
            ?>
                <div class="part-card odds-card" draggable="true"
                     data-item="<?= h($p['item_no']) ?>"
                     data-desc="<?= h($p['description']) ?>"
                     data-qty="<?= h(thales_fmt_qty($p['qty_median'])) ?>"
                     data-hue="<?= (int) $p['hue'] ?>"
                     data-modal="<?= h($modalQs) ?>">
                    <span class="part-bar" style="background: hsl(<?= (int) $p['hue'] ?>, 55%, 45%);"></span>
                    <button type="button" class="odds-add" title="<?= h(LOC('test.nodig_add')) ?>">+</button>
                    <span class="odds-pct"><?= h(LOC('test.odds_pct', (string) (int) round($p['odds'] * 100))) ?></span>
                    <strong class="part-name<?= $p['description'] === '' ? ' muted' : '' ?>"><?= h($p['description'] !== '' ? $p['description'] : LOC('lib.no_description')) ?></strong>
                    <span class="part-no"><?= h($p['item_no']) ?></span>
                    <span class="muted part-stats"><?= h(LOC('test.odds_n', $p['times'], $taskOdds['tasks'])) ?> · <?= h(LOC('test.odds_qty', thales_fmt_qty($p['qty_median']))) ?></span>
                    <?php if ($motorBrand !== ''): ?>
                    <a class="muted" href="?<?= h($modalQs) ?>" data-odds-open="1"><?= h(LOC('test.open_part')) ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($results !== null):
        $hasTaskHits = ($results['task'] ?? []) !== [];
        foreach (['task' => 'test.task_hits', 'used' => 'test.used', 'other' => 'test.other'] as $key => $title):
        if ($key === 'task' && !$hasTaskHits) { continue; }
        if ($key === 'used' && !$lookup) { continue; }
        $rows = $results[$key] ?? [];
    ?>
    <section>
        <h2><?= h(LOC($title)) ?></h2>
        <?php if ($rows === []): ?><p class="muted"><?= h(LOC('test.none')) ?></p><?php else: ?>
        <div class="part-grid">
        <?php foreach ($rows as $r):
            $desc = (string) ($r['description'] ?? '');
            $item = (string) $r['item_no'];
            $hue = thales_part_hue($desc, $item);
            $times = (int) ($r['times_used'] ?? $r['times'] ?? 0);
            $scorePct = (int) round(((float) ($r['score'] ?? 0)) * 100);
            $odds = array_key_exists('odds', $r) ? (float) $r['odds'] : null;
            $modalQs = http_build_query(array_filter([
                'tab' => 'library',
                'brand' => $motorBrand !== '' ? $motorBrand : null,
                'type' => $motorType !== '' ? $motorType : null,
                'item' => $item,
            ], static fn($v) => $v !== null && $v !== ''));
        ?>
            <div class="part-card odds-card" draggable="true"
                 data-item="<?= h($item) ?>"
                 data-desc="<?= h($desc) ?>"
                 data-qty="1"
                 data-hue="<?= (int) $hue ?>"
                 <?php if ($motorBrand !== ''): ?>data-modal="<?= h($modalQs) ?>"<?php endif; ?>>
                <span class="part-bar" style="background: hsl(<?= (int) $hue ?>, 55%, 45%);"></span>
                <button type="button" class="odds-add" title="<?= h(LOC('test.nodig_add')) ?>">+</button>
                <?php if ($odds !== null): ?>
                <span class="odds-pct"><?= h(LOC('test.odds_pct', (string) (int) round($odds * 100))) ?></span>
                <?php endif; ?>
                <strong class="part-name<?= $desc === '' ? ' muted' : '' ?>"><?= h($desc !== '' ? $desc : LOC('lib.no_description')) ?></strong>
                <span class="part-no"><?= h($item) ?></span>
                <span class="muted part-stats"><?= h(LOC('col.times')) ?>: <?= $times ?><?php if ($scorePct > 0): ?> · <small><?= $scorePct ?>%</small><?php endif; ?></span>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endforeach; endif; ?>
    </div>

    <aside class="nodig-col" id="nodig-dropzone">
        <section class="nodig-panel" id="nodig-panel">
            <h2><?= h(LOC('test.nodig_title')) ?></h2>
            <p class="muted"><?= h(LOC('test.nodig_hint')) ?></p>
            <ul class="nodig-list" id="nodig-list"></ul>
            <p class="muted" id="nodig-empty"><?= h(LOC('test.nodig_empty')) ?></p>
        </section>
    </aside>
    </div>

    <div id="test-modal-mount"></div>
    <script>
    (function () {
        function bindSuggest(input, menu, opts) {
            if (!input || !menu || !window.fetch) { return; }
            var timer = null, last = '', items = [], active = -1, blurTimer = null;
            var minLen = opts.minLen || 1, debounce = opts.debounce || 150;
            function close() {
                menu.hidden = true; menu.innerHTML = ''; items = []; active = -1;
                input.setAttribute('aria-expanded', 'false');
            }
            function render() {
                menu.innerHTML = '';
                items.forEach(function (row, i) {
                    var li = document.createElement('li');
                    li.setAttribute('role', 'option');
                    li.setAttribute('aria-selected', i === active ? 'true' : 'false');
                    li.textContent = row.label || row.value;
                    if (row.sub) {
                        var sub = document.createElement('span');
                        sub.className = 'ac-sub';
                        sub.textContent = row.sub;
                        li.appendChild(sub);
                    }
                    li.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        pick(i);
                    });
                    menu.appendChild(li);
                });
                menu.hidden = items.length === 0;
                input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
            }
            function setActive(i) {
                if (!items.length) { active = -1; return; }
                active = (i + items.length) % items.length;
                Array.prototype.forEach.call(menu.children, function (li, j) {
                    li.setAttribute('aria-selected', j === active ? 'true' : 'false');
                    if (j === active) { li.scrollIntoView({ block: 'nearest' }); }
                });
            }
            function pick(i) {
                if (i < 0 || i >= items.length) { return; }
                var row = items[i];
                input.value = row.value;
                close();
                if (typeof opts.onPick === 'function') { opts.onPick(row); }
                else if (input.form) { input.form.requestSubmit ? input.form.requestSubmit() : input.form.submit(); }
            }
            function load(q) {
                var url = opts.url(q);
                fetch(url, { credentials: 'same-origin' }).then(function (r) {
                    if (!r.ok) { throw new Error('suggest ' + r.status); }
                    return r.json();
                }).then(function (rows) {
                    if (input.value.trim() !== q) { return; }
                    items = (rows || []).map(opts.mapRow || function (row) {
                        return { value: row.value, label: row.label || row.value, sub: row.kind || '' };
                    });
                    active = items.length ? 0 : -1;
                    render();
                }).catch(function () { close(); });
            }
            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    var q = input.value.trim();
                    if (q === last) { return; }
                    last = q;
                    if (q.length < minLen) { close(); return; }
                    load(q);
                }, debounce);
            });
            input.addEventListener('keydown', function (e) {
                if (menu.hidden) { return; }
                if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
                else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); pick(active); }
                else if (e.key === 'Escape') { e.preventDefault(); close(); }
            });
            input.addEventListener('blur', function () {
                blurTimer = setTimeout(close, 150);
            });
            input.addEventListener('focus', function () { clearTimeout(blurTimer); });
        }
        var brandKey = <?= json_encode($motorBrand, JSON_UNESCAPED_UNICODE) ?>;
        var typeKey = <?= json_encode($motorType, JSON_UNESCAPED_UNICODE) ?>;
        var taskCodeJs = <?= json_encode($taskCode, JSON_UNESCAPED_UNICODE) ?>;
        bindSuggest(document.getElementById('component-input'), document.getElementById('component-ac'), {
            minLen: 2,
            url: function (q) { return '?suggest=' + encodeURIComponent(q); },
            mapRow: function (row) { return { value: row.value, label: row.value, sub: row.kind || '' }; }
        });
        bindSuggest(document.getElementById('task-input'), document.getElementById('task-ac'), {
            minLen: 1,
            url: function (q) { return '?suggest_tasks=' + encodeURIComponent(q); },
            mapRow: function (row) { return { value: row.value, label: row.label || row.value, sub: '' }; }
        });
        bindSuggest(document.getElementById('part-input'), document.getElementById('part-ac'), {
            minLen: 2,
            url: function (q) {
                var url = '?suggest_parts=' + encodeURIComponent(q);
                if (brandKey) { url += '&brand=' + encodeURIComponent(brandKey) + '&type=' + encodeURIComponent(typeKey); }
                if (taskCodeJs) { url += '&task=' + encodeURIComponent(taskCodeJs); }
                return url;
            },
            mapRow: function (row) { return { value: row.value, label: row.label || row.value, sub: '' }; }
        });

        /* ---- Nodig (localStorage per component + DnD; kolom = dropzone) ---- */
        var componentJs = <?= json_encode($component, JSON_UNESCAPED_UNICODE) ?>;
        var LS = 'thales-nodig-v2:' + (componentJs || '_');
        var zone = document.getElementById('nodig-dropzone');
        var ul = document.getElementById('nodig-list');
        var empty = document.getElementById('nodig-empty');
        var nodigConfirm = <?= json_encode(LOC('test.nodig_confirm'), JSON_UNESCAPED_UNICODE) ?>;
        var nodigQtyLabel = <?= json_encode(LOC('test.nodig_qty'), JSON_UNESCAPED_UNICODE) ?>;
        var nodigRemove = <?= json_encode(LOC('test.nodig_remove'), JSON_UNESCAPED_UNICODE) ?>;
        function partHue(desc, item) {
            var key = String(desc || '').trim().toLowerCase().replace(/\s+/g, ' ');
            if (!key) { key = String(item || '').toUpperCase(); }
            var crc = 0 ^ (-1);
            for (var i = 0; i < key.length; i++) {
                crc = (crc >>> 8) ^ partHue.table[(crc ^ key.charCodeAt(i)) & 0xff];
            }
            return ((crc ^ (-1)) >>> 0) % 360;
        }
        partHue.table = (function () {
            var t = [], n, c, k;
            for (n = 0; n < 256; n++) {
                c = n;
                for (k = 0; k < 8; k++) { c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1); }
                t[n] = c;
            }
            return t;
        })();
        function parseQty(v) {
            if (v === null || v === undefined) { return 0; }
            var s = String(v).trim().replace(',', '.');
            if (s === '') { return 0; }
            var n = parseFloat(s);
            return isNaN(n) ? 0 : n;
        }
        function fmtQty(n) {
            if (!isFinite(n) || n === 0) { return ''; }
            var r = Math.round(n * 1000) / 1000;
            return String(r);
        }
        function loadNodig() {
            try { return JSON.parse(localStorage.getItem(LS) || '[]'); } catch (e) { return []; }
        }
        function saveNodig(items) {
            localStorage.setItem(LS, JSON.stringify(items));
            renderNodig();
        }
        function renderNodig() {
            var items = loadNodig();
            ul.innerHTML = '';
            items.forEach(function (it, idx) {
                var hue = (it.hue !== undefined && it.hue !== null && it.hue !== '') ? parseInt(it.hue, 10) : partHue(it.desc, it.item);
                if (isNaN(hue)) { hue = partHue(it.desc, it.item); }
                var li = document.createElement('li');
                li.innerHTML = '<span class="part-bar"></span><div class="nodig-meta"><strong></strong><div class="part-no"></div></div>';
                li.querySelector('.part-bar').style.background = 'hsl(' + (hue % 360) + ', 55%, 45%)';
                li.querySelector('strong').textContent = it.desc || it.item;
                li.querySelector('.part-no').textContent = it.item;
                var qtyWrap = document.createElement('div');
                qtyWrap.className = 'nodig-qty-wrap';
                var inp = document.createElement('input');
                inp.type = 'number';
                inp.step = 'any';
                inp.min = '0';
                inp.className = 'nodig-qty';
                inp.setAttribute('aria-label', nodigQtyLabel);
                inp.value = (it.qty !== undefined && it.qty !== null && it.qty !== '') ? it.qty : '';
                inp.addEventListener('change', function () {
                    var next = loadNodig();
                    var v = String(inp.value).trim();
                    if (v === '' || parseQty(v) === 0) {
                        next.splice(idx, 1);
                    } else {
                        next[idx].qty = fmtQty(parseQty(v));
                    }
                    saveNodig(next);
                });
                qtyWrap.appendChild(inp);
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'nodig-remove';
                btn.textContent = '×';
                btn.title = nodigRemove;
                btn.addEventListener('click', function () {
                    var next = loadNodig();
                    next.splice(idx, 1);
                    saveNodig(next);
                });
                qtyWrap.appendChild(btn);
                li.appendChild(qtyWrap);
                ul.appendChild(li);
            });
            empty.hidden = items.length > 0;
        }
        function addNodig(item, desc, qty, hue) {
            if (!item) { return; }
            var items = loadNodig();
            var add = parseQty(qty);
            var h = (hue !== undefined && hue !== null && hue !== '') ? parseInt(hue, 10) : partHue(desc, item);
            if (isNaN(h)) { h = partHue(desc, item); }
            var found = -1;
            for (var i = 0; i < items.length; i++) {
                if (items[i].item === item) { found = i; break; }
            }
            if (found >= 0) {
                items[found].qty = fmtQty(parseQty(items[found].qty) + add);
                if (desc) { items[found].desc = desc; }
                items[found].hue = h;
                if (parseQty(items[found].qty) === 0) { items.splice(found, 1); }
            } else {
                items.push({ item: item, desc: desc || '', qty: fmtQty(add) || '', hue: h });
            }
            saveNodig(items);
        }
        function closeQtyPops(except) {
            document.querySelectorAll('.odds-qty-pop').forEach(function (el) {
                if (el !== except) { el.remove(); }
            });
        }
        function openQtyPop(card) {
            closeQtyPops();
            var pop = document.createElement('div');
            pop.className = 'odds-qty-pop';
            pop.addEventListener('click', function (e) { e.stopPropagation(); });
            var pin = document.createElement('input');
            pin.type = 'number';
            pin.step = 'any';
            pin.min = '0';
            pin.setAttribute('aria-label', nodigQtyLabel);
            pin.value = card.getAttribute('data-qty') || '';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = nodigConfirm;
            function commit() {
                addNodig(
                    card.getAttribute('data-item'),
                    card.getAttribute('data-desc'),
                    pin.value,
                    card.getAttribute('data-hue')
                );
                pop.remove();
            }
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                commit();
            });
            pin.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); commit(); }
                if (e.key === 'Escape') { e.preventDefault(); pop.remove(); }
            });
            pop.appendChild(pin);
            pop.appendChild(btn);
            card.appendChild(pop);
            pin.focus();
            pin.select();
        }
        renderNodig();
        document.addEventListener('click', function () { closeQtyPops(); });
        if (zone) {
            zone.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                zone.classList.add('drag-over');
            });
            zone.addEventListener('dragleave', function (e) {
                if (!zone.contains(e.relatedTarget)) { zone.classList.remove('drag-over'); }
            });
            zone.addEventListener('drop', function (e) {
                e.preventDefault();
                zone.classList.remove('drag-over');
                try {
                    var data = JSON.parse(e.dataTransfer.getData('application/json') || '{}');
                    if (data.item) { addNodig(data.item, data.desc, data.qty, data.hue); }
                } catch (err) {}
            });
        }
        document.querySelectorAll('.odds-card').forEach(function (card) {
            card.addEventListener('dragstart', function (e) {
                closeQtyPops();
                e.dataTransfer.setData('application/json', JSON.stringify({
                    item: card.getAttribute('data-item'),
                    desc: card.getAttribute('data-desc'),
                    qty: card.getAttribute('data-qty'),
                    hue: card.getAttribute('data-hue')
                }));
                e.dataTransfer.effectAllowed = 'copy';
            });
            var plus = card.querySelector('.odds-add');
            if (plus) {
                plus.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    openQtyPop(card);
                });
            }
        });

        /* ---- Modal open vanaf kanskaart (zelfde partial als Bibliotheek) ---- */
        var mount = document.getElementById('test-modal-mount');
        function overlay() { return document.getElementById('parts-modal'); }
        function bindOverlay(el) {
            if (!el || el.dataset.bound === '1') { return; }
            el.dataset.bound = '1';
            document.body.style.overflow = 'hidden';
            var closer = el.querySelector('.modal-close');
            if (closer) {
                closer.addEventListener('click', function (e) {
                    e.preventDefault();
                    el.hidden = true;
                    document.body.style.overflow = '';
                });
            }
            el.addEventListener('click', function (e) {
                if (e.target === el) { el.hidden = true; document.body.style.overflow = ''; }
            });
        }
        function openModal(qs) {
            if (!qs || !window.fetch || !mount) { return; }
            var url = '?' + qs + (qs.indexOf('partial=') === -1 ? '&partial=1' : '');
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
                .then(function (r) { if (!r.ok) { throw new Error('modal'); } return r.text(); })
                .then(function (html) {
                    var wrap = document.createElement('div');
                    wrap.innerHTML = String(html).trim();
                    var next = wrap.firstElementChild;
                    if (!next) { return; }
                    var cur = overlay();
                    if (cur) { cur.replaceWith(next); } else { mount.appendChild(next); }
                    next.hidden = false;
                    bindOverlay(next);
                }).catch(function () { location.href = '?' + qs; });
        }
        document.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            if (e.target.closest && e.target.closest('.odds-add, .odds-qty-pop')) { return; }
            var a = e.target.closest && e.target.closest('a[data-odds-open]');
            var card = a
                ? a.closest('.odds-card')
                : (e.target.closest && e.target.closest('.odds-card[data-modal]'));
            if (!card) { return; }
            if (!a && e.target.closest && e.target.closest('a')) { return; }
            var qs = card.getAttribute('data-modal') || '';
            if (!qs) { return; }
            e.preventDefault();
            openModal(qs);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var el = overlay();
                if (el && !el.hidden) { el.hidden = true; document.body.style.overflow = ''; }
            }
        });
    })();
    </script>
<?php endif; ?>
</main>
<?php renderLanguageSwitcherScript(); ?>
</body>
</html>
