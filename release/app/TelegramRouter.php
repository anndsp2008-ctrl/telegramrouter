<?php declare(strict_types=1);
namespace App;

use danog\MadelineProto\EventHandler;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\SimpleEventHandler;

final class TelegramRouter extends SimpleEventHandler
{
    // restricted_media_fallback_v2
    // chat_forwards_restricted_v1 compatibility marker for Railway boot validation
    private string $deliveryMethod='unknown';
    private array $deliveryTelemetry=[];
    private bool $reportingOutboxTimerStarted=false;

    public static function getPlugins(): array { return []; }
    public function getReportPeers(): array { return []; }

    public function onStart(): void
    {
        $this->cleanupRestrictedOrphans();
        $pdo=Database::pdo();
        $pdo->prepare("INSERT INTO worker_status(worker_key,status,last_activity_at) VALUES('telegram-global','conectado',NOW()) ON DUPLICATE KEY UPDATE status='conectado',last_activity_at=NOW(),last_error=NULL")->execute();
        if(!$this->reportingOutboxTimerStarted){
            $this->reportingOutboxTimerStarted=true;
            \Revolt\EventLoop::repeat(15.0,function(): void {
                $this->flushReportingOutbox();
            });
        } // TMR_REPORTING_TIMER_V1
    }

    #[EventHandler\Attributes\Handler]
    public function handleIncoming(Message $message): void
    {
        $text=(string)($message->message??'');
        $source=(string)($message->chatId??$message->senderId??'');
        if($source==='') return;

        $peerInfo=[];
        try {
            $info=$this->getInfo($message->chatId);
            if(is_array($info)) $peerInfo=$info;
        } catch(\Throwable) {
            // O ID numérico continua disponível mesmo quando o cache do peer falha.
        }
        $identifiers=ChatMatcher::identifiers($source,$peerInfo);
        foreach(Repository::allRules() as $rule) {
            if(!(int)$rule['enabled'] || !$this->matches($identifiers,(string)$rule['source_chat'],$text,(string)$rule['trigger_text'])) continue;

            // Negative trigger is a hard veto for this matched rule. Evaluate it
            // before translation, AI, media download or any other paid processing.
            $excluded=self::exclusionMatch($text,(string)($rule['exclude_text']??''));
            if($excluded!==null){
                $this->recordExcluded($source,(int)$message->id,$rule,$excluded);
                break;
            }

            $this->process($message,$source,$rule);
            break;
        }
    }

    /** @param array<int,string> $identifiers */
    private function matches(array $identifiers,string $wanted,string $text,string $trigger): bool
    {
        if(!ChatMatcher::matches($identifiers,$wanted)) return false;
        return self::triggerMatches($text,$trigger);
    }

    // trigger_and_terms_v1: "termo 1 + termo 2" exige todos os termos, em qualquer ordem.
    // Gatilhos sem " + " preservam exatamente o comportamento literal anterior.
    private static function triggerMatches(string $text,string $trigger): bool
    {
        if($trigger==='') return true;

        $terms=preg_split('/\s+\+\s+/u',trim($trigger));
        if(!is_array($terms) || count($terms)<2){
            return mb_stripos($text,$trigger,0,'UTF-8')!==false;
        }

        foreach($terms as $term){
            $term=trim($term);
            if($term==='' || mb_stripos($text,$term,0,'UTF-8')===false) return false;
        }
        return true;
    }

    // Uma exclusão por linha. Linhas são OR entre si; dentro da linha,
    // "termo 1 + termo 2" preserva a mesma semântica AND do gatilho positivo.
    private static function exclusionMatch(string $text,string $exclude): ?string
    {
        $exclude=trim($exclude);
        if($exclude==='') return null;

        $rules=preg_split('/\R+/u',$exclude);
        if(!is_array($rules)) return null;

        foreach($rules as $rule){
            $rule=trim($rule);
            if($rule!=='' && self::triggerMatches($text,$rule)) return $rule;
        }
        return null;
    }

