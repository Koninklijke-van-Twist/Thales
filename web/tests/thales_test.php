<?php
/**
 * Parser + nightly met nep-Graph en nep-Mímir. Geen netwerk.
 * Run: php web/tests/thales_test.php [map-met-echte-rapporten]
 * Echte rapporten (klantdata) staan niet in git; standaard /workspace/servicerapport-aliases als die bestaat.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$GLOBALS['THALES_DATA_DIR'] = sys_get_temp_dir() . '/thales-test-' . getmypid();
require dirname(__DIR__) . '/thales_lib.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "ok  $message\n";
}

/** Klein synthetisch rapport (inline strings, geen klantdata). */
function make_fixture(string $path, array $sheets): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $wb = $rels = '';
    $i = 0;
    foreach ($sheets as $name => $rows) {
        $i++;
        $wb .= '<sheet name="' . htmlspecialchars($name) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $xml = '';
        foreach ($rows as $r => $cells) {
            $xml .= '<row r="' . $r . '">';
            foreach ($cells as $col => $v) {
                $xml .= is_int($v) ? '<c r="' . $col . $r . '"><v>' . $v . '</v></c>'
                    : '<c r="' . $col . $r . '" t="inlineStr"><is><t>' . htmlspecialchars($v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xml . '</sheetData></worksheet>');
    }
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wb . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $zip->close();
}

$intern = [18 => ['A' => 'Gebruikte onderdelen:'], 19 => ['A' => 'Correctief onderhoud', 'B' => 1, 'C' => 'PCS', 'D' => 'CO'],
    20 => ['A' => 'ACCU TEST', 'B' => 4, 'C' => 'PCS', 'D' => 'TST-1'], 21 => ['A' => 'FILTER TEST', 'B' => 2, 'C' => 'PCS', 'D' => 'TST-2'],
    23 => ['A' => 'Opmerkingen:']];
$fixture = sys_get_temp_dir() . '/thales-fixture-' . getmypid() . '.xlsx';
make_fixture($fixture, [
    'RapportEQUANS' => [14 => ['A' => 'Installatiegegevens'], 15 => ['A' => 'SET', 'B' => '90000001', 'E' => 'SETBOUWER', 'G' => 'X1'],
        16 => ['A' => 'DMTR', 'B' => '90000002', 'E' => 'Industrial Perkins ', 'G' => '4016TAG2A'], 17 => ['A' => 'GEN', 'B' => '90000003', 'E' => 'ALT']],
    'Intern' => $intern,
]);

// ---------- bestandsnaam / taakcodes
$meta = thales_parse_filename('10033847_20260324_WO2604191_632H_GOH_1701.xlsm');
check($meta['equipment_no'] === '10033847' && $meta['wo'] === 'WO2604191' && $meta['date'] === '2026-03-24' && $meta['task_codes'] === ['GOH'], 'bestandsnaam');
check(thales_task_codes_included(thales_parse_filename('1_20260101_WO1_1H_GOH SA ST24_1.xlsm')['task_codes']), 'GOH SA ST24 telt mee');
check(!thales_task_codes_included(thales_parse_filename('1_20260101_WO1_1H_SM03_1.xlsx')['task_codes']), 'SM03 telt niet');
check(thales_parse_filename('10014530_20260820_WO2609290_150H_CO_.xlsm')['task_codes'] === ['CO'], 'lege code aan het eind');
check(thales_parse_report('/dev/null', '1_20260101_WO1_1H_QOH_1.xlsm')['status'] === 'skipped', 'QOH overgeslagen zonder te openen');

// ---------- normalisatie
check(thales_brand('INDUSTRIAL', 'Industrial Perkins ')['key'] === 'PERKINS', 'INDUSTRIAL => PERKINS');
check(thales_brand('DIVERS')['key'] === '?' && thales_brand('')['key'] === '?', 'DIVERS/leeg = onbekend');
check(thales_brand('MTU', 'MTU Friedrichshafen ')['label'] === 'MTU Friedrichshafen', 'onbekende code toont Manufacturer_Name getrimd');
check(thales_type_key('4.236 T') === '4236T' && thales_type_key('T 4.236') === 'T4236', 'typesleutel');

// ---------- synthetisch rapport
$r = thales_parse_report($fixture, '90000001_20260101_WO2600001_10H_CO_1.xlsx');
check($r['status'] === 'ok' && $r['sheet'] === 'RapportEQUANS', 'componenttabel op RapportEQUANS');
check($r['motor']['component_no'] === '90000002', 'DMTR is de motor');
check(array_column($r['parts'], 'item_no') === ['TST-1', 'TST-2'] && $r['parts'][0]['qty'] === 4.0, 'onderdelen zonder CO-regel, stopt bij label');

// ---------- echte voorbeeldrapporten (optioneel)
$dir = $argv[1] ?? '/workspace/servicerapport-aliases';
if (is_file($dir . '/voorbeeld.xlsm')) {
    $v = thales_parse_report($dir . '/voorbeeld.xlsm', '10016718_20260101_WO2600680_1H_CO_1.xlsm');
    check($v['motor']['component_no'] === '10018862' && $v['motor']['brand'] === 'PERKINS' && $v['motor']['type'] === '4016TAG2A', 'voorbeeld: DMTR 10018862 PERKINS 4016TAG2A');
    check(array_map(fn($p) => [$p['item_no'], $p['qty'], $p['unit']], $v['parts']) === [['AO-11A1050S', 4.0, 'PCS'], ['AO-11A1060', 2.0, 'PCS']], 'voorbeeld: onderdelen zonder CO');
    foreach (glob($dir . '/samples/*.xls[xm]') as $file) {
        $s = thales_parse_report($file, basename($file));
        check($s['status'] === 'ok' && $s['motor'] !== null, basename($file) . ': ' . $s['sheet'] . ', motor ' . $s['motor']['component_no'] . ' ' . $s['motor']['brand'] . ' ' . $s['motor']['type'] . ', ' . count($s['parts']) . ' onderdelen');
    }
} else {
    echo "(echte voorbeeldrapporten niet gevonden, overgeslagen)\n";
}

// ---------- nightly met nep-Graph / nep-Mímir
$GLOBALS['thalesTenantId'] = 't';
$deltaPages = [];
$GLOBALS['THALES_HTTP'] = function (string $method, string $url, array $h, ?string $body, ?string $saveTo) use (&$deltaPages, $fixture): array {
    if (str_contains($url, '/oauth2/v2.0/token')) {
        return [200, json_encode(['access_token' => 'x', 'expires_in' => 3600])];
    }
    if (str_contains($url, '/content')) {
        copy($fixture, $saveTo);
        return [200, ''];
    }
    if (str_contains($url, 'delta')) {
        return [200, json_encode(array_shift($deltaPages))];
    }
    return [404, '{}'];
};
// splitsen op leestekens: 'KO' raakt het maximum en alle nummers gaan verder met '-'
$GLOBALS['thalesBcWorkorder'] = ['companies' => ['KvT', 'HvT']];
$GLOBALS['THALES_MIMIR'] = function (string $table, array $select, string $filter, int $top): array {
    if ($filter === "startswith(No,'K')" || $filter === "startswith(No,'KO')") { return array_fill(0, THALES_MIMIR_CHUNK, ['No' => 'KO-X']); }
    if ($filter === "startswith(No,'KO-')") { return [['No' => 'KO-1'], ['No' => 'KO-2']]; }
    return [];
};
$seen = [];
thales_mimir_each('AppItems', ['No'], 'No', '', function (array $row) use (&$seen): void { $seen[] = $row['No']; });
check($seen === ['KO-1', 'KO-2'], 'mimir_each splitst ook op \'-\' (KO-…)');
$mimirCalls = 0;
$GLOBALS['THALES_MIMIR'] = function (string $table, array $select, string $filter, int $top, ?string $company = null) use (&$mimirCalls): array {
    $mimirCalls++;
    if ($top < 1 || $top > 10000) { check(false, 'Mímir top binnen 1..10000'); }
    if ($table === 'AppComponentCard' && str_contains($filter, "'9'")) {
        return [
            ['No' => '90000001', 'Sub_Entity' => 'EPG 1', 'Manufacturer_Code' => 'SETB', 'Manufacturer_Name' => 'Setbouwer', 'Manufacturer_Model' => 'X1', 'Description_2' => '', 'Component_No_Parent' => ''],
            ['No' => '90000002', 'Sub_Entity' => 'DIESEL ENGINE', 'Manufacturer_Code' => 'INDUSTRIAL', 'Manufacturer_Name' => 'Industrial Perkins ', 'Manufacturer_Model' => '', 'Description_2' => '4016TAG2A', 'Component_No_Parent' => '90000001'],
        ];
    }
    if ($table === 'AppItems' && str_contains($filter, "'T'")) {
        return [['No' => 'TST-1', 'Description' => 'STARTACCU 24V'], ['No' => 'TST-2', 'Description' => 'OLIEFILTER'], ['No' => 'TST-3', 'Description' => 'STARTACCU 12V']];
    }
    if ($table === 'AppWerkorders') {
        $prev = (new DateTimeImmutable('first day of last month'))->format('Y-m-d');
        if ($top === 1) { // oudste maand bepalen: werkorders vanaf vorige maand
            preg_match('/Created_On lt (\S+)/', $filter, $mm);
            return $mm[1] > $prev ? [['No' => 'X']] : [];
        }
        if (str_contains($filter, ' lt ')) { // backfill op Created_On: één maand
            return str_contains($filter, 'Created_On ge ' . $prev) && $company === 'KvT' ? [['No' => 'WO2500001', 'Component_No' => '90000001', 'Created_On' => $prev, 'Start_Date' => '0001-01-01']] : [];
        }
        if (str_starts_with($filter, 'Start_Date')) {
            return [['No' => 'WO2600098', 'Component_No' => '', 'Created_On' => '2020-01-01', 'Start_Date' => date('Y-m-d')]];
        }
        return [['No' => 'WO2600099', 'Component_No' => $company === 'KvT' ? '90000002' : '90000001', 'Created_On' => date('Y-m-d'), 'Start_Date' => '0001-01-01']];
    }
    return [];
};
$file = fn(string $id, string $name, string $parent, string $mod) => ['id' => $id, 'name' => $name, 'file' => [], 'size' => 10, 'parentReference' => ['id' => $parent],
    'createdDateTime' => '2026-01-01T10:00:00Z', 'lastModifiedDateTime' => $mod, 'webUrl' => 'https://sp/' . $name];
$deltaPages = [
    ['value' => [['id' => 'F1', 'name' => '90000001_TEST', 'folder' => [], 'parentReference' => ['id' => 'ROOT']],
        ['id' => 'F2', 'name' => '101_SERV_REP_EXCEL', 'folder' => [], 'parentReference' => ['id' => 'F1']]], '@odata.nextLink' => 'https://g/delta?page=2'],
    ['value' => [$file('A', '90000001_20260101_WO2600001_10H_CO_1.xlsx', 'F2', '2026-01-01T10:00:00Z'),
        $file('B', '90000001_20260102_WO2600002_10H_ST_1.xlsx', 'F2', '2026-01-02T10:00:00Z'),
        $file('C', 'andere.xlsx', 'F1', '2026-01-02T10:00:00Z')], '@odata.deltaLink' => 'https://g/delta?token=1'],
];
$sum = thales_nightly(60);
check($sum['status'] === 'ok' && $sum['ok'] === 1 && $sum['skipped'] === 1, 'nightly: 1 verwerkt, 1 overgeslagen (ST), bestand buiten 101_SERV_REP_EXCEL genegeerd');
check(thales_state('delta_link') === 'https://g/delta?token=1' && thales_state('day_index') !== null, 'deltaLink + dag-index opgeslagen');
$brands = thales_brands();
check(count($brands) === 1 && $brands[0]['brand_key'] === 'PERKINS' && $brands[0]['types'][0]['type'] === '4016TAG2A', 'merk/type uit BC (Description_2 als fallback)');
$parts = thales_parts('PERKINS', '4016TAG2A');
check(count($parts) === 2 && $parts[0]['item_no'] === 'TST-1' && $parts[0]['description'] === 'STARTACCU 24V' && (int) $parts[0]['times_used'] === 1, 'onderdelen met BC-omschrijving');
check(count(thales_part_sources('PERKINS', '4016TAG2A', 'TST-1')) === 1, 'bronrapport');
$lookup = thales_component_lookup('90000001');
check($lookup['role'] === 'SET' && $lookup['motor']['component_no'] === '90000002' && $lookup['motor']['brand_key'] === 'PERKINS', 'SET => motor uit BC');
$search = thales_search('startacu', 'PERKINS', '4016TAG2A');
check(($search['used'][0]['item_no'] ?? '') === 'TST-1' && ($search['other'][0]['item_no'] ?? '') === 'TST-3', 'fuzzy: gebruikt eerst, dan overige (typefout startacu)');

// Werkorders: delta + één maand backfill per nacht
$cur = (new DateTimeImmutable('now'))->format('Y-m');
check(thales_state('workorders_backfill_oldest_month|KvT') === $cur && thales_state('workorders_backfill_end|KvT') === (new DateTimeImmutable('first day of last month'))->format('Y-m')
    && thales_state('workorders_backfill_done|KvT') === null, 'backfill: eerste nacht huidige maand, eindpunt = oudste Created_On-maand');
$hits = thales_workorder_components('wo2600099');
check(count($hits) === 2 && $hits[0]['company'] === 'HvT' && $hits[0]['component_no'] === '90000001' && $hits[1]['component_no'] === '90000002', 'zelfde nummer in twee bedrijven: beide treffers');
check(thales_workorder_components('WO2600098')[0]['component_no'] === '' && count(thales_workorder_components('WO2600098')) === 2, 'delta op Start_Date; leeg component bewaard');
check(thales_workorder_components('WO2600001') === [['company' => '', 'wo_no' => 'WO2600001', 'component_no' => '90000001']], 'werkorder uit rapport als fallback');
$sugg = array_column(thales_suggest('wo26'), 'value');
check(in_array('werkorder HvT,werkorder KvT', array_column(thales_suggest('WO2600099'), 'kind'), true) && in_array('WO2600099', $sugg, true) && in_array('WO2600001', $sugg, true) && array_column(thales_suggest('9000'), 'value') === ['90000001', '90000002', '90000003'], 'autocomplete prefix op werkorders en componenten');
check(thales_suggest('%') === [] && count(thales_suggest('9', 1)) === 1, 'autocomplete: % letterlijk, limiet');

// Correctie: zelfde item opnieuw gewijzigd => vervangt, geen dubbeltelling
$deltaPages = [['value' => [$file('A', '90000001_20260101_WO2600001_10H_CO_1.xlsx', 'F2', gmdate('Y-m-d\TH:i:s\Z'))], '@odata.deltaLink' => 'https://g/delta?token=2']];
thales_nightly(60);
check((int) thales_parts('PERKINS', '4016TAG2A')[0]['times_used'] === 1 && (float) thales_parts('PERKINS', '4016TAG2A')[0]['qty_total'] === 4.0, 'herverwerking telt niet dubbel');

// Crashbeveiliging: 'processing' bij de start => retry, na de gewone wachtrij opnieuw geprobeerd
$db = thales_db();
$db->exec("UPDATE reports SET parse_status = 'processing' WHERE item_id = 'A'");
$deltaPages = [['value' => [], '@odata.deltaLink' => 'https://g/delta?token=3']];
thales_nightly(60);
$a = $db->query("SELECT parse_status, retry_count, first_failed_at FROM reports WHERE item_id = 'A'")->fetch();
check(thales_workorder_components('WO2500001')[0]['component_no'] === '90000001' && thales_state('workorders_backfill_done|KvT') === '1' && thales_state('workorders_backfill_done|HvT') === '1', 'backfill op Created_On, klaar bij oudste maand');
check($a['parse_status'] === 'ok' && (int) $a['retry_count'] === 0 && $a['first_failed_at'] === null, 'gecrasht rapport via retry-lijst opnieuw verwerkt');
check(str_contains((string) file_get_contents($GLOBALS['THALES_DATA_DIR'] . '/nightly.log'), 'Rapport: 90000001_20260101_WO2600001_10H_CO_1.xlsx (10 bytes)'), 'naam en grootte gelogd voor download');
// Een week lang mislukt => given_up, ook een nieuwe wijzigingsdatum zet hem niet terug
$db->prepare("UPDATE reports SET parse_status = 'retry', retry_count = 3, first_failed_at = ? WHERE item_id = 'A'")->execute([gmdate('c', time() - 8 * 86400)]);
$deltaPages = [['value' => [$file('A', '90000001_20260101_WO2600001_10H_CO_1.xlsx', 'F2', gmdate('Y-m-d\TH:i:s\Z'))], '@odata.deltaLink' => 'https://g/delta?token=4']];
thales_nightly(60);
check($db->query("SELECT parse_status FROM reports WHERE item_id = 'A'")->fetchColumn() === 'given_up', 'na een week: given_up, ook na nieuwe wijziging');
check((thales_status()['counts']['given_up'] ?? 0) === 1, 'status telt given_up');

// Budget op: hervatbaar
$deltaPages = [['value' => [], '@odata.nextLink' => 'https://g/delta?page=x']];
$s2 = thales_nightly(0);
check($s2['status'] === 'partial', 'zonder budget: partial, dag-index blijft staan');
check(is_file($GLOBALS['THALES_DATA_DIR'] . '/.htaccess'), 'data/.htaccess aangemaakt');

echo "Alle tests geslaagd\n";
