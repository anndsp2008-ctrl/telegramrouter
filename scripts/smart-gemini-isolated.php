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
    $ch=curl_init($endpoint);
    if($ch===false){echo '{"ok":false}';exit(0);}
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$data['payload'],
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$data['key']],
        CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0
    ]);
    $result=curl_exec($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($http!==200||!is_string($result)||strlen($result)>400000){
        echo '{"ok":false}';exit(0);
    }
    $decoded=json_decode($result,true);
    $body=$decoded['candidates'][0]['content']['parts'][0]['text']??null;
    $parsed=is_string($body)?json_decode($body,true):null;
    if(!is_array($parsed)){echo '{"ok":false}';exit(0);}
    $output=json_encode(['ok'=>true,'data'=>$parsed],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    echo is_string($output)?$output:'{"ok":false}';
} catch(\Throwable $error) {
    echo '{"ok":false}';
}
