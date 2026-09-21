<?php declare(strict_types=1);
/**
 * Opt-in smart formatting installer.
 * Runs only AFTER the existing translation, routing, branding and footer patches.
 * No old routing code is removed: disabled rules use their previous send calls.
 */
$productionHealthGuard=getenv('SMART_FORMAT_TEST_ONLY')!=='1' && is_file(__DIR__.'/health');
$installerSucceeded=false;
if($productionHealthGuard){
    register_shutdown_function(static function() use (&$installerSucceeded): void {
        if(!$installerSucceeded)@unlink(__DIR__.'/health');
    });
}
if(getenv('SMART_FORMAT_TEST_ONLY')!=='1'){
    require_once __DIR__.'/bootstrap.php';
    \App\SmartFormatting::migrate();
    $gdReady=extension_loaded('gd') && function_exists('imagettftext');
    $fontReady=is_file('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')
        || is_file('/usr/share/fonts/TTF/DejaVuSans.ttf')
        || is_file('/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf');
    $pureReady=function_exists('gzcompress');
    echo 'SMART_FORMAT_RENDERER_READY='.(($pureReady||($gdReady&&$fontReady))?'1':'0')
        .' mode='.(($gdReady&&$fontReady)?'gd':($pureReady?'pure_png':'none'))."\n";
}

