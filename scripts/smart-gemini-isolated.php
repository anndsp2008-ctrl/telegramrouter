<?php declare(strict_types=1);
/* Isolated Gemini HTTP request: receives private payload via STDIN, never CLI arguments. */
error_reporting(0);
try {
    $input=stream_get_contents(STDIN,7500000);
    $data=is_string($input)?json_decode($input,true):null;
    if(!is_array($data)||!isset($data['model'],$data['key'],$data['payload'])
       ||!is_string($data['model'])||!preg_match('/^[A-Za-z0-9_.-]+$/D',$data['model'])
       ||!is_string($data['key'])||$data['key']===''
       ||!is_string($data['payload'])||strlen($data['payload'])>7000000
       ||!function_exists('curl_init')){echo '{"ok":false}';exit(0);}
    $endpoint='https://generativelanguage.googleapis.com/v1beta/models/'
             .rawurlencode($data['model']).':generateContent';
    // SMART_GEMINI_TEMPORARY_RETRY_V1: at most one short retry for transient overload.
    // Never retry authentication, bad requests, or response-format failures.
    // Both attempts together stay below 26 seconds; no Telegram send occurs here.
    $result=false;
    $curlErr=0;
    $http=0;
    $retryAfter=0;
    foreach([16,8] as $attempt=>$timeoutSeconds){
        $ch=curl_init($endpoint);
        if($ch===false){echo '{"ok":false,"reason":"CURL_INIT_FAILED"}';exit(0);}
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$data['payload'],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$data['key']],
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
        // A 429 is a quota/rate signal, not a transient transport failure.
        // Retrying it immediately wastes quota and lengthens forwarding latency.
        if($attempt===0 && in_array($http,[502,503,504],true)){
            usleep(650000);
            continue;
        }
        break;
    }
    if($http!==200||!is_string($result)||strlen($result)>400000){
        $reason=$http===429?'HTTP_429':($http===503?'HTTP_503':($http===401||$http===403?'HTTP_AUTH':($http>=400&&$http<500?'HTTP_CLIENT':($http>=500?'HTTP_SERVER':($curlErr===28?'CURL_TIMEOUT':'NETWORK_ERROR')))));
        $error=['ok'=>false,'reason'=>$reason];
        if($reason==='HTTP_429')$error['retry_after']=$retryAfter>0?$retryAfter:60;
        echo json_encode($error);exit(0);
    }
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
    if(!is_array($parsed)){echo '{"ok":false,"reason":"JSON_OR_RESPONSE_SCHEMA"}';exit(0);}
    $output=json_encode(['ok'=>true,'data'=>$parsed],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    echo is_string($output)?$output:'{"ok":false}';
} catch(\Throwable $error) {
    echo '{"ok":false}';
}
