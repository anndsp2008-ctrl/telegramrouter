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
  <p class="field-help">Opcional por regra. A interpretação e a tradução inteligentes usam o mesmo provedor definido na regra e, quando necessário, seu fallback configurado. Para gerar cards, o provedor deve aceitar interpretação por IA (OpenAI, Gemini ou Workers AI); Google Cloud Translation, isoladamente, não interpreta comprovantes. Com a opção desligada, o encaminhamento atual permanece igual.</p>
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
  <p class="field-help">A análise original será preservada. A tradução seguirá a configuração desta regra. Se todas as tentativas de IA ou a renderização do card falharem, a mensagem original será preservada e encaminhada; o histórico e os logs registrarão a ordem, o motivo e o tempo das tentativas sem expor o conteúdo da tip.</p>
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
        $analysisMediaDownloadMs=0;
        $contingencyTranslationMs=0;
        $contingencyRenderMs=0;
        $smartFormatFailure=null;
        try {
            if($setting['enabled']){
                if($analysisMedia!==null){
                    $tempDir=sys_get_temp_dir().'/tmr-smart-'.bin2hex(random_bytes(10));
                    $mediaStarted=hrtime(true);
                    try {
                        if(!@mkdir($tempDir,0700,true) && !is_dir($tempDir))throw new \RuntimeException('SMART_MEDIA_TEMP_FAILED');
                        $mediaResult=$this->downloadSmartAnalysisImage($analysisMedia,$tempDir);
                        $sourceImage=$mediaResult['path'];
                        $analysisMediaDownloadMs=(int)$mediaResult['ms'];
                    } catch(\Throwable $mediaError){
                        $analysisMediaDownloadMs=self::elapsedMs($mediaStarted);
                        $requiresImage=$this->smartAnalysisMediaIsImage($analysisMedia);
                        error_log('TMR_SMART_MEDIA_ANALYSIS_UNAVAILABLE '.json_encode([
                            'required_for_card'=>$requiresImage && (($setting['output_mode']??'')==='card'),
                            'elapsed_ms'=>$analysisMediaDownloadMs,
                            'exception'=>get_class($mediaError),
                            'reason'=>TranslationService::sanitizeError($mediaError->getMessage())
                        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
                        $sourceImage=null;
                        // A mandatory card that visibly depends on a received image must
                        // never silently degrade to a text-only contingency. Failing here
                        // is safer than publishing a generic card that omits the fixture.
                        if($requiresImage && (($setting['output_mode']??'')==='card')){
                            throw new \RuntimeException('SMART_CARD_SOURCE_MEDIA_UNAVAILABLE',0,$mediaError);
                        }
                    }
                }
                $formatted=SmartFormatting::prepare($text,$rule,$sourceImage,$setting['output_mode']);
                if($formatted===null && ($setting['output_mode']??'')==='card'){
                    // Mandatory-card rescue: structured extraction may fail while
                    // plain text translation still succeeds. Translate first,
                    // then build a deterministic card without inventing fields.
                    // When a receipt image is present, never treat Telegram caption
                    // alternatives or AI-generated multiple_details as verified
                    // selections from the receipt. Keep the original receipt visual
                    // and use a neutral Portuguese fallback instead.
                    $receiptOnly=$sourceImage!==null&&is_file($sourceImage);
                    $multipleDetected=!$receiptOnly && SmartFormatting::multipleDetected();
                    $multipleDetails=(!$receiptOnly&&$multipleDetected)
                        ?SmartFormatting::multipleDetails():'';
                    $sourceAnalysis=SmartFormatting::extractSourceAnalysis($text);
                    $translatedSourceAnalysis=$sourceAnalysis;
                    $contingencyTranslated=empty($rule['translation_enabled'])
                        || ($multipleDetails!=='' && SmartFormatting::multipleDetailsTranslated());

                    // Preserve author analysis independently from receipt extraction.
                    // When translation is enabled, translate only that preserved prose;
                    // never replace it with generated commentary.
                    if($sourceAnalysis!=='' && !empty($rule['translation_enabled'])){
                        $translationStarted=microtime(true);
                        try {
                            $analysisTranslation=Transform::translateDetailed($sourceAnalysis,$rule,[
                                'rule_id'=>(int)($rule['id']??0),
                                'context'=>'smart_card_source_analysis'
                            ]);
                            $translatedAnalysis=trim((string)($analysisTranslation['text']??''));
                            if($translatedAnalysis!=='')$translatedSourceAnalysis=$translatedAnalysis;
                            $contingencyTranslated=!empty($analysisTranslation['translated']);
                            error_log('TMR_SMART_CARD_SOURCE_ANALYSIS_PRESERVED '.json_encode([
                                'translated'=>$contingencyTranslated,
                                'provider'=>(string)($analysisTranslation['provider']??'unknown')
                            ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
                        } catch(\Throwable $translationError) {
                            $translatedSourceAnalysis=$sourceAnalysis;
                            error_log('TMR_SMART_CARD_SOURCE_ANALYSIS_TRANSLATION_FAILED '.json_encode([
                                'exception'=>get_class($translationError)
                            ]));
                        } finally {
                            $contingencyTranslationMs=max(0,(int)round((microtime(true)-$translationStarted)*1000));
                        }
                    }

                    $contingencyText=\App\ContingencyCardRenderer::groundedText(
                        $multipleDetails!==''?$multipleDetails:$text,
                        $sourceImage,
                        $translatedSourceAnalysis
                    );

                    // Without a receipt, the entire original text may still need
                    // translation. Receipt contingencies translate only preserved
                    // analysis because selections remain grounded in the image.
                    if(!$receiptOnly && !empty($rule['translation_enabled'])
                        && $multipleDetails==='' && trim($text)!==''){
                        $translationStarted=microtime(true);
                        try {
                            $translationFallback=Transform::translateDetailed($text,$rule,[
                                'rule_id'=>(int)($rule['id']??0),
                                'context'=>'smart_card_contingency'
                            ]);
                            $translatedText=trim((string)($translationFallback['text']??''));
                            if($translatedText!=='')$contingencyText=$translatedText;
                            $contingencyTranslated=!empty($translationFallback['translated']);
                            error_log('TMR_SMART_CARD_CONTINGENCY_TRANSLATION '.json_encode([
                                'translated'=>$contingencyTranslated,
                                'provider'=>(string)($translationFallback['provider']??'unknown')
                            ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
                        } catch(\Throwable $translationError) {
                            error_log('TMR_SMART_CARD_CONTINGENCY_TRANSLATION_FAILED '.json_encode([
                                'exception'=>get_class($translationError)
                            ]));
                        } finally {
                            $contingencyTranslationMs=max(
                                $contingencyTranslationMs,
                                max(0,(int)round((microtime(true)-$translationStarted)*1000))
                            );
                        }
                    }
                    // Reapply the rule AFTER translation/AI fallback. Translators may
                    // preserve or introduce emoji/link characters even when the source was
                    // already cleaned. The final outgoing contingency must obey the rule.
                    $contingencyText=SmartFormatting::normalizePublishedStakeText(
                        trim(Transform::clean($contingencyText,$rule,[]))
                    );
                    error_log('TMR_SMART_CARD_CONTINGENCY_RULE_CLEAN '.json_encode([
                        'remove_emojis'=>!empty($rule['remove_emojis']),
                        'remove_links'=>!empty($rule['remove_links']),
                        'custom_removals'=>trim((string)($rule['custom_removals']??''))!==''
                    ]));
                    try {
                        $contingencyRenderStarted=microtime(true);
                        $contingencyCard=\App\ContingencyCardRenderer::render(
                            $contingencyText,$sourceImage,$translatedSourceAnalysis
                        );
                        $contingencyRenderMs=max(0,(int)round((microtime(true)-$contingencyRenderStarted)*1000));
                        if($contingencyCard!==null){
                            $formatted=[
                                'caption'=>\App\ContingencyCardRenderer::caption($contingencyText),
                                'image'=>$contingencyCard,
                                'mode'=>'card',
                                'contingency'=>true,
                                'multiple_bet'=>$multipleDetected,
                                'translation_ok'=>$contingencyTranslated
                            ];
                            error_log('TMR_SMART_CARD_CONTINGENCY_READY '.json_encode([
                                'translated'=>$contingencyTranslated,
                                'multiple_bet'=>$multipleDetected,
                                'has_image'=>$sourceImage!==null,
                                'receipt_only'=>$receiptOnly
                            ]));
                        }
                    } catch(\Throwable $contingencyError) {
                        error_log('TMR_SMART_CARD_CONTINGENCY_RENDER_FAILED '.json_encode([
                            'exception'=>get_class($contingencyError)
                        ]));
                    }
                }
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
            $smartFormatFailure=$error;
            $formatted=null;
        } finally {
            if($sourceImage!==null)@unlink($sourceImage);
            if(isset($tempDir) && is_dir($tempDir)){
                foreach(glob($tempDir.'/*')?:[] as $tmpFile)@unlink($tmpFile);
                @rmdir($tempDir);
            }
        }
        if($formatted!==null){
            // Generated smart-card captions keep their structural emojis globally.
            // The card renderer already strips emojis only from analysis/details.
            $isContingency=!empty($formatted['contingency']);
            $captionForDelivery=$isContingency
                ?\App\CardLayoutEmojis::clean((string)$formatted['caption'],$rule,true)
                :(string)$formatted['caption'];
            $newText=SmartFormatting::normalizePublishedStakeText(trim($captionForDelivery));
            $output=$formatted['mode'];
            $card=$formatted['image'];
            $isContingency=!empty($formatted['contingency']);
            if($output==='card' && $card!==null){
                try {
                    // Split only at the Telegram caption boundary; keep the entire analysis.
                    [$summary,$continuation]=$this->splitCaption($newText,[],1024);
                    $footer=\App\CardLayoutEmojis::cleanSystemDecoration(SmartFormatting::signature(),$rule);
                    $continuationPrefix=\App\CardLayoutEmojis::cleanSystemDecoration("↪️ Continuação da mensagem:\n\n",$rule);
                    if($continuation!==''){
                        $units=(int)(strlen(mb_convert_encoding($continuationPrefix.$continuation.$footer,'UTF-16LE','UTF-8'))/2);
                        if($units>4096){
                            // Do not send the card unless the complete analysis can follow.
                            $formatted=null;
                        }
                    }
                    if($formatted!==null){
                        $telegramStarted=microtime(true);
                        $partial=false;
                        $this->messages->sendMedia(
                            peer:$peer,
                            media:['_'=>'inputMediaUploadedPhoto','file'=>$card],
                            message:$summary,
                            entities:[]
                        );
                        if($continuation!==''){
                            try {
                                $this->sendContinuation($peer,$continuation.$footer,[]);
                            } catch(\Throwable $continuationError) {
                                $partial=true;
                                error_log('TMR_SMART_CARD_CONTINUATION_FAILED '.json_encode([
                                    'exception'=>get_class($continuationError)
                                ]));
                            }
                        }
                        $telegramMs=max(0,(int)round((microtime(true)-$telegramStarted)*1000));
                        $timing=SmartFormatting::timingCompact(
                            $telegramMs,
                            $isContingency?$contingencyRenderMs:0,
                            $isContingency?$contingencyTranslationMs:0
                        );
                        if($analysisMediaDownloadMs>0){
                            $mediaTiming='Mídia='.number_format($analysisMediaDownloadMs/1000,1,'.','').'s';
                            $timing=$mediaTiming.($timing!==''?'; '.$timing:'');
                        }
                        if($isContingency){
                            $translationStatus=!empty($formatted['translation_ok'])?'OK':'indisponível';
                            $prefix=$partial?'ai_vip_card_contingency_partial':'ai_vip_card_contingency';
                            $this->deliveryMethod=$prefix.' ['
                                .(!empty($formatted['multiple_bet'])?'tipo=múltipla; ':'')
                                .'tradução='.$translationStatus
                                .($timing!==''?'; '.$timing:'').']';
                        } else {
                            $prefix=$partial?'ai_vip_card_partial':'ai_vip_card';
                            $this->deliveryMethod=$prefix.($timing!==''?' ['.$timing.']':'');
                        }
                        $stageDiag=SmartFormatting::diagnostics();
                        error_log('TMR_SMART_CARD_STAGE_TIMING '.json_encode([
                            'ai_ms'=>(int)($stageDiag['ai_ms']??0),
                            'render_ms'=>(int)($stageDiag['render_ms']??0)
                                +($isContingency?$contingencyRenderMs:0),
                            'translation_ms'=>$isContingency?$contingencyTranslationMs:0,
                            'telegram_ms'=>$telegramMs,
                            'contingency'=>$isContingency,
                            'multiple_bet'=>$isContingency&&!empty($formatted['multiple_bet'])
                        ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
                        return;
                    }
                } finally {
                    @unlink($card);
                }
            }
            if($formatted!==null && ($output==='text' || $deliveryMedia===null)){
                // Telegram's text cap is 4096 UTF-16 units. Do not truncate analysis.
                $complete=$newText.\App\CardLayoutEmojis::cleanSystemDecoration(SmartFormatting::signature(),$rule);
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
        // Smart formatting must never make a valid source message disappear.
        // For CARD mode, all configured generative providers were already tried
        // above. Running the legacy translator again cannot create a card and
        // only repeats the same providers, adding tens of seconds before the
        // original is preserved. Caption/text modes may still benefit from the
        // established translation fallback because their final output is text.
        if(SmartFormatting::cardHandlesTranslation($rule,$setting)){
            if(($setting['output_mode']??'')==='card'){
                error_log('TMR_SMART_CARD_TRANSLATION_FALLBACK_SKIPPED_AFTER_FORMAT_FAILURE');
            } elseif(SmartFormatting::providerUnavailable()){
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
                'reason'=>SmartFormatting::failureSummary(),
                'diagnostics'=>SmartFormatting::diagnostics()
            ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
            if(($setting['output_mode']??'')==='card'){
                // Preserve the actionable root cause when the mandatory receipt image
                // could not be downloaded; do not mask it as a renderer failure.
                if($smartFormatFailure instanceof \RuntimeException
                    && $smartFormatFailure->getMessage()==='SMART_CARD_SOURCE_MEDIA_UNAVAILABLE'){
                    throw $smartFormatFailure;
                }
                // A mandatory-card rule must never leak a raw/original delivery.
                // Both GD and pure-PHP card renderers were already attempted.
                throw new \RuntimeException('SMART_CARD_CONTINGENCY_UNAVAILABLE');
            }
        }
        if($deliveryMedia===null){
            $this->messages->sendMessage(peer:$peer,message:$text,entities:$entities);
        } else {
            $this->sendMediaWithSafeCaption($peer,$deliveryMedia,$text,$entities);
        }
        if(!empty($setting['enabled'])){
            // Keep the stable method prefix used by current activity telemetry,
            // while appending a bounded, source-free diagnostic visible in the
            // existing "Método" field. This avoids a schema/UI migration.
            $diag=SmartFormatting::diagnosticsCompact();
            $this->deliveryMethod='smart_original_fallback'.($diag!==''?' ['.$diag.']':'');
        }
    }

    /** TMR_SMART_MEDIA_DOWNLOAD_V1 — bounded, observable analysis-media download. */
    private function downloadSmartAnalysisImage(mixed $media,string $tempDir): array
    {
        $overallStarted=hrtime(true);
        $timeout=(int)(getenv('SMART_MEDIA_DOWNLOAD_TIMEOUT_SECONDS')?:0);
        $timeout=max(5,min(30,$timeout>0?$timeout:12));
        $lastReason='SMART_MEDIA_DOWNLOAD_FAILED';

        for($attempt=1;$attempt<=2;$attempt++){
            foreach(glob($tempDir.'/*')?:[] as $stale)if(is_file($stale))@unlink($stale);
            $attemptStarted=hrtime(true);
            $wallStarted=microtime(true);
            $progress=static function(...$args) use($wallStarted,$timeout): void {
                if((microtime(true)-$wallStarted)>$timeout){
                    throw new \RuntimeException('SMART_MEDIA_DOWNLOAD_TIMEOUT');
                }
            };
            // The progress callback cannot fire while Telegram is stalled waiting for
            // upload.getFile. Pass a real Amp cancellation into MadelineProto so the
            // attempt is bounded even when zero bytes/progress events are received.
            $cancellation=new \Amp\TimeoutCancellation(
                (float)$timeout,
                'SMART_MEDIA_DOWNLOAD_TIMEOUT'
            );
            try {
                if(is_object($media) && method_exists($media,'downloadToDir')){
                    $path=$media->downloadToDir($tempDir,$progress,$cancellation);
                } else {
                    $path=$this->downloadToDir($media,$tempDir,$progress,$cancellation);
                }
                if(!is_string($path)||$path===''||!is_file($path)){
                    throw new \RuntimeException('SMART_MEDIA_DOWNLOAD_EMPTY');
                }

                $realDir=realpath($tempDir);
                $realPath=realpath($path);
                if($realDir===false||$realPath===false||!str_starts_with($realPath,$realDir.DIRECTORY_SEPARATOR)){
                    throw new \RuntimeException('SMART_MEDIA_TEMP_PATH_INVALID');
                }

                $bytes=(int)(filesize($realPath)?:0);
                if($bytes<=0)throw new \RuntimeException('SMART_MEDIA_EMPTY_FILE');
                if($bytes>4*1024*1024)throw new \RuntimeException('SMART_MEDIA_TOO_LARGE');

                $imageInfo=@getimagesize($realPath);
                $imageType=is_array($imageInfo)?(int)($imageInfo[2]??0):0;
                if(!in_array($imageType,[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)){
                    throw new \RuntimeException('SMART_MEDIA_NOT_SUPPORTED_IMAGE');
                }

                $attemptMs=self::elapsedMs($attemptStarted);
                $totalMs=self::elapsedMs($overallStarted);
                error_log('TMR_SMART_MEDIA_ANALYSIS_DOWNLOAD '.json_encode([
                    'status'=>'success',
                    'attempt'=>$attempt,
                    'attempt_ms'=>$attemptMs,
                    'elapsed_ms'=>$totalMs,
                    'bytes'=>$bytes
                ],JSON_UNESCAPED_SLASHES));
                return ['path'=>$realPath,'ms'=>$totalMs,'attempts'=>$attempt];
            } catch(\Throwable $error) {
                $attemptMs=self::elapsedMs($attemptStarted);
                $previous=$error->getPrevious();
                $timedOut=$error instanceof \Amp\TimeoutException
                    || ($error instanceof \Amp\CancelledException && $previous instanceof \Amp\TimeoutException)
                    || $error->getMessage()==='SMART_MEDIA_DOWNLOAD_TIMEOUT';
                $lastReason=$timedOut
                    ?'SMART_MEDIA_DOWNLOAD_TIMEOUT'
                    :TranslationService::sanitizeError($error->getMessage());
                error_log('TMR_SMART_MEDIA_ANALYSIS_DOWNLOAD '.json_encode([
                    'status'=>'failed',
                    'attempt'=>$attempt,
                    'attempt_ms'=>$attemptMs,
                    'reason'=>$lastReason
                ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));

                $deterministic=in_array($error->getMessage(),[
                    'SMART_MEDIA_TOO_LARGE',
                    'SMART_MEDIA_NOT_SUPPORTED_IMAGE',
                    'SMART_MEDIA_TEMP_PATH_INVALID'
                ],true);
                if($deterministic||$attempt>=2)break;
                usleep(300000);
            }
        }

        throw new \RuntimeException('SMART_MEDIA_ANALYSIS_DOWNLOAD_FAILED: '.$lastReason);
    }

    private function smartAnalysisMediaIsImage(mixed $media): bool
    {
        $type=strtolower(is_object($media)?get_class($media):(string)(is_array($media)?($media['_']??''):''));
        if(str_contains($type,'photo')||str_contains($type,'image'))return true;

        try {
            $meta=$this->restrictedMediaMeta($media);
            $mime=strtolower(trim((string)($meta['mime']??'')));
            if(str_starts_with($mime,'image/'))return true;
            $ext=strtolower(ltrim(trim((string)($meta['ext']??'')),'.'));
            if(in_array($ext,['jpg','jpeg','png','webp'],true))return true;
        } catch(\Throwable) {}
        return false;
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
// Reorder only the single learning link within the sidebar; other page links
// to the rules screen must never be interpreted as duplicate menu items.
$navPattern='~<nav class="saas-nav">.*?</nav>~s';
$aiLinkPattern='~<a\b[^>]*\bhref="/ai-learning\.php"[^>]*>.*?</a>~s';
$rulesLinkPattern='~href="/\?page=rules"[^>]*>.*?</a>~s';
if(preg_match_all($navPattern,$index,$navMatches)===1){
    $nav=$navMatches[0][0];
    $aiCount=preg_match_all($aiLinkPattern,$nav,$aiMatches);
    $rulesCount=preg_match_all($rulesLinkPattern,$nav,$rulesMatches);
    if($rulesCount===1 && $aiCount!==false && $aiCount<=1){
        $aiLink=$aiCount===1
            ?$aiMatches[0][0]
            :'<a href="/ai-learning.php"><span class="nav-icon">✦</span>Aprendizado da IA</a>';
        $newNav=$aiCount===1?str_replace($aiLink,'',$nav):$nav;
        $newNav=preg_replace_callback($rulesLinkPattern,
            static fn(array $match): string=>$match[0].$aiLink,
            $newNav,1);
        if(is_string($newNav))$index=str_replace($nav,$newNav,$index);
    }else{
        echo "AI_LEARNING_NAV_SKIPPED_UNKNOWN_MENU\n";
    }
}else{
    echo "AI_LEARNING_NAV_SKIPPED_UNKNOWN_MENU\n";
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
// Reapply OpenAI token telemetry after Railway restores the baseline bundle.
// This is observational only: request payloads, model selection and routing are unchanged.
$openAIPath=__DIR__.'/app/OpenAIProvider.php';
$openAISource=@file_get_contents($openAIPath);
if(!is_string($openAISource)){fwrite(STDERR,"OPENAI_USAGE_TELEMETRY_SOURCE_MISSING\\n");exit(1);}
if(!str_contains($openAISource,'TMR_OPENAI_USAGE')){
    $usageAnchor="        if(\$text===''){\n            return ['ok'=>false,'model'=>\$model,'fallback_used'=>false,'latency_ms'=>\$latency,'http_code'=>\$http,'reason'=>'OPENAI_RESPONSE_EMPTY'];\n        }";
    if(substr_count($openAISource,$usageAnchor)!==1){fwrite(STDERR,"OPENAI_USAGE_TELEMETRY_ANCHOR_MISMATCH\\n");exit(1);}
    $usagePatch=<<<'PHP'
        $usage=is_array($data['usage']??null)?$data['usage']:[];
        $inputTokens=max(0,(int)($usage['input_tokens']??0));
        $outputTokens=max(0,(int)($usage['output_tokens']??0));
        $totalTokens=max(0,(int)($usage['total_tokens']??($inputTokens+$outputTokens)));
        $cachedTokens=max(0,(int)($usage['input_tokens_details']['cached_tokens']??0));
        $reasoningTokens=max(0,(int)($usage['output_tokens_details']['reasoning_tokens']??0));
        error_log('TMR_OPENAI_USAGE '.json_encode([
            'model'=>$model,
            'input_tokens'=>$inputTokens,
            'cached_tokens'=>$cachedTokens,
            'output_tokens'=>$outputTokens,
            'reasoning_tokens'=>$reasoningTokens,
            'total_tokens'=>$totalTokens,
            'latency_ms'=>$latency,
            'http_code'=>$http
        ],JSON_UNESCAPED_SLASHES));
PHP;
    $openAISource=str_replace($usageAnchor,$usagePatch."\n".$usageAnchor,$openAISource);
    $openAITemp=$openAIPath.'.usage-candidate';
    if(@file_put_contents($openAITemp,$openAISource)===false){fwrite(STDERR,"OPENAI_USAGE_TELEMETRY_WRITE_FAILED\\n");exit(1);}
    $openAILint=[];$openAIStatus=0;
    exec('php -l '.escapeshellarg($openAITemp).' 2>&1',$openAILint,$openAIStatus);
    if($openAIStatus!==0){@unlink($openAITemp);fwrite(STDERR,"OPENAI_USAGE_TELEMETRY_LINT_FAILED ".implode(' ',$openAILint)."\\n");exit(1);}
    if(!@rename($openAITemp,$openAIPath)){@unlink($openAITemp);fwrite(STDERR,"OPENAI_USAGE_TELEMETRY_REPLACE_FAILED\\n");exit(1);}
    echo "TMR_OPENAI_USAGE_TELEMETRY_INSTALLED\\n";
}else{
    echo "TMR_OPENAI_USAGE_TELEMETRY_ALREADY_PRESENT\\n";
}

$installerSucceeded=true;
$routerHash=@hash_file('sha256',$routerPath);
$smartHash=@hash_file('sha256',__DIR__.'/app/SmartFormatting.php');
echo 'TMR_SMART_FORMAT_RUNTIME_HASH router='.substr((string)$routerHash,0,12)
    .' smart='.substr((string)$smartHash,0,12)."\n";
echo "TMR_SMART_FORMAT_V1_INSTALLED\n";