    private function recordExcluded(string $source,int $messageId,array $rule,string $matched): void
    {
        $matched=mb_substr(trim($matched),0,240,'UTF-8');
        $details='Mensagem ignorada: condição de exclusão encontrada: '.$matched
            .' | Nenhum encaminhamento, tradução ou IA foi executado.';
        try {
            $stmt=Database::pdo()->prepare(
                "INSERT INTO router_events(source_chat,message_id,destination_chat,trigger_text,status,details)
                 VALUES(?,?,?,?, 'skipped',?)"
            );
            $stmt->execute([
                $source,
                $messageId,
                (string)($rule['destination_chat']??''),
                (string)($rule['trigger_text']??''),
                $details,
            ]);
        } catch(\PDOException $e) {
            if((int)($e->errorInfo[1]??0)===1062) return;
            error_log('TMR_ROUTING_EXCLUSION_LOG_FAILED '.get_class($e));
        } catch(\Throwable $e) {
            error_log('TMR_ROUTING_EXCLUSION_LOG_FAILED '.get_class($e));
        }
    }

    private function process(Message $message,string $source,array $rule): void
    {
        $started=hrtime(true);
        $id=(int)$message->id;
        SmartFormatting::resetReportingTicket(); // TMR_REPORTING_RESET_EVENT_V1
        $pdo=Database::pdo();
        try {
            $s=$pdo->prepare("INSERT INTO router_events(source_chat,message_id,destination_chat,trigger_text,status) VALUES(?,?,?,?, 'processing')");
            $s->execute([$source,$id,$rule['destination_chat'],$rule['trigger_text']]);
        } catch(\PDOException $e) {
            // source_message é UNIQUE: retries/restarts não podem encaminhar a mesma mensagem duas vezes.
            if((int)($e->errorInfo[1]??0)===1062) return;
            throw $e;
        }

        $claimedMediaId=null;
        $this->deliveryMethod='unknown';
        $this->deliveryTelemetry=[];
        try {
            $this->resolveDestination((string)$rule['destination_chat']);
            $cleaned=Transform::cleanWithEntities((string)($message->message??''),$rule,(array)($message->entities??[]));

            try {
                $cardOwnsTranslation=SmartFormatting::shouldTranslateInsideCard($rule);
                $translationRule=$rule;
                if($cardOwnsTranslation)$translationRule['translation_enabled']=false;
                $translation=Transform::translateDetailed($cleaned['text'],$translationRule,[
                    'source_chat'=>$source,
                    'message_id'=>$id,
                    'rule_id'=>(int)($rule['id']??0),
                    'context'=>'message',
                ]);
            } catch(TranslationFailureException $e) {
                $totalMs=self::elapsedMs($started);
                $this->finish($source,$id,'skipped',TranslationService::sanitizeError($e->getMessage()).' | Processamento total: '.$totalMs.' ms');
                return;
            }

            $text=(string)$translation['text'];
            $realTranslation=!empty($translation['translated']);
            $sendEntities=$realTranslation?[]:$cleaned['entities'];
            $mode=(string)$rule['media_mode'];

            if($mode==='text_only' && $text==='') {
                $this->finish($source,$id,'skipped','Mensagem ignorada: não há texto para encaminhar após o tratamento. | Processamento total: '.self::elapsedMs($started).' ms');
                return;
            }

            $sendStarted=hrtime(true);
            $this->deliveryMethod=$mode==='text_only'?'text':'direct_media';
            if($mode==='text_only') {
                $this->sendSmartOrOriginal((string)$rule['destination_chat'],null,$text,$sendEntities,$rule,$message->media??null);
            } elseif($mode==='current_media' && !empty($message->media)) {
                $this->sendSmartOrOriginal((string)$rule['destination_chat'],$message->media,$text,$sendEntities,$rule,$message->media);
            } else {
                $previous=$this->findAndClaimPreviousPhoto($message,$source,$id);
                if(!$previous) {
                    $details=self::joinDetails((string)($translation['details']??''),'Nenhuma foto anterior encontrada nos últimos 15 minutos','Processamento total: '.self::elapsedMs($started).' ms');
                    $this->finish($source,$id,'skipped',$details);
                    return;
                }
                $claimedMediaId=(int)$previous['media_id'];
                $this->sendSmartOrOriginal((string)$rule['destination_chat'],$previous['media'],$text,$sendEntities,$rule,$previous['media']);
            }
            $sendMs=self::elapsedMs($sendStarted);
            $totalMs=self::elapsedMs($started);
            $details=self::joinDetails((string)($translation['details']??''),$this->deliveryDetails(),'Envio Telegram: '.$sendMs.' ms','Processamento total: '.$totalMs.' ms');
            $this->finish($source,$id,'forwarded',$details);
            try {
                \App\Reporting\ReportingBridge::afterForward($rule,$source,$id);
            } catch(\Throwable $reportingError) {
                error_log('TMR_REPORTING_CAPTURE_HOOK_NON_FATAL '.get_class($reportingError));
            } // TMR_REPORTING_CAPTURE_V1
        } catch(\Throwable $e) {
            if($claimedMediaId!==null) $this->releaseMediaClaim($source,$claimedMediaId,$id);
            $details=ErrorTranslator::message($e,'o encaminhamento da mensagem').' | Erro técnico: '.TranslationService::sanitizeError($e->getMessage()).' | Processamento total: '.self::elapsedMs($started).' ms';
            $this->finish($source,$id,'failed',$details);
        }
    }

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
                $this->sleep(0.3);
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

