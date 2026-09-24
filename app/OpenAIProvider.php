<?php declare(strict_types=1);

namespace App;

final class OpenAIProvider
{
    private const ENDPOINT='https://api.openai.com/v1/responses';
    private const MODELS=['gpt-5.6-luna','gpt-5.6-terra','gpt-5.6-sol'];

    /** @return list<string> */
    public static function models(): array
    {
        return self::MODELS;
    }

    public static function validModel(string $model): bool
    {
        return in_array($model,self::MODELS,true);
    }

    public static function enabled(): bool
    {
        return Repository::integration('openai_enabled')==='1';
    }

    public static function apiKey(): string
    {
        return trim(Repository::integration('openai_api_key'));
    }

    public static function primaryModel(): string
    {
        $model=trim(Repository::integration('openai_model'));
        return self::validModel($model)?$model:'gpt-5.6-luna';
    }

    public static function fallbackEnabled(): bool
    {
        $raw=Repository::integration('openai_fallback_enabled');
        return $raw==='' || $raw==='1';
    }

    public static function fallbackModel(): string
    {
        $model=trim(Repository::integration('openai_fallback_model'));
        return self::validModel($model)?$model:'gpt-5.6-terra';
    }

    /** @return array{ok:bool,data?:array<string,mixed>,text?:string,model:string,fallback_used:bool,latency_ms:int,http_code:?int,reason?:string} */
    public static function structured(string $prompt,?string $image=null,?string $keyOverride=null,?string $modelOverride=null): array
    {
        $key=trim((string)($keyOverride??self::apiKey()));
        $primary=trim((string)($modelOverride??self::primaryModel()));
        if(!self::validModel($primary))$primary=self::primaryModel();

        $models=[$primary];
        if($modelOverride===null && self::fallbackEnabled()){
            $fallback=self::fallbackModel();
            if($fallback!==$primary)$models[]=$fallback;
        }

        $last=[
            'ok'=>false,'model'=>$primary,'fallback_used'=>false,'latency_ms'=>0,
            'http_code'=>null,'reason'=>'OPENAI_UNAVAILABLE'
        ];
        foreach($models as $index=>$model){
            $attempt=self::request($key,$model,$prompt,$image,true);
            $attempt['fallback_used']=$index>0;
            $last=$attempt;
            if(!empty($attempt['ok']) && isset($attempt['text'])){
                $decoded=json_decode((string)$attempt['text'],true);
                if(is_array($decoded)){
                    $attempt['data']=$decoded;
                    return $attempt;
                }
                $attempt['ok']=false;
                $attempt['reason']='OPENAI_RESPONSE_NOT_JSON';
                $last=$attempt;
            }
        }
        return $last;
    }

    /** @return array{ok:bool,text?:string,model:string,fallback_used:bool,latency_ms:int,http_code:?int,reason?:string} */
    public static function translate(string $text,string $target,?string $keyOverride=null,?string $modelOverride=null): array
    {
        $prompt="Traduza fielmente o texto a seguir para {$target}. Preserve nomes próprios, números, odds, símbolos e quebras de linha quando possível. Não acrescente explicações. Responda somente com a tradução.\n\nTEXTO:\n".$text;

        $key=trim((string)($keyOverride??self::apiKey()));
        $primary=trim((string)($modelOverride??self::primaryModel()));
        if(!self::validModel($primary))$primary=self::primaryModel();

        $models=[$primary];
        if($modelOverride===null && self::fallbackEnabled()){
            $fallback=self::fallbackModel();
            if($fallback!==$primary)$models[]=$fallback;
        }

        $last=[
            'ok'=>false,'model'=>$primary,'fallback_used'=>false,'latency_ms'=>0,
            'http_code'=>null,'reason'=>'OPENAI_UNAVAILABLE'
        ];
        foreach($models as $index=>$model){
            $attempt=self::request($key,$model,$prompt,null,false);
            $attempt['fallback_used']=$index>0;
            $last=$attempt;
            if(!empty($attempt['ok']) && trim((string)($attempt['text']??''))!=='')return $attempt;
        }
        return $last;
    }

