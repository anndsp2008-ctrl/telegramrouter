<?php declare(strict_types=1);
/** Isolated Cloudflare REST extraction; secrets and source enter through STDIN only. */
function reply(bool $ok,string $reason='OK',?array $data=null): never {
    echo json_encode(['ok'=>$ok,'reason'=>$reason,'data'=>$data],
        JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}
function parseTip(string $response): ?array {
    $response=trim($response);
    $parsed=json_decode($response,true);
    if(is_array($parsed)&&!array_is_list($parsed))return $parsed;
    $fence=str_repeat(chr(96),3);
    if(str_starts_with($response,$fence)&&str_ends_with($response,$fence)){
        $start=strpos($response,"\n");
        if($start!==false){
            $inner=trim(substr($response,$start+1,-3));
            $parsed=json_decode($inner,true);
            if(is_array($parsed)&&!array_is_list($parsed))return $parsed;
        }
    }
    return null;
}
try{
    $raw=stream_get_contents(STDIN,7500000);
    $input=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($input))reply(false,'INPUT_INVALID');
    $account=(string)($input['account']??'');
    $token=(string)($input['token']??'');
    $model=(string)($input['model']??'');
    $prompt=(string)($input['prompt']??'');
    $image=$input['image']??null;
    if(!preg_match('/^[a-f0-9]{32}$/Di',$account)||
       !preg_match('~^@cf/[A-Za-z0-9._-]+/[A-Za-z0-9._-]{2,100}$~D',$model)||
       $token===''||$prompt===''||strlen($prompt)>300000||
       ($image!==null&&(!is_string($image)||strlen($image)>5700000||
           !preg_match('~^data:image/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=\r\n]+$~D',$image)))||
       !function_exists('curl_init'))reply(false,'INPUT_INVALID');
    $endpoint='https://api.cloudflare.com/client/v4/accounts/'.$account.'/ai/run/'.$model;
    $payload=['prompt'=>$prompt,'temperature'=>0,'max_tokens'=>2800];
    if($image!==null)$payload['image']=$image;
    $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if(!is_string($encoded))reply(false,'PAYLOAD_INVALID');
    foreach([16,8] as $attempt=>$timeout){
        $ch=curl_init($endpoint);
        if($ch===false)reply(false,'CURL_INIT');
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encoded,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json','Accept: application/json'],
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2
        ]);
        $body=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $errno=curl_errno($ch);
        unset($ch);
        if($http===200&&is_string($body)&&strlen($body)<=450000){
            $envelope=json_decode($body,true);
            $response=$envelope['result']['response']??null;
            if(!empty($envelope['success'])&&is_string($response)){
                $bet=parseTip($response);
                if(is_array($bet))reply(true,'OK',$bet);
            }
            reply(false,'RESPONSE_INVALID');
        }
        if($attempt===0&&(in_array($http,[429,500,502,503,504],true)||
            in_array($errno,[6,7,28,52,56],true))){usleep(450000);continue;}
        $reason=$http===429?'HTTP_429':($http===401||$http===403?'HTTP_AUTH':
            ($http===400?'HTTP_400':($http>=500?'HTTP_5XX':($errno===28?'TIMEOUT':'NETWORK'))));
        reply(false,$reason);
    }
    reply(false,'UNAVAILABLE');
} catch(Throwable) {
    reply(false,'UNEXPECTED');
}
