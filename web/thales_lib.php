<?php

/**
 * Thales: opslag (SQLite), SharePoint-sync (Graph delta), BC-cache (Mímir), aggregaten en lees-API.
 * Pagina's lezen alleen uit SQLite; Graph en Mímir worden alleen vanuit nightly.php aangeroepen.
 */
require_once __DIR__ . '/thales_parser.php';

const THALES_SITE_ID_DEFAULT = 'kvtnl.sharepoint.com,c1c8a9d4-9d43-433d-9460-8f443afc0525,678213c0-df01-4462-b0a0-528f653a8ce1';
const THALES_DRIVE_ID_DEFAULT = 'b!1KnIwUOdPUOUYI9EOvwFJcATgmcB32JEsKBSj2U6jOGlm_IorWP4Srp1K5cz9T1L';
const THALES_REPORT_FOLDER = '101_SERV_REP_EXCEL';
const THALES_MIMIR_MAX_AGE = 14400;
const THALES_DEFAULT_BUDGET_SECONDS = 3600;

/**
 * BC-velden, bevestigd door Ariadne (04-11-2026, Mímir-namen, company Koninklijke van Twist).
 * Overschrijfbaar in auth.php met $thalesBcComponent / $thalesBcItem (zelfde sleutels).
 */
const THALES_BC_COMPONENT_DEFAULT = [
    'entity' => 'AppComponentCard',
    'key' => 'No',
    'sub_entity' => 'Sub_Entity',              // 'DIESEL ENGINE', 'GENERATOR', SET: 'EPG…' of 'GENERATOR SET'
    'manufacturer_code' => 'Manufacturer_Code', // merksleutel, bijv. 'INDUSTRIAL'
    'manufacturer_name' => 'Manufacturer_Name', // weergave, bijv. 'Industrial Perkins '
    'model' => 'Manufacturer_Model',           // type, bijv. '4016TAG2A'
    'model_fallback' => 'Description_2',       // als Manufacturer_Model leeg is
    'parent' => 'Component_No_Parent',
];
const THALES_BC_ITEM_DEFAULT = [
    'entity' => 'AppItems',             // alternatief workflowItems negeert $select; AppItems wel
    'key' => 'No',
    'description' => 'Description',
    'modified' => 'Last_Date_Modified', // alleen een datum: delta met 'ge'
];
/** Mímir accepteert max. top 10000; top 0 loopt vast. Deel groter dan dit wordt verder opgesplitst. */
const THALES_MIMIR_CHUNK = 5000;
/** Motor-subentities onder een SET. */
const THALES_ENGINE_SUB_ENTITIES = ['DIESEL ENGINE', 'GAS ENGINE'];
/** Merkcodes die als onbekend tellen. */
const THALES_UNKNOWN_BRANDS = ['', 'ONBEKEND', 'DIVERS', 'KVT'];
/**
 * Manufacturer_Code (of Excel-merk) => merknaam. Alleen zekere gevallen (Ariadne); de rest toont Manufacturer_Name.
 * Uitbreidbaar in auth.php met $thalesBrandNames.
 */
const THALES_BRAND_NAMES_DEFAULT = [
    'INDUSTRIAL' => 'PERKINS', 'SABRE' => 'PERKINS', 'DIPERK' => 'PERKINS',
    'LEROY SOM' => 'LEROY SOMER', 'LEROYSOMER' => 'LEROY SOMER',
    'VOLVO' => 'VOLVO PENTA', 'VOLVO PENT' => 'VOLVO PENTA',
    'CAT MARINE' => 'CAT',
];

/* ---------------------------------------------------------------- config */

function thales_cfg(string $name, mixed $default): mixed
{
    return $GLOBALS[$name] ?? $default;
}

function thales_bc_component_config(): array
{
    return array_merge(THALES_BC_COMPONENT_DEFAULT, (array) thales_cfg('thalesBcComponent', []));
}

/**
 * Merksleutel + weergave. $code = Manufacturer_Code (of genormaliseerd Excel-merk), $name = Manufacturer_Name.
 * @return array{key:string,label:string}
 */
function thales_brand(string $code, string $name = ''): array
{
    $code = thales_normalize_brand($code);
    if (in_array($code, THALES_UNKNOWN_BRANDS, true)) {
        return ['key' => '?', 'label' => 'Onbekend'];
    }
    $map = array_merge(THALES_BRAND_NAMES_DEFAULT, (array) thales_cfg('thalesBrandNames', []));
    if (isset($map[$code])) {
        return ['key' => $map[$code], 'label' => $map[$code]];
    }
    return ['key' => $code, 'label' => trim($name) !== '' ? trim($name) : $code];
}

/** Typesleutel: hoofdletters, zonder spaties/punten/streepjes. '4.236' en '4.236 T' blijven verschillend. */
function thales_type_key(string $type): string
{
    return (string) preg_replace('/[\s.\-]+/u', '', strtoupper(trim($type)));
}

function thales_bc_item_config(): array
{
    return array_merge(THALES_BC_ITEM_DEFAULT, (array) thales_cfg('thalesBcItem', []));
}

/* ---------------------------------------------------------------- opslag */

