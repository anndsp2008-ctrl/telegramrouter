<?php declare(strict_types=1);
namespace App;

/**
 * Optional text-only translation provider. No change to the Gemini card engine.
 * API credentials are read from the existing encrypted integration store, with
 * an optional Railway secret fallback for the initial setup.
 */
final class WorkersAITranslation
{
    public const DEFAULT_MODEL='@cf/meta/llama-3.1-8b-instruct-fp8';

    public static function token(array $overrides=[]): string
    {
        $value=trim((string)($overrides['workers_ai_api_token']??''));
        if($value!=='')return $value;
        $stored=trim(Repository::integration('workers_ai_api_token'));
        return $stored!==''?$stored:trim((string)(getenv('WORKERS_AI_API_TOKEN')?:''));
    }
    public static function account(array $overrides=[]): string
    {
        $value=trim((string)($overrides['workers_ai_account_id']??''));
        if($value!=='')return $value;
        $stored=trim(Repository::integration('workers_ai_account_id'));
        return $stored!==''?$stored:trim((string)(getenv('WORKERS_AI_ACCOUNT_ID')?:''));
    }
    public static function model(array $overrides=[]): string
    {
        $value=trim((string)($overrides['workers_ai_model']??''));
        return $value!==''?$value:(trim(Repository::integration('workers_ai_model'))?:self::DEFAULT_MODEL);
    }
    public static function validModel(string $value): bool
    {
        return (bool)preg_match('~^@cf/[A-Za-z0-9._-]+/[A-Za-z0-9._-]{2,100}$~D',$value);
    }
    /** @return array{provider:string,success:bool,text:string,latency_ms:int,http_code:?int,source_language:?string,target_language:string,error_text:?string,fallback_used:bool} */
    public static function attempt(string $text,string $target,bool $fallback,array $context=[]): array
    {
        $overrides=(array)($context['overrides']??[]);
        $account=self::account($overrides);
        $token=self::token($overrides);
        $model=self::model($overrides);
        if($account===''||$token===''){
            throw self::failed(0,null,$target,$fallback,'Configure o token e o ID da conta do Workers AI.');
        }
        if(!preg_match('/^[a-f0-9]{32}$/Di',$account)||!self::validModel($model)){
            throw self::failed(0,null,$target,$fallback,'ID da conta ou nome do modelo Workers AI inválido.');
        }
        $target=trim($target);
        if(!preg_match('/^[a-z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/D',$target)){
            throw self::failed(0,null,$target,$fallback,'Idioma de destino inválido.');
        }
        $worker=dirname(__DIR__).'/scripts/workers-ai-request.php';
        if(!is_file($worker)||!function_exists('proc_open')){
            throw self::failed(0,null,$target,$fallback,'Transporte Workers AI indisponível.');
        }
        $input=json_encode([
            'account'=>$account,'token'=>$token,'model'=>$model,
            'text'=>$text,'target'=>$target
        ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if(!is_string($input))throw self::failed(0,null,$target,$fallback,'Não foi possível preparar a tradução.');
        $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']];
        $start=hrtime(true);
        $process=@proc_open(['php',$worker],$spec,$pipes,dirname(__DIR__));
        if(!is_resource($process))throw self::failed(0,null,$target,$fallback,'Processo Workers AI indisponível.');
        $output='';$exit=-1;
        try{
            $offset=0;$size=strlen($input);
            while($offset<$size){
                $sent=@fwrite($pipes[0],substr($input,$offset,65536));
                if($sent===false||$sent===0)break;
                $offset+=$sent;
            }
            fclose($pipes[0]);unset($pipes[0]);
            if($offset===$size){
                $data=@stream_get_contents($pipes[1],150000);
                if(is_string($data))$output=$data;
            }
            fclose($pipes[1]);unset($pipes[1]);
        } finally {
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            $exit=proc_close($process);
        }
        $latency=max(0,(int)round((hrtime(true)-$start)/1000000));
        $result=json_decode($output,true);
        $http=is_array($result)&&isset($result['http'])?(int)$result['http']:null;
        if($exit!==0||!is_array($result)||empty($result['ok'])){
            $code=is_array($result)?(string)($result['reason']??'UNAVAILABLE'):'UNAVAILABLE';
            $allowed=['AUTH','PERMISSION','RATE_LIMIT','HTTP_ERROR','INVALID_RESPONSE','NETWORK','TIMEOUT','INPUT_ERROR','UNAVAILABLE'];
            if(!in_array($code,$allowed,true))$code='UNAVAILABLE';
            throw self::failed($latency,$http,$target,$fallback,'Workers AI: '.$code.'.');
        }
        $answer=trim((string)($result['text']??''));
        if($answer==='')throw self::failed($latency,$http,$target,$fallback,'Workers AI: resposta vazia.');
        // The ordinary translation test checks a TEXT model only. The smart
        // card with a receipt uses a DIFFERENT Vision model, which can return
        // 403 for missing Meta license even with a valid token. Probe it only
        // for the explicit Testar conexão action, never during regular tips.
        if(($context['context']??'')==='test'){
            $vision=self::probeVision($account,$token);
            if(!$vision['ok']){
                $reason=(string)$vision['reason'];
                $message=match($reason){
                    'MODEL_LICENSE_REQUIRED_5016'=>'Modelo de imagens: aceite os termos do Llama 3.2 Vision na Cloudflare (código 5016). Trocar o token não resolve este bloqueio.',
                    'WORKERS_PAID_REQUIRED_5035'=>'Modelo de imagens: sua conta precisa de um plano Workers Paid (código 5035).',
                    'HTTP_401_UNAUTHORIZED'=>'Modelo de imagens: HTTP 401. Confira token, permissões e ID da conta.',
                    default=>'Modelo de imagens indisponível: '.$reason.'. Confira o acesso ao modelo Vision na Cloudflare.'
                };
                throw self::failed($latency,$vision['http'],$target,$fallback,$message);
            }
        }
        return [
            'provider'=>'workers_ai','success'=>true,'text'=>$answer,
            'latency_ms'=>$latency,'http_code'=>$http,'source_language'=>null,
            'target_language'=>$target,'error_text'=>null,'fallback_used'=>$fallback
        ];
    }
    /**
     * Non-mutating model readiness test. This does not send the "agree" prompt,
     * accept third-party license terms, or include real user messages/photos.
     * Credentials are passed via STDIN and no upstream body is exposed.
     * @return array{ok:bool,reason:string,http:?int}
     */
    private static function probeVision(string $account,string $token): array
    {
        $script=dirname(__DIR__).'/scripts/smart-workers-isolated.php';
        if(!is_file($script)||!function_exists('proc_open'))
            return ['ok'=>false,'reason'=>'PROBE_UNAVAILABLE','http'=>null];
        $input=json_encode([
            'account'=>$account,'token'=>$token,
            'model'=>'@cf/meta/llama-3.2-11b-vision-instruct',
            'prompt'=>'Responda somente OK.','image'=>null,'check_only'=>true
        ],JSON_UNESCAPED_UNICODE);
        if(!is_string($input))
            return ['ok'=>false,'reason'=>'PROBE_INPUT_INVALID','http'=>null];
        $pipes=[];
        $process=@proc_open(['php',$script],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],
            $pipes,dirname(__DIR__));
        if(!is_resource($process))
            return ['ok'=>false,'reason'=>'PROBE_UNAVAILABLE','http'=>null];
        $output='';$exit=-1;
        try {
            $sent=@fwrite($pipes[0],$input);
            fclose($pipes[0]);unset($pipes[0]);
            if($sent===strlen($input)){
                $response=@stream_get_contents($pipes[1],12000);
                if(is_string($response))$output=$response;
            }
            fclose($pipes[1]);unset($pipes[1]);
        } finally {
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            $exit=proc_close($process);
        }
        $result=json_decode($output,true);
        if($exit!==0||!is_array($result))
            return ['ok'=>false,'reason'=>'PROBE_UNAVAILABLE','http'=>null];
        $reason=(string)($result['reason']??'PROBE_UNAVAILABLE');
        if(!preg_match('/^[A-Z0-9_]{1,60}$/D',$reason))$reason='PROBE_UNAVAILABLE';
        $http=isset($result['http'])?(int)$result['http']:null;
        return ['ok'=>!empty($result['ok']),'reason'=>$reason,'http'=>$http];
    }
    private static function failed(int $ms,?int $http,string $target,bool $fallback,string $message): TranslationFailureException
    {
        $attempt=[
            'provider'=>'workers_ai','success'=>false,'text'=>'',
            'latency_ms'=>$ms,'http_code'=>$http,'source_language'=>null,
            'target_language'=>$target,'error_text'=>$message,'fallback_used'=>$fallback
        ];
        return new TranslationFailureException($message,[$attempt]);
    }
}
