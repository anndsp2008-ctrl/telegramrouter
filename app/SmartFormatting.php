<?php declare(strict_types=1);
namespace App;

/**
 * Isolated, opt-in AI formatting. Existing forwarding is the only fallback.
 * Never writes raw tips, photos or API keys to application logs.
 */
final class SmartFormatting
{
    private const SIGNATURE='⚡ TelegramRouter • Aposta encaminhada';

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
        if($key==='')return null;
        if(trim($sourceText)===''&&($localImage===null||!is_file($localImage)))return null;
        $target=trim((string)($rule['translation_target_language']??'pt-BR'))?:'pt-BR';
        $translate=!empty($rule['translation_enabled']);
        $inputLanguage=$translate?'Use o idioma '.$target.' em TODO o texto, inclusive a análise.':'Use o idioma da mensagem original. Não traduza.';
        $fields=['sport','status','match','league','market','selection','odd','time','day','stake','bookmaker','stake_amount','potential_return','analysis'];
        $json=self::request($key,$sourceText,$localImage,$inputLanguage,$fields);
        if(!$json)return null;
        $bet=[];
        foreach($fields as $field)$bet[$field]=trim((string)($json[$field]??''));
        if($bet['selection']===''||$bet['market']===''||$bet['match']==='')return null;
        if($bet['analysis']==='' && self::containsAnalysis($sourceText))return null;
        $text=self::asText($bet,$translate);
        $image=null;
        if($mode==='card') {
            // Keep the complete formatted analysis in the card caption; never silently shorten it.
            // If it exceeds Telegram's media-caption limit, keep the original delivery unchanged.
            $captionUnits=(int)(strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8'))/2);
            if($captionUnits>1024)return null;
            $image=VipCardRenderer::render($bet);
            if($image===null)return null; // No renderer: preserve original routing instead of a partial card.
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
        if(!function_exists('curl_init'))return null;
        $model=trim(Repository::integration('gemini_model'))?:'gemini-2.5-flash';
        if(!preg_match('/^[A-Za-z0-9_.-]+$/D',$model))return null;
        $endpoint='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';
        $prompt="Você interpreta dicas de apostas, SEM CRIAR OU ALTERAR DADOS. ".
            "Responda somente com um objeto JSON, com todas estas chaves string: ".implode(', ',$fields).". ".
            "Leia o texto e a imagem (se presente). Apenas dados explícitos; desconhecido = string vazia. ".
            "Diferencie stake sugerida do valor real do bilhete e aposta ao vivo de pré-jogo. ".
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
        $ch=curl_init($endpoint);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],
            CURLOPT_CONNECTTIMEOUT=>4,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0
        ]);
        $result=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($http!==200||!is_string($result)||strlen($result)>400000)return null;
        $response=json_decode($result,true);
        $body=$response['candidates'][0]['content']['parts'][0]['text']??null;
        if(!is_string($body))return null;
        $json=json_decode($body,true);
        return is_array($json)?$json:null;
    }
    public static function asText(array $bet,bool $translated): string
    {
        $label=static fn(string $pt,string $en): string=>$translated?$pt:$en;
        $lines=['⚽ '.($bet['match']??'')];
        if(!empty($bet['league']))$lines[]='🏆 '.$bet['league'];
        $lines[]='';
        $lines[]='🎯 '.$label('Mercado','Market').': '.($bet['market']??'');
        $lines[]='✅ '.$label('Seleção','Selection').': '.($bet['selection']??'');
        foreach(['odd'=>['📈','Odd','Odd'],'time'=>['🕒','Horário','Time'],
          'day'=>['📅','Dia','Day'],'stake'=>['📍','Stake','Stake'],
          'bookmaker'=>['🏦','Casa de apostas','Bookmaker'],
          'stake_amount'=>['💶','Valor apostado','Amount staked'],
          'potential_return'=>['💰','Retorno potencial','Potential return']] as $field=>$labels){
            if(!empty($bet[$field]))$lines[]=$labels[0].' '.$label($labels[1],$labels[2]).': '.$bet[$field];
        }
        if(!empty($bet['analysis'])){$lines[]='';$lines[]='📝 '.$label('Análise original','Original analysis').':';$lines[]=$bet['analysis'];}
        return implode("\n",$lines);
    }
    public static function signature(): string
    {
        return "\n\n━━━━━━━━━━━━\n".self::SIGNATURE;
    }
}
