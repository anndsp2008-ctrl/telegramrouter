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
            return self::failed(0,null,$target,$fallback,'Configure o token e o ID da conta do Workers AI.');
        }
        if(!preg_match('/^[a-f0-9]{32}$/Di',$account)||!self::validModel($model)){
            return self::failed(0,null,$target,$fallback,'ID da conta ou nome do modelo Workers AI inválido.');
        }
        $target=trim($target);
        if(!preg_match('/^[a-z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/D',$target)){
            return self::failed(0,null,$target,$fallback,'Idioma de destino inválido.');
        }
        $worker=dirname(__DIR__).'/scripts/workers-ai-request.php';
        if(!is_file($worker)||!function_exists('proc_open')){
            return self::failed(0,null,$target,$fallback,'Transporte Workers AI indisponível.');
        }
        $input=json_encode([
            'account'=>$account,'token'=>$token,'model'=>$model,
            'text'=>$text,'target'=>$target
        ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if(!is_string($input))return self::failed(0,null,$target,$fallback,'Não foi possível preparar a tradução.');
        $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']];
        $start=hrtime(true);
        $process=@proc_open(['php',$worker],$spec,$pipes,dirname(__DIR__));
        if(!is_resource($process))return self::failed(0,null,$target,$fallback,'Processo Workers AI indisponível.');
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
            return self::failed($latency,$http,$target,$fallback,'Workers AI: '.$code.'.');
        }
        $answer=trim((string)($result['text']??''));
        if($answer==='')return self::failed($latency,$http,$target,$fallback,'Workers AI: resposta vazia.');
        return [
            'provider'=>'workers_ai','success'=>true,'text'=>$answer,
            'latency_ms'=>$latency,'http_code'=>$http,'source_language'=>null,
            'target_language'=>$target,'error_text'=>null,'fallback_used'=>$fallback
        ];
    }
    private static function failed(int $ms,?int $http,string $target,bool $fallback,string $message): array
    {
        return [
            'provider'=>'workers_ai','success'=>false,'text'=>'',
            'latency_ms'=>$ms,'http_code'=>$http,'source_language'=>null,
            'target_language'=>$target,'error_text'=>$message,'fallback_used'=>$fallback
        ];
    }
}
