<?php declare(strict_types=1);

function out(string $label, mixed $value): void {
    echo $label.' '.json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}
function qid(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('invalid identifier');
    return '`'.$name.'`';
}
function classify(string $table): string {
    $name = strtolower($table);
    if (in_array($name, ['reset_security','reset_audit'], true)) return 'protected';
    if (str_contains($name,'telegram') || str_contains($name,'credential')) return 'telegram';
    if (str_contains($name,'integration')) return 'integrations';
    if (str_contains($name,'rule')) return 'rules';
    if (preg_match('/translation|telemetr|metric/i',$name)) return 'translation';
    if (preg_match('/event|activit|histor|processed|deliver|message|log/i',$name)) return 'activity';
    if (preg_match('/(^|_)(admin|admins|user|users|migration|migrations|schema|schemas|password|passwords)($|_)/i',$name)) return 'protected';
    return 'other';
}

$host = trim((string)getenv('DB_HOST'));
$port = (int)(getenv('DB_PORT') ?: 3306);
$db   = trim((string)getenv('DB_NAME'));
$user = (string)getenv('DB_USER');
$pass = (string)getenv('DB_PASS');

if ($host==='' || $db==='' || $user==='') {
    out('RESET_DIAG_ERROR', 'database env incomplete');
    exit(2);
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);

echo "RESET_DIAG_BEGIN\n";

try {
    $audit = $pdo->query("SELECT created_at, mode, categories, tables_cleared, rows_deleted, files_cleared, telegram_disconnected, status FROM reset_audit ORDER BY id DESC LIMIT 10")->fetchAll();
    out('RESET_DIAG_AUDIT', $audit);
} catch (Throwable $e) {
    out('RESET_DIAG_AUDIT_ERROR', get_class($e).': '.$e->getMessage());
}

$tables = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$summary = [];
foreach ($tables as $table) {
    if (!is_string($table) || $table==='') continue;
    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM '.qid($table))->fetchColumn();
    } catch (Throwable $e) {
        $count = -1;
    }
    $summary[] = [
        'table'=>$table,
        'category'=>classify($table),
        'rows'=>$count,
    ];
}
out('RESET_DIAG_TABLES', $summary);

$zeroCritical = array_values(array_filter($summary, static function(array $row): bool {
    return in_array($row['category'], ['rules','integrations','telegram','translation'], true) && (int)$row['rows']===0;
}));
out('RESET_DIAG_ZERO_CRITICAL', $zeroCritical);


try {
    $cols = $pdo->query("SHOW COLUMNS FROM app_settings")->fetchAll();
    $safeCols = array_map(static fn(array $c): array => ['field'=>$c['Field']??null,'type'=>$c['Type']??null], $cols);
    out('RESET_DIAG_APP_SETTINGS_COLUMNS', $safeCols);
    $fields = array_values(array_filter(array_map(static fn(array $c): string => (string)($c['Field']??''), $cols)));
    $keyCol = null;
    foreach (['setting_key','key_name','key','name','setting'] as $candidate) {
        if (in_array($candidate, $fields, true)) { $keyCol = $candidate; break; }
    }
    if ($keyCol !== null) {
        $keys = $pdo->query('SELECT '.qid($keyCol).' FROM app_settings ORDER BY '.qid($keyCol))->fetchAll(PDO::FETCH_COLUMN);
        out('RESET_DIAG_APP_SETTINGS_KEYS', array_values(array_map('strval',$keys)));
    } else {
        out('RESET_DIAG_APP_SETTINGS_KEYS', ['key_column_not_identified']);
    }
} catch (Throwable $e) {
    out('RESET_DIAG_APP_SETTINGS_ERROR', get_class($e).': '.$e->getMessage());
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM worker_status")->fetchAll();
    $fields = array_values(array_filter(array_map(static fn(array $c): string => (string)($c['Field']??''), $cols)));
    $safe = [];
    foreach (['status','last_activity_at','updated_at','last_error'] as $field) if (in_array($field,$fields,true)) $safe[] = qid($field);
    if ($safe) out('RESET_DIAG_WORKER_STATUS', $pdo->query('SELECT '.implode(',',$safe).' FROM worker_status LIMIT 3')->fetchAll());
} catch (Throwable $e) {
    out('RESET_DIAG_WORKER_STATUS_ERROR', get_class($e).': '.$e->getMessage());
}

echo "RESET_DIAG_END\n";
