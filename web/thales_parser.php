<?php

/**
 * Parser voor servicerapporten (xlsx/xlsm). Geen I/O buiten het bestand zelf.
 */
require_once __DIR__ . '/thales_xlsx.php';

/** Taakcodes waarvoor een rapport meetelt. */
const THALES_TASK_CODES_INCLUDED = ['CO', 'GOH'];
/** Onderdeelregels met deze artikelnummers zijn taakregels, geen onderdelen. */
const THALES_TASK_ITEM_NOS = ['CO', 'GOH', 'ST', 'SA', 'SM', 'QOH', 'PM', 'LB', 'KM', 'REIS', 'ARBEID'];
const THALES_COMPONENT_ROLES = ['SET', 'DMTR', 'GEN'];
const THALES_PARTS_LABEL = 'gebruikte onderdelen';

/**
 * <equipmentnr>_<YYYYMMDD>_WO<nr>_<uren>H_<taakcode(s)>_<code>.xlsm
 *
 * @return array{equipment_no:string,date:?string,wo:string,hours:string,task_codes:list<string>,code:string}|null
 */
function thales_parse_filename(string $name): ?array
{
    if (!preg_match('/^([A-Z]*\d+)_(\d{8})_WO(\d+)_([\d.,]*)H?_(.*?)_([^_]*)\.xls[xm]$/i', trim($name), $m)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Ymd', $m[2]);
    $codes = preg_split('/[\s,+&]+/', strtoupper(trim($m[5]))) ?: [];
    return [
        'equipment_no' => strtoupper($m[1]),
        'date' => $date ? $date->format('Y-m-d') : null,
        'wo' => 'WO' . $m[3],
        'hours' => $m[4],
        'task_codes' => array_values(array_filter($codes, static fn(string $c): bool => $c !== '')),
        'code' => $m[6],
    ];
}

/** @param list<string> $taskCodes */
function thales_task_codes_included(array $taskCodes): bool
{
    return array_intersect($taskCodes, THALES_TASK_CODES_INCLUDED) !== [];
}

/** Merk uit Excel: trim, spaties samenvoegen, hoofdletters. Geen merken samenvoegen. */
function thales_normalize_brand(string $value): string
{
    return strtoupper(trim((string) preg_replace('/\s+/u', ' ', $value)));
}

function thales_is_task_item_no(string $itemNo): bool
{
    $no = strtoupper(trim($itemNo));
    if ($no === '') {
        return true;
    }
    // Hele GOH-familie (GOH ST24, GOH SA ST24, …) — spaties/suffixen vallen buiten ^GOH\d*$
    if (str_starts_with($no, 'GOH')) {
        return true;
    }
    // Storingsdienst e.d. die niet in AppItems staan (800000/800001)
    if (ctype_digit($no) && str_starts_with($no, '8') && strlen($no) >= 6) {
        return true;
    }
    foreach (THALES_TASK_ITEM_NOS as $code) {
        // CO, GOH, SM03, LB100, ST24 …
        if (preg_match('/^' . $code . '\d*$/', $no)) {
            return true;
        }
    }
    return false;
}

function thales_component_role(string $label): ?string
{
    $label = strtoupper(trim($label));
    foreach (THALES_COMPONENT_ROLES as $role) {
        if ($label === $role || str_ends_with($label, '-' . $role)) {
            return $role;
        }
    }
    return null;
}

function thales_is_component_no(string $value): bool
{
    return (bool) preg_match('/^(\d{5,}|COM[\w-]+)$/i', trim($value));
}

/**
 * Componenttabel: eerste tabblad (werkboekvolgorde) met SET/DMTR/GEN in kolom A en een
 * componentnummer in kolom B. Rijen eronder met een componentnummer horen er ook bij (BRST …).
 *
 * @return array{sheet:string,components:list<array{role:string,component_no:string,brand_raw:string,type_raw:string,serial:string}>}|null
 */
function thales_find_components(ThalesXlsx $xlsx): ?array
{
    $names = $xlsx->sheetNames();
    usort($names, static fn(string $a, string $b): int => (int) !str_starts_with(strtolower($a), 'rapport') <=> (int) !str_starts_with(strtolower($b), 'rapport'));
    foreach ($names as $sheet) {
        if (strcasecmp($sheet, 'Intern') === 0) {
            continue;
        }
        $rows = $xlsx->rows($sheet, 60);
        $components = [];
        foreach ($rows as $r => $cells) {
            $role = thales_component_role((string) ($cells['A'] ?? ''));
            $no = trim((string) ($cells['B'] ?? ''));
            if ($components === [] && ($role === null || !thales_is_component_no($no))) {
                continue;
            }
            if (!thales_is_component_no($no)) {
                break;
            }
            $components[] = [
                'role' => $role ?? strtoupper(trim((string) ($cells['A'] ?? ''))),
                'component_no' => strtoupper($no),
                'brand_raw' => (string) ($cells['E'] ?? ''),
                'type_raw' => trim((string) ($cells['G'] ?? '')),
                'serial' => trim((string) ($cells['I'] ?? '')),
            ];
        }
        if ($components !== []) {
            return ['sheet' => $sheet, 'components' => $components];
        }
    }
    return null;
}

/**
 * Onderdelen uit 'Intern': rijen onder 'Gebruikte onderdelen:' tot de eerste lege rij of het volgende label.
 *
 * @return list<array{item_no:string,description_raw:string,qty:float,unit:string}>
 */
function thales_find_parts(ThalesXlsx $xlsx): array
{
    $sheet = null;
    foreach ($xlsx->sheetNames() as $name) {
        if (strcasecmp(trim($name), 'Intern') === 0) {
            $sheet = $name;
        }
    }
    if ($sheet === null) {
        return [];
    }
    $rows = $xlsx->rows($sheet, 150);
    $start = null;
    foreach ($rows as $r => $cells) {
        if (str_starts_with(strtolower(trim((string) ($cells['A'] ?? ''))), THALES_PARTS_LABEL)) {
            $start = $r;
            break;
        }
    }
    if ($start === null) {
        return [];
    }
    $parts = [];
    for ($r = $start + 1; isset($rows[$r]); $r++) {
        $cells = $rows[$r];
        $a = trim((string) ($cells['A'] ?? ''));
        $itemNo = strtoupper(trim((string) ($cells['D'] ?? '')));
        if (str_ends_with($a, ':') && $itemNo === '') {
            break; // volgend label
        }
        if (thales_is_task_item_no($itemNo)) {
            continue;
        }
        $parts[] = [
            'item_no' => $itemNo,
            'description_raw' => $a,
            'qty' => (float) str_replace(',', '.', (string) ($cells['B'] ?? '0')),
            'unit' => trim((string) ($cells['C'] ?? '')),
        ];
    }
    return $parts;
}

/**
 * Volledige parse van één rapport. $name is de SharePoint-bestandsnaam.
 *
 * @return array{status:string,reason:string,meta:?array,sheet:string,components:list<array>,motor:?array,parts:list<array>}
 */
function thales_parse_report(string $path, string $name): array
{
    $meta = thales_parse_filename($name);
    $result = ['status' => 'skipped', 'reason' => '', 'meta' => $meta, 'sheet' => '', 'components' => [], 'motor' => null, 'parts' => []];
    if ($meta === null) {
        $result['reason'] = 'bestandsnaam onherkenbaar';
        return $result;
    }
    if (!thales_task_codes_included($meta['task_codes'])) {
        $result['reason'] = 'taakcode ' . implode(' ', $meta['task_codes']);
        return $result;
    }
    $xlsx = new ThalesXlsx($path);
    $found = thales_find_components($xlsx);
    if ($found === null) {
        $result['components'] = [['role' => 'SET', 'component_no' => $meta['equipment_no'], 'brand_raw' => '', 'type_raw' => '', 'serial' => '']];
        $result['reason'] = 'geen componenttabel, equipmentnummer gebruikt';
    } else {
        $result['sheet'] = $found['sheet'];
        $result['components'] = $found['components'];
    }
    foreach ($result['components'] as &$component) {
        $component['brand'] = thales_normalize_brand($component['brand_raw']);
        $component['type'] = strtoupper(trim($component['type_raw']));
    }
    unset($component);
    $result['motor'] = thales_pick_motor($result['components']);
    $result['parts'] = thales_find_parts($xlsx);
    $result['status'] = 'ok';
    return $result;
}

/** Motor = DMTR, anders SET, anders de eerste component. */
function thales_pick_motor(array $components): ?array
{
    foreach (['DMTR', 'SET'] as $role) {
        foreach ($components as $component) {
            if ($component['role'] === $role) {
                return $component;
            }
        }
    }
    return $components[0] ?? null;
}
