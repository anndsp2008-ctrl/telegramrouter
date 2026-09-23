<?php declare(strict_types=1);
/* Isolated Gemini HTTP request: receives private payload via STDIN, never CLI arguments. */
error_reporting(0);

function validModel(mixed $value): bool {
    return is_string($value) && (bool)preg_match('/^[A-Za-z0-9_.-]+$/D',$value);
}
/** @return array{body:string|false,http:int,curl_errno:int,retry_after:int} */
function callGemini(string $model,string $key,string $payload): array {
    $endpoint='https://generativelanguage.googleapis.com/v1beta/models/'
             .rawurlencode($model).':generateContent';
    $result=false;$curlErr=0;$http=0;$retryAfter=0;
    // Preserve PR #39 bounded recovery for transient transport/5xx failures.
    // Never retry HTTP 429 on the same model.
    foreach([16,8] as $attempt=>$timeoutSeconds){
        $ch=curl_init($endpoint);
        if($ch===false)return ['body'=>false,'http'=>0,'curl_errno'=>0,'retry_after'=>0];
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],
            CURLOPT_HEADERFUNCTION=>static function($curl,string $line) use (&$retryAfter): int {
                if(preg_match('/^Retry-After:\s*(\d+)\s*$/i',trim($line),$m)){
                    $retryAfter=max($retryAfter,min(900,(int)$m[1]));
                }
                return strlen($line);
            },
            CURLOPT_CONNECTTIMEOUT=>min(4,$timeoutSeconds),
            CURLOPT_TIMEOUT=>$timeoutSeconds,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0
        ]);
        $result=curl_exec($ch);
        $curlErr=(int)curl_errno($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($http===200 && is_string($result))break;
        if($attempt===0 && (
            in_array($http,[500,502,503,504],true)
            || $curlErr===28
            || in_array($curlErr,[6,7,52,56],true)
        )){
            usleep($http===503?1800000:650000);
            continue;
        }
        break;
    }
    return ['body'=>$result,'http'=>$http,'curl_errno'=>$curlErr,'retry_after'=>$retryAfter];
}
function failureReason(int $http,int $curlErr): string {
    return $http===429?'HTTP_429':
        ($http===503?'HTTP_503':
        ($http===401||$http===403?'HTTP_AUTH':
        ($http>=400&&$http<500?'HTTP_CLIENT':
        ($http>=500?'HTTP_SERVER':
        ($curlErr===28?'CURL_TIMEOUT':'NETWORK_ERROR')))));
}
function parseGeminiJson(string $result): ?array {
    if(strlen($result)>400000)return null;
    $decoded=json_decode($result,true);
    $parts=$decoded['candidates'][0]['content']['parts']??null;
    $body='';
    if(is_array($parts)){
        foreach($parts as $part){
            if(is_array($part) && is_string($part['text']??null))$body.=$part['text'];
        }
    }
    $body=trim($body);
    if(str_starts_with($body,'```')){
        $body=preg_replace('/^\x60{3}(?:json)?\s*/i','',$body)??$body;
        $body=preg_replace('/\s*\x60{3}$/','',$body)??$body;
    }
    $parsed=$body!==''?json_decode($body,true):null;
    return is_array($parsed)?$parsed:null;
}

if(getenv('SMART_GEMINI_MODEL_ROUTE_TEST')==='1'){
    if(!validModel('gemini-2.5-flash')||validModel('../bad/model'))
        throw new RuntimeException('Gemini model validator regression');
    if(failureReason(429,0)!=='HTTP_429'||failureReason(503,0)!=='HTTP_503')
        throw new RuntimeException('Gemini failure routing regression');
    echo "SMART_GEMINI_MODEL_ROUTE_TESTS_PASSED\n";
    exit(0);
}

try {
    $input=stream_get_contents(STDIN,7500000);
    $data=is_string($input)?json_decode($input,true):null;
    if(!is_array($data)||!isset($data['model'],$data['key'],$data['payload'])
       ||!validModel($data['model'])
       ||!is_string($data['key'])||$data['key']===''
       ||!is_string($data['payload'])||strlen($data['payload'])>7000000
       ||!function_exists('curl_init')){echo '{"ok":false}';exit(0);}

    $models=[(string)$data['model']];
    $fallback=$data['fallback_model']??null;
    if(validModel($fallback) && $fallback!==$models[0])$models[]=(string)$fallback;

    $maxRetryAfter=0;
    foreach($models as $index=>$model){
        $attempt=callGemini($model,$data['key'],$data['payload']);
        $maxRetryAfter=max($maxRetryAfter,(int)$attempt['retry_after']);
        $http=(int)$attempt['http'];
        $body=$attempt['body'];

        if($http===200 && is_string($body)){
            $parsed=parseGeminiJson($body);
            if(!is_array($parsed)){
                echo '{"ok":false,"reason":"JSON_OR_RESPONSE_SCHEMA"}';exit(0);
            }
            $output=json_encode([
                'ok'=>true,'data'=>$parsed,'model_fallback_used'=>$index>0
            ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            echo is_string($output)?$output:'{"ok":false}';
            exit(0);
        }

        $reason=failureReason($http,(int)$attempt['curl_errno']);
        // PR #76: use the backup model only for quota/capacity signals.
        // Do not multiply auth, malformed payload, or network failures.
        if($index===0 && count($models)>1 && in_array($reason,['HTTP_429','HTTP_503'],true)){
            continue;
        }
        $error=['ok'=>false,'reason'=>$reason];
        if($reason==='HTTP_429')$error['retry_after']=$maxRetryAfter>0?$maxRetryAfter:60;
        echo json_encode($error);exit(0);
    }

    echo json_encode([
        'ok'=>false,'reason'=>'HTTP_429',
        'retry_after'=>$maxRetryAfter>0?$maxRetryAfter:60
    ]);
} catch(\Throwable $error) {
    echo '{"ok":false}';
}