    /** @return array{ok:bool,text?:string,model:string,fallback_used:bool,latency_ms:int,http_code:?int,reason?:string} */
    public static function test(?string $keyOverride=null,?string $modelOverride=null): array
    {
        $key=trim((string)($keyOverride??self::apiKey()));
        $model=trim((string)($modelOverride??self::primaryModel()));
        if(!self::validModel($model))$model=self::primaryModel();
        $attempt=self::request($key,$model,'Responda somente com: OK',null,false);
        $attempt['fallback_used']=false;
        return $attempt;
    }

    /** @return array{ok:bool,text?:string,model:string,fallback_used:bool,latency_ms:int,http_code:?int,reason?:string} */
    private static function request(string $key,string $model,string $prompt,?string $image,bool $json): array
    {
        if($key===''){
            return ['ok'=>false,'model'=>$model,'fallback_used'=>false,'latency_ms'=>0,'http_code'=>null,'reason'=>'OPENAI_KEY_MISSING'];
        }
        if(!self::validModel($model)){
            return ['ok'=>false,'model'=>$model,'fallback_used'=>false,'latency_ms'=>0,'http_code'=>null,'reason'=>'OPENAI_MODEL_INVALID'];
        }
        if(!function_exists('curl_init')){
            return ['ok'=>false,'model'=>$model,'fallback_used'=>false,'latency_ms'=>0,'http_code'=>null,'reason'=>'OPENAI_CURL_MISSING'];
        }

        $content=[['type'=>'input_text','text'=>$prompt]];
        if($image!==null && is_file($image) && filesize($image)>0 && filesize($image)<=4*1024*1024){
            $mime=mime_content_type($image)?:'';
            if(in_array($mime,['image/png','image/jpeg','image/webp'],true)){
                $bytes=@file_get_contents($image);
                if(is_string($bytes)){
                    $content[]=[
                        'type'=>'input_image',
                        'image_url'=>'data:'.$mime.';base64,'.base64_encode($bytes),
                        'detail'=>'high'
                    ];
                }
            }
        }

        $payload=[
            'model'=>$model,
            'input'=>[['role'=>'user','content'=>$content]],
            'max_output_tokens'=>$json?3000:1800,
            'reasoning'=>['effort'=>'low']
        ];
        if($json)$payload['text']=['format'=>['type'=>'json_object']];

        $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if(!is_string($encoded)){
            return ['ok'=>false,'model'=>$model,'fallback_used'=>false,'latency_ms'=>0,'http_code'=>null,'reason'=>'OPENAI_PAYLOAD_ERROR'];
        }

        $ch=curl_init(self::ENDPOINT);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>[
                'Authorization: Bearer '.$key,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS=>$encoded,
            CURLOPT_CONNECTTIMEOUT=>6,
            CURLOPT_TIMEOUT=>40,
        ]);
        $started=microtime(true);
        $body=curl_exec($ch);
        $latency=max(0,(int)round((microtime(true)-$started)*1000));
        $errno=curl_errno($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($body===false || $errno!==0){
            return ['ok'=>false,'model'=>$model,'fallback_used'=>false,'latency_ms'=>$latency,'http_code'=>$http?:null,'reason'=>'OPENAI_NETWORK'];
        }
        $data=json_decode((string)$body,true);
        if(!is_array($data) || $http<200 || $http>=300){
            $reason='OPENAI_HTTP_'.$http;
            if(is_array($data)){
                $type=preg_replace('/[^A-Za-z0-9_]/','_',strtoupper((string)($data['error']['type']??'')));
                if(is_string($type)&&$type!=='')$reason=substr('OPENAI_'.$type,0,64);
            }
            return ['ok'=>false,'model'=>$model,'fallback_used'=>false,'latency_ms'=>$latency,'http_code'=>$http?:null,'reason'=>$reason];
        }

        $text='';
        foreach(($data['output']??[]) as $item){
            if(!is_array($item) || ($item['type']??'')!=='message')continue;
            foreach(($item['content']??[]) as $part){
                if(is_array($part) && ($part['type']??'')==='output_text'){
                    $text.=(string)($part['text']??'');
                }
            }
        }
        $text=trim($text);
        if($text===''){
            return ['ok'=>false,'model'=>$model,'fallback_used'=>false,'latency_ms'=>$latency,'http_code'=>$http,'reason'=>'OPENAI_RESPONSE_EMPTY'];
        }
        return ['ok'=>true,'text'=>$text,'model'=>$model,'fallback_used'=>false,'latency_ms'=>$latency,'http_code'=>$http];
    }
}
