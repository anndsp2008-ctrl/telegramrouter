<?php declare(strict_types=1);
/** Isolated Cloudflare REST extraction; secrets and source enter through STDIN only. */
function reply(bool $ok,string $reason='OK',?array $data=null,?string $evidence=null): never {
    // Only the parent isolated PHP process receives evidence over stdout.
    // Never print model text, image data or the original tip in application logs.
    echo json_encode(['ok'=>$ok,'reason'=>$reason,'data'=>$data,'evidence'=>$evidence],
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
/**
 * Llama Vision sometimes wraps JSON in a sentence/code fence. Extract only a
 * complete, syntactically valid JSON object; never fill in missing bet fields
 * or convert narrative text into invented structured data.
 */
function parseTip(string $response): ?array {
    $response=trim($response);
    if($response==='')return null;
    $direct=json_decode($response,true);
    if(is_array($direct)&&!array_is_list($direct))return $direct;
    $length=strlen($response);
    $start=null;$depth=0;$inString=false;$escaped=false;
    for($i=0;$i<$length;$i++){
        $char=$response[$i];
        if($start===null){
            if($char!=='{')continue;
            $start=$i;$depth=1;$inString=false;$escaped=false;
            continue;
        }
        if($inString){
            if($escaped){$escaped=false;continue;}
            if($char==='\\'){$escaped=true;continue;}
            if($char==='"')$inString=false;
            continue;
        }
        if($char==='"'){$inString=true;continue;}
        if($char==='{'){$depth++;continue;}
        if($char==='}'){
            $depth--;
            if($depth!==0)continue;
            $candidate=json_decode(substr($response,$start,$i-$start+1),true);
            if(is_array($candidate)&&!array_is_list($candidate))return $candidate;
            $start=null;
        }
    }
    return null;
}
/**
 * Vision can return a structured tool_calls result with no response string.
 * Never execute a model-suggested tool; accept only a complete data object
 * containing explicit match, market and selection as normal card extraction.
 * Ignore names, destinations, URLs and any other proposed tool behavior.
 */
function parsedVisionBet(array $result): ?array {
    // JSON Mode can return result.response as an already decoded object.
    // Validate that data exactly as we validate the string form; never
    // accept tool actions, partial objects or unstructured model output.
    $structuredResponse=$result['response']??null;
    if(is_array($structuredResponse) && !array_is_list($structuredResponse)){
        $encoded=json_encode($structuredResponse,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if(is_string($encoded) && tipResponseStatus($encoded)==='OK')
            return $structuredResponse;
    }
    // Cloudflare's ImageTextToText REST result uses "description", not
    // necessarily the TextGeneration "response" field. Accept either ONLY
    // when it contains validated structured bet data (never free-form prose).
    foreach(['response','description'] as $textField){
        $response=$result[$textField]??null;
        if(is_string($response)){
            $data=parseTip($response);
            if(is_array($data)&&tipResponseStatus($response)==='OK')return $data;
        }
    }
    $calls=$result['tool_calls']??null;
    if(!is_array($calls))return null;
    foreach($calls as $call){
        if(!is_array($call))continue;
        $args=$call['arguments']??null;
        if(is_string($args))$args=parseTip($args);
        if(!is_array($args)||array_is_list($args))continue;
        $candidate=json_encode($args,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if(is_string($candidate)&&tipResponseStatus($candidate)==='OK')return $args;
    }
    return null;
}
/**
 * Keep bounded visual observations only inside the worker-to-parent stdout.
 * Do not copy suggested model tool-actions, original image bytes or secrets.
 */
function collectVisualEvidence(array $result,string $existing=''): string {
    foreach(['response','description'] as $key){
        $observed=$result[$key]??null;
        if(!is_string($observed)||trim($observed)==='')continue;
        $observed=mb_substr(trim($observed),0,6000,'UTF-8');
        if(str_contains($existing,$observed))continue;
        $existing=mb_substr(trim($existing."\n".$observed),0,10000,'UTF-8');
    }
    return $existing;
}
/** Return a privacy-safe failure category without echoing generated text. */
function tipResponseStatus(string $response): string {
    if(trim($response)==='')return 'RESPONSE_EMPTY';
    $result=parseTip($response);
    if($result===null)return 'RESPONSE_NOT_JSON';
    foreach(['match','market','selection'] as $key){
        if(!isset($result[$key])||!is_scalar($result[$key])||trim((string)$result[$key])===''){
            return 'RESPONSE_MISSING_REQUIRED_FIELDS';
        }
    }
    foreach($result as $value){
        if(!is_scalar($value)&&$value!==null)return 'RESPONSE_BAD_FIELD_TYPES';
    }
    return 'OK';
}


if(getenv('SMART_WORKERS_JSON_TEST')==='1'){
    $base=['match'=>'Venezia x Lazio','market'=>'Total de escanteios','selection'=>'Mais de 8,5',
        'analysis'=>'Dado do autor: {não altera mercado} e "citação"'];
    $json=json_encode($base,JSON_UNESCAPED_UNICODE);
    if(!is_string($json)||tipResponseStatus($json)!=='OK')
        throw new RuntimeException('Valid tip was rejected');
    $fence=str_repeat(chr(96),3);
    foreach([$json,"Aqui está o JSON:\n".$json."\n", "~~~\n".$json."\n~~~",
       $fence."json\n".$json."\n".$fence] as $value){
        if(parseTip($value)!==$base||tipResponseStatus($value)!=='OK')
            throw new RuntimeException('Valid wrapped JSON parsing regression');
    }
    foreach(['Um relato sem JSON','{"match":"Venezia"', '{"match":"Venezia","market":"","selection":"Mais de 8,5"}',
             '{"match":{"untrusted":true},"market":"Escanteios","selection":"Mais de 8,5"}'] as $value){
        if(tipResponseStatus($value)==='OK')throw new RuntimeException('Invalid JSON accepted');
    }

    // Cloudflare JSON Mode returns result.response as an object rather
    // than a JSON-encoded string on some routes.
    if(parsedVisionBet(['response'=>$base])!==$base ||
       parsedVisionBet(['response'=>['match'=>'Venezia x Lazio']])!==null ||
       parsedVisionBet(['response'=>['match'=>'Venezia x Lazio',
           'market'=>'Total de escanteios','selection'=>'',
           'action'=>'forward_to_unknown_channel']])!==null){
        throw new RuntimeException('Structured JSON response must still pass field validation');
    }
    $structured=['response'=>null,'tool_calls'=>[['name'=>'unknown_model_generated_name','arguments'=>$base]]];
    if(parsedVisionBet($structured)!==$base)
        throw new RuntimeException('Structured Vision data was ignored');
    $visionDescription=['description'=>$json,'response'=>null];
    if(parsedVisionBet($visionDescription)!==$base)
        throw new RuntimeException('ImageTextToText description JSON not parsed');
    $visionNarrative=['description'=>'Observações do comprovante, sem JSON','response'=>null];
    if(parsedVisionBet($visionNarrative)!==null)
        throw new RuntimeException('Non-JSON image description was treated as a bet');
    $stringArgs=['response'=>null,'tool_calls'=>[['arguments'=>$json]]];
    if(parsedVisionBet($stringArgs)!==$base)
        throw new RuntimeException('String-encoded Vision data was ignored');
    $unsafe=['response'=>null,'tool_calls'=>[['name'=>'arbitrary_action',
        'arguments'=>['action'=>'send','destination'=>'unknown']]]];
    if(parsedVisionBet($unsafe)!==null)
        throw new RuntimeException('Unstructured model tool action was accepted as a bet');
    if(parsedVisionBet(['response'=>'Not a bet','tool_calls'=>[['arguments'=>['match'=>'Venezia']]]])!==null)
        throw new RuntimeException('Incomplete model output was accepted');
    $observed=collectVisualEvidence([
        'response'=>'Texto visual: Venezia x Lazio, seleção Mais de 8,5',
        'description'=>'JSON parcial: {"match":"Venezia x Lazio"}',
        'tool_calls'=>[['arguments'=>['action'=>'send','token'=>'SECRET_DO_NOT_COPY']]]
    ]);
    if(!str_contains($observed,'Venezia x Lazio')||
       !str_contains($observed,'JSON parcial')||
       str_contains($observed,'SECRET_DO_NOT_COPY')||
       collectVisualEvidence(['response'=>null,'description'=>null])!==''||
       collectVisualEvidence(['response'=>'Mesmo texto'],'Mesmo texto')!=='Mesmo texto')
        throw new RuntimeException('Visual evidence collector failed safe handling');
    if(mb_strlen(collectVisualEvidence(['response'=>str_repeat('A',12000)]),'UTF-8')>10000)
        throw new RuntimeException('Unbounded visual evidence output');
    // A malformed, but legible Vision reply is evidence for text recovery,
    // not a reason to retry the same slow multimodal request before recovery.
    if(tipResponseStatus('Visual: Fulham x United, ambos marcam')!=='RESPONSE_NOT_JSON' ||
       collectVisualEvidence(['response'=>'Visual: Fulham x United, ambos marcam'])===''){
        throw new RuntimeException('Legible visual evidence was not preserved');
    }
    echo "WORKERS_AI_JSON_EXTRACTION_TESTS_PASSED\n";
    exit(0);
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
    $evidenceRescue=($input['evidence_rescue']??false)===true;
    // Only the rescue vision model uses guided_json; the selected Llama 3.2
    // primary and the existing translation transport remain unchanged.
    $fields=$input['fields']??[];
    if(!is_array($fields)||count($fields)>24)$fields=[];
    $fields=array_values(array_filter($fields,static fn($field): bool =>
        is_string($field)&&preg_match('/^[a-z_]{2,30}$/D',$field)===1));
    $structuredEvidence=$evidenceRescue && !$checkOnly &&
        $image===null && $model==='@cf/meta/llama-3.1-8b-instruct-fp8' &&
        count(array_intersect(['match','market','selection'],$fields))===3;

    if(!preg_match('/^[a-f0-9]{32}$/Di',$account)||
       !preg_match('~^@cf/[A-Za-z0-9._-]+/[A-Za-z0-9._-]{2,100}$~D',$model)||
       $token===''||$prompt===''||strlen($prompt)>300000||
       ($image!==null&&(!is_string($image)||strlen($image)>5700000||
           !preg_match('~^data:image/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=\r\n]+$~D',$image)))||
       !function_exists('curl_init'))reply(false,'INPUT_INVALID');
    $endpoint='https://api.cloudflare.com/client/v4/accounts/'.$account.'/ai/run/'.$model;
    // A connection probe must not request a full card-length answer: even a
    // short prompt with 2800 max output tokens can exceed a Vision timeout.
    // Full cards retain their current output allowance and validation.
    $payload=['prompt'=>$prompt,'temperature'=>0,'max_tokens'=>$checkOnly?12:2800];
    // Llama 3.1 8B accepts chat messages; this is the same proven schema used
    // by the working Workers AI text-translation transport. A short text tip
    // does not need the Vision endpoint or a 2800-token completion allowance.
    if(!$checkOnly && $image===null &&
       $model==='@cf/meta/llama-3.1-8b-instruct-fp8'){
        $payload=[
            'messages'=>[['role'=>'user','content'=>$prompt]],
            'temperature'=>0,'max_tokens'=>strlen($prompt)>4500?2800:1600,
            'stream'=>false
        ];
    }
    if($image!==null){
        // Cloudflare's Vision input schema recommends an image_url part inside
        // the user message. The former top-level image parameter is deprecated
        // and can return HTTP 200 without generated text for some inputs.
        // Keep the original data URI unchanged and NEVER expose it in logs.
        $payload=[
            'messages'=>[[
                'role'=>'user',
                'content'=>[
                    ['type'=>'text','text'=>$prompt],
                    ['type'=>'image_url','image_url'=>['url'=>$image]]
                ]
            ]],
            'temperature'=>0,'max_tokens'=>2800,'stream'=>false
        ];
        if($model==='@cf/meta/llama-4-scout-17b-16e-instruct' && !$checkOnly && $fields!==[]){
            $properties=[];
            foreach($fields as $field)$properties[$field]=['type'=>'string'];
            $payload['guided_json']=['type'=>'object','properties'=>$properties,
                'additionalProperties'=>false];
        }
    }
    // Image extraction does not require a 2800-token narrative. The parent
    // builds the longer grounded analysis only after validation. Keep enough
    // space for a long source analysis while reducing avoidable inference time.
    if(!$checkOnly && $image!==null){
        $payload['max_tokens']=strlen($prompt)>6500?2300:1700;
    } elseif(!$checkOnly && $evidenceRescue &&
             $model==='@cf/meta/llama-3.1-8b-instruct-fp8'){
        $payload['max_tokens']=strlen($prompt)>6500?1650:1150;
    }
    // The model supports Cloudflare JSON Mode: requesting a schema is more
    // reliable than hoping that another "respond only in JSON" prompt works.
    // This is opt-in for Vision-evidence recovery only; already working text
    // cards, image Vision and the configured Gemini fallback are unchanged.
    if($structuredEvidence){
        $properties=[];
        foreach($fields as $field)$properties[$field]=['type'=>'string'];
        $payload['response_format']=[
            'type'=>'json_schema',
            'json_schema'=>[
                'type'=>'object','properties'=>$properties,
                'required'=>['match','market','selection'],
                'additionalProperties'=>false
            ]
        ];
    }
    $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if(!is_string($encoded))reply(false,'PAYLOAD_INVALID');
    $visualEvidence=''; // Local-only, bounded image observation for text structuring.
    // Vision inference may need more than the former 16s/8s windows.
    // Only test requests use small output; production card requests have
    // an independent, larger timeout and one bounded transient retry.
    $timeouts=$checkOnly?[30,20]:[45,30];
    // Text cards on the lightweight model have their own bounded window.
    // Do not block the configured provider fallback for up to 75 seconds.
    if(!$checkOnly && $image===null &&
       $model==='@cf/meta/llama-3.1-8b-instruct-fp8')$timeouts=[25,10];
    // Image rescue must never consume 40+ seconds before the configured
    // provider fallback. First image inference gets a bounded window; the
    // auxiliary Scout and evidence-text passes have their own shorter caps.
    if(!$checkOnly && $image!==null &&
       $model==='@cf/meta/llama-3.2-11b-vision-instruct')$timeouts=[24,8];
    if(!$checkOnly && $image!==null &&
       $model==='@cf/meta/llama-4-scout-17b-16e-instruct')$timeouts=[14,5];
    if(!$checkOnly && $evidenceRescue && $image===null &&
       $model==='@cf/meta/llama-3.1-8b-instruct-fp8')$timeouts=[12,5];
    foreach($timeouts as $attempt=>$timeout){
        if($attempt===1&&!$checkOnly&&!($retryPrepared??false)){
            // Retry once with strict JSON output after an invalid, empty, or
            // transient response. Preserve the original image part in-place.
            $strictPrompt="Return ONLY one COMPLETE valid JSON object. ".
                "The first character MUST be { and the final character MUST be }. ".
                "Every requested field must be a STRING; unknown values are empty strings. ".
                "No headings, explanations, tools, markdown, or additional text.\n".$prompt;
            if($image!==null){
                $payload['messages'][0]['content'][0]['text']=$strictPrompt;
            } elseif(isset($payload['messages'][0]['content'])){
                // Text-only 8B uses chat messages, never mix prompt and messages.
                $payload['messages'][0]['content']=$strictPrompt;
            } else {
                $payload['prompt']=$strictPrompt;
            }
            $retryBody=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            if(!is_string($retryBody))reply(false,'PAYLOAD_INVALID');
            $encoded=$retryBody;
        }
        $ch=curl_init($endpoint);
        if($ch===false)reply(false,'CURL_INIT');
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encoded,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json','Accept: application/json'],
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2
        ]);
        $body=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $errno=curl_errno($ch);
        unset($ch);
        if($http===200&&is_string($body)&&strlen($body)<=450000){
            $envelope=json_decode($body,true);
            $response=is_array($envelope)?($envelope['result']['response']??null):null;
            if(!is_array($envelope)||empty($envelope['success'])){
                reply(false,'CF_ENVELOPE_INVALID');
            }
            if($checkOnly){
                if(is_string($response)&&trim($response)!=='')reply(true,'OK',[]);
                reply(false,'PROBE_MISSING_TEXT');
            }
            $result=(array)($envelope['result']??[]);
            $bet=parsedVisionBet($result);
            if($bet!==null)reply(true,'OK',$bet);
            // Llama Vision can describe the ticket or return incomplete JSON.
            // Keep only bounded model observations for the SAME Cloudflare
            // account's text model. The parent never logs these strings.
            if($image!==null){
                $visualEvidence=collectVisualEvidence($result,$visualEvidence);
            }
            $description=$result['description']??null;
            $hasTools=is_array($result['tool_calls']??null)&&count($result['tool_calls'])>0;
            $reason=is_string($response)?tipResponseStatus($response)
                :(is_string($description)?tipResponseStatus($description)
                :($hasTools?'RESPONSE_UNSTRUCTURED_TOOL_CALLS':'RESPONSE_MISSING_TEXT'));
            // When Vision has already observed legible content but failed to
            // wrap it as JSON, return evidence to its parent immediately. A
            // second multimodal call often wastes the remaining Telegram
            // processing window before the same-account text rescue can run.
            // Never fabricate missing fields here; the parent still validates.
            if($image!==null && $visualEvidence!=='' &&
               in_array($reason,['RESPONSE_NOT_JSON','RESPONSE_MISSING_REQUIRED_FIELDS',
                   'RESPONSE_BAD_FIELD_TYPES','RESPONSE_UNSTRUCTURED_TOOL_CALLS'],true)){
                reply(false,$reason,null,$visualEvidence);
            }
            if($attempt===0){
                if(is_string($description)&&trim($description)!==''){
                    // For ImageTextToText outputs that are descriptive rather
                    // than JSON, use the SAME selected Workers AI model once
                    // more on text: combine original prompt + observed image
                    // description. Do not fabricate or overwrite source data.
                    $payload=[
                        'prompt'=>$prompt."\nEVIDÊNCIA EXTRAÍDA DA IMAGEM (não inventar nem ampliar):\n".
                            mb_substr(trim($description),0,8000,'UTF-8'),
                        'temperature'=>0,'max_tokens'=>1800,'stream'=>false
                    ];
                    $retryBody=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
                    if(!is_string($retryBody))reply(false,'PAYLOAD_INVALID');
                    $encoded=$retryBody;
                    $retryPrepared=true;
                } elseif($image!==null){
                    // The model can return HTTP 200 with no generated text for
                    // a multimodal message in some runtime versions. Retry
                    // once using the documented prompt + image input schema.
                    $payload=['prompt'=>$strictPrompt??$prompt,'image'=>$image,
                        'temperature'=>0,'max_tokens'=>1800,'stream'=>false];
                    $retryBody=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
                    if(!is_string($retryBody))reply(false,'PAYLOAD_INVALID');
                    $encoded=$retryBody;
                    $retryPrepared=true;
                }
                usleep(250000);continue;
            }
            reply(false,$reason,null,$visualEvidence!==''?$visualEvidence:null);
        }
        // If this account/model rejects JSON Mode, retry the existing text
        // extraction once without that option. No implicit provider change.
        if($structuredEvidence && $attempt===0 && $http===400 &&
           isset($payload['response_format'])){
            unset($payload['response_format']);
            $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            if(!is_string($encoded))reply(false,'PAYLOAD_INVALID');
            $retryPrepared=true;
            continue;
        }
        if($attempt===0&&(in_array($http,[500,502,503,504],true)||
            in_array($errno,[6,7,28,52,56],true))){usleep(450000);continue;}
        reply(false,cloudflareFailureReason($http,$body,$errno));
    }
    reply(false,'UNAVAILABLE');
} catch(Throwable) {
    reply(false,'UNEXPECTED');
}
