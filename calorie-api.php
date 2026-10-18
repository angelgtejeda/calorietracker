<?php
/** Calorie Tracker protocol 2. PHP 8.0+. Keep data and configuration outside web root. */
declare(strict_types=1);
const MAX_BYTES = 3145728;
const CONFIG_FILE = '/volume1/leadville-private/calorie-config.php';
$config = is_file(CONFIG_FILE) ? require CONFIG_FILE : [];
if (!is_array($config)) $config = [];
$token = (string)($config['token'] ?? getenv('CALORIE_TRACKER_TOKEN') ?: '');
$dataDir = (string)($config['data_dir'] ?? getenv('CALORIE_TRACKER_DATA_DIR') ?: '/volume1/leadville-private');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
function reply(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (strlen($token) < 24 || $token === 'REPLACE_WITH_A_NEW_RANDOM_SECRET_32_CHARS_OR_MORE') reply(503, ['error'=>'Configure a new private Calorie Tracker token (24+ characters).']);
if (!hash_equals($token, $_SERVER['HTTP_X_CALORIE_TOKEN'] ?? '')) reply(401, ['error'=>'Unauthorized. Check the token in NAS Setup.']);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET','PUT'], true)) reply(405,['error'=>'Use GET or PUT.']);
if (!is_dir($dataDir) || !is_writable($dataDir)) reply(500,['error'=>'Private NAS data folder unavailable or not writable.']);
$file = $dataDir.'/calorie-tracker.json';
$backupDir = $dataDir.'/calorie-backups';
$lock = @fopen($dataDir.'/calorie-tracker.lock', 'c');
if (!$lock || !flock($lock, $method === 'GET' ? LOCK_SH : LOCK_EX)) reply(500,['error'=>'Cannot lock calorie data.']);
$current = is_file($file) ? @file_get_contents($file) : 'null';
if (!is_string($current)) reply(500,['error'=>'Cannot read existing calorie data.']);
$stored = json_decode($current, true);
if (json_last_error() !== JSON_ERROR_NONE || ($stored !== null && !validStore($stored))) reply(500,['error'=>'Existing NAS data is invalid; refusing writes.']);
$revision = hash('sha256', $current);
if ($method === 'GET') reply(200,['protocol'=>2,'revision'=>$revision,'baseTag'=>storeTag($stored),'store'=>$stored]);
$body = @file_get_contents('php://input', false, null, 0, MAX_BYTES+1);
if (!is_string($body) || strlen($body)>MAX_BYTES) reply(413,['error'=>'Request exceeds 3 MiB.']);
$payload = json_decode($body, true);
// Legacy whole-store PUT requests must never be accepted, even with a current revision.
if (!is_array($payload) || ($payload['protocol'] ?? null) !== 2 || isset($payload['store']) ||
    !is_string($payload['revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $payload['revision'])) {
    reply(400,['error'=>'This API requires protocol 2 record changes. Update and reload the tracker on every device.']);
}
if (array_key_exists('initialize', $payload)) {
    if ($stored !== null || !hash_equals($revision,$payload['revision'])) reply(409,['error'=>'NAS is not empty. Initial upload cannot replace existing records.']);
    if (!validStore($payload['initialize'])) reply(400,['error'=>'Invalid initial dataset.']);
    $next = $payload['initialize'];
} else {
    if ($stored === null) reply(409,['error'=>'NAS is empty. Use explicit first upload after backing up device data.']);
    if (!is_array($payload['changes'] ?? null)) reply(400,['error'=>'Missing record changes.']);
    // Resolve the actual server-owned baseline; never trust a client-supplied base dataset.
    $base = null;
    if (hash_equals($revision, $payload['revision'])) $base = $stored;
    else {
        foreach (glob($backupDir.'/calorie-*-'.substr($payload['revision'],0,12).'.json') ?: [] as $candidate) {
            $raw = @file_get_contents($candidate);
            if (is_string($raw) && hash_equals(hash('sha256',$raw), $payload['revision'])) {
                $decoded = json_decode($raw,true);
                if (validStore($decoded)) { $base = $decoded; break; }
            }
        }
    }
    if ($base === null) reply(409,['error'=>'The last synced NAS revision is unavailable. Export device JSON, then load NAS before saving again.']);
    if (!is_string($payload['baseTag'] ?? null) || !hash_equals(storeTag($base),$payload['baseTag'])) reply(409,['error'=>'Device sync metadata does not match its NAS baseline. Export device JSON and load NAS before editing.']);
    $next = mergeChanges($base, $stored, $payload['changes']);
    if (!validStore($next)) reply(400,['error'=>'Changes would create invalid data or duplicate daily dates. Nothing was saved.']);
}
$json = json_encode($next, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_string($json) || strlen($json)>MAX_BYTES) reply(413,['error'=>'Result exceeds storage limit.']);
if ($next == $stored) reply(200,['protocol'=>2,'saved'=>true,'revision'=>$revision,'baseTag'=>storeTag($stored),'store'=>$stored]);
if (!is_dir($backupDir) && !@mkdir($backupDir,0700,true) && !is_dir($backupDir)) reply(500,['error'=>'Cannot create private backups.']);
if (is_file($file)) {
    $backup = $backupDir.'/calorie-'.gmdate('Ymd-His').'-'.substr($revision,0,12).'.json';
    if (!@copy($file,$backup)) reply(500,['error'=>'Cannot back up NAS data; refusing write.']);
    @chmod($backup,0600);
}
$tmp = @tempnam($dataDir,'calorie-new-');
if (!$tmp) reply(500,['error'=>'Cannot create NAS temporary file.']);
@chmod($tmp,0600);
if (file_put_contents($tmp,$json,LOCK_EX) !== strlen($json) || !@rename($tmp,$file)) {
    @unlink($tmp); reply(500,['error'=>'Cannot save calorie data.']);
}
reply(200,['protocol'=>2,'saved'=>true,'revision'=>hash('sha256',$json),'baseTag'=>storeTag($next),'store'=>$next]);

function storeTag($store): string { return hash('sha256',json_encode($store,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)); }
function rowMap(array $rows): array {
    $result=[];
    foreach ($rows as $row) $result['id:'.(string)$row['id']]=$row;
    return $result;
}
function sameSlot(array $a, array $b, string $key): bool {
    return array_key_exists($key,$a) === array_key_exists($key,$b) &&
        (!array_key_exists($key,$a) || $a[$key] == $b[$key]);
}
function mergeSlots(array $base, array $remote, array $set, array $remove, string $section): array {
    $desired=$base; $touched=[];
    foreach ($set as $key=>$value) { $desired[$key]=$value; $touched[$key]=true; }
    foreach ($remove as $key) {
        if (!is_string($key) || isset($touched[$key])) reply(400,['error'=>'Invalid or duplicate change key.']);
        unset($desired[$key]); $touched[$key]=true;
    }
    foreach ($touched as $key=>$_) {
        if (sameSlot($desired,$base,(string)$key)) continue;
        if (!sameSlot($remote,$base,(string)$key) && !sameSlot($remote,$desired,(string)$key)) {
            reply(409,['error'=>'Conflicting edit in '.$section.' ('.$key.'). Nothing saved. Export device JSON and back up NAS before reconciling.']);
        }
        if (array_key_exists($key,$desired)) $remote[$key]=$desired[$key]; else unset($remote[$key]);
    }
    return $remote;
}
function mergeChanges(array $base, array $remote, array $changes): array {
    foreach ($changes as $section=>$ops) {
        if (!in_array($section,['goals','dailyLogs','weightTimeline','bodyFatTimeline'],true) || !is_array($ops)) reply(400,['error'=>'Unknown change section.']);
        if ($section === 'goals') {
            if (!is_array($ops['set'] ?? null) || !is_array($ops['remove'] ?? null)) reply(400,['error'=>'Invalid goal changes.']);
            $remote[$section]=mergeSlots($base[$section],$remote[$section],$ops['set'],$ops['remove'],$section);
        } else {
            if (!is_array($ops['upsert'] ?? null) || !is_array($ops['delete'] ?? null)) reply(400,['error'=>'Invalid record changes.']);
            $set=[]; $remove=[];
            foreach ($ops['upsert'] as $row) {
                if (!validRow($row)) reply(400,['error'=>'Invalid changed record.']);
                $key='id:'.(string)$row['id'];
                if (isset($set[$key])) reply(400,['error'=>'Duplicate changed record ID.']);
                $set[$key]=$row;
            }
            foreach ($ops['delete'] as $id) {
                if (!validId($id)) reply(400,['error'=>'Invalid deleted record ID.']);
                $remove[]='id:'.(string)$id;
            }
            $remote[$section]=array_values(mergeSlots(rowMap($base[$section]),rowMap($remote[$section]),$set,$remove,$section));
        }
    }
    return $remote;
}
function validId($id): bool { return (is_int($id) && $id >= 0) || (is_string($id) && strlen($id)>0 && strlen($id)<=100); }
function validRow($row): bool { return is_array($row) && validId($row['id'] ?? null) && is_string($row['date'] ?? null); }
function dailyDateKey(string $date): string {
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})$/D',$date,$m)) {
        $y=(int)$m[3]; if ($y<100) $y+=($y>=70 ? 1900 : 2000);
        return sprintf('%04d-%02d-%02d',$y,(int)$m[1],(int)$m[2]);
    }
    return $date;
}
function validStore($s): bool {
    if (!is_array($s) || !isset($s['goals']['startDate'],$s['goals']['idealWeight'])) return false;
    foreach (['dailyLogs','weightTimeline','bodyFatTimeline'] as $key) {
        if (!isset($s[$key]) || !is_array($s[$key]) || count($s[$key])>25000) return false;
        $ids=[]; $dates=[];
        foreach ($s[$key] as $row) {
            if (!validRow($row)) return false;
            $id='id:'.(string)$row['id']; if (isset($ids[$id])) return false; $ids[$id]=true;
            if ($key==='dailyLogs') { $date=dailyDateKey($row['date']); if (isset($dates[$date])) return false; $dates[$date]=true; }
        }
    }
    return true;
}
