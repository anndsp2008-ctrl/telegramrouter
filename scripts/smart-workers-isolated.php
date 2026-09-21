<?php declare(strict_types=1);
/** Isolated Cloudflare REST extraction; secrets and source enter through STDIN only. */
function reply(bool $ok,string $reason='OK',?array $data=null,?string $evidence=null,?array $shape=null): never {
    // Only the parent isolated PHP process receives evidence over stdout.
    // Never print model text, image data or the original tip in application logs.
    echo json_encode(['ok'=>$ok,'reason'=>$reason,'data'=>$data,'evidence'=>$evidence,'shape'=>$shape],
        JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}
/** Cloudflare API errors are reduced to safe status/code labels. Never log or
 * propagate upstream response bodies (they may contain private details).
 * Error 5016 is model-license acceptance, not a bad API token.
 */
function cloudflareFailureReason(int $http,string|false $body,int $errno): string {
    $errorCode=0;
    $errorMessage='';
    if(is_string($body) && strlen($body)<60000){
        $json=json_decode($body,true);
        $code=is_array($json)?($json['errors'][0]['code']??null):null;
        if((is_int($code)||is_string($code))&&preg_match('/^[0-9]{3,5}$/D',(string)$code))
            $errorCode=(int)$code;
        // Interpret a few stable error categories locally; NEVER expose the
        // upstream message, which may contain request text or image metadata.
        $message=is_array($json)?($json['errors'][0]['message']??null):null;
        if(is_string($message)&&strlen($message)<2000)$errorMessage=$message;
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
    if($http===400){
        if($errorCode===5007)return 'MODEL_NOT_FOUND_5007';
        if($errorCode===5004)return 'IMAGE_INPUT_INVALID_5004';
        if($errorCode===3030){
            // 3030 is NOT one error with one fix: Cloudflare also uses it
            // for missing model inputs and content-policy rejections.
            // Policy errors must not be retried through another vision model.
            if(preg_match('/\\b(?:nsfw|content.policy|content.filter|safety.filter|unsafe.content)\\b/i',$errorMessage))
                return 'HTTP_400_CF_3030_POLICY';
            if(preg_match('/\\b(?:missing required (?:input|field)|required (?:input|field) [^ ]+ (?:is )?missing)\\b/i',$errorMessage))
                return 'HTTP_400_CF_3030_MISSING_INPUT';
            return 'HTTP_400_CF_3030_UNCLASSIFIED';
        }
        return $errorCode>0?'HTTP_400_CF_'.$errorCode:'HTTP_400';
    }
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
        [400,5004,'IMAGE_INPUT_INVALID_5004'],
        [400,5007,'MODEL_NOT_FOUND_5007'],
        [400,3030,'HTTP_400_CF_3030_UNCLASSIFIED'],
        [400,1234,'HTTP_400_CF_1234'],
        [400,0,'HTTP_400']
    ] as [$status,$code,$expected]){
        $body=$code>0?json_encode(['errors'=>[['code'=>$code,'message'=>'REDACTED']]]):'{}';
        if(cloudflareFailureReason($status,$body,0)!==$expected)
            throw new RuntimeException('Workers AI classification mismatch '.$status.'/'.$code);
    }
    foreach([
        ['AiError: Model input is not valid: missing required input image',
            'HTTP_400_CF_3030_MISSING_INPUT'],
        ['AiError: Input prompt contains NSFW content.',
            'HTTP_400_CF_3030_POLICY'],
        ['AiError: content filter declined input.',
            'HTTP_400_CF_3030_POLICY'],
        ['AiError: unknown invalid payload',
            'HTTP_400_CF_3030_UNCLASSIFIED']
    ] as [$message,$expected]){
        $body=json_encode(['errors'=>[['code'=>3030,'message'=>$message]]]);
        if(cloudflareFailureReason(400,$body,0)!==$expected)
            throw new RuntimeException('Workers AI code 3030 category regression');
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
/** Normalize supported Cloudflare result envelopes without inventing tip fields.
 * A successful REST response can contain JSON text as the whole result rather
 * than under result.response. Direct structured results are validated too.
 * Never interpret unstructured text or arbitrary objects as betting facts.
 */
function normalizedCloudflareResult(array $envelope): array {
    $raw=$envelope['result']??null;
    if(is_string($raw))return ['response'=>$raw];
    if(!is_array($raw)||array_is_list($raw))return [];
    foreach(['response','description','tool_calls'] as $field){
        if(array_key_exists($field,$raw))return $raw;
    }
    $encoded=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if(is_string($encoded)&&tipResponseStatus($encoded)==='OK')
        return ['response'=>$encoded];
    return $raw;
}
function parsedVisionBet(array $result): ?array {
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
    foreach([
        ['success'=>true,'result'=>$json],
        ['success'=>true,'result'=>$base],
        ['success'=>true,'result'=>['response'=>$json]],
        ['success'=>true,'result'=>['description'=>$json]]
    ] as $envelope){
        if(parsedVisionBet(normalizedCloudflareResult($envelope))!==$base)
            throw new RuntimeException('Cloudflare response envelope lost structured tip');
    }
    foreach([
        ['success'=>true,'result'=>'Relato sem JSON'],
        ['success'=>true,'result'=>['match'=>'Venezia','market'=>'','selection'=>'Mais de 8,5']],
        ['success'=>true,'result'=>['arbitrary'=>'unknown']],
        ['success'=>true,'result'=>null],
        ['success'=>true,'result'=>[1,2,3]]
    ] as $envelope){
        if(parsedVisionBet(normalizedCloudflareResult($envelope))!==null)
            throw new RuntimeException('Cloudflare envelope created unsupported tip data');
    }
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
    echo "WORKERS_AI_JSON_EXTRACTION_TESTS_PASSED\n";
    exit(0);
}

/** Vision payloads are model-specific. Keep text and image together
 * for primary multimodal chat and preserve Scout's existing schema.
 */
function smartVisionPayload(string $model,string $prompt,string $image,bool $checkOnly,array $fields): array {
    if($model==='@cf/meta/llama-3.2-11b-vision-instruct'){
        // Keep text and image in one user message. The REST request never
        // combines a top-level image with chat messages.
        return [
            'messages'=>[[
                'role'=>'user',
                'content'=>[
                    ['type'=>'text','text'=>$prompt],
                    ['type'=>'image_url','image_url'=>['url'=>$image]]
                ]
            ]],
            'temperature'=>0,'max_tokens'=>$checkOnly?12:2800,'stream'=>false
        ];
    }
    // Scout retains the already deployed multimodal image_url schema.
    $payload=[
        'messages'=>[[
            'role'=>'user',
            'content'=>[
                ['type'=>'text','text'=>$prompt],
                ['type'=>'image_url','image_url'=>['url'=>$image]]
            ]
        ]],
        'temperature'=>0,'max_tokens'=>$checkOnly?12:2800,'stream'=>false
    ];
    if($model==='@cf/meta/llama-4-scout-17b-16e-instruct' && !$checkOnly && $fields!==[]){
        $properties=[];
        foreach($fields as $field)$properties[$field]=['type'=>'string'];
        $payload['guided_json']=['type'=>'object','properties'=>$properties,
            'additionalProperties'=>false];
    }
    return $payload;
}
/** One alternative documented REST prompt+base64 image request for the
 * primary Vision model only; never use after an auth, quota or timeout error.
 */
function smartVisionCompatPayload(string $prompt,string $image): array {
    $separator=strpos($image,',');
    $base64=$separator===false?'':substr($image,$separator+1);
    return ['prompt'=>$prompt,'image'=>$base64,
        'temperature'=>0,'max_tokens'=>1200,'stream'=>false];
}
/** Metadata from a fixed allowlist only: no response content, image or keys. */
function safeWorkersResponseShape(array $envelope,array $result): array {
    $kind=static function(mixed $v): string {
        if($v===null)return 'null';
        if(is_string($v))return 'string';
        if(is_array($v))return 'array';
        return 'other';
    };
    return [
        'result_type'=>$kind($envelope['result']??null),
        'response_type'=>$kind($result['response']??null),
        'description_type'=>$kind($result['description']??null),
        'tool_calls_present'=>is_array($result['tool_calls']??null)
            && count($result['tool_calls'])>0
    ];
}
// Offline contract test: never calls Cloudflare or reads production credentials.
if(getenv('SMART_WORKERS_VISION_PAYLOAD_TEST')==='1'){
    $rawImage=base64_encode('offline-test-image');
    $image='data:image/png;base64,'.$rawImage;
    $vision=smartVisionPayload('@cf/meta/llama-3.2-11b-vision-instruct',
        'Test prompt',$image,false,['match']);
    $primaryParts=$vision['messages'][0]['content']??[];
    if(($vision['messages'][0]['role']??null)!=='user' ||
       ($primaryParts[0]['type']??null)!=='text' ||
       ($primaryParts[0]['text']??null)!=='Test prompt' ||
       ($primaryParts[1]['type']??null)!=='image_url' ||
       ($primaryParts[1]['image_url']['url']??null)!==$image ||
       ($vision['max_tokens']??null)!==2800 ||
       isset($vision['image']) || isset($vision['prompt']))
        throw new RuntimeException('Primary Vision unified content payload regression');
    $probe=smartVisionPayload('@cf/meta/llama-3.2-11b-vision-instruct',
        'Probe',$image,true,[]);
    if(($probe['max_tokens']??null)!==12 ||
       ($probe['messages'][0]['content'][1]['image_url']['url']??null)!==$image ||
       isset($probe['image']))
        throw new RuntimeException('Vision probe payload regression');
    $compat=smartVisionCompatPayload('Test prompt',$image);
    if(($compat['prompt']??null)!=='Test prompt' ||
       ($compat['image']??null)!==$rawImage || ($compat['max_tokens']??null)!==1200 ||
       isset($compat['messages']))
        throw new RuntimeException('Alternative REST Vision payload regression');
    $scout=smartVisionPayload('@cf/meta/llama-4-scout-17b-16e-instruct',
        'Scout',$image,false,['match','odd']);
    $parts=$scout['messages'][0]['content']??[];
    if(($parts[0]['text']??null)!=='Scout' ||
       ($parts[1]['image_url']['url']??null)!==$image ||
       isset($scout['image']) ||
       ($scout['guided_json']['properties']['odd']['type']??null)!=='string')
        throw new RuntimeException('Scout Vision payload regression');
    $shape=safeWorkersResponseShape(['result'=>['response'=>null,'description'=>null]],
        ['response'=>null,'description'=>null,'tool_calls'=>[]]);
    if($shape!==['result_type'=>'array','response_type'=>'null',
        'description_type'=>'null','tool_calls_present'=>false])
        throw new RuntimeException('Private data leaked into response-shape diagnostics');
    echo "WORKERS_VISION_PAYLOAD_TESTS_PASSED\n";
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
    // Only the rescue vision model uses guided_json; the selected Llama 3.2
    // primary and the existing translation transport remain unchanged.
    $fields=$input['fields']??[];
    if(!is_array($fields)||count($fields)>24)$fields=[];
    $fields=array_values(array_filter($fields,static fn($field): bool =>
        is_string($field)&&preg_match('/^[a-z_]{2,30}$/D',$field)===1));

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
    if($image!==null)$payload=smartVisionPayload($model,$prompt,$image,$checkOnly,$fields);
    $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if(!is_string($encoded))reply(false,'PAYLOAD_INVALID');
    $visualEvidence=''; // Local-only, bounded image observation for text structuring.
    // Vision inference may need more than the former 16s/8s windows.
    // Only test requests use small output; production card requests have
    // an independent, larger timeout and one bounded transient retry.
    $timeouts=$checkOnly?[30,20]:[45,30];
    // Limit the optional Scout rescue so an unresponsive vision model does not
    // consume the whole message-processing window before the configured fallback.
    if(!$checkOnly && $image!==null &&
       $model==='@cf/meta/llama-4-scout-17b-16e-instruct')$timeouts=[22,8];
    // Text cards on the lightweight model have their own bounded window.
    // Do not block the configured provider fallback for up to 75 seconds.
    if(!$checkOnly && $image===null &&
       $model==='@cf/meta/llama-3.1-8b-instruct-fp8')$timeouts=[25,10];
    foreach($timeouts as $attempt=>$timeout){
        if($attempt===1&&!$checkOnly&&!($retryPrepared??false)){
            // Retry once with strict JSON output after an invalid, empty, or
            // transient response. Preserve the original image part in-place.
            $strictPrompt="Return ONLY one COMPLETE valid JSON object. ".
                "The first character MUST be { and the final character MUST be }. ".
                "Every requested field must be a STRING; unknown values are empty strings. ".
                "No headings, explanations, tools, markdown, or additional text.\n".$prompt;
            if($image!==null && isset($payload['messages'][0]['content'][0]['text'])){
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
            if(!is_array($envelope)||empty($envelope['success'])){
                reply(false,'CF_ENVELOPE_INVALID');
            }
            $result=normalizedCloudflareResult($envelope);
            $response=$result['response']??null;
            if($checkOnly){
                if(is_string($response)&&trim($response)!=='')reply(true,'OK',[]);
                reply(false,'PROBE_MISSING_TEXT');
            }
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
            // A 200 response with no usable text and no visual evidence cannot
            // be repaired by an identical request. Pass control to the existing
            // same-account Vision rescue without spending another 30-45 seconds.
            if($image!==null && $reason==='RESPONSE_MISSING_TEXT' && $visualEvidence===''){
                if($attempt===0 &&
                   $model==='@cf/meta/llama-3.2-11b-vision-instruct'){
                    // Different documented Vision schema, not an identical retry.
                    $compat=smartVisionCompatPayload($prompt,$image);
                    $retryBody=json_encode($compat,
                        JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
                    if(!is_string($retryBody))reply(false,'PAYLOAD_INVALID');
                    $payload=$compat;
                    $encoded=$retryBody;
                    $retryPrepared=true;
                    usleep(250000);
                    continue;
                }
                reply(false,$reason,null,null,safeWorkersResponseShape($envelope,$result));
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
                }
                usleep(250000);continue;
            }
            reply(false,$reason,null,$visualEvidence!==''?$visualEvidence:null,
                safeWorkersResponseShape($envelope,$result));
        }
        // The primary REST Vision payload may be rejected as HTTP 400 by
        // model-specific validation. Try the other documented schema ONCE,
        // only for Llama 3.2, without changing provider or repeating credentials.
        // Do not retry invalid images (5004), unknown models (5007), auth,
        // quota or transient failures here.
        if($attempt===0 && $http===400 && $image!==null && !$checkOnly &&
           $model==='@cf/meta/llama-3.2-11b-vision-instruct' &&
           in_array(cloudflareFailureReason($http,$body,$errno),
               ['HTTP_400','HTTP_400_CF_3030_MISSING_INPUT'],true)){
            $compat=smartVisionCompatPayload($prompt,$image);
            $retryBody=json_encode($compat,
                JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            if(!is_string($retryBody))reply(false,'PAYLOAD_INVALID');
            $payload=$compat;
            $encoded=$retryBody;
            $retryPrepared=true;
            usleep(250000);
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
