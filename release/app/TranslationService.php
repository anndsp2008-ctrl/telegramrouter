<?php declare(strict_types=1);
namespace App;

final class TranslationFailureException extends \RuntimeException
{
    public function __construct(string $message, public readonly array $attempts=[])
    {
        parent::__construct($message);
    }
}

final class TranslationService
{
    /** translation_size_telemetry_v1: diagnostic counters only; no message body is stored. */
    private static int $telemetryTextChars = 0;
    private static int $telemetryTextBytes = 0;

    private const AZURE_DEFAULT_ENDPOINT='https://api.cognitive.microsofttranslator.com';
    private const AZURE_CONNECT_TIMEOUT_MS=1800;
    private const AZURE_TIMEOUT_MS=4500;
    private const GEMINI_CONNECT_TIMEOUT_MS=2200;
    private const GEMINI_TIMEOUT_MS=20000; // translation_retry_v1
    private const GOOGLE_CLOUD_TIMEOUT_MS=5000;

    /** @return array{text:string,translated:bool,details:string,attempts:array<int,array>,fallback_used:bool,original_preserved:bool} */
    public static function translate(string $text,array $rule,array $context=[]): array
    {
        self::$telemetryTextChars = mb_strlen($text, 'UTF-8');
        self::$telemetryTextBytes = strlen($text);
        if(empty($rule['translation_enabled']) || !self::hasTranslatableText($text)) {
            return ['text'=>$text,'translated'=>false,'details'=>'','attempts'=>[],'fallback_used'=>false,'original_preserved'=>false];
        }
        [$primary,$fallback]=self::resolveProviders((string)($rule['translation_provider']??''));
        $target=trim((string)($rule['translation_target_language']??'pt-BR')) ?: 'pt-BR';
        $source='auto';
        $attempts=[];
        $fallbackUsed=false;
        $fallbackNote='';
        if ($primary==='gemini' && $fallback===null) {
            $fallbackNote=trim(Repository::integration('azure_translator_api_key'))===''
                ? 'Azure Translator não configurado.' : 'Azure Translator não habilitado como fallback em Integrações.';
        }
        foreach(array_values(array_filter([$primary,$fallback])) as $index=>$provider) {
            $isFallback=$index>0;
            if ($isFallback && $provider==='azure' && trim(Repository::integration('azure_translator_api_key'))==='') {
                $fallbackNote='Azure Translator não configurado.';
                continue;
            }
            $fallbackUsed=$fallbackUsed || $isFallback;
            $maxAttempts=$provider==='gemini'?2:1;
            for ($number=1; $number<=$maxAttempts; $number++) {
                try {
                    $attempt=self::attempt($provider,$text,$source,$target,$isFallback,$context);
                } catch(TranslationFailureException $e) {
                    $attempt=$e->attempts[0]??null;
                    if(!is_array($attempt)) throw $e;
                }
                $attempt['attempt_number']=$number;
                $attempts[]=$attempt;
                self::recordAttempt($attempt,$context);
                if(!empty($attempt['success'])) {
                    return ['text'=>(string)$attempt['text'],'translated'=>((string)$attempt['text']!==$text),
                        'details'=>self::failureSummary($attempts),'attempts'=>$attempts,
                        'fallback_used'=>$fallbackUsed,'original_preserved'=>false];
                }
                if ($number===$maxAttempts || empty($attempt['retryable'])) break;
                usleep(500000);
            }
        }
        $summary=self::failureSummary($attempts).($fallbackNote!==''?' | '.$fallbackNote:'');
        if(!empty($rule['translation_fallback_original'])) {
            return ['text'=>$text,'translated'=>false,'details'=>$summary.' | Mensagem original preservada conforme a regra.',
                'attempts'=>$attempts,'fallback_used'=>$fallbackUsed,'original_preserved'=>true];
        }
        throw new TranslationFailureException('Mensagem ignorada: a tradução falhou e a regra não permite preservar o original. '.$summary,$attempts);
    }
    /** @return array{0:string,1:?string} */
    public static function resolveProviders(string $ruleProvider): array
    {
        $globalPrimary=Repository::translationPrimaryProvider();
        $globalFallback=Repository::translationFallbackProvider();
        $primary=in_array($ruleProvider,['azure','gemini','google_cloud','workers_ai'],true)?$ruleProvider:$globalPrimary;
        $fallback=null;
        if($globalFallback!=='none') {
            if($globalFallback!==$primary) $fallback=$globalFallback;
            elseif($globalPrimary!==$primary) $fallback=$globalPrimary;
        }
        return [$primary,$fallback];
    }

