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
check(thales_part_hue('WATERFILTER  ', 'AO-1') === thales_part_hue('waterfilter', 'AO-2') && thales_part_hue('WATERFILTER', 'AO-1') !== thales_part_hue('WATERSEPARATOR', 'AO-1') && thales_part_hue('', 'AO-1') === thales_part_hue('', 'AO-1') && thales_part_hue('', 'AO-1') !== thales_part_hue('', 'AO-2'), 'kaartkleur op genormaliseerde naam, fallback artikelnr');

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
        return [
            ['No' => 'TST-1', 'Description' => 'STARTACCU 24V', 'Type' => 'Voorraad', 'LVS_Maintenance_Task_Item' => false],
            ['No' => 'TST-2', 'Description' => 'OLIEFILTER', 'Type' => 'Voorraad', 'LVS_Maintenance_Task_Item' => false],
            ['No' => 'TST-3', 'Description' => 'STARTACCU 12V', 'Type' => 'Voorraad', 'LVS_Maintenance_Task_Item' => false],
        ];
    }
    if ($table === 'AppItems' && (str_contains($filter, "'G'") || str_contains($filter, "'8'") || str_contains($filter, "'S'"))) {
        return [
            ['No' => 'GOH ST24', 'Description' => 'Groot onderhoud incl. storingsdienst', 'Type' => 'Voorraad', 'LVS_Maintenance_Task_Item' => true],
            ['No' => 'GOH SA ST24', 'Description' => 'Groot onderhoud SA ST24', 'Type' => 'Voorraad', 'LVS_Maintenance_Task_Item' => true],
            ['No' => 'SVC-1', 'Description' => 'Service ding', 'Type' => 'Service', 'LVS_Maintenance_Task_Item' => false],
        ];
    }
    if ($table === 'AppResource') {
        return str_contains($filter, "'1'") ? [['No' => '1574', 'Name' => 'Mierlo, Ricardo van']] : [];
    }
    if ($table === 'AppWerkorders') {
        $prev = (new DateTimeImmutable('first day of last month'))->format('Y-m-d');
        // Description-probe / gerichte batch (niet in brede delta/backfill-select)
        if (in_array('Description', $select, true)) {
            if ($filter === '') {
                return [['No' => 'PROBE', 'Description' => '']];
            }
            if (preg_match_all("/No eq '([^']+)'/", $filter, $mm)) {
                $out = [];
                foreach ($mm[1] as $no) {
                    $out[] = ['No' => $no, 'Description' => strtoupper($no) === 'WO2600099' ? 'Correctief bij klant' : ''];
                }
                return $out;
            }
            return [];
        }
        if ($top === 1 && str_contains($filter, 'Created_On lt ')) { // oudste maand bepalen
            preg_match('/Created_On lt (\S+)/', $filter, $mm);
            return ($mm[1] ?? '') > $prev ? [['No' => 'X']] : [];
        }
        if (str_contains($filter, ' lt ')) { // backfill op Created_On: één maand
            return str_contains($filter, 'Created_On ge ' . $prev) && $company === 'KvT'
                ? [['No' => 'WO2500001', 'Component_No' => '90000001', 'Task_Code' => 'GOH', 'Task_Description' => 'Preventive maintenance', 'Created_On' => $prev, 'Start_Date' => '0001-01-01']]
                : [];
        }
        if (str_starts_with($filter, 'Start_Date')) {
            return [['No' => 'WO2600098', 'Component_No' => '', 'Task_Code' => 'ST', 'Task_Description' => 'Storingsdiagnose', 'Created_On' => '2020-01-01', 'Start_Date' => date('Y-m-d')]];
        }
        return [['No' => 'WO2600099', 'Component_No' => $company === 'KvT' ? '90000002' : '90000001', 'Task_Code' => 'CO', 'Task_Description' => 'Correctief onderhoud', 'Created_On' => date('Y-m-d'), 'Start_Date' => '0001-01-01']];
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

check(thales_state('items_full_v') === '4' && ($st = thales_status()) && $st['items_count'] >= 3 && $st['items_synced_on'] !== null, 'items_full_v=4 + status items_count/synced_on');
// Excel-naam als weergave als BC leeg is; zoeken/autocomplete op Excel-naam
$db = thales_db();
$db->exec("UPDATE items SET description = '', search = ' tst-1 accu excel alias ' WHERE item_no = 'TST-1'");
$db->exec("INSERT OR REPLACE INTO item_excel_names(item_no, name, times) VALUES('TST-1', 'ACCU EXCEL ALIAS', 2)");
$db->exec("UPDATE items SET excel_name = 'ACCU EXCEL ALIAS' WHERE item_no = 'TST-1'");
thales_refresh_item_search();
$partsExcel = thales_parts('PERKINS', '4016TAG2A');
check(($partsExcel[0]['item_no'] ?? '') === 'TST-1' && $partsExcel[0]['description'] === 'ACCU EXCEL ALIAS', 'kaart toont Excel-naam als BC leeg is');
$searchExcel = thales_search('excel alias', 'PERKINS', '4016TAG2A');
check(($searchExcel['used'][0]['item_no'] ?? '') === 'TST-1', 'fuzzy zoekt ook op Excel-naam');
$sp = thales_suggest_parts('excel', 'PERKINS', '4016TAG2A');
check(($sp[0]['value'] ?? '') === 'TST-1' && str_contains($sp[0]['label'] ?? '', 'ACCU EXCEL ALIAS') && str_contains($sp[0]['label'] ?? '', 'TST-1'), 'autocomplete onderdelen: naam + artikelnr');
$spAll = thales_suggest_parts('STARTACCU');
check(in_array('TST-3', array_column($spAll, 'value'), true), 'autocomplete zonder motor: alle bekende onderdelen');
// BC terugzetten voor rest van de suite
$db->exec("UPDATE items SET description = 'STARTACCU 24V', excel_name = 'ACCU EXCEL ALIAS' WHERE item_no = 'TST-1'");
thales_refresh_item_search();
check(thales_parts('PERKINS', '4016TAG2A')[0]['description'] === 'STARTACCU 24V', 'kaart prefereert BC-naam boven Excel-naam');
$spSta = thales_suggest_parts('sta', 'PERKINS', '4016TAG2A');
$spStaNos = array_column($spSta, 'value');
check(in_array('TST-1', $spStaNos, true), 'autocomplete fuzzy op omschrijving (sta → STARTACCU)');
check(($spStaNos[0] ?? '') === 'TST-1', 'autocomplete met motor: usage van motortype eerst');
check(in_array('TST-3', $spStaNos, true), 'autocomplete met motor: daarna overige uit cache');

// Dienst-/taak-/resource-artikelen negeren
check(thales_is_task_item_no('GOH ST24') && thales_is_task_item_no('GOH SA ST24') && thales_is_task_item_no('800000') && !thales_is_task_item_no('TST-1'), 'parser negeert GOH-familie en 8xxxxx');
$db->exec("INSERT OR REPLACE INTO items(item_no, description, excel_name, bc_type, is_task, search, synced_at) VALUES
    ('GOH ST24', 'Groot onderhoud', '', 'Voorraad', 1, ' goh st24 ', '2026-01-01'),
    ('GOH SA ST24', 'GOH SA', '', 'Voorraad', 1, ' goh sa ', '2026-01-01'),
    ('SVC-1', 'Service', '', 'Service', 0, ' svc ', '2026-01-01'),
    ('800000', 'Storingsdienst', '', '', 0, ' 800000 ', '2026-01-01'),
    ('1574', 'Mierlo van R.', '', '', 0, ' 1574 mierlo ', '2026-01-01')");
$db->exec("INSERT OR REPLACE INTO resources(resource_no, name, synced_at) VALUES('1574', 'Mierlo, Ricardo van', '2026-01-01')");
$db->exec("INSERT OR REPLACE INTO usage(brand_key, brand, type_key, type, brand_source, item_no, times_used, qty_total) VALUES
    ('PERKINS', 'PERKINS', '4016TAG2A', '4016TAG2A', 'bc', 'GOH ST24', 5, 5),
    ('PERKINS', 'PERKINS', '4016TAG2A', '4016TAG2A', 'bc', '1574', 10, 130.32),
    ('PERKINS', 'PERKINS', '4016TAG2A', '4016TAG2A', 'bc', '800000', 3, 3)");
$partNos = array_column(thales_parts('PERKINS', '4016TAG2A'), 'item_no');
check(!in_array('GOH ST24', $partNos, true) && !in_array('1574', $partNos, true) && !in_array('800000', $partNos, true)
    && in_array('TST-1', $partNos, true), 'parts filtert GOH/resource/800000 weg');
check(thales_item_is_ignored(['bc_type' => 'Voorraad', 'is_task' => 1], 'GOH SA ST24')
    && thales_item_is_ignored(['bc_type' => 'Service', 'is_task' => 0], 'SVC-1')
    && thales_item_is_ignored(null, '1574')
    && thales_item_is_ignored(['bc_type' => '', 'is_task' => 0], '800001'), 'is_ignored: task/service/resource/8xxxxx');
$sugIgn = array_column(thales_suggest_parts('GOH'), 'value');
check(!in_array('GOH ST24', $sugIgn, true) && !in_array('GOH SA ST24', $sugIgn, true), 'autocomplete negeert GOH');
$searchIgn = thales_search('mierlo', 'PERKINS', '4016TAG2A');
check(($searchIgn['used'] ?? []) === [] || !in_array('1574', array_column($searchIgn['used'], 'item_no'), true), 'zoeken negeert resource 1574');



// Werkorders: delta + één maand backfill per nacht
$cur = (new DateTimeImmutable('now'))->format('Y-m');
check(thales_state('workorders_backfill_oldest_month|KvT') === $cur && thales_state('workorders_backfill_end|KvT') === (new DateTimeImmutable('first day of last month'))->format('Y-m')
    && thales_state('workorders_backfill_done|KvT') === null, 'backfill: eerste nacht huidige maand, eindpunt = oudste Created_On-maand');
$hits = thales_workorder_components('wo2600099');
check(count($hits) === 2 && $hits[0]['company'] === 'HvT' && $hits[0]['component_no'] === '90000001' && $hits[1]['component_no'] === '90000002', 'zelfde nummer in twee bedrijven: beide treffers');
check(thales_workorder_components('WO2600098')[0]['component_no'] === '' && count(thales_workorder_components('WO2600098')) === 2, 'delta op Start_Date; leeg component bewaard');
check(thales_workorder_components('WO2600001') === [['company' => '', 'wo_no' => 'WO2600001', 'component_no' => '90000001', 'task_code' => '', 'task_description' => '', 'description' => '']], 'werkorder uit rapport als fallback');
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


// ---------- taakcode: opslag, labels, kans (alleen motortype)
$wo99 = thales_workorder_components('WO2600099');
check(($wo99[0]['task_code'] ?? '') === 'CO' && ($wo99[1]['task_code'] ?? '') === 'CO', 'werkorder bewaart Task_Code');
check(thales_state('workorders_taskfill_oldest_month|KvT') !== null, 'taskfill: eerste nacht heeft een maand gelezen');
// Koppel rapport WO2600001 aan KvT-werkorder met taakcode + bouw task_parts handmatig uit meerdere WO's
$db->exec("INSERT OR REPLACE INTO workorders(company, wo_no, component_no, task_code, task_description, created_on, start_date, synced_at) VALUES
    ('KvT', 'WO2600001', '90000001', 'CO', 'Correctief onderhoud', '2026-01-01', '2026-01-01', '2026-01-01'),
    ('KvT', 'WO260000A', '90000001', 'CO', 'Correctief onderhoud', '2026-01-02', '2026-01-02', '2026-01-01'),
    ('KvT', 'WO260000B', '90000001', 'CO', 'Correctief', '2026-01-03', '2026-01-03', '2026-01-01'),
    ('KvT', 'WO260000C', '90000001', 'CO', 'Correctief', '2026-01-04', '2026-01-04', '2026-01-01'),
    ('KvT', 'WO260000D', '90000002', 'GOH', 'Preventive maintenance', '2026-01-05', '2026-01-05', '2026-01-01')");
// Zorg dat report A (WO2600001) in task_parts belandt: rebuild
thales_rebuild_aggregates();
// synthetische task_parts voor kansberekening (4 CO-taken op PERKINS/4016TAG2A)
$db->exec("DELETE FROM task_parts");
$db->exec("INSERT INTO task_parts(company, wo_no, task_code, brand_key, type_key, item_no, qty) VALUES
    ('KvT', 'WO2600001', 'CO', 'PERKINS', '4016TAG2A', 'TST-1', 4),
    ('KvT', 'WO260000A', 'CO', 'PERKINS', '4016TAG2A', 'TST-1', 2),
    ('KvT', 'WO260000A', 'CO', 'PERKINS', '4016TAG2A', 'TST-2', 1),
    ('KvT', 'WO260000B', 'CO', 'PERKINS', '4016TAG2A', 'TST-1', 4),
    ('KvT', 'WO260000C', 'CO', 'PERKINS', '4016TAG2A', 'TST-2', 3),
    ('KvT', 'WO260000D', 'GOH', 'PERKINS', '4016TAG2A', 'TST-1', 1),
    ('KvT', 'WO-THIN-1', 'SA', 'PERKINS', '4016TAG2A', 'TST-3', 1),
    ('KvT', 'WO-THIN-2', 'SA', 'OTHER', 'X', 'TST-3', 1)");
$db->exec("DELETE FROM task_code_labels");
$db->exec("INSERT INTO task_code_labels(task_code, description, times) VALUES
    ('CO', 'Correctief onderhoud', 3),
    ('GOH', 'Preventive maintenance', 1),
    ('SA', 'Smeerolie analyse', 1)");
$odds = thales_task_odds('CO', 'PERKINS', '4016TAG2A');
check($odds['level'] === 'motor+task' && $odds['tasks'] === 4, 'kans: 4 CO-taken op dit motortype');
$byItem = array_column($odds['parts'], null, 'item_no');
check(isset($byItem['TST-1']) && abs($byItem['TST-1']['odds'] - 0.75) < 0.001 && $byItem['TST-1']['times'] === 3, 'TST-1 in 3/4 = 75%');
check(isset($byItem['TST-2']) && abs($byItem['TST-2']['odds'] - 0.5) < 0.001, 'TST-2 in 2/4 = 50%');
check(abs($byItem['TST-1']['qty_median'] - 4.0) < 0.01, 'mediaan qty TST-1 = 4 (2,4,4)');
// Weinig data op motor+taak → toch kansen, level low (geen terugval naar andere motoren)
$thin = thales_task_odds('SA', 'PERKINS', '4016TAG2A');
check($thin['level'] === 'low' && $thin['tasks'] === 1 && ($thin['parts'][0]['item_no'] ?? '') === 'TST-3', 'lage zekerheid: 1 SA-taak op dit motortype');
$empty = thales_task_odds('SA', 'PERKINS', 'ONBEKEND');
check($empty['level'] === 'none' && $empty['tasks'] === 0 && $empty['parts'] === [], '0 taken op motortype → geen voorstellen');
$noMotor = thales_task_odds('CO', '', '');
check($noMotor['level'] === 'none' && $noMotor['tasks'] === 0, 'zonder motor geen kansberekening');
check(thales_median([1, 3, 2]) === 2.0 && thales_median([1, 2, 3, 4]) === 2.5 && thales_median([]) === 0.0, 'mediaan helper');
$sug = thales_suggest_tasks('correctief');
check(($sug[0]['value'] ?? '') === 'CO', 'suggest_tasks fuzzy op omschrijving → CO');
$sug2 = thales_suggest_tasks('goh');
check(($sug2[0]['value'] ?? '') === 'GOH', 'suggest_tasks prefix op code');
check(str_contains(thales_task_label('CO'), 'Correctief'), 'task_label toont omschrijving');

// Zoeken/suggest met taakcode: taak+motor eerst (met kans), dan overige motor, dan rest
$db->exec("INSERT OR REPLACE INTO items(item_no, description, excel_name, bc_type, is_task, search, synced_at) VALUES
    ('TST-MOTOR', 'MOTOR ONLY FILTER', '', 'Voorraad', 0, ' tst-motor motor only filter ', '2026-01-01')");
$db->exec("INSERT OR REPLACE INTO usage(brand_key, brand, type_key, type, brand_source, item_no, times_used, qty_total) VALUES
    ('PERKINS', 'PERKINS', '4016TAG2A', '4016TAG2A', 'bc', 'TST-MOTOR', 2, 2)");
$withTask = thales_search('TST', 'PERKINS', '4016TAG2A', 50, 'CO');
$taskNos = array_column($withTask['task'] ?? [], 'item_no');
$usedNos = array_column($withTask['used'] ?? [], 'item_no');
$otherNos = array_column($withTask['other'] ?? [], 'item_no');
check(in_array('TST-1', $taskNos, true) && in_array('TST-2', $taskNos, true), 'zoeken met taak: CO-onderdelen in task-tier');
check(in_array('TST-MOTOR', $usedNos, true) && !in_array('TST-MOTOR', $taskNos, true), 'zoeken met taak: motor-only ná task-tier');
check(!in_array('TST-1', $usedNos, true), 'zoeken met taak: task-items niet dubbel in used');
$sugTask = array_column(thales_suggest_parts('TST', 'PERKINS', '4016TAG2A', 20, 'CO'), 'value');
check(($sugTask[0] ?? '') === 'TST-1' || ($sugTask[0] ?? '') === 'TST-2', 'suggest met taak: taak+motor eerst');
check(in_array('TST-MOTOR', $sugTask, true), 'suggest met taak: ook motor-only onderdelen');


// ---------- WO Description: gericht vullen, bewaren bij sync, suggest/fuzzy
$db->exec("UPDATE workorders SET description = NULL WHERE wo_no = 'WO2600099'");
$fill = thales_fill_workorder_descriptions(microtime(true) + 30, gmdate('c', time() - 86400));
check(str_contains($fill, 'gevuld') || str_contains($fill, 'gezet'), 'description-fill: statusregel');
$woDesc = thales_workorder_components('WO2600099');
check(($woDesc[0]['description'] ?? '') === 'Correctief bij klant' || ($woDesc[1]['description'] ?? '') === 'Correctief bij klant', 'description-fill: WO2600099 krijgt Description');
// Sync mag description niet wissen
$before = (string) $db->query("SELECT description FROM workorders WHERE company='KvT' AND wo_no='WO2600099'")->fetchColumn();
thales_store_workorders('KvT', "No eq 'WO2600099'", '2026-11-05');
$after = (string) $db->query("SELECT description FROM workorders WHERE company='KvT' AND wo_no='WO2600099'")->fetchColumn();
check($before !== '' && $before === $after, 'description blijft staan na store_workorders');
$sugDesc = thales_suggest('Correctief bij');
check(in_array('WO2600099', array_column($sugDesc, 'value'), true), 'suggest fuzzy op WO-description');
$lab = null;
foreach ($sugDesc as $row) {
    if ($row['value'] === 'WO2600099') { $lab = $row['label'] ?? ''; break; }
}
check(is_string($lab) && str_contains($lab, 'Correctief bij klant'), 'suggest label toont No · Description');
// Ontbrekend veld: Ariadne-melding, geen crash
$GLOBALS['THALES_MIMIR'] = function (string $table, array $select, string $filter, int $top, ?string $company = null): array {
    if ($table === 'AppWerkorders' && in_array('Description', $select, true)) {
        throw new RuntimeException('Mímir AppWerkorders HTTP 400: Onbekende kolom: Description');
    }
    return [];
};
thales_set_state('workorders_description_field', '0');
$missing = thales_fill_workorder_descriptions(microtime(true) + 10);
check(str_contains($missing, 'ontbreekt') && str_contains($missing, 'Ariadne'), 'description ontbreekt → Ariadne-melding');

// ---------- schema-upgrade vanaf pre-#15 DB (subprocess: verse static PDO)
$upgradeDir = sys_get_temp_dir() . '/thales-upgrade-' . getmypid();
@mkdir($upgradeDir);
$upgradeDb = $upgradeDir . '/thales.sqlite';
@unlink($upgradeDb);
$old = new PDO('sqlite:' . $upgradeDb);
$old->exec("CREATE TABLE state (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE workorders (company TEXT NOT NULL, wo_no TEXT NOT NULL, component_no TEXT, created_on TEXT, start_date TEXT, synced_at TEXT, PRIMARY KEY (company, wo_no));
CREATE TABLE reports (item_id TEXT PRIMARY KEY, name TEXT, web_url TEXT, folder_name TEXT, equipment_no TEXT, report_date TEXT, wo TEXT, task_codes TEXT, size INTEGER, created TEXT, modified TEXT, parse_status TEXT NOT NULL DEFAULT 'pending', parse_note TEXT, sheet TEXT, processed_at TEXT);
CREATE TABLE report_components (item_id TEXT NOT NULL, role TEXT, component_no TEXT, brand_raw TEXT, brand TEXT, type TEXT, serial TEXT, is_motor INTEGER NOT NULL DEFAULT 0);
CREATE TABLE report_parts (item_id TEXT NOT NULL, line INTEGER, item_no TEXT, description_raw TEXT, qty REAL, unit TEXT);
CREATE TABLE components (component_no TEXT PRIMARY KEY, parent_no TEXT, sub_entity TEXT, manufacturer_code TEXT, manufacturer_name TEXT, model TEXT, synced_at TEXT);
CREATE TABLE report_motor (item_id TEXT PRIMARY KEY, component_no TEXT, brand_key TEXT, brand TEXT, type_key TEXT, type TEXT, brand_source TEXT);
CREATE TABLE items (item_no TEXT PRIMARY KEY, description TEXT, excel_name TEXT NOT NULL DEFAULT '', bc_type TEXT NOT NULL DEFAULT '', is_task INTEGER NOT NULL DEFAULT 0, search TEXT, synced_at TEXT);
CREATE TABLE usage (brand_key TEXT, brand TEXT, type_key TEXT, type TEXT, brand_source TEXT, item_no TEXT, times_used INTEGER, qty_total REAL, PRIMARY KEY (brand_key, type_key, item_no));
CREATE TABLE resources (resource_no TEXT PRIMARY KEY, name TEXT, synced_at TEXT);
CREATE TABLE folders (id TEXT PRIMARY KEY, name TEXT, parent_id TEXT);
INSERT INTO workorders(company, wo_no, component_no) VALUES ('KVT','WO-OLD','C1');");
$old = null;
$cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    '$GLOBALS["THALES_DATA_DIR"]=' . var_export($upgradeDir, true) . ';'
    . 'require ' . var_export(dirname(__DIR__) . '/thales_lib.php', true) . ';'
    . '$db=thales_db();'
    . '$cols=array_column($db->query("PRAGMA table_info(workorders)")->fetchAll(),"name");'
    . 'if (!in_array("task_code",$cols,true)||!in_array("task_description",$cols,true)||!in_array("description",$cols,true)) {fwrite(STDERR,"missing cols\n"); exit(1);}'
    . 'if (!$db->query("SELECT 1 FROM sqlite_master WHERE name=\"task_parts\"")->fetchColumn()) {fwrite(STDERR,"no task_parts\n"); exit(1);}'
    . 'if ((string)$db->query("SELECT value FROM state WHERE key=\"schema_version\"")->fetchColumn() !== (string)THALES_SCHEMA_VERSION) {fwrite(STDERR,"bad version\n"); exit(1);}'
    . '$h=thales_workorder_components("WO-OLD");'
    . 'if (($h[0]["component_no"]??"")!=="C1") {fwrite(STDERR,"wo fail\n"); exit(1);}'
    . 'echo "upgrade-ok\n";'
);
exec($cmd, $out, $code);
check($code === 0 && ($out[0] ?? '') === 'upgrade-ok', 'schema-upgrade vanaf pre-#15 DB (task_code + task_parts + description)');

// ---------- page-read tijdens write-lock (WAL + busy_timeout): korte exclusive lock, read moet wachten en slagen
$lockDir = sys_get_temp_dir() . '/thales-lock-' . getmypid();
@mkdir($lockDir);
$GLOBALS['THALES_DATA_DIR'] = $lockDir;
// forceer verse connectie: static PDO wijst nog naar vorige testdir — open via subprocess
$lockDb = $lockDir . '/thales.sqlite';
@unlink($lockDb);
$boot = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    '$GLOBALS["THALES_DATA_DIR"]=' . var_export($lockDir, true) . ';'
    . 'require ' . var_export(dirname(__DIR__) . '/thales_lib.php', true) . ';'
    . 'thales_db(); echo "boot\n";'
);
exec($boot, $bout, $bcode);
check($bcode === 0, 'lock-test: schema gebootstrapt');
$reader = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    '$GLOBALS["THALES_DATA_DIR"]=' . var_export($lockDir, true) . ';'
    . 'require ' . var_export(dirname(__DIR__) . '/thales_lib.php', true) . ';'
    . 'usleep(200000);' // 200ms: writer houdt lock vast
    . '$t=microtime(true); $n=(int)thales_db()->query("SELECT COUNT(*) FROM workorders")->fetchColumn();'
    . 'echo "read-ok $n " . round((microtime(true)-$t)*1000) . "\n";'
);
$writer = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    '$db=new PDO("sqlite:" . ' . var_export($lockDb, true) . ');'
    . '$db->exec("PRAGMA busy_timeout=60000");'
    . '$db->exec("BEGIN IMMEDIATE");'
    . '$db->exec("CREATE TABLE IF NOT EXISTS _lock_probe(x INTEGER)");'
    . 'usleep(500000);' // 500ms write-lock
    . '$db->exec("COMMIT");'
    . 'echo "write-ok\n";'
);
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pw = proc_open($writer, $descriptors, $pipesW);
usleep(50000); // writer start eerst
$pr = proc_open($reader, $descriptors, $pipesR);
$outW = stream_get_contents($pipesW[1]);
$outR = stream_get_contents($pipesR[1]);
fclose($pipesW[1]); fclose($pipesW[2]);
fclose($pipesR[1]); fclose($pipesR[2]);
$cw = proc_close($pw);
$cr = proc_close($pr);
check($cw === 0 && str_contains($outW, 'write-ok'), 'lock-test: writer klaar');
check($cr === 0 && str_contains($outR, 'read-ok'), 'lock-test: page-read wacht op lock en slaagt (WAL/busy_timeout)');

echo "Alle tests geslaagd\n";