    private function resolveDestination(string $peer): void
    {
        try { $this->getInfo($peer); return; }
        catch(\Throwable $first) {
            try { $this->getFullDialogs(); } catch(\Throwable) {}
            try { $this->getInfo($peer); return; }
            catch(\Throwable) {
                throw new \RuntimeException('O destino '.$peer.' não está acessível pela conta Telegram. Abra/entre no canal ou use o @username público do destino.',0,$first);
            }
        }
    }

    private function sendMediaWithSafeCaption(string $peer,mixed $media,string $text,array $entities=[]): void
    {
        [$caption,$rest,$captionEntities,$restEntities]=$this->splitCaption($text,$entities,1024);
        // TMR_CAPTION_FOOTER_V1: Never insert a divider before an auto-generated continuation.
        // UTF-16 lengths match Telegram's caption/message limits; all original entities stay unchanged.
        $signature="\n\n━━━━━━━━━━━━\n⚡ TelegramRouter • Aposta encaminhada";
        $lengthInUnits=static fn(string $value): int =>
            (int)(strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2);
        if($rest!=='') {
            // The router sends this fragment with its own continuation label.
            // Put the signature at the very end, only when the whole text fits.
            $continuationPrefix="↪️ Continuação da mensagem:\n\n";
            if($lengthInUnits($continuationPrefix.$rest.$signature)<=4096) {
                $rest.=$signature;
            }
        } elseif($lengthInUnits($caption.$signature)<=1024) {
            // Keep the media and signature in a single Telegram message.
            $caption.=$signature;
        }
        try {
            $inputMedia=$this->toReusableInputMedia($media);
            $this->messages->sendMedia(peer:$peer,media:$inputMedia,message:$caption,entities:$captionEntities);
            $this->deliveryMethod='direct_media';
        } catch(\Throwable $e) {
            if(!$this->isRestrictedForwardError($e)) throw $e;
            $this->deliveryMethod='protected_media_reupload';
            $this->deliveryTelemetry=['fallback'=>'CHAT_FORWARDS_RESTRICTED'];
            error_log('ROUTER_DELIVERY '.json_encode(['method'=>'protected_media_reupload','stage'=>'restricted_detected'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $this->sendRestrictedMediaByReupload($peer,$media,$caption,$captionEntities);
        }
        if($rest!=='') $this->sendContinuation($peer,$rest,$restEntities);
    }

    private function isRestrictedForwardError(\Throwable $e): bool
    {
        $cursor=$e;
        while($cursor) {
            $technical=strtoupper(get_class($cursor).' '.$cursor->getMessage());
            if(str_contains($technical,'CHAT_FORWARDS_RESTRICTED') || str_contains($technical,'CHATFORWARDSRESTRICTED')) return true;
            $cursor=$cursor->getPrevious();
        }
        return false;
    }

    private function sendRestrictedMediaByReupload(string $peer,mixed $media,string $caption,array $entities): void
    {
        $operationId=bin2hex(random_bytes(10));
        $tempDir=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'telegramrouter-restricted-'.$operationId;
        $stage='prepare';
        $downloadStarted=0;
        $uploadStarted=0;
        $path=null;

        if(!@mkdir($tempDir,0700,true) && !is_dir($tempDir)) {
            throw new \RuntimeException('PROTECTED_MEDIA_TEMP_DIR_FAILED');
        }

        try {
            $meta=$this->restrictedMediaMeta($media);
            $expectedBytes=max(0,(int)($meta['size']??0));
            $maxBytes=$this->restrictedMaxBytes();
            if($expectedBytes>0 && $expectedBytes>$maxBytes) {
                throw new \RuntimeException('PROTECTED_MEDIA_TOO_LARGE expected='.$expectedBytes.' max='.$maxBytes);
            }

            $free=@disk_free_space($tempDir);
            $reserve=(int)(getenv('ROUTER_RESTRICTED_DISK_RESERVE_BYTES')?:0);
            if($reserve<=0) $reserve=134217728; // 128 MiB de margem para o container.
            if($free!==false && $expectedBytes>0 && $free<($expectedBytes+$reserve)) {
                throw new \RuntimeException('PROTECTED_MEDIA_INSUFFICIENT_TEMP_SPACE expected='.$expectedBytes.' free='.(int)$free);
            }

            $stage='download';
            $downloadStarted=hrtime(true);
            $downloadWall=microtime(true);
            $maxSeconds=(int)(getenv('ROUTER_RESTRICTED_MAX_SECONDS')?:0);
            if($maxSeconds<=0) $maxSeconds=180;
            $progress=static function(...$args) use($downloadWall,$maxSeconds): void {
                if((microtime(true)-$downloadWall)>$maxSeconds) throw new \RuntimeException('PROTECTED_MEDIA_DOWNLOAD_TIMEOUT');
            };

            if(is_object($media) && method_exists($media,'downloadToDir')) {
                $path=$media->downloadToDir($tempDir,$progress);
            } else {
                $path=$this->downloadToDir($media,$tempDir,$progress);
            }
            $downloadMs=self::elapsedMs($downloadStarted);

            if(!is_string($path) || $path==='' || !is_file($path)) {
                throw new \RuntimeException('PROTECTED_MEDIA_DOWNLOAD_EMPTY');
            }

            $realDir=realpath($tempDir);
            $realPath=realpath($path);
            if($realDir===false || $realPath===false || !str_starts_with($realPath,$realDir.DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('PROTECTED_MEDIA_TEMP_PATH_INVALID');
            }

            $actualBytes=(int)(filesize($realPath)?:0);
            if($actualBytes<=0) throw new \RuntimeException('PROTECTED_MEDIA_EMPTY_FILE');
            if($actualBytes>$maxBytes) throw new \RuntimeException('PROTECTED_MEDIA_TOO_LARGE actual='.$actualBytes.' max='.$maxBytes);

            $stage='upload';
            $uploadStarted=hrtime(true);
            $inputMedia=$this->buildUploadedInputMedia($media,$realPath,$meta);
            $this->messages->sendMedia(peer:$peer,media:$inputMedia,message:$caption,entities:$entities);
            $uploadMs=self::elapsedMs($uploadStarted);

            $this->deliveryTelemetry=array_merge($this->deliveryTelemetry,[
                'bytes'=>$actualBytes,
                'download_ms'=>$downloadMs,
                'upload_ms'=>$uploadMs,
            ]);
            error_log('ROUTER_DELIVERY '.json_encode([
                'method'=>'protected_media_reupload',
                'stage'=>'success',
                'bytes'=>$actualBytes,
                'download_ms'=>$downloadMs,
                'upload_ms'=>$uploadMs,
            ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        } catch(\Throwable $e) {
            $technical=TranslationService::sanitizeError($e->getMessage());
            $code=match($stage) {
                'download'=>'PROTECTED_MEDIA_DOWNLOAD_FAILED',
                'upload'=>'PROTECTED_MEDIA_REUPLOAD_FAILED',
                default=>'PROTECTED_MEDIA_PREPARE_FAILED',
            };
            if(str_contains(strtoupper($technical),'DOWNLOAD_TIMEOUT')) $code='PROTECTED_MEDIA_DOWNLOAD_TIMEOUT';
            error_log('ROUTER_DELIVERY '.json_encode([
                'method'=>'protected_media_reupload',
                'stage'=>$stage.'_failed',
                'error'=>$technical,
            ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            throw new \RuntimeException($code.': '.$technical,0,$e);
        } finally {
            $cleaned=$this->removeRestrictedTempTree($tempDir);
            $this->deliveryTelemetry['cleanup']=$cleaned?'ok':'failed';
            if(!$cleaned) error_log('ROUTER_DELIVERY '.json_encode(['method'=>'protected_media_reupload','stage'=>'temp_cleanup_failed'],JSON_UNESCAPED_SLASHES));
        }
    }

    /** @return array{size:int,name:string,mime:string,ext:string} */
    private function restrictedMediaMeta(mixed $media): array
    {
        $info=[];
        try {
            if(is_object($media) && method_exists($media,'getDownloadInfo')) $info=(array)$media->getDownloadInfo();
            else $info=(array)$this->getFileInfo($media);
        } catch(\Throwable) {}

        $size=(int)($info['size']??$info['file_size']??0);
        $name=(string)($info['name']??$info['file_name']??'');
        $mime=(string)($info['mime']??$info['mime_type']??'');
        $ext=(string)($info['ext']??'');
        return ['size'=>$size,'name'=>$name,'mime'=>$mime,'ext'=>$ext];
    }

    private function restrictedMaxBytes(): int
    {
        $configured=(int)(getenv('ROUTER_RESTRICTED_MAX_BYTES')?:getenv('RESTRICTED_MEDIA_MAX_BYTES')?:0);
        return $configured>0?$configured:1073741824; // padrão: 1 GiB, sem carregar o arquivo na RAM.
    }

    private function buildUploadedInputMedia(mixed $media,string $path,array $meta): array
    {
        $type=is_object($media)?strtolower(get_class($media)):strtolower((string)(is_array($media)?($media['_']??''):''));
        $isDocumentPhoto=str_contains($type,'documentphoto');
        $isPhoto=!$isDocumentPhoto && (str_contains($type,'messageMediaPhoto') || str_ends_with($type,'\\photo') || $type==='messagemediaphoto');
        if($isPhoto) {
            return ['_'=>'inputMediaUploadedPhoto','file'=>$path];
        }

        $fileName=basename($path);
        if($fileName==='' || $fileName==='.' || $fileName==='..') {
            $fileName='media'.((string)($meta['ext']??'')!==''?'.'.ltrim((string)$meta['ext'],'.'):'');
        }
        $mime=(string)($meta['mime']??'');
        if($mime==='' && function_exists('mime_content_type')) $mime=(string)(@mime_content_type($path)?:'');
        if($mime==='') $mime='application/octet-stream';

        $attributes=[['_'=>'documentAttributeFilename','file_name'=>$fileName]];
        if(str_contains($type,'roundvideo')) {
            $attributes[]=['_'=>'documentAttributeVideo','round_message'=>true,'supports_streaming'=>true,'duration'=>$this->mediaInt($media,'duration',0),'w'=>$this->mediaInt($media,'width',1),'h'=>$this->mediaInt($media,'height',1)];
        } elseif(str_contains($type,'video')) {
            $attributes[]=['_'=>'documentAttributeVideo','supports_streaming'=>true,'duration'=>$this->mediaInt($media,'duration',0),'w'=>$this->mediaInt($media,'width',1),'h'=>$this->mediaInt($media,'height',1)];
        } elseif(str_contains($type,'voice')) {
            $audio=['_'=>'documentAttributeAudio','voice'=>true,'duration'=>$this->mediaInt($media,'duration',0)];
            $waveform=$this->mediaValue($media,'waveform',null);
            if(is_string($waveform) || is_array($waveform)) $audio['waveform']=$waveform;
            $attributes[]=$audio;
        } elseif(str_contains($type,'audio')) {
            $audio=['_'=>'documentAttributeAudio','voice'=>false,'duration'=>$this->mediaInt($media,'duration',0)];
            $title=$this->mediaValue($media,'title',null); if(is_string($title)&&$title!=='') $audio['title']=$title;
            $performer=$this->mediaValue($media,'performer',null); if(is_string($performer)&&$performer!=='') $audio['performer']=$performer;
            $attributes[]=$audio;
        } elseif(str_contains($type,'gif')) {
            $attributes[]=['_'=>'documentAttributeAnimated'];
        }

        return ['_'=>'inputMediaUploadedDocument','file'=>$path,'mime_type'=>$mime,'attributes'=>$attributes];
    }

    private function mediaValue(mixed $media,string $property,mixed $default=null): mixed
    {
        if(is_object($media) && isset($media->{$property})) return $media->{$property};
        if(is_array($media) && array_key_exists($property,$media)) return $media[$property];
        return $default;
    }

    private function mediaInt(mixed $media,string $property,int $default=0): int
    {
        $value=$this->mediaValue($media,$property,$default);
        return is_numeric($value)?(int)$value:$default;
    }

    private function cleanupRestrictedOrphans(): void
    {
        $pattern=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'telegramrouter-restricted-*';
        $now=time();
        $ttl=(int)(getenv('ROUTER_RESTRICTED_ORPHAN_TTL_SECONDS')?:0);
        if($ttl<=0) $ttl=3600;
        foreach(glob($pattern,GLOB_ONLYDIR)?:[] as $dir) {
            $mtime=@filemtime($dir);
            if($mtime!==false && ($now-$mtime)>=$ttl) $this->removeRestrictedTempTree($dir);
        }
    }

    private function removeRestrictedTempTree(string $dir): bool
    {
        $prefix=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'telegramrouter-restricted-';
        if(!str_starts_with($dir,$prefix)) return false;
        if(!file_exists($dir)) return true;
        try {
            $iterator=new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach($iterator as $item) {
                $pathname=$item->getPathname();
                if($item->isLink() || $item->isFile()) @unlink($pathname);
                elseif($item->isDir()) @rmdir($pathname);
            }
            @rmdir($dir);
            return !file_exists($dir);
        } catch(\Throwable) {
            return false;
        }
    }

    private function deliveryDetails(): string
    {
        $method=$this->deliveryMethod;
        if($method==='protected_media_reupload') {
            $parts=['Método: protected_media_reupload'];
            if(isset($this->deliveryTelemetry['bytes'])) $parts[]='Mídia temporária: '.(int)$this->deliveryTelemetry['bytes'].' bytes';
            if(isset($this->deliveryTelemetry['download_ms'])) $parts[]='Download: '.(int)$this->deliveryTelemetry['download_ms'].' ms';
            if(isset($this->deliveryTelemetry['upload_ms'])) $parts[]='Reupload: '.(int)$this->deliveryTelemetry['upload_ms'].' ms';
            if(isset($this->deliveryTelemetry['cleanup'])) $parts[]='Cleanup: '.$this->deliveryTelemetry['cleanup'];
            return implode(' | ',$parts);
        }
        return 'Método: '.$method;
    }

    private function toReusableInputMedia(mixed $media): mixed
    {
        try {
            $info=$this->getFileInfo($media);
            if(is_array($info)) {
                foreach(['InputMedia','inputMedia','input_media'] as $key) if(isset($info[$key])) return $info[$key];
            }
        } catch(\Throwable) {
            // O próprio MadelineProto ainda pode aceitar a mídia original.
        }
        return $media;
    }

    /** @return array{0:string,1:string,2:array,3:array} */
    private function splitCaption(string $text,array $entities,int $limit): array
    {
        $chars=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
        $units=0;$cut=count($chars);
        foreach($chars as $i=>$char){$size=(int)(strlen(mb_convert_encoding($char,'UTF-16LE','UTF-8'))/2);if($units+$size>$limit){$cut=$i;break;}$units+=$size;}
        if($cut===count($chars)) return [$text,'',$entities,[]];
        $caption=implode('',array_slice($chars,0,$cut));$rest=implode('',array_slice($chars,$cut));
        $first=[];$second=[];
        foreach($entities as $entity){$offset=(int)($entity['offset']??0);$length=(int)($entity['length']??0);$end=$offset+$length;$copy=$entity;if($offset<$units&&$end>0){$copy['offset']=$offset;$copy['length']=min($end,$units)-$offset;if($copy['length']>0)$first[]=$copy;}if($end>$units){$copy=$entity;$copy['offset']=max(0,$offset-$units);$copy['length']=$end-max($offset,$units);if($copy['length']>0)$second[]=$copy;}}
        return [$caption,$rest,$first,$second];
    }

    private function sendContinuation(string $peer,string $text,array $entities): void
    {
        $label='↪️ Continuação da mensagem:';$prefix=$label."\n\n";
        $prefixUnits=(int)(strlen(mb_convert_encoding($prefix,'UTF-16LE','UTF-8'))/2);
        $labelUnits=(int)(strlen(mb_convert_encoding($label,'UTF-16LE','UTF-8'))/2);
        $shifted=[['_'=>'messageEntityBold','offset'=>0,'length'=>$labelUnits]];
        foreach($entities as $entity){$copy=$entity;$copy['offset']=(int)($copy['offset']??0)+$prefixUnits;$shifted[]=$copy;}
        $this->messages->sendMessage(peer:$peer,message:$prefix.$text,entities:$shifted);
    }

    /** @return array{media:mixed,media_id:int}|null */
    private function findAndClaimPreviousPhoto(Message $message,string $source,int $eventId): ?array
    {
        $eventDate=(int)($message->date??time());
        $history=$this->messages->getHistory(peer:$message->chatId,offset_id:$eventId,limit:25);
        foreach(($history['messages']??[]) as $candidate) {
            $candidateArray=is_array($candidate)?$candidate:[];
            $candidateId=(int)($candidateArray['id']??(is_object($candidate)?($candidate->id??0):0));
            $candidateDate=(int)($candidateArray['date']??(is_object($candidate)?($candidate->date??0):0));
            $media=$candidateArray['media']??(is_object($candidate)?($candidate->media??null):null);
            $isPhoto=false;
            if(is_array($media)) $isPhoto=(($media['_']??'')==='messageMediaPhoto'||isset($media['photo']));
            elseif(is_object($media)) $isPhoto=str_contains(strtolower(get_class($media)),'photo')||!empty($media->photo);
            if(!$candidateId||!$candidateDate||!$isPhoto||$candidateDate>$eventDate||($eventDate-$candidateDate)>900) continue;
            $claim=Database::pdo()->prepare('INSERT INTO router_media_claims(source_chat,media_message_id,event_message_id,media_date,event_date) VALUES(?,?,?,?,?)');
            try {
                $claim->execute([$source,$candidateId,$eventId,date('Y-m-d H:i:s',$candidateDate),date('Y-m-d H:i:s',$eventDate)]);
                return ['media'=>$media,'media_id'=>$candidateId];
            } catch(\PDOException $e) {
                if((int)($e->errorInfo[1]??0)===1062) continue;
                throw $e;
            }
        }
        return null;
    }

    private function releaseMediaClaim(string $source,int $mediaId,int $eventId): void
    {
        try {
            $s=Database::pdo()->prepare('DELETE FROM router_media_claims WHERE source_chat=? AND media_message_id=? AND event_message_id=?');
            $s->execute([$source,$mediaId,$eventId]);
        } catch(\Throwable) {}
    }

    private function flushReportingOutbox(): void
    {
        $row=null;
        try {
            $settings=\App\Reporting\Repository::settings();
            if(empty($settings['enabled']) || empty($settings['daily_report']))return;
            $row=\App\Reporting\Repository::claimOutbox();
            if(!is_array($row))return;

            $response=$this->messages->sendMessage(
                peer:(string)$row['destination_chat'],
                message:(string)$row['payload'],
                entities:[],
                random_id:(int)$row['telegram_random_id']
            );
            \App\Reporting\Repository::markOutboxSent(
                (int)$row['id'],
                self::reportingTelegramMessageId($response)
            );
            error_log('TMR_REPORTING_OUTBOX_SENT id='.(int)$row['id']);
        } catch(\Throwable $e) {
            if(is_array($row) && isset($row['id'])){
                try {
                    \App\Reporting\Repository::markOutboxFailed((int)$row['id'],get_class($e).': '.$e->getMessage());
                } catch(\Throwable) {}
            }
            error_log('TMR_REPORTING_OUTBOX_NON_FATAL '.get_class($e));
        }
    }

    private static function reportingTelegramMessageId(mixed $response): ?int
    {
        if(is_object($response) && isset($response->id) && is_numeric($response->id)){
            return (int)$response->id;
        }
        if(is_array($response) && isset($response['id']) && is_numeric($response['id'])){
            return (int)$response['id'];
        }
        return null;
    }
    private function finish(string $source,int $id,string $status,string $details): void
    {
        $s=Database::pdo()->prepare('UPDATE router_events SET status=?,details=?,updated_at=NOW() WHERE source_chat=? AND message_id=?');
        $s->execute([$status,$details,$source,$id]);
    }

    private static function elapsedMs(int $started): int { return (int)round((hrtime(true)-$started)/1_000_000); }

    private static function joinDetails(string ...$parts): string
    {
        return implode(' | ',array_values(array_filter(array_map('trim',$parts),static fn(string $v):bool=>$v!=='')));
    }
}

final class WorkerStatus
{
    public static function error(string $message): void
    {
        try { Database::pdo()->prepare("INSERT INTO worker_status(worker_key,status,last_activity_at,last_error) VALUES('telegram-global','erro',NOW(),?) ON DUPLICATE KEY UPDATE status='erro',last_activity_at=NOW(),last_error=?")->execute([$message,$message]); } catch(\Throwable) {}
    }
}

register_shutdown_function(static function():void{
    $error=error_get_last();
    if($error&&in_array($error['type'],[E_ERROR,E_CORE_ERROR,E_COMPILE_ERROR],true)) WorkerStatus::error($error['message']);
});
