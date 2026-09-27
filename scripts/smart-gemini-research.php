<?php declare(strict_types=1);
/**
 * Isolated Gemini research call for AI-generated sports analysis.
 * Credentials arrive through STDIN and are never written to logs or CLI args.
 */
error_reporting(0);

function validModel(mixed $value): bool {
    return is_string($value) && (bool)preg_match('/^[A-Za-z0-9_.-]+$/D',$value);
}
function failureReason(int $http,int $curlErr): string {
    return $http===429?'HTTP_429':
        ($http===503?'HTTP_503':
        ($http===401||$http===403?'HTTP_AUTH':
        ($http>=400&&$http<500?'HTTP_CLIENT':
        ($http>=500?'HTTP_SERVER':
        ($curlErr===28?'CURL_TIMEOUT':'NETWORK_ERROR')))));
}
/** @return array{body:string|false,http:int,curl_errno:int,latency_ms:int} */
function callGeminiResearch(string $model,string $key,string $prompt): array {
    $endpoint='https://generativelanguage.googleapis.com/v1beta/models/'
        .rawurlencode($model).':generateContent';
    $payload=json_encode([
        'contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],
        'tools'=>[['googleSearch'=>(object)[]]],
        'generationConfig'=>[
            'temperature'=>0.2,
            'maxOutputTokens'=>1100
        ]
    ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if(!is_string($payload))return ['body'=>false,'http'=>0,'curl_errno'=>0,'latency_ms'=>0];

    $ch=curl_init($endpoint);
    if($ch===false)return ['body'=>false,'http'=>0,'curl_errno'=>0,'latency_ms'=>0];
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>28,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0
    ]);
    $started=microtime(true);
    $body=curl_exec($ch);
    $latency=max(0,(int)round((microtime(true)-$started)*1000));
    $curlErr=(int)curl_errno($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    unset($ch);
    return ['body'=>$body,'http'=>$http,'curl_errno'=>$curlErr,'latency_ms'=>$latency];
}
/** @return array{text:string,sources:array<int,array{title:string,url:string}>}|null */
function parseResearch(string $body): ?array {
    if(strlen($body)>450000)return null;
    $decoded=json_decode($body,true);
    if(!is_array($decoded))return null;
    $candidate=$decoded['candidates'][0]??null;
    if(!is_array($candidate))return null;
    $text='';
    foreach(($candidate['content']['parts']??[]) as $part){
        if(is_array($part)&&is_string($part['text']??null))$text.=$part['text'];
    }
    $text=trim($text);
    if($text==='')return null;

    $sources=[];
    foreach(($candidate['groundingMetadata']['groundingChunks']??[]) as $chunk){
        if(!is_array($chunk))continue;
        $web=$chunk['web']??null;
        if(!is_array($web))continue;
        $url=trim((string)($web['uri']??''));
        if($url===''||filter_var($url,FILTER_VALIDATE_URL)===false)continue;
        $sources[$url]=[
            'title'=>mb_substr(trim((string)($web['title']??'')),0,120,'UTF-8'),
            'url'=>$url
        ];
        if(count($sources)>=3)break;
    }
    return ['text'=>$text,'sources'=>array_values($sources)];
}

try {
    $raw=stream_get_contents(STDIN,250000);
    $data=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($data)
        ||!validModel($data['model']??null)
        ||!is_string($data['key']??null)||trim((string)$data['key'])===''
        ||!is_string($data['prompt']??null)||trim((string)$data['prompt'])===''
        ||strlen((string)$data['prompt'])>120000
        ||!function_exists('curl_init')){
        echo '{"ok":false,"reason":"INPUT_ERROR"}';exit(0);
    }

    $models=[(string)$data['model']];
    $fallback=$data['fallback_model']??null;
    if(validModel($fallback)&&$fallback!==$models[0])$models[]=(string)$fallback;

    foreach($models as $index=>$model){
        $attempt=callGeminiResearch($model,trim((string)$data['key']),(string)$data['prompt']);
        $http=(int)$attempt['http'];
        $body=$attempt['body'];
        if($http===200&&is_string($body)){
            $parsed=parseResearch($body);
            if($parsed!==null){
                echo json_encode([
                    'ok'=>true,
                    'text'=>$parsed['text'],
                    'sources'=>$parsed['sources'],
                    'model'=>$model,
                    'model_fallback_used'=>$index>0,
                    'latency_ms'=>(int)$attempt['latency_ms'],
                    'http'=>$http
                ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
                exit(0);
            }
            echo json_encode([
                'ok'=>false,'reason'=>'INVALID_RESPONSE',
                'latency_ms'=>(int)$attempt['latency_ms'],'http'=>$http
            ]);exit(0);
        }
        $reason=failureReason($http,(int)$attempt['curl_errno']);
        if($index===0&&count($models)>1&&in_array($reason,['HTTP_429','HTTP_503'],true))continue;
        echo json_encode([
            'ok'=>false,'reason'=>$reason,
            'latency_ms'=>(int)$attempt['latency_ms'],'http'=>$http?:null
        ]);exit(0);
    }
    echo '{"ok":false,"reason":"UNAVAILABLE"}';
} catch(Throwable $error) {
    echo '{"ok":false,"reason":"UNAVAILABLE"}';
}
