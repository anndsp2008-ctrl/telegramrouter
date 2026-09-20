<?php declare(strict_types=1);
namespace App;

/**
 * Isolated, opt-in AI formatting. Existing forwarding is the only fallback.
 * Never writes raw tips, photos or API keys to application logs.
 */
final class SmartFormatting
{
    private const SIGNATURE='⚡ TelegramRouter • Aposta encaminhada';
    /** Sanitized per-tip codes only: never store message content, photos or keys. */
    private static array $failureCodes=[];
    private static function diag(string $code): void
    {
        $safe=strtoupper($code);
        if(!preg_match('/^[A-Z0-9_]{1,64}$/D',$safe))$safe='UNCLASSIFIED';
        self::$failureCodes[]=$safe;
        if(count(self::$failureCodes)>12)array_shift(self::$failureCodes);
        error_log('TMR_SMART_FORMAT_REASON '.$safe);
    }
    /** Called only after an enabled rule has failed before sending to Telegram. */
    public static function failureSummary(): string
    {
        $causes=array_values(array_unique(array_filter(self::$failureCodes,
            static fn(string $code): bool => !str_starts_with($code,'SMART_PROVIDER_FAILED_')
                && $code!=='ALL_CONFIGURED_PROVIDERS_FAILED'
        )));
        return implode('; ',array_slice($causes,-4));
    }

