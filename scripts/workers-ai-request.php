<?php declare(strict_types=1);
/**
 * Standalone Workers AI REST transport. Credentials arrive only via stdin,
 * are never put in argv, URLs or logs, and no upstream response is logged.
 */
function reply(bool $ok,?int $http,string $reason,string $text=''): never
{
    echo json_encode(['ok'=>$ok,'http'=>$http,'reason'=>$reason,'text'=>$text],
        JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}
$raw=stream_get_contents(STDIN,1000000);
$input=is_string($raw)?json_decode($raw,true):null;
if(!is_array($input))reply(false,null,'INPUT_ERROR');
$account=trim((string)($input['account']??''));
$token=trim((string)($input['token']??''));
$model=trim((string)($input['model']??''));
$text=(string)($input['text']??'');
$target=trim((string)($input['target']??''));
if(!preg_match('/^[a-f0-9]{32}$/Di',$account)||$token===''||$text===''||
   !preg_match('~^@cf/[A-Za-z0-9._-]+/[A-Za-z0-9._-]{2,100}$~D',$model)||
   !preg_match('/^[a-z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/D',$target))reply(false,null,'INPUT_ERROR');
if(!function_exists('curl_init'))reply(false,null,'UNAVAILABLE');
$endpoint='https://api.cloudflare.com/client/v4/accounts/'.$account.'/ai/run/'.$model;
$payload=json_encode([
    'messages'=>[
        ['role'=>'system','content'=>'Translate the user text into '.$target.'. Return ONLY the complete translation, preserving line breaks, paragraphs, match names, odds, scores, numeric values, links and @handles. Do not add explanations, commentary or a second original-language version.'],
        ['role'=>'user','content'=>$text]
    ],
    'temperature'=>0,'max_tokens'=>3072
],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
if(!is_string($payload))reply(false,null,'INPUT_ERROR');
$curl=curl_init($endpoint);
if($curl===false)reply(false,null,'UNAVAILABLE');
curl_setopt_array($curl,[
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json','Accept: application/json'],
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_CONNECTTIMEOUT=>5,
    CURLOPT_TIMEOUT=>20,
    CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_SSL_VERIFYHOST=>2,
    CURLOPT_MAXREDIRS=>0
]);
$body=curl_exec($curl);
$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
$errno=curl_errno($curl);
unset($curl);
if($body===false || $errno!==0)reply(false,$http?:null,$errno===28?'TIMEOUT':'NETWORK');
if($http===401)reply(false,$http,'AUTH');
if($http===403)reply(false,$http,'PERMISSION');
if($http===429)reply(false,$http,'RATE_LIMIT');
if($http<200||$http>=300)reply(false,$http,'HTTP_ERROR');
$data=json_decode((string)$body,true);
if(!is_array($data)||empty($data['success'])||!isset($data['result']['response'])||
    !is_string($data['result']['response']))reply(false,$http,'INVALID_RESPONSE');
$result=trim($data['result']['response']);
if($result==='')reply(false,$http,'INVALID_RESPONSE');
reply(true,$http,'OK',$result);