    public static function hasTranslatableText(string $text): bool
    {
        if(trim($text)==='') return false;
        $probe=preg_replace('~(?:https?://|tg://|www\.|t\.me/|telegram\.me/)[^\s<>]+|@[\p{L}\p{N}_]{1,64}|#[\p{L}\p{N}_]{1,128}~iu',' ', $text) ?? $text;
        return (bool)preg_match('/\p{L}/u',$probe);
    }

    public static function maskSecret(string $secret): string
    {
        $secret=trim($secret);
        if($secret==='') return '';
        $tail=substr($secret,-4);
        return str_repeat('•',12).$tail;
    }

    /** @param array<string,string> $overrides @return array{ok:bool,provider:string,latency_ms:int,http_code:?int,message:string,source_language:?string,target_language:string} */
    public static function testProvider(string $provider,array $overrides=[]): array
    {
        if(!in_array($provider,['azure','gemini','google_cloud','workers_ai'],true)) throw new \InvalidArgumentException('Provedor de tradução inválido.');
        try {
            $attempt=self::attempt($provider,'Hello','auto','pt-BR',false,['context'=>'test','overrides'=>$overrides]);
            Repository::saveProviderTest($provider,true,(int)$attempt['latency_ms'],$attempt['http_code']??null,null);
            return [
                'ok'=>true,'provider'=>$provider,'latency_ms'=>(int)$attempt['latency_ms'],'http_code'=>$attempt['http_code']??null,
                'message'=>'Conexão realizada com sucesso — '.(int)$attempt['latency_ms'].' ms',
                'source_language'=>$attempt['source_language']??'en','target_language'=>'pt-BR'
            ];
        } catch(TranslationFailureException $e) {
            $a=$e->attempts[0]??[];
            $lat=(int)($a['latency_ms']??0); $http=isset($a['http_code'])?(int)$a['http_code']:null;
            $err=self::sanitizeError((string)($a['error_text']??$e->getMessage()));
            Repository::saveProviderTest($provider,false,$lat,$http,$err);
            return [
                'ok'=>false,'provider'=>$provider,'latency_ms'=>$lat,'http_code'=>$http,
                'message'=>'Falha na conexão'.($http?' — HTTP '.$http:'').' — '.$err,
                'source_language'=>null,'target_language'=>'pt-BR'
            ];
        }
    }

    /** @return array{provider:string,success:bool,text:string,latency_ms:int,http_code:?int,source_language:?string,target_language:string,error_text:?string,fallback_used:bool} */
    private static function attempt(string $provider,string $text,string $source,string $target,bool $fallback,array $context): array
    {
        return match($provider) {
            'azure'=>self::azure($text,$source,$target,$fallback,$context),
            'gemini'=>self::gemini($text,$source,$target,$fallback,$context),
            'google_cloud'=>self::googleCloud($text,$target,$fallback,$context),
            'workers_ai'=>WorkersAITranslation::attempt($text,$target,$fallback,$context),
            default=>throw new TranslationFailureException('Provedor de tradução inválido.'),
        };
    }