$indexPath=__DIR__.'/index.php';
$routerPath=__DIR__.'/app/TelegramRouter.php';
$index=@file_get_contents($indexPath);
$router=@file_get_contents($routerPath);
$style='<link rel="stylesheet" href="/assets/brand/smart-format.css?v=1">';
if(is_string($index) && !str_contains($index,'smart-format.css')){
    if(substr_count($index,'</head>')!==1){fwrite(STDERR,"SMART_FORMAT_HEAD_MISMATCH\n");exit(1);}
    $index=str_replace('</head>',$style.'</head>',$index);
}
if(!is_string($index)||!is_string($router)){fwrite(STDERR,"SMART_FORMAT_SOURCE_MISSING\n");exit(1);}
if(str_contains($router,'TMR_SMART_FORMAT_V1') && str_contains($index,'tmr-smart-format-choice')){
    $installerSucceeded=true;
    echo "SMART_FORMAT_ALREADY_APPLIED\n";exit(0);
}
$replaceOne=static function(string $content,string $old,string $new,string $label): string {
    if(substr_count($content,$old)!==1){
        throw new \RuntimeException('SMART_FORMAT_ANCHOR_MISMATCH_'.$label);
    }
    return str_replace($old,$new,$content);
};
try {
    $index=$replaceOne($index,
        "\$notice='Nova regra publicada.';",
        "\\App\\SmartFormatting::saveForRule((int)\\App\\Database::pdo()->lastInsertId(),\$_POST);\$notice='Nova regra publicada.';",
        'RULE_CREATE'
    );
    $index=$replaceOne($index,
        "\$notice='Regra atualizada e aplicada ao trabalhador.';",
        "\\App\\SmartFormatting::saveForRule((int)\$_POST['id'],\$_POST);\$notice='Regra atualizada e aplicada ao trabalhador.';",
        'RULE_UPDATE'
    );
    $section=<<<'HTML'
<section class="treatment-block tmr-smart-format-choice" aria-labelledby="smart-format-title">
  <div class="treatment-title"><span id="smart-format-title">✦ Formatação inteligente com IA</span></div>
  <p class="field-help">Opcional por regra. A interpretação e a tradução inteligentes usam o mesmo provedor definido na regra e, quando necessário, seu fallback configurado. Para gerar cards, o provedor deve aceitar interpretação por IA (Gemini ou Workers AI); Azure Translator e Google Cloud Translation, isoladamente, não interpretam comprovantes. Com a opção desligada, o encaminhamento atual permanece igual.</p>
  <div class="treatment-checks">
    <label><input type="checkbox" name="smart_format_enabled" <?=\App\SmartFormatting::settings((int)($editRule['id']??0))['enabled']?'checked':''?>> Ativar somente nesta regra</label>
  </div>
  <label>Formato de envio
    <select name="smart_output_mode">
      <?php $smartMode=\App\SmartFormatting::settings((int)($editRule['id']??0))['output_mode']; ?>
      <option value="card" <?=$smartMode==='card'?'selected':''?>>Card visual APOSTA VIP (imagem gerada)</option>
      <option value="caption" <?=$smartMode==='caption'?'selected':''?>>Imagem original + legenda formatada</option>
      <option value="text" <?=$smartMode==='text'?'selected':''?>>Somente texto formatado</option>
    </select>
  </label>
  <p class="field-help">A análise original será preservada. A tradução seguirá a configuração desta regra. Se a IA ou o card falhar, a mensagem NÃO será encaminhada sem formatação; a falha será registrada no histórico, sem encaminhar o conteúdo original.</p>
</section>
HTML;
    $index=$replaceOne($index,
        '<input type="hidden" name="translation_source_language" value="auto">',
        $section.'<input type="hidden" name="translation_source_language" value="auto">',
        'FORM_SECTION'
    );
    $index=$replaceOne($index,'</head>',
        '<link rel="stylesheet" href="/assets/brand/smart-format.css?v=1"></head>',
        'STYLESHEET'
    );


    // The source text is cleaned first. Only opt-in card rules with translation
    // bypass the legacy translator: the card AI translates once, and the old
    // translator is invoked ONLY if card generation fails before any send.
    $router=$replaceOne($router,
        "\$translation=Transform::translateDetailed(\$cleaned['text'],\$rule,[",
        "\$cardOwnsTranslation=SmartFormatting::shouldTranslateInsideCard(\$rule);\n".
        "                \$translationRule=\$rule;\n".
        "                if(\$cardOwnsTranslation)\$translationRule['translation_enabled']=false;\n".
        "                \$translation=Transform::translateDetailed(\$cleaned['text'],\$translationRule,[",
        'SINGLE_PASS_CARD_TRANSLATION'
    );
    $router=$replaceOne($router,
        "\$this->messages->sendMessage(peer:(string)\$rule['destination_chat'],message:\$text,entities:\$sendEntities);",
        "\$this->sendSmartOrOriginal((string)\$rule['destination_chat'],null,\$text,\$sendEntities,\$rule,\$message->media??null);",
        'TEXT_SEND'
    );
    $router=$replaceOne($router,
        "\$this->sendMediaWithSafeCaption((string)\$rule['destination_chat'],\$message->media,\$text,\$sendEntities);",
        "\$this->sendSmartOrOriginal((string)\$rule['destination_chat'],\$message->media,\$text,\$sendEntities,\$rule,\$message->media);",
        'CURRENT_MEDIA_SEND'
    );
    $router=$replaceOne($router,
        "\$this->sendMediaWithSafeCaption((string)\$rule['destination_chat'],\$previous['media'],\$text,\$sendEntities);",
        "\$this->sendSmartOrOriginal((string)\$rule['destination_chat'],\$previous['media'],\$text,\$sendEntities,\$rule,\$previous['media']);",
        'PREVIOUS_MEDIA_SEND'
    );
    $method=<<<'CODE'
    /** TMR_SMART_FORMAT_V1 — safe opt-in delivery; never retries a sent message. */
    private function sendSmartOrOriginal(
        string $peer,
        mixed $deliveryMedia,
        string $text,
        array $entities,
        array $rule,
        mixed $analysisMedia=null
    ): void
    {
        // If settings cannot be read, never guess that the rule is disabled:
        // that could leak an unformatted source message to a mandatory AI rule.
        try {
            $setting=SmartFormatting::settings((int)($rule['id']??0));
        } catch(\Throwable $error) {
            throw new \RuntimeException('SMART_FORMAT_SETTINGS_UNAVAILABLE',0,$error);
        }
        $formatted=null;
        $sourceImage=null;
        try {
            if($setting['enabled']){
                if($analysisMedia!==null){
                    $tempDir=sys_get_temp_dir().'/tmr-smart-'.bin2hex(random_bytes(10));
                    try {
                        if(!@mkdir($tempDir,0700,true) && !is_dir($tempDir))throw new \RuntimeException('SMART_MEDIA_TEMP_FAILED');
                        if(is_object($analysisMedia) && method_exists($analysisMedia,'downloadToDir')){
                            $sourceImage=$analysisMedia->downloadToDir($tempDir);
                        } else {
                            $sourceImage=$this->downloadToDir($analysisMedia,$tempDir);
                        }
                        if(!is_string($sourceImage) || !is_file($sourceImage) || filesize($sourceImage)>4*1024*1024){
                            $sourceImage=null;
                        }
                    } catch(\Throwable $e){
                        $sourceImage=null;
                    }
                }
                $formatted=SmartFormatting::prepare($text,$rule,$sourceImage,$setting['output_mode']);
                if($formatted===null) error_log('TMR_SMART_FORMAT_UNAVAILABLE '.json_encode([
                    'rule_id'=>(int)($rule['id']??0),
                    'mode'=>(string)($setting['output_mode']??'unknown'),
                    'has_image'=>$sourceImage!==null
                ]));
            }
        } catch(\Throwable $error){
            error_log('TMR_SMART_FORMAT_FALLBACK '.json_encode([
                'rule_id'=>(int)($rule['id']??0),
                'mode'=>(string)($setting['output_mode']??'unknown'),
                'exception'=>get_class($error),
                'location'=>basename($error->getFile()).':'.$error->getLine()
            ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
            $formatted=null;
        } finally {
            if($sourceImage!==null)@unlink($sourceImage);
            if(isset($tempDir) && is_dir($tempDir)){
                foreach(glob($tempDir.'/*')?:[] as $tmpFile)@unlink($tmpFile);
                @rmdir($tempDir);
            }
        }
        if($formatted!==null){
            $newText=$formatted['caption'];
            $output=$formatted['mode'];
            $card=$formatted['image'];
            if($output==='card' && $card!==null){
                try {
                    // Split only at the Telegram caption boundary; keep the entire analysis.
                    [$summary,$continuation]=$this->splitCaption($newText,[],1024);
                    $footer=SmartFormatting::signature();
                    $continuationPrefix="↪️ Continuação da mensagem:\n\n";
                    if($continuation!==''){
                        $units=(int)(strlen(mb_convert_encoding($continuationPrefix.$continuation.$footer,'UTF-16LE','UTF-8'))/2);
                        if($units>4096){
                            // Do not send the card unless the complete analysis can follow.
                            $formatted=null;
                        }
                    }
                    if($formatted!==null){
                        $this->messages->sendMedia(
                            peer:$peer,
                            media:['_'=>'inputMediaUploadedPhoto','file'=>$card],
                            message:$summary,
                            entities:[]
                        );
                        $this->deliveryMethod='ai_vip_card';
                        if($continuation!==''){
                            try {
                                $this->sendContinuation($peer,$continuation.$footer,[]);
                            } catch(\Throwable $continuationError) {
                                $this->deliveryMethod='ai_vip_card_partial';
                                error_log('TMR_SMART_CARD_CONTINUATION_FAILED '.json_encode([
                                    'exception'=>get_class($continuationError)
                                ]));
                            }
                        }
                        return;
                    }
                } finally {
                    @unlink($card);
                }
            }
            if($formatted!==null && ($output==='text' || $deliveryMedia===null)){
                // Telegram's text cap is 4096 UTF-16 units. Do not truncate analysis.
                $complete=$newText.SmartFormatting::signature();
                $units=(int)(strlen(mb_convert_encoding($complete,'UTF-16LE','UTF-8'))/2);
                if($units<=4096){
                    $this->messages->sendMessage(peer:$peer,message:$complete,entities:[]);
                    $this->deliveryMethod='ai_formatted_text';
                    return;
                }
            } elseif($output==='caption' && $deliveryMedia!==null) {
                // A formatted caption is one Telegram media message, never a split
                // continuation. If it cannot fit with the existing signature,
                // preserve the untouched legacy delivery instead of truncating.
                $full=$newText.SmartFormatting::signature();
                $units=(int)(strlen(mb_convert_encoding($full,'UTF-16LE','UTF-8'))/2);
                if($units<=1024){
                    $this->sendMediaWithSafeCaption($peer,$deliveryMedia,$newText,[]);
                    $this->deliveryMethod='ai_formatted_caption';
                    return;
                }
            }
        }
        // PR #75 hardening: smart formatting must not make a valid source
        // message disappear. If formatting failed before any Telegram send,
        // try the existing translation path once when it is still meaningful.
        if(SmartFormatting::cardHandlesTranslation($rule,$setting)){
            if(SmartFormatting::providerUnavailable()){
                error_log('TMR_SMART_TRANSLATION_FALLBACK_SKIPPED_PROVIDER_BACKOFF');
            } else {
                try {
                    $translationFallback=Transform::translateDetailed($text,$rule,[
                        'rule_id'=>(int)($rule['id']??0),
                        'context'=>'message'
                    ]);
                    $text=(string)$translationFallback['text'];
                    if(!empty($translationFallback['translated']))$entities=[];
                    error_log('TMR_SMART_CARD_TRANSLATION_FALLBACK');
                } catch(\Throwable $translationError) {
                    error_log('TMR_SMART_TRANSLATION_FALLBACK_FAILED '.json_encode([
                        'exception'=>get_class($translationError)
                    ]));
                }
            }
        }
        if(!empty($setting['enabled'])){
            error_log('TMR_SMART_FORMAT_ORIGINAL_FALLBACK '.json_encode([
                'rule_id'=>(int)($rule['id']??0),
                'mode'=>(string)($setting['output_mode']??'unknown'),
                'reason'=>SmartFormatting::failureSummary()
            ]));
        }
        if($deliveryMedia===null){
            $this->messages->sendMessage(peer:$peer,message:$text,entities:$entities);
        } else {
            $this->sendMediaWithSafeCaption($peer,$deliveryMedia,$text,$entities);
        }
        if(!empty($setting['enabled']))$this->deliveryMethod='smart_original_fallback';
    }

CODE;
    $router=$replaceOne($router,'    private function resolveDestination(string $peer): void',$method.'    private function resolveDestination(string $peer): void','NEW_METHOD');
} catch(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

$errorPath=__DIR__.'/app/ErrorTranslator.php';
$errorSource=@file_get_contents($errorPath);
if(!is_string($errorSource)){fwrite(STDERR,"SMART_FORMAT_ERROR_TRANSLATOR_MISSING\n");exit(1);}
$oldErrorText='não foi possível determinar a causa exata; a tentativa foi interrompida e será reavaliada após a conexão ser restabelecida';
$newErrorText='não foi possível determinar a causa exata; a tentativa foi interrompida';
if(str_contains($errorSource,$oldErrorText)){
    if(substr_count($errorSource,$oldErrorText)!==1){fwrite(STDERR,"SMART_FORMAT_ERROR_TRANSLATOR_ANCHOR_MISMATCH\n");exit(1);}
    $errorSource=str_replace($oldErrorText,$newErrorText,$errorSource);
} elseif(!str_contains($errorSource,$newErrorText)){
    fwrite(STDERR,"SMART_FORMAT_ERROR_TRANSLATOR_UNKNOWN_STATE\n");exit(1);
}

// The baseline source archive replaces index.php on every Railway start.
// Restore only the opt-in learning navigation after the existing smart-format patch.
// If an unfamiliar menu is encountered, leave it unchanged; direct admin URL still works.
if(!str_contains($index,'href="/ai-learning.php"')){
    $navAnchor='<a href="/connect.php">';
    if(substr_count($index,$navAnchor)===1){
        $index=str_replace($navAnchor,
            '<a href="/ai-learning.php"><span class="nav-icon">✦</span>Aprendizado da IA</a>'.$navAnchor,
            $index);
    }elseif(substr_count($index,'<nav class="saas-nav">')===1){
        $index=str_replace('<nav class="saas-nav">',
            '<nav class="saas-nav"><a href="/ai-learning.php"><span class="nav-icon">✦</span>Aprendizado da IA</a>',
            $index);
    }else{
        echo "AI_LEARNING_NAV_SKIPPED_UNKNOWN_MENU\\n";
    }
}

$paths=[$indexPath=>$index,$routerPath=>$router,$errorPath=>$errorSource];
$temps=[];
foreach($paths as $dest=>$content){
    $temp=$dest.'.smart-candidate';
    if(@file_put_contents($temp,$content)===false){fwrite(STDERR,"SMART_FORMAT_WRITE_FAILED\n");exit(1);}
    $lint=[];$status=0;
    exec('php -l '.escapeshellarg($temp).' 2>&1',$lint,$status);
    if($status!==0){fwrite(STDERR,"SMART_FORMAT_LINT_FAILED ".implode(' ',$lint)."\n");exit(1);}
    $temps[$dest]=$temp;
}
foreach($temps as $dest=>$temp){
    if(!@rename($temp,$dest)){fwrite(STDERR,"SMART_FORMAT_REPLACE_FAILED\n");exit(1);}
}
// Apply the opt-in learning overlay only after the original smart-format runtime is verified.
// Failure leaves the previous formatter and forwarding behavior unchanged.
if(is_file(__DIR__.'/runtime-ai-learning.php'))require __DIR__.'/runtime-ai-learning.php';
$installerSucceeded=true;
$routerHash=@hash_file('sha256',$routerPath);
$smartHash=@hash_file('sha256',__DIR__.'/app/SmartFormatting.php');
echo 'TMR_SMART_FORMAT_RUNTIME_HASH router='.substr((string)$routerHash,0,12)
    .' smart='.substr((string)$smartHash,0,12)."\n";
echo "TMR_SMART_FORMAT_V1_INSTALLED\n";
