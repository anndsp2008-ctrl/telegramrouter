<?php declare(strict_types=1);

$root=dirname(__DIR__);
if(is_file($root.'/vendor/autoload.php'))require $root.'/vendor/autoload.php';
require $root.'/config/config.php';
require $root.'/app/Database.php';
require $root.'/app/Crypto.php';
require $root.'/app/Repository.php';
require $root.'/app/SportsApiIntegration.php';

use App\SportsApiIntegration;

try{
    if(SportsApiIntegration::stakeKey()===''){
        error_log('TMR_STAKE_HEALTH '.json_encode([
            'ok'=>false,
            'status'=>'missing_key',
        ],JSON_UNESCAPED_SLASHES));
        exit(0);
    }

    $result=SportsApiIntegration::test('stake');
    error_log('TMR_STAKE_HEALTH '.json_encode([
        'ok'=>(bool)($result['ok']??false),
        'message'=>(string)($result['message']??''),
        'latency_ms'=>(int)($result['latency_ms']??0),
        'http_code'=>isset($result['http_code'])?(int)$result['http_code']:null,
        'error'=>isset($result['error'])?(string)$result['error']:null,
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}catch(Throwable $e){
    error_log('TMR_STAKE_HEALTH '.json_encode([
        'ok'=>false,
        'status'=>'exception',
        'error'=>get_class($e),
    ],JSON_UNESCAPED_SLASHES));
}

exit(0);
