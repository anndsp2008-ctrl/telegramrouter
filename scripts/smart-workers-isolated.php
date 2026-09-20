<?php declare(strict_types=1);
/** Isolated Cloudflare REST extraction; secrets and source enter through STDIN only. */
function reply(bool $ok,string $reason='OK',?array $data=null): never {
    echo json_encode(['ok'=>$ok,'reason'=>$reason,'data'=>$data],
        JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}
/** Cloudflare API errors are reduced to safe status/code labels. Never log or
 * propagate upstream response bodies (they may contain private details).
 * Error 5016 is model-license acceptance, not a bad API token.
 */
function cloudflareFailureReason(int $http,string|false $body,int $errno): string {
    $errorCode=0;
    if(is_string($body) && strlen($body)<60000){
        $json=json_decode($body,true);
        $code=is_array($json)?($json['errors'][0]['code']??null):null;
        if((is_int($code)||is_string($code))&&preg_match('/^[0-9]{3,5}$/D',(string)$code))
            $errorCode=(int)$code;
    }
    if($http===403){
        return match($errorCode){
            5016=>'MODEL_LICENSE_REQUIRED_5016',
            5035=>'WORKERS_PAID_REQUIRED_5035',
            5018,3041=>'MODEL_ACCESS_DENIED_'.$errorCode,
            3023=>'ACCOUNT_BLOCKED_3023',
            default=>$errorCode>0?'HTTP_403_CF_'.$errorCode:'HTTP_403_FORBIDDEN'
        };
    }
    if($http===401)return 'HTTP_401_UNAUTHORIZED';
    if($http===429){
        return match($errorCode){
            3036=>'DAILY_QUOTA_EXHAUSTED_3036',
            3040=>'CAPACITY_EXCEEDED_3040',
            default=>'HTTP_429'
        };
    }
    if($http===400)return $errorCode===5007?'MODEL_NOT_FOUND_5007':
        ($errorCode===5004?'IMAGE_INPUT_INVALID_5004':'HTTP_400');
    if($http>=500)return 'HTTP_5XX';
    if($errno===28)return 'TIMEOUT';
    return 'NETWORK';
}
// Offline regression test: fake Cloudflare status/envelopes, never call the API.
if(getenv('SMART_WORKERS_CLASSIFIER_TEST')==='1'){
    foreach([
        [403,5016,'MODEL_LICENSE_REQUIRED_5016'],
        [403,5035,'WORKERS_PAID_REQUIRED_5035'],
        [403,5018,'MODEL_ACCESS_DENIED_5018'],
        [403,3023,'ACCOUNT_BLOCKED_3023'],
        [403,0,'HTTP_403_FORBIDDEN'],
        [401,0,'HTTP_401_UNAUTHORIZED'],
        [429,3036,'DAILY_QUOTA_EXHAUSTED_3036'],
        [400,5004,'IMAGE_INPUT_INVALID_5004']
    ] as [$status,$code,$expected]){
        $body=$code>0?json_encode(['errors'=>[['code'=>$code,'message'=>'REDACTED']]]):'{}';
        if(cloudflareFailureReason($status,$body,0)!==$expected)
            throw new RuntimeException('Workers AI classification mismatch '.$status.'/'.$code);
    }
    echo "WORKERS_VISION_ERROR_CLASSIFIER_TESTS_PASSED\n";
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
    $checkOnly=($input['check_only']??false)===true;
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
                if($checkOnly&&trim($response)!=='')reply(true,'OK',[]);
                $bet=parseTip($response);
                if(is_array($bet))reply(true,'OK',$bet);
            }
            reply(false,'RESPONSE_INVALID');
        }
        if($attempt===0&&(in_array($http,[429,500,502,503,504],true)||
            in_array($errno,[6,7,28,52,56],true))){usleep(450000);continue;}
        reply(false,cloudflareFailureReason($http,$body,$errno));
    }
    reply(false,'UNAVAILABLE');
} catch(Throwable) {
    reply(false,'UNEXPECTED');
}