    public static function migrate(): void
    {
        Database::pdo()->exec(
            "CREATE TABLE IF NOT EXISTS router_smart_format_settings (
              rule_id INT UNSIGNED NOT NULL PRIMARY KEY,
              enabled TINYINT(1) NOT NULL DEFAULT 0,
              output_mode VARCHAR(12) NOT NULL DEFAULT 'card',
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
    /** @return array{enabled:bool,output_mode:string} */
    public static function settings(int $ruleId): array
    {
        if($ruleId<=0)return ['enabled'=>false,'output_mode'=>'card'];
        $s=Database::pdo()->prepare('SELECT enabled,output_mode FROM router_smart_format_settings WHERE rule_id=?');
        $s->execute([$ruleId]);$row=$s->fetch();
        return [
            'enabled'=>(bool)($row['enabled']??false),
            'output_mode'=>in_array($row['output_mode']??'',['card','caption','text'],true)?$row['output_mode']:'card'
        ];
    }
    public static function saveForRule(int $ruleId,array $input): void
    {
        if($ruleId<=0)throw new \InvalidArgumentException('Regra inválida para formatação.');
        $mode=(string)($input['smart_output_mode']??'card');
        if(!in_array($mode,['card','caption','text'],true))$mode='card';
        $enabled=!empty($input['smart_format_enabled'])?1:0;
        Database::pdo()->prepare(
            'INSERT INTO router_smart_format_settings(rule_id,enabled,output_mode) VALUES(?,?,?)
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),output_mode=VALUES(output_mode)'
        )->execute([$ruleId,$enabled,$mode]);
    }
    /**
     * With AI card + translation enabled, the extraction request itself renders
     * the translated card and caption. Do not call the legacy translator first,
     * otherwise Gemini is charged twice and HTTP 429 can replace the card with
     * the original image. Other rule types keep their existing translation path.
     */
    public static function cardHandlesTranslation(array $rule,array $setting): bool
    {
        return !empty($rule['translation_enabled']) && !empty($setting['enabled'])
            && in_array($setting['output_mode']??'',['card','caption','text'],true);
    }
    public static function shouldTranslateInsideCard(array $rule): bool
    {
        if(empty($rule['translation_enabled']))return false;
        try {
            return self::cardHandlesTranslation($rule,self::settings((int)($rule['id']??0)));
        } catch(\Throwable $error) {
            return false; // Preserve legacy translation when settings are unavailable.
        }
    }
    /**
     * $localImage must be a temporary file downloaded from the received Telegram message.
     * @return array{caption:string,image:?string,mode:string}|null
     */
    public static function prepare(string $sourceText,array $rule,?string $localImage,string $mode): ?array
    {
        self::$failureCodes=[]; // Never carry another message's errors into this event.
        if(trim($sourceText)===''&&($localImage===null||!is_file($localImage))){self::diag('SOURCE_EMPTY');return null;}
        $target=trim((string)($rule['translation_target_language']??'pt-BR'))?:'pt-BR';
        $translate=!empty($rule['translation_enabled']);
        $inputLanguage=$translate?'Produza somente conteúdo no idioma '.$target.' em TODOS os campos de texto, inclusive a análise original traduzida. Não inclua versões no idioma original, não duplique a mensagem e mantenha nomes próprios, mercado, seleção, odds e números fiéis.':'Use o idioma da mensagem original. Não traduza.';
        $fields=['sport','status','match','league','market','selection','odd','time','day','stake','bookmaker','stake_amount','potential_return','analysis'];
        // Use the EXACT primary/fallback resolution from the translation rule.
        // Every attempt must translate AND extract; no invisible Gemini call
        // when the configured provider is Workers AI or translation-only.
        $bet=null;
        $providers=self::cardProviders($rule);
        foreach($providers as $provider){
            $json=null;
            if($provider==='workers_ai'){
                $json=self::requestWorkers($sourceText,$localImage,$inputLanguage,$fields);
            } elseif($provider==='gemini'){
                $key=trim(Repository::integration('gemini_api_key'));
                if($key!=='')$json=self::request($key,$sourceText,$localImage,$inputLanguage,$fields);
                else self::diag('GEMINI_KEY_MISSING');
            } else {
                // Azure Translator/Google Cloud Translation translate text but
                // cannot extract structured tip data from source photos.
                self::diag('PROVIDER_NOT_GENERATIVE_'.$provider);
            }
            if(!is_array($json)){
                self::diag('SMART_PROVIDER_FAILED_'.$provider);
                continue;
            }
            $candidate=[];
            foreach($fields as $field)$candidate[$field]=trim((string)($json[$field]??''));
            $candidate['potential_profit']=self::calculatePotentialProfit(
                $candidate['stake_amount'],$candidate['potential_return']);
            if($candidate['selection']===''||$candidate['market']===''||$candidate['match']===''){
                self::diag('REQUIRED_FIELDS_INCOMPLETE_'.$provider);
                continue;
            }
            if($candidate['analysis']==='' && self::containsAnalysis($sourceText)){
                self::diag('ANALYSIS_ABSENT_'.$provider);
                continue;
            }
            $bet=$candidate;
            error_log('TMR_SMART_FORMAT_PROVIDER '.json_encode([
                'provider'=>$provider,'fallback'=>$provider!==$providers[0]
            ]));
            break;
        }
        if($bet===null){self::diag('ALL_CONFIGURED_PROVIDERS_FAILED');return null;}
        // Keep the underlying extraction untouched. Only card-mode presentation
        // suppresses tip-send time, bookmaker and receipt amounts. Text and
        // original-image caption modes continue to behave exactly as before.
        $presentedBet=$mode==='card'?self::cardView($bet):$bet;
        if($mode==='card'){
            // Privacy-safe diagnostics: never log message content, competition,
            // extracted text, dates, channel handles or bookmaker details.
            $sportBefore=mb_strtolower(trim((string)($bet['sport']??'')),'UTF-8');
            error_log('TMR_SMART_CARD_BADGES '.json_encode([
                'sport_input_generic'=>in_array($sportBefore,['','esporte','esportes','sport','sports',
                    'desconhecido','unknown','não identificado','nao identificado','n/a','-'],true),
                'sport_resolved'=>!in_array(mb_strtolower(trim((string)($presentedBet['sport']??'')),'UTF-8'),
                    ['','esporte','esportes','sport','sports','desconhecido','unknown','n/a','-'],true),
                'competition_present'=>trim((string)($bet['league']??''))!=='',
                'status_present'=>trim((string)($bet['status']??''))!=='',
                'live_badge'=>($presentedBet['status']??'')==='AO VIVO'
            ]));
        }
        $text=self::asText($presentedBet,$translate);
        $image=null;
        if($mode==='card') {
            // A long analysis can continue after the visual card. Keep all original details.
            // Reject only if the full text cannot fit ONE caption plus ONE continuation.
            $totalUnits=(int)(strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8'))/2);
            $continuationOverhead=(int)(strlen(mb_convert_encoding("↪️ Continuação da mensagem:\n\n".self::signature(),'UTF-16LE','UTF-8'))/2);
            if($totalUnits>1024+4096-$continuationOverhead){self::diag('CARD_TEXT_EXCEEDS_SINGLE_CONTINUATION');return null;}
            $image=VipCardRenderer::render($presentedBet);
            if($image===null){self::diag('CARD_RENDER_FAILED');return null;} // Preserve original on rendering failure.
        }
        return ['caption'=>$text,'image'=>$image,'mode'=>$mode];
    }
    /**
     * The existing translation service owns provider selection and fallback.
     * Never substitute a different provider silently; unsupported translation-
     * only providers are skipped only when a configured compatible fallback exists.
     * @return list<string>
     */
    public static function cardProviders(array $rule): array
    {
        // Provider order applies even when translation is OFF. Formatting and
        // interpreting a tip are distinct from whether to translate its text.
        [$primary,$fallback]=TranslationService::resolveProviders(
            (string)($rule['translation_provider']??''));
        $providers=[];
        foreach([$primary,$fallback] as $provider){
            if(is_string($provider)&&$provider!==''&&$provider!=='none'&&!in_array($provider,$providers,true)){
                $providers[]=$provider;
            }
        }
        return $providers;
    }
    private static function containsAnalysis(string $text): bool
    {
        return mb_strlen(trim($text),'UTF-8')>=180;
    }
    /**
     * Workers AI may use a text model for text-only tips; when a receipt image
     * is attached a separate Cloudflare vision model must inspect its contents.
     * The isolated REST transport never logs source text, images or credentials.
     * @param list<string> $fields
     */
    private static function requestWorkers(string $text,?string $image,string $language,array $fields): ?array
    {
        $account=WorkersAITranslation::account();
        $token=WorkersAITranslation::token();
        if($account===''||$token===''){self::diag('WORKERS_AI_CREDENTIALS_MISSING');return null;}
        $hasImage=$image!==null&&is_file($image)&&filesize($image)>0;
        // The integration model now defaults to Llama 3.2 Vision for BOTH
        // translation and smart-card extraction. Preserve custom text models
        // for text tips; receipt images still require a vision-capable model.
        $configuredModel=WorkersAITranslation::model();
        $model=$hasImage && $configuredModel!==WorkersAITranslation::VISION_MODEL
            ?WorkersAITranslation::VISION_MODEL:$configuredModel;
        if(!WorkersAITranslation::validModel($model)){self::diag('WORKERS_AI_MODEL_INVALID');return null;}
        $prompt="Interprete tip de aposta a partir do TEXTO ORIGINAL e comprovante opcional. ".
            "Responda SOMENTE um objeto JSON válido, sem markdown, com cada chave string: ".implode(', ',$fields).". ".
            "Extraia apenas fatos explícitos, desconhecido = string vazia. Não invente mercado, seleção, odd, partida ou status. ".
            "Preserve toda a análise original (exceto publicidade e links), sem resumir ou alterar o sentido. ".
            "Status AO VIVO somente se a PARTIDA estiver explicitamente em andamento; bilhete aberto não basta. ".
            "Identifique esporte e campeonato quando inequívocos; se ausente deixe vazio. ".
            "Traduza todos os campos de texto e a análise quando solicitado; jamais repita o original separadamente. ".
            $language." TEXTO ORIGINAL:\n".$text;
        $photo=null;
        if($hasImage){
            $mime=mime_content_type($image)?:'';
            $size=filesize($image);
            if($size>4*1024*1024){self::diag('WORKERS_AI_IMAGE_TOO_LARGE');return null;}
            if(!in_array($mime,['image/png','image/jpeg','image/webp'],true)){
                self::diag('WORKERS_AI_IMAGE_UNSUPPORTED');return null;
            }
            $bytes=file_get_contents($image);
            if(!is_string($bytes)){self::diag('WORKERS_AI_IMAGE_UNREADABLE');return null;}
            $photo='data:'.$mime.';base64,'.base64_encode($bytes);
        }
        $transport=dirname(__DIR__).'/scripts/smart-workers-isolated.php';
        if(!function_exists('proc_open')||!is_file($transport)){
            self::diag('WORKERS_AI_TRANSPORT_MISSING');return null;
        }
        $input=json_encode(['account'=>$account,'token'=>$token,'model'=>$model,
            'prompt'=>$prompt,'image'=>$photo],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if(!is_string($input)){self::diag('WORKERS_AI_INPUT_ERROR');return null;}
        $pipes=[];$process=@proc_open(['php',$transport],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],
            $pipes,dirname(__DIR__));
        if(!is_resource($process)){self::diag('WORKERS_AI_PROCESS_UNAVAILABLE');return null;}
        $output='';$exit=-1;
        try{
            $offset=0;$length=strlen($input);
            while($offset<$length){
                $bytesWritten=@fwrite($pipes[0],substr($input,$offset,65536));
                if($bytesWritten===false||$bytesWritten===0)break;
                $offset+=$bytesWritten;
            }
            fclose($pipes[0]);unset($pipes[0]);
            if($offset===$length){
                $response=@stream_get_contents($pipes[1],450000);
                if(is_string($response))$output=$response;
            }
            fclose($pipes[1]);unset($pipes[1]);
        } finally {
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            $exit=proc_close($process);
        }
        $result=json_decode($output,true);
        if($exit!==0||!is_array($result)||empty($result['ok'])||!is_array($result['data']??null)){
            $reason=(string)($result['reason']??'PROCESS_FAILED');
            if(!preg_match('/^[A-Z0-9_]{1,40}$/D',$reason))$reason='PROCESS_FAILED';
            self::diag('WORKERS_AI_'.$reason);
            return null;
        }
        return $result['data'];
    }
    /** @param list<string> $fields */
    private static function request(string $key,string $text,?string $image,string $language,array $fields): ?array
    {
        if(!function_exists('curl_init')){self::diag('CURL_EXTENSION_MISSING');return null;}
        $model=trim(Repository::integration('gemini_model'))?:'gemini-2.5-flash';
        if(!preg_match('/^[A-Za-z0-9_.-]+$/D',$model))return null;
        $endpoint='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';
        $prompt="Você interpreta dicas de apostas, SEM CRIAR OU ALTERAR DADOS. ".
            "Responda somente com um objeto JSON, com todas estas chaves string: ".implode(', ',$fields).". ".
            "Leia o texto e a imagem (se presente). Apenas dados explícitos; desconhecido = string vazia. ".
            "Diferencie stake sugerida do valor real do bilhete e aposta ao vivo de pré-jogo. ".
            "Retorne status exatamente AO VIVO quando o TEXTO OU IMAGEM indicar explicitamente PARTIDA em andamento, live/in-play, ao vivo, en vivo, en directo ou jogo em curso. ".
            "Nao use AO VIVO apenas porque o bilhete esta aberto (ex.: En curso na area de aposta pode ser somente ticket nao liquidado). ".
            "Se partida ao vivo nao estiver comprovada, mantenha o status descritivo ou vazio, sem adivinhar. ".
            "Identifique o esporte específico quando explícito ou inequívoco pelo confronto e campeonato (ex.: La Liga = futebol). Não use o valor genérico esporte se houver evidência clara. ".
            "Se identificar moeda, preserve seu símbolo original no valor apostado e retorno. ".
            "Não transforme horário em outro fuso nem complete data ausente. ".
            "O campo analysis deve preservar integralmente o conteúdo analítico relevante do autor, ".
            "sem resumir fatos, sem publicidade, links ou dados inventados. ".
            "Omitir analysis é permitido SOMENTE quando não há análise de fato. ".
            "Não mencione o nome do roteador no JSON. ".$language." ".
            "TEXTO ORIGINAL:\n".$text;
        $parts=[['text'=>$prompt]];
        if($image!==null&&is_file($image)&&filesize($image)>0&&filesize($image)<=4*1024*1024){
            $mime=mime_content_type($image)?:'';
            if(in_array($mime,['image/jpeg','image/png','image/webp'],true)){
                $bytes=file_get_contents($image);
                if(is_string($bytes))$parts[]=['inline_data'=>['mime_type'=>$mime,'data'=>base64_encode($bytes)]];
            }
        }
        $payload=json_encode([
            'contents'=>[['role'=>'user','parts'=>$parts]],
            'generationConfig'=>['temperature'=>0,'responseMimeType'=>'application/json','maxOutputTokens'=>2500]
        ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if($payload===false)return null;
        // MadelineProto forwards events from inside its event loop. A blocking
        // curl_exec in that context can throw before any Gemini response arrives.
        // Run the same Gemini request in an isolated CLI process, passing
        // credentials via stdin (never command arguments or logs).
        if(!function_exists('proc_open')){self::diag('PROCESS_EXTENSION_MISSING');return null;}
        $transport=dirname(__DIR__).'/scripts/smart-gemini-isolated.php';
        if(!is_file($transport)){self::diag('ISOLATED_TRANSPORT_MISSING');return null;}
        $input=json_encode(['model'=>$model,'key'=>$key,'payload'=>$payload],
            JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if(!is_string($input))return null;
        $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']];
        $process=@proc_open(['php',$transport],$spec,$pipes,dirname(__DIR__));
        if(!is_resource($process)){self::diag('ISOLATED_PROCESS_START_FAILED');return null;}
        $output='';
        try {
            $offset=0;$length=strlen($input);
            while($offset<$length){
                $written=fwrite($pipes[0],substr($input,$offset,65536));
                if($written===false||$written===0)break;
                $offset+=$written;
            }
            fclose($pipes[0]);unset($pipes[0]);
            if($offset===$length){
                // Child's HTTP timeout is 20s, and it outputs at most 400KB
                // of Gemini content. Never echo API payloads to the worker log.
                $read=stream_get_contents($pipes[1],450000);
                if(is_string($read))$output=$read;
            }
            fclose($pipes[1]);unset($pipes[1]);
        } finally {
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            $exit=proc_close($process);
        }
        if($exit!==0||$output===''){self::diag('ISOLATED_PROCESS_EMPTY_OR_ERROR');return null;}
        $response=json_decode($output,true);
        if(empty($response['ok'])||!isset($response['data'])||!is_array($response['data'])){
            $reason=(string)($response['reason']??'UNCLASSIFIED');
            if(!preg_match('/^[A-Z0-9_]{1,48}$/D',$reason))$reason='UNCLASSIFIED';
            self::diag('GEMINI_'. $reason);
            return null;
        }
        return $response['data'];
    }
    /** Card-mode only. Never alter the extracted source or the original analysis. */
    public static function cardView(array $bet): array
    {
        foreach(['time','day','bookmaker','stake_amount','potential_return','potential_profit'] as $key){
            unset($bet[$key]);
        }
        // Sport can be absent from a valid tip. Only infer it from a clearly
        // football-specific competition, never from a generic match/odds.
        // Gemini sometimes fills the generic word "Esporte" instead of a
        // specific sport. Treat it as missing, but preserve any named sport.
        $rawSport=mb_strtolower(trim((string)($bet['sport']??'')),'UTF-8');
        $genericSport=in_array($rawSport,['','esporte','esportes','sport','sports',
            'desconhecido','unknown','não identificado','nao identificado','n/a','-'],true);
        if($genericSport){
            $league=mb_strtolower(trim((string)($bet['league']??'')),'UTF-8');
            if(preg_match('/\\b(la liga|laliga|uefa|champions league|europa league|libertadores|copa do brasil|premier league|bundesliga)\\b/u',$league)){
                $bet['sport']='Futebol';
            } elseif(preg_match('/\\b(espa(nha|ña)|spain|espana|espanha)\\b/u',$league)
                     && preg_match('/\\b(primera divisi[oó]n|primeira divis[aã]o|first division)\\b/u',$league)){
                $bet['sport']='Futebol';
            }
            // Never force football if the competition does not identify it.
            // The renderer will keep a neutral generic badge in that case.
        }
        // Canonicalize supported explicit "match live" phrases only in card
        // mode. Raw extraction, caption mode and original source are untouched.
        $status=mb_strtolower(trim((string)($bet['status']??'')),'UTF-8');
        $explicitLive=in_array($status,[
            'ao vivo','live','livebet','in play','in-play','em jogo',
            'partida em andamento','jogo em andamento','em andamento',
            'en vivo','en directo','partido en curso','match live',
            'match in progress','en direct'
        ],true);
        if($explicitLive)$bet['status']='AO VIVO';
        return $bet;
    }
    public static function asText(array $bet,bool $translated): string
    {
        $label=static fn(string $pt,string $en): string=>$translated?$pt:$en;
        $lines=['⚽ '.($bet['match']??'')];
        if(!empty($bet['league']))$lines[]='🏆 '.$bet['league'];
        if(($bet['status']??'')==='AO VIVO')$lines[]='🔴 AO VIVO';
        $lines[]='';
        $lines[]='🎯 '.$label('Mercado','Market').': '.($bet['market']??'');
        $lines[]='✅ '.$label('Seleção','Selection').': '.($bet['selection']??'');
        foreach(['odd'=>['📈','Odd','Odd'],'time'=>['🕒','Horário','Time'],
          'day'=>['📅','Dia','Day'],'stake'=>['📍','Stake','Stake'],
          'bookmaker'=>['🏦','Casa de apostas','Bookmaker'],
          'stake_amount'=>['💶','Valor apostado','Amount staked'],
          'potential_return'=>['💰','Retorno potencial','Potential return'],
          'potential_profit'=>['💵','Lucro potencial','Potential profit']] as $field=>$labels){
            if(!empty($bet[$field]))$lines[]=$labels[0].' '.$label($labels[1],$labels[2]).': '.$bet[$field];
        }
        if(!empty($bet['analysis'])){$lines[]='';$lines[]='📝 '.$label('Análise original','Original analysis').':';$lines[]=$bet['analysis'];}
        return implode("\n",$lines);
    }
    /**
     * No implicit currency conversion; amounts are parsed to integer cents.
     * Missing or contradictory money values leave the profit field absent.
     */
    public static function calculatePotentialProfit(string $stake,string $return): string
    {
        $a=self::moneyParts($stake);$b=self::moneyParts($return);
        if($a===null||$b===null||$a['currency']!==$b['currency']||$a['cents']<=0||$b['cents']<$a['cents'])return '';
        $cents=$b['cents']-$a['cents'];
        $amount=number_format(intdiv($cents,100),0,',','.').','.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
        return $a['currency']==='R$'?'R$ '.$amount:$amount.' '.$a['currency'];
    }
    /** @return array{cents:int,currency:string}|null */
    private static function moneyParts(string $raw): ?array
    {
        $raw=trim(str_replace("\xC2\xA0",' ',$raw));
        if(!preg_match('/^(R\\$|€|£|\\$|[A-Z]{3})?\\s*([0-9][0-9., ]*)\\s*(R\\$|€|£|\\$|[A-Z]{3})?$/uD',$raw,$m))return null;
        $currency=($m[1]??'')?:($m[3]??'');
        if($currency===''||(!empty($m[1])&&!empty($m[3])&&$m[1]!==$m[3]))return null;
        $value=str_replace(' ','',$m[2]);
        if(!preg_match('/^([0-9][0-9.,]*?)(?:([,.])([0-9]{1,2}))?$/D',$value,$parts))return null;
        $whole=$parts[1];$fraction=$parts[3]??'';
        $group=($parts[2]??'')===','?'.':',';
        if(str_contains($whole,',')||str_contains($whole,'.')){
            if(!preg_match('/^[0-9]{1,3}(?:'.preg_quote($group,'/').'[0-9]{3})+$/D',$whole))return null;
            $whole=str_replace($group,'',$whole);
        }
        if(!ctype_digit($whole)||strlen($whole)>14)return null;
        $cents=(int)$whole*100+(int)str_pad($fraction,2,'0');
        return ['cents'=>$cents,'currency'=>$currency];
    }
    public static function signature(): string
    {
        return "\n\n━━━━━━━━━━━━\n".self::SIGNATURE;
    }
}