    private static function azure(string $text,string $source,string $target,bool $fallback,array $context): array
    {
        $overrides=(array)($context['overrides']??[]);
        $key=trim((string)($overrides['azure_api_key']??Repository::integration('azure_translator_api_key')));
        $region=trim((string)($overrides['azure_region']??Repository::integration('azure_translator_region')));
        $endpoint=trim((string)($overrides['azure_endpoint']??Repository::integration('azure_translator_endpoint')));
        if($endpoint==='') $endpoint=self::AZURE_DEFAULT_ENDPOINT;
        if($key==='') throw self::failure('azure',0,null,$target,'Microsoft Azure Translator não configurado: informe a API Key no módulo Integrações.',$fallback);
        if(!self::validAzureEndpoint($endpoint)) throw self::failure('azure',0,null,$target,'Endpoint do Azure Translator inválido. Use um endpoint oficial HTTPS do Microsoft Translator.',$fallback);
        if($region!=='' && !preg_match('/^[A-Za-z0-9-]{2,64}$/',$region)) throw self::failure('azure',0,null,$target,'Region do Azure Translator possui formato inválido.',$fallback);

        $azureTarget=self::azureLanguage($target);
        $query=['api-version'=>'3.0','to'=>$azureTarget];
        // Source language is always auto-detected.
        $url=rtrim($endpoint,'/').'/translate?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $headers=['Content-Type: application/json; charset=UTF-8','Ocp-Apim-Subscription-Key: '.$key];
        if($region!=='') $headers[]='Ocp-Apim-Subscription-Region: '.$region;
        $payload=json_encode([['Text'=>$text]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        [$body,$http,$latency,$curlError]=self::postJson($url,$payload,$headers,self::AZURE_CONNECT_TIMEOUT_MS,self::AZURE_TIMEOUT_MS);

        if($curlError!=='') throw self::failure('azure',$latency,$http,$target,'Falha de comunicação/timeout com Azure Translator: '.$curlError,$fallback);
        $data=json_decode($body,true);
        if($http<200||$http>=300||!is_array($data)) {
            $apiMessage=is_array($data)?(string)($data['error']['message']??'resposta inválida da API'):'resposta inválida da API';
            throw self::failure('azure',$latency,$http,$target,'Azure Translator recusou a solicitação: '.$apiMessage,$fallback);
        }
        $translated=trim((string)($data[0]['translations'][0]['text']??''));
        if($translated==='') throw self::failure('azure',$latency,$http,$target,'Azure Translator não retornou uma tradução.',$fallback);
        $detected=$source==='auto'?(string)($data[0]['detectedLanguage']['language']??'auto'):$source;
        return ['provider'=>'azure','success'=>true,'text'=>$translated,'latency_ms'=>$latency,'http_code'=>$http,'source_language'=>$detected,'target_language'=>$target,'error_text'=>null,'fallback_used'=>$fallback];
    }

    private static function googleCloud(string $text,string $target,bool $fallback,array $context): array
    {
        $overrides=(array)($context['overrides']??[]);
        $key=trim((string)($overrides['google_cloud_api_key']??Repository::integration('google_cloud_api_key')));
        if($key==='') throw self::failure('google_cloud',0,null,$target,'Google Cloud Translation não configurado: informe a API Key em Integrações.',$fallback);
        $language=strtolower(str_replace('_','-',trim($target)));
        if(in_array($language,['pt-br','pt-pt'],true)) $language='pt';
        if(!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/',$language) || $language==='auto') {
            throw self::failure('google_cloud',0,null,$target,'Idioma de destino inválido. Informe um código como pt, en ou es.',$fallback);
        }
        // Basic v2: fixed NMT model, plain text, automatic source detection.
        $payload=json_encode(['q'=>[$text],'target'=>$language,'format'=>'text','model'=>'nmt'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $url='https://translation.googleapis.com/language/translate/v2?key='.rawurlencode($key);
        [$body,$http,$latency,$curlError,$errno]=self::postJson($url,$payload,['Content-Type: application/json; charset=UTF-8'],1800,self::GOOGLE_CLOUD_TIMEOUT_MS);
        if($curlError!=='') throw self::failure('google_cloud',$latency,$http,$target,'Falha de comunicação/timeout com Google Cloud Translation: '.str_replace([$key,rawurlencode($key)],'[REDACTED]',$curlError),$fallback,$errno);
        $data=json_decode($body,true);
        if($http<200 || $http>=300 || !is_array($data) || !empty($data['error'])) {
            // Do not store raw API errors: they can echo credentials or message text.
            $reason=match($http) {
                400=>'Solicitação inválida. Verifique a chave e o idioma de destino.',
                401,403=>'Acesso recusado. Verifique a chave, suas restrições, a API ativada e o faturamento do projeto.',
                429=>'Cota ou limite de solicitações atingido no Google Cloud.',
                default=>'Serviço indisponível ou resposta inválida da API.',
            };
            throw self::failure('google_cloud',$latency,$http,$target,$reason,$fallback);
        }
        $item=$data['data']['translations'][0]??null;
        if(!is_array($item) || !is_string($item['translatedText']??null) || trim($item['translatedText'])==='') {
            throw self::failure('google_cloud',$latency,$http,$target,'Google Cloud Translation não retornou uma tradução válida.',$fallback);
        }
        $translated=html_entity_decode($item['translatedText'],ENT_QUOTES|ENT_HTML5,'UTF-8');
        return ['provider'=>'google_cloud','success'=>true,'text'=>$translated,'latency_ms'=>$latency,'http_code'=>$http,
            'source_language'=>(string)($item['detectedSourceLanguage']??'auto'),'target_language'=>$target,'error_text'=>null,'fallback_used'=>$fallback];
    }

    private static function gemini(string $text,string $source,string $target,bool $fallback,array $context): array
    {
        $overrides=(array)($context['overrides']??[]);
        $key=trim((string)($overrides['gemini_api_key']??Repository::integration('gemini_api_key')));
        $model=trim((string)($overrides['gemini_model']??Repository::integration('gemini_model')));
        if($model==='') $model='gemini-3.6-flash';
        if($key==='') throw self::failure('gemini',0,null,$target,'Google Gemini não configurado: informe a API Key no módulo Integrações.',$fallback);
        if(!preg_match('/^[A-Za-z0-9._-]{2,96}$/',$model)) throw self::failure('gemini',0,null,$target,'Nome do modelo Gemini inválido.',$fallback);

        $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($key);
        $instruction='Translate to '.$target.'. Output only the translated text. Preserve line breaks, emojis, numbers, odds, names, URLs, @usernames, #hashtags, punctuation and symbols exactly when they do not require translation.';
        $payload=json_encode([
            'systemInstruction'=>['parts'=>[['text'=>$instruction]]],
            'contents'=>[['role'=>'user','parts'=>[['text'=>$text]]]],
            'generationConfig'=>['temperature'=>0]
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        [$body,$http,$latency,$curlError,$curlErrno]=self::postJson($url,$payload,['Content-Type: application/json'],self::GEMINI_CONNECT_TIMEOUT_MS,self::GEMINI_TIMEOUT_MS);
        if($curlError!=='') throw self::failure('gemini',$latency,$http,$target,'Falha de comunicação/timeout com Google Gemini: '.$curlError,$fallback,$curlErrno);
        $data=json_decode($body,true);
        if($http<200||$http>=300||!is_array($data)||!empty($data['error'])) {
            $apiMessage=is_array($data)?(string)($data['error']['message']??'resposta inválida da API'):'resposta inválida da API';
            throw self::failure('gemini',$latency,$http,$target,'Google Gemini recusou a solicitação: '.$apiMessage,$fallback);
        }
        $translated=trim((string)($data['candidates'][0]['content']['parts'][0]['text']??''));
        if($translated==='') throw self::failure('gemini',$latency,$http,$target,'Google Gemini não retornou uma tradução.',$fallback);
        return ['provider'=>'gemini','success'=>true,'text'=>$translated,'latency_ms'=>$latency,'http_code'=>$http,'source_language'=>($source==='auto'?null:$source),'target_language'=>$target,'error_text'=>null,'fallback_used'=>$fallback];
    }

    /** @return array{0:string,1:int,2:int,3:string,4:int} */
    private static function postJson(string $url,string $payload,array $headers,int $connectTimeoutMs,int $timeoutMs): array
    {
        $ch=curl_init($url);
        if($ch===false) return ['',0,0,'não foi possível inicializar a conexão HTTP',0];
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT_MS=>$connectTimeoutMs,
            CURLOPT_TIMEOUT_MS=>$timeoutMs,
            CURLOPT_NOSIGNAL=>true,
            CURLOPT_ENCODING=>'',
            CURLOPT_FOLLOWLOCATION=>false,
        ]);
        $started=hrtime(true);
        $body=curl_exec($ch);
        $latency=(int)round((hrtime(true)-$started)/1_000_000);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $error=(string)curl_error($ch);
        $errno=curl_errno($ch);
        unset($ch);
        return [(string)$body,$http,$latency,$error,$errno];
    }

    private static function failure(string $provider,int $latency,?int $http,string $target,string $message,bool $fallback,int $curlErrno=0): TranslationFailureException
    {
        $message=self::sanitizeError($message);
        $attempt=['provider'=>$provider,'success'=>false,'text'=>'','latency_ms'=>$latency,'http_code'=>$http,'source_language'=>null,'target_language'=>$target,'error_text'=>$message,'fallback_used'=>$fallback];
        $attempt['curl_errno']=$curlErrno;
        // Retry only transient Gemini failures: network errors plus HTTP 429/503.
        // Permanent/auth/request errors (400/401/403/404 etc.) remain non-retryable.
        $attempt['retryable']=$provider==='gemini' && (
            in_array($http,[429,503],true)
            || (!($http>=400 && $http<500)
                && in_array($curlErrno,[5,6,7,18,28,52,55,56,92],true))
        );
        return new TranslationFailureException($message,[$attempt]);
    }

    private static function recordAttempt(array $attempt,array $context): void
    {
        try {
            error_log('TRANSLATION_RETRY_ATTEMPT '.json_encode([
                'provider'=>$attempt['provider'],'attempt_number'=>$attempt['attempt_number']??1,
                'success'=>(bool)$attempt['success'],'latency_ms'=>(int)$attempt['latency_ms'],
                'http_code'=>$attempt['http_code']??null,'curl_errno'=>$attempt['curl_errno']??0,
                'retryable'=>$attempt['retryable']??false,'fallback_used'=>$attempt['fallback_used']??false,
                'text_chars'=>self::$telemetryTextChars,'text_bytes'=>self::$telemetryTextBytes,
            ],JSON_UNESCAPED_SLASHES));
            Repository::recordTranslationAttempt([
                'source_chat'=>(string)($context['source_chat']??''),
                'message_id'=>(int)($context['message_id']??0),
                'rule_id'=>isset($context['rule_id'])?(int)$context['rule_id']:null,
                'provider'=>(string)$attempt['provider'],
                'success'=>(bool)$attempt['success'],
                'fallback_used'=>(bool)($attempt['fallback_used']??false),
                'http_code'=>$attempt['http_code']??null,
                'source_language'=>$attempt['source_language']??null,
                'target_language'=>(string)$attempt['target_language'],
                'latency_ms'=>(int)$attempt['latency_ms'],
                'text_chars'=>self::$telemetryTextChars,
                'text_bytes'=>self::$telemetryTextBytes,
                'error_text'=>$attempt['error_text']??null,
                'context'=>(string)($context['context']??'message'),
            ]);
        } catch(\Throwable) {
            // Telemetria nunca pode interromper o roteamento.
        }
    }

    private static function attemptSummary(array $a): string
    {
        $name=self::providerLabel((string)$a['provider']);
        $src=strtoupper((string)($a['source_language']??'AUTO'));
        $target=strtoupper((string)$a['target_language']);
        return $name.' | Sucesso | '.$src.' → '.$target.' | '.(int)$a['latency_ms'].' ms';
    }

    private static function failureSummary(array $attempts): string
    {
        if(!$attempts) return 'Falha de tradução sem tentativa registrada.';
        $parts=[];
        foreach($attempts as $i=>$a) {
            $prefix=(!empty($a['fallback_used'])?'Fallback ':'').'Tentativa '.(int)($a['attempt_number']??1).' | ';
            if (!empty($a['success'])) { $parts[]=$prefix.self::attemptSummary($a); continue; }
            $http=!empty($a['http_code'])?'HTTP '.(int)$a['http_code']:'erro de comunicação';
            $parts[]=$prefix.self::providerLabel((string)$a['provider']).' | Falha | '.$http.' | '.(int)$a['latency_ms'].' ms | '.self::sanitizeError((string)($a['error_text']??'erro não informado'));
        }
        return implode(' | ',$parts);
    }

    public static function providerLabel(string $provider): string
    {
        return match($provider) {'azure'=>'Azure Translator','gemini'=>'Google Gemini','google_cloud'=>'Google Cloud Translation','workers_ai'=>'Cloudflare Workers AI',default=>$provider};
    }

    public static function sanitizeError(string $message): string
    {
        $message=preg_replace('/([?&]key=)[^&\s]+/i','$1[REDACTED]',$message) ?? $message;
        $message=preg_replace('/\bAIza[0-9A-Za-z_-]{20,}\b/','[REDACTED]',$message) ?? $message;
        $message=preg_replace('/\b[0-9a-f]{32,}\b/i','[REDACTED]',$message) ?? $message;
        $message=preg_replace('/\s+/u',' ',trim($message)) ?? trim($message);
        return substr($message,0,900);
    }

    private static function validAzureEndpoint(string $endpoint): bool
    {
        $parts=parse_url($endpoint);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https') return false;
        $host=strtolower((string)($parts['host']??''));
        if($host==='api.cognitive.microsofttranslator.com') return true;
        return str_ends_with($host,'.cognitiveservices.azure.com') || str_ends_with($host,'.microsofttranslator.com');
    }

    public static function azureLanguage(string $lang): string
    {
        $lang=strtolower(str_replace('_','-',trim($lang)));
        if($lang===''||$lang==='auto') return 'auto';
        if(in_array($lang,['pt-br','pt'],true)) return 'pt';
        return $lang;
    }
}
