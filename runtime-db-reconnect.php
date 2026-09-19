<?php declare(strict_types=1);

/**
 * Scoped runtime fix: recover a stale MySQL connection before evaluating routing rules.
 * This does not touch trigger matching, media delivery, the database schema or settings.
 */
$root = __DIR__;
$dbPath = $root.'/app/Database.php';
$repoPath = $root.'/app/Repository.php';
$db = @file_get_contents($dbPath);
$repo = @file_get_contents($repoPath);
if (!is_string($db) || !is_string($repo)) {
    fwrite(STDERR, "ROUTER_DB_RECONNECT_SOURCE_MISSING\n");
    exit(1);
}

$dbMarker = '    private static ?\\PDO $pdo = null;';
$dbReplacement = $dbMarker."\n".<<<'CODE'

    public static function forgetConnection(): void
    {
        self::$pdo = null;
    }
CODE;

$original = "    public static function allRules(): array { return Database::pdo()->query('SELECT * FROM router_rules ORDER BY id DESC')->fetchAll(); }";
$updated = <<<'CODE'
    public static function allRules(): array
    {
        $sql='SELECT * FROM router_rules ORDER BY id DESC';
        try {
            return Database::pdo()->query($sql)->fetchAll();
        } catch (\PDOException $e) {
            $driverCode=(int)($e->errorInfo[1]??0);
            $description=strtolower($e->getMessage());
            $stale=in_array($driverCode,[2006,2013],true)
                || str_contains($description,'server has gone away')
                || str_contains($description,'lost connection to mysql server');
            if(!$stale) throw $e;
            Database::forgetConnection();
            error_log('ROUTER_DB_RECONNECT stale_connection retry=1');
            // This SELECT is read-only: a single retry cannot duplicate a forwarded message.
            return Database::pdo()->query($sql)->fetchAll();
        }
    }
CODE;

$patchedDb = str_contains($db, 'function forgetConnection(): void');
$patchedRepo = str_contains($repo, 'ROUTER_DB_RECONNECT stale_connection retry=1');
if ($patchedDb !== $patchedRepo) {
    fwrite(STDERR, "ROUTER_DB_RECONNECT_PARTIAL_PATCH_REFUSED\n");
    exit(1);
}
if ($patchedDb) {
    echo "ROUTER_DB_RECONNECT_ALREADY_APPLIED\n";
    exit(0);
}
if (substr_count($db,$dbMarker)!==1 || substr_count($repo,$original)!==1) {
    fwrite(STDERR, "ROUTER_DB_RECONNECT_SOURCE_MISMATCH\n");
    exit(1);
}
$newDb=str_replace($dbMarker,$dbReplacement,$db);
$newRepo=str_replace($original,$updated,$repo);
foreach ([[$dbPath,$newDb],[$repoPath,$newRepo]] as [$path,$content]) {
    $temp=$path.'.db-reconnect-candidate';
    if (@file_put_contents($temp,$content)===false) {
        fwrite(STDERR,"ROUTER_DB_RECONNECT_WRITE_FAILED\n");
        exit(1);
    }
    exec('php -l '.escapeshellarg($temp).' 2>&1',$output,$code);
    if($code!==0) {
        fwrite(STDERR,"ROUTER_DB_RECONNECT_SYNTAX_FAILED\n".implode("\n",$output)."\n");
        @unlink($temp);
        exit(1);
    }
}
foreach ([$dbPath,$repoPath] as $path) {
    if(!@rename($path.'.db-reconnect-candidate',$path)) {
        fwrite(STDERR,"ROUTER_DB_RECONNECT_REPLACE_FAILED\n");
        exit(1);
    }
}
echo "ROUTER_DB_RECONNECT_V1_APPLIED\n";
