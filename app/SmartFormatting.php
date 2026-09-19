<?php declare(strict_types=1);
namespace App;

/**
 * Isolated, opt-in AI formatting. Existing forwarding is the only fallback.
 * Never writes raw tips, photos or API keys to application logs.
 */
final class SmartFormatting
{
    private const SIGNATURE='⚡ TelegramRouter • Aposta encaminhada';
    private static function diag(string $code): void {error_log('TMR_SMART_FORMAT_REASON '.$code);}

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
     * $localImage must be a temporary file downloaded from the received Telegram message.
     * @return array{caption:string,image:?string,mode:string}|null
     */
    public static function prepare(string $sourceText,array $rule,?string $localImage,string $mode): ?array
    {
        $key=trim(Repository::integration('gemini_api_key'));
        if($key===''){self::diag('GEMINI_KEY_MISSING');return null;}
        if(trim($sourceText)===''&&($localImage===null||!is_file($localImage))){self::diag('SOURCE_EMPTY');return null;}
        $target=trim((string)($rule['translation_target_language']??'pt-BR'))?:'pt-BR';
        $translate=!empty($rule['translation_enabled']);
        $inputLanguage=$translate?'Use o idioma '.$target.' em TODO o texto, inclusive a análise.':'Use o idioma da mensagem original. Não traduza.';
        $fields=['sport','status','match','league','market','selection','odd','time','day','stake','bookmaker','stake_amount','potential_return','analysis'];
        $json=self::request($key,$sourceText,$localImage,$inputLanguage,$fields);
        if(!$json){self::diag('GEMINI_RESPONSE_UNAVAILABLE');return null;}
        $bet=[];
        foreach($fields as $field)$bet[$field]=trim((string)($json[$field]??''));
        // Compute profit deterministically from explicit, matching currencies.
        $bet['potential_profit']=self::calculatePotentialProfit($bet['stake_amount'],$bet['potential_return']);
        if($bet['selection']===''||$bet['market']===''||$bet['match']===''){self::diag('REQUIRED_FIELDS_INCOMPLETE');return null;}
        if($bet['analysis']==='' && self::containsAnalysis($sourceText)){self::diag('ANALYSIS_ABSENT');return null;}
        $text=self::asText($bet,$translate);
        $image=null;
        if($mode==='card') {
            // A long analysis can continue after the visual card. Keep all original details.
            // Reject only if the full text cannot fit ONE caption plus ONE continuation.
            $totalUnits=(int)(strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8'))/2);
            $continuationOverhead=(int)(strlen(mb_convert_encoding("↪️ Continuação da mensagem:\n\n".self::signature(),'UTF-16LE','UTF-8'))/2);
            if($totalUnits>1024+4096-$continuationOverhead){self::diag('CARD_TEXT_EXCEEDS_SINGLE_CONTINUATION');return null;}
            $image=VipCardRenderer::render($bet);
            if($image===null){self::diag('CARD_RENDER_FAILED');return null;} // Preserve original on rendering failure.
        }
        return ['caption'=>$text,'image'=>$image,'mode'=>$mode];
    }
    private static function containsAnalysis(string $text): bool
    {
        return mb_strlen(trim($text),'UTF-8')>=180;
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
            "Status AO VIVO somente se a partida estiver explicitamente acontecendo; bilhete En curso sozinho pode significar aposta em aberto. ".
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
        $raw=trim(str_replace("\\xc2\\xa0",' ',$raw));
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