function thales_data_dir(): string
{
    $dir = (string) ($GLOBALS['THALES_DATA_DIR'] ?? (__DIR__ . '/data'));
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    if (!is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

function thales_db(): PDO
{
    static $pdo = null, $path = null;
    $want = thales_data_dir() . '/thales.sqlite';
    if ($pdo instanceof PDO && $path === $want) {
        return $pdo;
    }
    $path = $want;
    $pdo = new PDO('sqlite:' . $want, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=10000; PRAGMA foreign_keys=ON;');
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS state (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE IF NOT EXISTS folders (id TEXT PRIMARY KEY, name TEXT, parent_id TEXT);
CREATE TABLE IF NOT EXISTS reports (
    item_id TEXT PRIMARY KEY, name TEXT, web_url TEXT, folder_name TEXT, equipment_no TEXT,
    report_date TEXT, wo TEXT, task_codes TEXT, size INTEGER, created TEXT, modified TEXT,
    parse_status TEXT NOT NULL DEFAULT 'pending', parse_note TEXT, sheet TEXT, processed_at TEXT);
CREATE INDEX IF NOT EXISTS reports_status ON reports(parse_status);
CREATE TABLE IF NOT EXISTS report_components (
    item_id TEXT NOT NULL REFERENCES reports(item_id) ON DELETE CASCADE, role TEXT, component_no TEXT,
    brand_raw TEXT, brand TEXT, type TEXT, serial TEXT, is_motor INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS rc_item ON report_components(item_id);
CREATE INDEX IF NOT EXISTS rc_no ON report_components(component_no);
CREATE TABLE IF NOT EXISTS report_parts (
    item_id TEXT NOT NULL REFERENCES reports(item_id) ON DELETE CASCADE, line INTEGER,
    item_no TEXT, description_raw TEXT, qty REAL, unit TEXT);
CREATE INDEX IF NOT EXISTS rp_item ON report_parts(item_id);
CREATE INDEX IF NOT EXISTS rp_no ON report_parts(item_no);
CREATE TABLE IF NOT EXISTS components (component_no TEXT PRIMARY KEY, parent_no TEXT, sub_entity TEXT,
    manufacturer_code TEXT, manufacturer_name TEXT, model TEXT, synced_at TEXT);
CREATE INDEX IF NOT EXISTS comp_parent ON components(parent_no);
CREATE TABLE IF NOT EXISTS report_motor (item_id TEXT PRIMARY KEY, component_no TEXT, brand_key TEXT, brand TEXT,
    type_key TEXT, type TEXT, brand_source TEXT);
CREATE TABLE IF NOT EXISTS items (item_no TEXT PRIMARY KEY, description TEXT, search TEXT, synced_at TEXT);
CREATE TABLE IF NOT EXISTS usage (
    brand_key TEXT, brand TEXT, type_key TEXT, type TEXT, brand_source TEXT, item_no TEXT, times_used INTEGER, qty_total REAL,
    PRIMARY KEY (brand_key, type_key, item_no));
SQL);
    thales_migrate($pdo);
    return $pdo;
}

/** Retry-kolommen toevoegen op een bestaande database; eerder gecrashte rapporten op de retry-lijst zetten. */
function thales_migrate(PDO $pdo): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(reports)')->fetchAll(), 'name');
    if (in_array('retry_count', $columns, true)) {
        return;
    }
    $pdo->exec('ALTER TABLE reports ADD COLUMN retry_count INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE reports ADD COLUMN first_failed_at TEXT');
    $pdo->exec('ALTER TABLE reports ADD COLUMN last_error TEXT');
    $pdo->prepare("UPDATE reports SET parse_status = 'retry', retry_count = 1, first_failed_at = ?, last_error = COALESCE(parse_note, 'crash')
        WHERE parse_status = 'processing' OR (parse_status = 'error' AND (parse_note LIKE 'Vorige run crashte%' OR parse_note LIKE 'FATAAL%'))")
        ->execute([gmdate('c')]);
}

/** Rapport na een harde crash of fatale fout op de retry-lijst zetten (na een week: given_up). */
function thales_mark_retry(string $itemId, string $error): void
{
    thales_db()->prepare("UPDATE reports SET parse_status = 'retry', retry_count = retry_count + 1,
        first_failed_at = COALESCE(first_failed_at, :now), last_error = :error, parse_note = :error, processed_at = :now WHERE item_id = :id")
        ->execute([':now' => gmdate('c'), ':error' => substr($error, 0, 500), ':id' => $itemId]);
}

function thales_state(string $key, ?string $default = null): ?string
{
    $stmt = thales_db()->prepare('SELECT value FROM state WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function thales_set_state(string $key, ?string $value): void
{
    if ($value === null) {
        thales_db()->prepare('DELETE FROM state WHERE key = ?')->execute([$key]);
        return;
    }
    thales_db()->prepare('INSERT INTO state(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}

function thales_log(string $message): void
{
    @file_put_contents(thales_data_dir() . '/nightly.log', date('Y-m-d H:i:s') . ' ' . $message . "\n", FILE_APPEND);
}

/** Fatale fout (geheugen, tijdslimiet) loggen, tonen en het huidige rapport op de retry-lijst zetten. */
function thales_fatal_guard(): void
{
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    $row = $GLOBALS['THALES_CURRENT'] ?? null;
    $msg = 'FATAAL' . ($row ? ' bij ' . $row['name'] . ' (' . round(((int) $row['size']) / 1048576, 1) . ' MB)' : '') . ': ' . $e['message'];
    thales_progress($msg);
    try {
        if ($row) {
            thales_mark_retry((string) $row['item_id'], $msg);
        }
        thales_set_state('last_run', (string) json_encode(['at' => gmdate('c'), 'status' => 'error', 'message' => substr($msg, 0, 300)]));
    } catch (Throwable) {
    }
}

/** Voortgangsregel naar de browser/het serverscript (houdt de verbinding open) en naar nightly.log. */
function thales_progress(string $message): void
{
    thales_log($message);
    if (PHP_SAPI !== 'cli' && empty($GLOBALS['THALES_QUIET'])) {
        echo date('H:i:s') . ' ' . $message . "\n";
        @flush();
    }
}

/* ---------------------------------------------------------------- http */

/**
 * @return array{0:int,1:string} [status, body]. Testbaar via $GLOBALS['THALES_HTTP'].
 */
function thales_http(string $method, string $url, array $headers = [], ?string $body = null, ?string $saveTo = null): array
{
    if (is_callable($GLOBALS['THALES_HTTP'] ?? null)) {
        return ($GLOBALS['THALES_HTTP'])($method, $url, $headers, $body, $saveTo);
    }
    $ch = curl_init($url);
    $fh = $saveTo !== null ? fopen($saveTo, 'wb') : null;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_USERAGENT => 'Thales/1.0',
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($fh) {
        curl_setopt($ch, CURLOPT_FILE, $fh);
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($fh) {
        fclose($fh);
    }
    if ($raw === false) {
        throw new RuntimeException('HTTP-fout: ' . $error);
    }
    return [$code, $fh ? '' : (string) $raw];
}

/* ---------------------------------------------------------------- graph */

function thales_graph_token(): string
{
    static $token = null, $expires = 0;
    if ($token !== null && time() < $expires - 60) {
        return $token;
    }
    $tenant = (string) thales_cfg('thalesTenantId', '');
    [$code, $raw] = thales_http('POST', 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => (string) thales_cfg('thalesClientId', ''),
            'client_secret' => (string) thales_cfg('thalesClientSecret', ''),
            'scope' => 'https://graph.microsoft.com/.default',
        ]));
    $json = json_decode($raw, true);
    if ($code !== 200 || !is_array($json) || empty($json['access_token'])) {
        throw new RuntimeException('Graph-token mislukt (HTTP ' . $code . '): ' . (string) ($json['error_description'] ?? $json['error'] ?? ''));
    }
    $token = (string) $json['access_token'];
    $expires = time() + (int) ($json['expires_in'] ?? 3600);
    return $token;
}

function thales_graph_get(string $url): array
{
    for ($attempt = 1; ; $attempt++) {
        [$code, $raw] = thales_http('GET', $url, ['Authorization: Bearer ' . thales_graph_token(), 'Accept: application/json']);
        if (($code === 429 || $code >= 500) && $attempt < 4) {
            sleep(5 * $attempt);
            continue;
        }
        $json = json_decode($raw, true);
        if ($code !== 200 || !is_array($json)) {
            throw new RuntimeException('Graph HTTP ' . $code . ': ' . (string) ($json['error']['message'] ?? substr($raw, 0, 200)), $code);
        }
        return $json;
    }
}

function thales_drive_id(): string
{
    return (string) thales_cfg('thalesDriveId', THALES_DRIVE_ID_DEFAULT);
}

/* ---------------------------------------------------------------- sharepoint-sync */

/** Begin van de dag-index (Europe/Amsterdam) als UTC ISO-string, of null bij de eerste run. */
function thales_index_boundary(): ?string
{
    $index = thales_state('day_index');
    if ($index === null) {
        return null;
    }
    return (new DateTimeImmutable($index . ' 00:00', new DateTimeZone('Europe/Amsterdam')))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/** UTC ISO-strings vergelijken als tijd. */
function thales_ts(?string $iso): int
{
    return $iso ? (int) strtotime($iso) : 0;
}

/**
 * Eén delta-item verwerken. Map- en bestandsnamen worden onthouden omdat Graph in delta geen pad meegeeft.
 */
function thales_apply_delta_item(array $item, ?string $boundary): void
{
    $db = thales_db();
    $id = (string) ($item['id'] ?? '');
    if ($id === '') {
        return;
    }
    if (isset($item['deleted'])) {
        $db->prepare('DELETE FROM reports WHERE item_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM folders WHERE id = ?')->execute([$id]);
        return;
    }
    $parentId = (string) ($item['parentReference']['id'] ?? '');
    if (isset($item['folder'])) {
        $db->prepare('INSERT INTO folders(id, name, parent_id) VALUES(?, ?, ?) ON CONFLICT(id) DO UPDATE SET name = excluded.name, parent_id = excluded.parent_id')
            ->execute([$id, (string) ($item['name'] ?? ''), $parentId]);
        return;
    }
    $name = (string) ($item['name'] ?? '');
    if (!isset($item['file']) || !preg_match('/\.xls[xm]$/i', $name) || str_starts_with($name, '~$')) {
        return;
    }
    $parent = thales_folder($parentId);
    if ($parent === null || strcasecmp($parent['name'], THALES_REPORT_FOLDER) !== 0) {
        // Ander pad: als het bestand eerder wel meetelde (verplaatst), eruit halen.
        $db->prepare('DELETE FROM reports WHERE item_id = ?')->execute([$id]);
        return;
    }
    $equipmentFolder = thales_folder($parent['parent_id']);
    $created = (string) ($item['createdDateTime'] ?? '');
    $modified = (string) ($item['lastModifiedDateTime'] ?? '');
    $meta = thales_parse_filename($name);

    $stmt = $db->prepare('SELECT modified, parse_status FROM reports WHERE item_id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    // Dag-index: opnieuw analyseren als created/modified op of na de index ligt (correcties), of als hij nieuw/gewijzigd is.
    $pending = $existing === false
        || $existing['modified'] !== $modified
        || $boundary === null
        || thales_ts($created) >= thales_ts($boundary)
        || thales_ts($modified) >= thales_ts($boundary);
    $db->prepare(<<<SQL
INSERT INTO reports(item_id, name, web_url, folder_name, equipment_no, report_date, wo, task_codes, size, created, modified, parse_status)
VALUES(:id, :name, :url, :folder, :eq, :date, :wo, :codes, :size, :created, :modified, 'pending')
ON CONFLICT(item_id) DO UPDATE SET name = excluded.name, web_url = excluded.web_url, folder_name = excluded.folder_name,
    equipment_no = excluded.equipment_no, report_date = excluded.report_date, wo = excluded.wo, task_codes = excluded.task_codes,
    size = excluded.size, created = excluded.created, modified = excluded.modified,
    parse_status = CASE WHEN reports.parse_status = 'given_up' THEN 'given_up' WHEN :pending = 1 THEN 'pending' ELSE reports.parse_status END
SQL)->execute([
        ':id' => $id, ':name' => $name, ':url' => (string) ($item['webUrl'] ?? ''),
        ':folder' => $equipmentFolder['name'] ?? '', ':eq' => $meta['equipment_no'] ?? strtok((string) ($equipmentFolder['name'] ?? ''), '_'),
        ':date' => $meta['date'] ?? null, ':wo' => $meta['wo'] ?? null, ':codes' => $meta ? implode(' ', $meta['task_codes']) : null,
        ':size' => (int) ($item['size'] ?? 0), ':created' => $created, ':modified' => $modified, ':pending' => $pending ? 1 : 0,
    ]);
}

/** @return array{name:string,parent_id:string}|null */
function thales_folder(string $id): ?array
{
    if ($id === '') {
        return null;
    }
    $stmt = thales_db()->prepare('SELECT name, parent_id FROM folders WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row !== false) {
        return $row;
    }
    // Ouder nog niet gezien in delta: los ophalen en onthouden.
    try {
        $json = thales_graph_get('https://graph.microsoft.com/v1.0/drives/' . rawurlencode(thales_drive_id()) . '/items/' . rawurlencode($id) . '?$select=id,name,parentReference');
    } catch (Throwable $e) {
        return null;
    }
    $row = ['name' => (string) ($json['name'] ?? ''), 'parent_id' => (string) ($json['parentReference']['id'] ?? '')];
    thales_db()->prepare('INSERT OR REPLACE INTO folders(id, name, parent_id) VALUES(?, ?, ?)')->execute([$id, $row['name'], $row['parent_id']]);
    return $row;
}

/**
 * Graph delta doorlopen tot de deltaLink of tot het budget op is. Hervat via state delta_next.
 * @return bool true als de delta compleet is (deltaLink ontvangen)
 */
function thales_sync_delta(float $deadline): bool
{
    $boundary = thales_index_boundary();
    $url = thales_state('delta_next')
        ?? thales_state('delta_link')
        ?? 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode(thales_drive_id())
            . '/root/delta?$select=id,name,size,file,folder,deleted,parentReference,createdDateTime,lastModifiedDateTime,webUrl';
    while (microtime(true) < $deadline) {
        try {
            $page = thales_graph_get($url);
        } catch (RuntimeException $e) {
            if ($e->getCode() === 410) { // deltaLink verlopen: opnieuw volledig opbouwen
                thales_log('deltaLink verlopen, volledige resync');
                thales_set_state('delta_link', null);
                thales_set_state('delta_next', null);
                return false;
            }
            throw $e;
        }
        $db = thales_db();
        $db->beginTransaction();
        foreach ((array) ($page['value'] ?? []) as $item) {
            thales_apply_delta_item((array) $item, $boundary);
        }
        $pages = ($pages ?? 0) + 1;
        if ($pages % 10 === 0) {
            thales_progress('SharePoint-delta: ' . $pages . ' pagina\'s gelezen');
        }
        if (isset($page['@odata.nextLink'])) {
            $url = (string) $page['@odata.nextLink'];
            thales_set_state('delta_next', $url);
            $db->commit();
            continue;
        }
        thales_set_state('delta_link', (string) ($page['@odata.deltaLink'] ?? ''));
        thales_set_state('delta_next', null);
        $db->commit();
        return true;
    }
    return false;
}

/** Wachtende rapporten downloaden en parsen tot het budget op is. */
function thales_process_pending(float $deadline, array &$counts): bool
{
    $db = thales_db();
    // Rapport waarop een vorige run hard is gecrasht (segfault/kill): op de retry-lijst.
    foreach ($db->query("SELECT item_id FROM reports WHERE parse_status = 'processing'")->fetchAll(PDO::FETCH_COLUMN) as $crashed) {
        thales_mark_retry((string) $crashed, 'Vorige run crashte op dit rapport');
    }
    // Een week lang mislukt: voor altijd overslaan.
    $db->prepare("UPDATE reports SET parse_status = 'given_up' WHERE parse_status = 'retry' AND first_failed_at <= ?")
        ->execute([gmdate('c', time() - 7 * 86400)]);
    // Gewone wachtrij (klein naar groot) eerst, retries daarna.
    $select = $db->query("SELECT item_id, name, size FROM reports WHERE parse_status IN ('pending', 'retry')
        ORDER BY parse_status = 'retry', CASE WHEN parse_status = 'retry' THEN first_failed_at END, size ASC, modified DESC");
    $todo = $select->fetchAll();
    thales_progress('Te verwerken rapporten: ' . count($todo));
    $done = 0;
    foreach ($todo as $row) {
        if (++$done % 25 === 0) {
            thales_progress('Rapporten: ' . $done . '/' . count($todo));
        }
        if (microtime(true) >= $deadline) {
            return false;
        }
        $id = (string) $row['item_id'];
        $tmp = null;
        $GLOBALS['THALES_CURRENT'] = $row;
        $db->prepare("UPDATE reports SET parse_status = 'processing' WHERE item_id = ?")->execute([$id]);
        try {
            $meta = thales_parse_filename((string) $row['name']);
            if ($meta === null || !thales_task_codes_included($meta['task_codes'])) {
                thales_store_result($id, thales_parse_report('/dev/null', (string) $row['name']));
                $counts['skipped']++;
                continue;
            }
            thales_log('Rapport: ' . $row['name'] . ' (' . (int) $row['size'] . ' bytes)');
            $tmp = tempnam(sys_get_temp_dir(), 'thales');
            [$code] = thales_http('GET', 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode(thales_drive_id()) . '/items/' . rawurlencode($id) . '/content',
                ['Authorization: Bearer ' . thales_graph_token()], null, $tmp);
            if ($code !== 200) {
                throw new RuntimeException('download HTTP ' . $code);
            }
            $result = thales_parse_report($tmp, (string) $row['name']);
            thales_store_result($id, $result);
            $counts[$result['status'] === 'ok' ? 'ok' : 'skipped']++;
        } catch (Throwable $e) {
            $counts['error']++;
            thales_log('FOUT ' . $row['name'] . ': ' . $e->getMessage());
            $db->prepare("UPDATE reports SET parse_status = 'error', parse_note = ?, processed_at = ? WHERE item_id = ?")
                ->execute([substr($e->getMessage(), 0, 500), gmdate('c'), $id]);
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
            unset($GLOBALS['THALES_CURRENT']);
        }
    }
    return true;
}

/** Bijdrage van een rapport vervangen (sleutel: drive item-id), zodat herverwerking niet dubbel telt. */
function thales_store_result(string $itemId, array $result): void
{
    $db = thales_db();
    $db->beginTransaction();
    $db->prepare('DELETE FROM report_components WHERE item_id = ?')->execute([$itemId]);
    $db->prepare('DELETE FROM report_parts WHERE item_id = ?')->execute([$itemId]);
    $insC = $db->prepare('INSERT INTO report_components(item_id, role, component_no, brand_raw, brand, type, serial, is_motor) VALUES(?, ?, ?, ?, ?, ?, ?, ?)');
    $motorNo = $result['motor']['component_no'] ?? null;
    foreach ($result['components'] as $c) {
        $insC->execute([$itemId, $c['role'], $c['component_no'], $c['brand_raw'], $c['brand'] ?? '', $c['type'] ?? '', $c['serial'], (int) ($c['component_no'] === $motorNo)]);
    }
    $insP = $db->prepare('INSERT INTO report_parts(item_id, line, item_no, description_raw, qty, unit) VALUES(?, ?, ?, ?, ?, ?)');
    foreach ($result['parts'] as $i => $p) {
        $insP->execute([$itemId, $i + 1, $p['item_no'], $p['description_raw'], $p['qty'], $p['unit']]);
    }
    $db->prepare('UPDATE reports SET parse_status = ?, parse_note = ?, sheet = ?, processed_at = ?, retry_count = 0, first_failed_at = NULL, last_error = NULL WHERE item_id = ?')
        ->execute([$result['status'], $result['reason'], $result['sheet'], gmdate('c'), $itemId]);
    $db->commit();
}

/* ---------------------------------------------------------------- BC-cache via Mímir (alleen nightly) */

/** @return list<array<string, mixed>> */
function thales_mimir_query(string $table, array $select, string $filter = '', int $top = THALES_MIMIR_CHUNK): array
{
    if (is_callable($GLOBALS['THALES_MIMIR'] ?? null)) {
        return ($GLOBALS['THALES_MIMIR'])($table, $select, $filter, $top);
    }
    $key = trim((string) thales_cfg('mimirApi', ''));
    if ($key === '') {
        throw new RuntimeException('$mimirApi ontbreekt in auth.php');
    }
    $base = rtrim((string) thales_cfg('mimirBase', 'https://sleutels.kvt.nl/mimir/api'), '/');
    $body = ['company' => (string) thales_cfg('thalesCompany', 'Koninklijke van Twist'), 'table' => $table,
        'max_age' => THALES_MIMIR_MAX_AGE, 'top' => $top, 'select' => array_values(array_filter($select))];
    if ($filter !== '') {
        $body['filter'] = $filter;
    }
    [$code, $raw] = thales_http('POST', $base . '/query.php',
        ['Authorization: Bearer ' . $key, 'X-API-Key: ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
        (string) json_encode($body));
    $json = json_decode($raw, true);
    if ($code < 200 || $code >= 300 || !is_array($json['value'] ?? null)) {
        throw new RuntimeException('Mímir ' . $table . ' HTTP ' . $code . ': ' . (string) ($json['error'] ?? ''));
    }
    return $json['value'];
}

function thales_odata_quote(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * Hele tabel ophalen in delen: per beginteken van de sleutel (startswith), en een deel dat het
 * maximum raakt verder opsplitsen. Zo blijft elke Mímir-aanroep onder top 10000.
 *
 * @param callable(array<string,mixed>):void $onRow
 */
function thales_mimir_each(string $table, array $select, string $key, string $baseFilter, callable $onRow, string $prefix = ''): int
{
    $count = 0;
    foreach (str_split('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ') as $char) {
        $p = $prefix . $char;
        $filter = 'startswith(' . $key . ',' . thales_odata_quote($p) . ')' . ($baseFilter !== '' ? ' and (' . $baseFilter . ')' : '');
        $rows = thales_mimir_query($table, $select, $filter, THALES_MIMIR_CHUNK);
        if (count($rows) >= THALES_MIMIR_CHUNK && strlen($p) < 8) {
            $count += thales_mimir_each($table, $select, $key, $baseFilter, $onRow, $p);
            continue;
        }
        foreach ($rows as $row) {
            $onRow($row);
            $count++;
        }
    }
    return $count;
}

/**
 * AppComponentCard volledig opnieuw (geen wijzigingsveld; ca. 34.000 rijen), in delen. Eén keer per dag.
 */
function thales_sync_components(): string
{
    $cfg = thales_bc_component_config();
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
    if (thales_state('components_synced_on') === $today) {
        return 'al ververst vandaag';
    }
    $db = thales_db();
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM components');
        $ins = $db->prepare('INSERT OR REPLACE INTO components(component_no, parent_no, sub_entity, manufacturer_code, manufacturer_name, model, synced_at) VALUES(?, ?, ?, ?, ?, ?, ?)');
        $select = [$cfg['key'], $cfg['sub_entity'], $cfg['manufacturer_code'], $cfg['manufacturer_name'], $cfg['model'], $cfg['model_fallback'], $cfg['parent']];
        $n = thales_mimir_each((string) $cfg['entity'], $select, (string) $cfg['key'], '', static function (array $row) use ($ins, $cfg, $today): void {
            $model = trim((string) ($row[$cfg['model']] ?? ''));
            if ($model === '') {
                $model = trim((string) ($row[$cfg['model_fallback']] ?? ''));
            }
            $ins->execute([
                strtoupper(trim((string) ($row[$cfg['key']] ?? ''))), strtoupper(trim((string) ($row[$cfg['parent']] ?? ''))),
                strtoupper(trim((string) ($row[$cfg['sub_entity']] ?? ''))), thales_normalize_brand((string) ($row[$cfg['manufacturer_code']] ?? '')),
                trim((string) ($row[$cfg['manufacturer_name']] ?? '')), $model, $today,
            ]);
        });
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack(); // oude cache blijft staan
        throw $e;
    }
    thales_set_state('components_synced_on', $today);
    return $n . ' componenten';
}

function thales_search_text(string $no, string $description): string
{
    return ' ' . strtolower($no . ' ' . (string) preg_replace('/\s+/u', ' ', $description)) . ' ';
}

/** Artikelcache (omschrijvingen): eerste keer volledig in delen, daarna delta op Last_Date_Modified (datum). */
function thales_sync_items(): string
{
    $cfg = thales_bc_item_config();
    $since = thales_state('items_synced_on');
    $filter = ($since !== null && !empty($cfg['modified'])) ? $cfg['modified'] . ' ge ' . $since : '';
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
    $db = thales_db();
    $db->beginTransaction();
    $ins = $db->prepare('INSERT OR REPLACE INTO items(item_no, description, search, synced_at) VALUES(?, ?, ?, ?)');
    $onRow = static function (array $row) use ($ins, $cfg, $today): void {
        $no = strtoupper(trim((string) ($row[$cfg['key']] ?? '')));
        if ($no !== '') {
            $description = trim((string) ($row[$cfg['description']] ?? ''));
            $ins->execute([$no, $description, thales_search_text($no, $description), $today]);
        }
    };
    try {
        $rows = $filter !== '' ? thales_mimir_query((string) $cfg['entity'], [$cfg['key'], $cfg['description']], $filter) : null;
        if ($rows !== null && count($rows) < THALES_MIMIR_CHUNK) {
            array_walk($rows, static fn(array $row) => $onRow($row));
            $n = count($rows);
        } else {
            $n = thales_mimir_each((string) $cfg['entity'], [$cfg['key'], $cfg['description']], (string) $cfg['key'], $filter, $onRow);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    thales_set_state('items_synced_on', $today);
    return $n . ' artikelen (' . ($filter === '' ? 'volledig' : 'delta') . ')';
}

/* ---------------------------------------------------------------- aggregaten */

function thales_bc_component(string $no): ?array
{
    $stmt = thales_db()->prepare('SELECT * FROM components WHERE component_no = ?');
    $stmt->execute([$no]);
    return $stmt->fetch() ?: null;
}

function thales_is_set_sub_entity(string $subEntity): bool
{
    return str_starts_with($subEntity, 'EPG') || $subEntity === 'GENERATOR SET';
}

/** Motorcomponent onder een SET uit de BC-cache (DIESEL/GAS ENGINE). */
function thales_bc_engine_of_set(string $setNo): ?array
{
    $in = implode(',', array_fill(0, count(THALES_ENGINE_SUB_ENTITIES), '?'));
    $stmt = thales_db()->prepare("SELECT * FROM components WHERE parent_no = ? AND sub_entity IN ($in) ORDER BY component_no LIMIT 1");
    $stmt->execute(array_merge([$setNo], THALES_ENGINE_SUB_ENTITIES));
    return $stmt->fetch() ?: null;
}

/**
 * Motormerk/-type van een rapport. Volgorde: DMTR-rij uit Excel; anders de SET en via BC zijn DIESEL/GAS ENGINE-kind.
 * Merk/type uit BC als die gevuld zijn, anders Excel (bron 'excel'). Het merk van een SET (setbouwer) telt niet als motormerk.
 *
 * @param list<array> $components rijen uit report_components
 * @return array{component_no:string,brand_key:string,brand:string,type_key:string,type:string,brand_source:string}
 */
function thales_resolve_motor(array $components): array
{
    $excel = null;
    foreach ($components as $c) {
        if ($c['role'] === 'DMTR') {
            $excel = $c;
            break;
        }
    }
    $bc = $excel !== null ? thales_bc_component($excel['component_no']) : null;
    if ($excel === null) {
        foreach ($components as $c) {
            if ($c['role'] === 'SET') {
                $bc = thales_bc_engine_of_set($c['component_no']);
                $excel = $c;
                if ($bc === null) { // geen motor bekend: setbouwer niet als motormerk gebruiken
                    $excel = ['component_no' => $c['component_no'], 'brand' => '', 'type' => ''];
                }
                break;
            }
        }
    }
    $excel ??= $components[0] ?? ['component_no' => '', 'brand' => '', 'type' => ''];
    $hasBcBrand = $bc !== null && !in_array((string) $bc['manufacturer_code'], THALES_UNKNOWN_BRANDS, true);
    $brand = $hasBcBrand ? thales_brand((string) $bc['manufacturer_code'], (string) $bc['manufacturer_name']) : thales_brand((string) ($excel['brand'] ?? ''));
    $type = ($bc !== null && trim((string) $bc['model']) !== '') ? trim((string) $bc['model']) : trim((string) ($excel['type'] ?? ''));
    return [
        'component_no' => (string) ($bc['component_no'] ?? $excel['component_no']),
        'brand_key' => $brand['key'], 'brand' => $brand['label'],
        'type_key' => thales_type_key($type), 'type' => $type,
        'brand_source' => $hasBcBrand ? 'bc' : 'excel',
    ];
}

function thales_rebuild_aggregates(): void
{
    $db = thales_db();
    $db->beginTransaction();
    $db->exec('DELETE FROM report_motor; DELETE FROM usage;');
    $ins = $db->prepare('INSERT INTO report_motor(item_id, component_no, brand_key, brand, type_key, type, brand_source) VALUES(?, ?, ?, ?, ?, ?, ?)');
    $byReport = [];
    foreach ($db->query("SELECT rc.* FROM report_components rc JOIN reports r ON r.item_id = rc.item_id AND r.parse_status = 'ok'") as $row) {
        $byReport[$row['item_id']][] = $row;
    }
    foreach ($byReport as $itemId => $components) {
        $m = thales_resolve_motor($components);
        $ins->execute([$itemId, $m['component_no'], $m['brand_key'], $m['brand'], $m['type_key'], $m['type'], $m['brand_source']]);
    }
    $db->exec('INSERT INTO usage(brand_key, brand, type_key, type, brand_source, item_no, times_used, qty_total)
        SELECT m.brand_key, MAX(m.brand), m.type_key, MAX(m.type), MIN(m.brand_source), p.item_no, COUNT(DISTINCT p.item_id), SUM(p.qty)
        FROM report_motor m JOIN report_parts p ON p.item_id = m.item_id GROUP BY m.brand_key, m.type_key, p.item_no');
    $db->commit();
}

/* ---------------------------------------------------------------- nightly */

/** @return array<string, mixed> samenvatting */
function thales_nightly(?int $budgetSeconds = null): array
{
    $lock = fopen(thales_data_dir() . '/nightly.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['status' => 'locked'];
    }
    $start = microtime(true);
    $deadline = $start + ($budgetSeconds ?? (int) thales_cfg('thalesBudgetSeconds', THALES_DEFAULT_BUDGET_SECONDS));
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
    $summary = ['status' => 'partial', 'ok' => 0, 'skipped' => 0, 'error' => 0];
    try {
        thales_progress('Start nightly');
        $deltaDone = thales_sync_delta($deadline);
        thales_progress('SharePoint-delta ' . ($deltaDone ? 'compleet' : 'nog niet compleet (gaat volgende run verder)'));
        $pendingDone = thales_process_pending($deadline, $summary);
        foreach (['components', 'items'] as $key) {
            thales_progress('BC-cache bijwerken: ' . $key);
            try {
                $summary[$key] = $key === 'items' ? thales_sync_items() : thales_sync_components();
            } catch (Throwable $e) {
                $summary[$key] = 'fout: ' . $e->getMessage();
                thales_log('BC ' . $key . ': ' . $e->getMessage());
            }
        }
        thales_rebuild_aggregates();
        if ($deltaDone && $pendingDone) {
            thales_set_state('day_index', $today);
            $summary['status'] = 'ok';
        }
    } catch (Throwable $e) {
        $summary['status'] = 'error';
        $summary['message'] = $e->getMessage();
        thales_log('RUN: ' . $e->getMessage());
    }
    $summary['seconds'] = (int) round(microtime(true) - $start);
    thales_set_state('last_run', (string) json_encode(['at' => gmdate('c')] + $summary));
    flock($lock, LOCK_UN);
    fclose($lock);
    return $summary;
}

/* ---------------------------------------------------------------- lees-API (alleen eigen cache) */

/** @return array<string, mixed> */
function thales_status(): array
{
    $counts = thales_db()->query('SELECT parse_status, COUNT(*) n FROM reports GROUP BY parse_status')->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'last_run' => json_decode((string) thales_state('last_run', 'null'), true),
        'day_index' => thales_state('day_index'),
        'counts' => array_map('intval', $counts),
        'backfill_busy' => thales_state('delta_link') === null || (int) ($counts['pending'] ?? 0) > 0,
    ];
}

/** @return list<array{brand_key:string,brand:string,types:list<array{type_key:string,type:string}>}> */
function thales_brands(): array
{
    $rows = thales_db()->query('SELECT brand_key, MAX(brand) brand, type_key, MAX(type) type FROM usage GROUP BY brand_key, type_key ORDER BY brand_key = \'?\', brand, type_key')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[$row['brand_key']] ??= ['brand_key' => $row['brand_key'], 'brand' => $row['brand'], 'types' => []];
        $out[$row['brand_key']]['types'][] = ['type_key' => $row['type_key'], 'type' => $row['type']];
    }
    return array_values($out);
}

/** @return list<array{item_no:string,description:string,times_used:int,qty_total:float,brand_source:string}> */
function thales_parts(string $brandKey, string $typeKey): array
{
    $stmt = thales_db()->prepare('SELECT u.item_no, COALESCE(i.description, \'\') description, u.times_used, u.qty_total, u.brand_source
        FROM usage u LEFT JOIN items i ON i.item_no = u.item_no WHERE u.brand_key = ? AND u.type_key = ? ORDER BY u.times_used DESC, u.qty_total DESC');
    $stmt->execute([$brandKey, $typeKey]);
    return $stmt->fetchAll();
}

/** Bronrapporten van een onderdeel op een merk/type. */
function thales_part_sources(string $brandKey, string $typeKey, string $itemNo): array
{
    $stmt = thales_db()->prepare('SELECT DISTINCT r.name, r.web_url, r.wo, r.report_date FROM report_motor m
        JOIN report_parts p ON p.item_id = m.item_id JOIN reports r ON r.item_id = m.item_id
        WHERE m.brand_key = ? AND m.type_key = ? AND p.item_no = ? ORDER BY r.report_date DESC');
    $stmt->execute([$brandKey, $typeKey, $itemNo]);
    return $stmt->fetchAll();
}

/**
 * Merk/type van een component. Bij een SET: de motor (BC-kind DIESEL/GAS ENGINE of DMTR uit het laatste rapport).
 * @return array{component_no:string,role:string,set_builder:string,motor:array}|null
 */
function thales_component_lookup(string $componentNo): ?array
{
    $no = strtoupper(trim($componentNo));
    if ($no === '') {
        return null;
    }
    $db = thales_db();
    $bc = thales_bc_component($no);
    $stmt = $db->prepare('SELECT rc.item_id, rc.role FROM report_components rc JOIN reports r ON r.item_id = rc.item_id
        WHERE rc.component_no = ? ORDER BY r.report_date DESC LIMIT 1');
    $stmt->execute([$no]);
    $hit = $stmt->fetch();
    if ($bc === null && $hit === false) {
        return null;
    }
    $isSet = ($bc !== null && thales_is_set_sub_entity((string) $bc['sub_entity'])) || ($hit !== false && $hit['role'] === 'SET');
    if ($hit !== false && ($isSet || $hit['role'] === 'DMTR')) {
        $rows = $db->prepare('SELECT * FROM report_components WHERE item_id = ?');
        $rows->execute([$hit['item_id']]);
        $components = $rows->fetchAll();
        if (!$isSet) { // dit component zelf is de motor
            $components = array_values(array_filter($components, static fn($c) => $c['component_no'] === $no));
        }
    } else {
        $components = [['role' => $isSet ? 'SET' : 'DMTR', 'component_no' => $no, 'brand' => '', 'type' => '']];
    }
    return [
        'component_no' => $no,
        'role' => $isSet ? 'SET' : (string) ($bc['sub_entity'] ?? $hit['role'] ?? ''),
        'set_builder' => $isSet && $bc !== null ? trim((string) $bc['manufacturer_name']) : '',
        'motor' => thales_resolve_motor($components),
    ];
}

/** 0..1: hoe goed $query op $text past (woorden, prefix, kleine typefouten). */
function thales_fuzzy_score(string $query, string $text): float
{
    $tokens = preg_split('/[^\p{L}\p{N}.\-]+/u', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($tokens === []) {
        return 0.0;
    }
    $words = preg_split('/[^\p{L}\p{N}.\-]+/u', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $total = 0.0;
    foreach ($tokens as $token) {
        $best = str_contains(strtolower($text), $token) ? 0.8 : 0.0;
        foreach ($words as $word) {
            if ($word === $token) {
                $best = 1.0;
                break;
            }
            if (str_starts_with($word, $token)) {
                $best = max($best, 0.9);
            } elseif (strlen($token) >= 4) {
                $distance = levenshtein($token, substr($word, 0, strlen($token) + 1));
                $best = max($best, max(0.0, 0.75 - 0.2 * $distance) * (int) ($distance <= 2));
            }
        }
        $total += $best;
    }
    return $total / count($tokens);
}

/**
 * Fuzzy zoeken op BC-omschrijving. Eerst artikelen gebruikt op merk+type (match, dan frequentie), dan overige uit de itemcache.
 * @return array{used:list<array>,other:list<array>}
 */
function thales_search(string $query, string $brandKey = '', string $typeKey = '', int $limit = 50): array
{
    $query = trim($query);
    $used = [];
    if ($brandKey !== '') {
        foreach (thales_parts($brandKey, $typeKey) as $part) {
            $part['score'] = $query === '' ? 1.0 : thales_fuzzy_score($query, $part['item_no'] . ' ' . $part['description']);
            if ($part['score'] >= 0.5) {
                $used[$part['item_no']] = $part;
            }
        }
        uasort($used, static fn($a, $b): int => [$b['score'], $b['times_used']] <=> [$a['score'], $a['times_used']]);
    }
    $other = [];
    if ($query !== '') {
        // Voorselectie in SQL op de eerste 3 tekens van elk woord, scoren in PHP.
        $tokens = array_slice(preg_split('/\s+/u', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);
        $where = implode(' OR ', array_fill(0, count($tokens), 'search LIKE ?'));
        $stmt = thales_db()->prepare('SELECT i.item_no, i.description, COALESCE(SUM(u.times_used), 0) times_used
            FROM items i LEFT JOIN usage u ON u.item_no = i.item_no WHERE ' . $where . ' GROUP BY i.item_no LIMIT 5000');
        $stmt->execute(array_map(static fn($t): string => '%' . mb_substr($t, 0, 3) . '%', $tokens));
        foreach ($stmt->fetchAll() as $row) {
            if (isset($used[$row['item_no']])) {
                continue;
            }
            $row['score'] = thales_fuzzy_score($query, $row['item_no'] . ' ' . $row['description']);
            if ($row['score'] >= 0.5) {
                $other[] = $row;
            }
        }
        usort($other, static fn($a, $b): int => [$b['score'], $b['times_used']] <=> [$a['score'], $a['times_used']]);
    }
    return ['used' => array_slice(array_values($used), 0, $limit), 'other' => array_slice($other, 0, $limit)];
}
