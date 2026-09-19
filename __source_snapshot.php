<?php declare(strict_types=1);
$expected=(string)getenv('SOURCE_SNAPSHOT_TOKEN');
$provided=(string)($_GET['token']??'');
if($expected==='' || $provided==='' || !hash_equals($expected,$provided)){http_response_code(404);exit;}
$tmp='/tmp/telegramrouter-source-snapshot.tar.gz';
@unlink($tmp);
$cmd="tar --exclude='./vendor' --exclude='./storage' --exclude='./.env' --exclude='./health' --exclude='./__source_snapshot.php' --exclude='./*.log' -czf ".escapeshellarg($tmp)." -C /app . 2>/tmp/source-snapshot.err";
exec($cmd,$out,$code);
if($code!==0 || !is_file($tmp)){http_response_code(500);header('Content-Type:text/plain');echo "snapshot failed";exit;}
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="telegramrouter-source-snapshot.tar.gz"');
header('Content-Length: '.filesize($tmp));
readfile($tmp);
@unlink($tmp);
