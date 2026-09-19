<?php declare(strict_types=1);
/**
 * Opt-in smart formatting installer.
 * Runs only AFTER the existing translation, routing, branding and footer patches.
 * No old routing code is removed: disabled rules use their previous send calls.
 */
require_once __DIR__.'/bootstrap.php';
\App\SmartFormatting::migrate();

$indexPath=__DIR__.'/index.php';
$routerPath=__DIR__.'/app/TelegramRouter.php';
$index=@file_get_contents($indexPath);
$router=@file_get_contents($routerPath);
if(!is_string($index)||!is_string($router)){fwrite(STDERR,"SMART_FORMAT_SOURCE_MISSING\n");exit(1);}
if(str_contains($router,'TMR_SMART_FORMAT_V1') && str_contains($index,'tmr-smart-format-choice')){
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
  <p class="field-help">Opcional por regra. Interpreta texto e imagens usando o Gemini configurado. Com a opção desligada, o encaminhamento atual permanece igual.</p>
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
  <p class="field-help">A análise original será preservada. A tradução seguirá a configuração desta regra. Caso a interpretação ou o card falhe, o envio original será mantido.</p>
</section>
HTML;
    $index=$replaceOne($index,
        '<input type="hidden" name="translation_source_language" value="auto">',
        $section.'<input type="hidden" name="translation_source_language" value="auto">',
        'FORM_SECTION'
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
        $setting=SmartFormatting::settings((int)($rule['id']??0));
        $formatted=null;
        $sourceImage=null;
        try {
            if($setting['enabled']){
                if($analysisMedia!==null){
                    $sourceImage=sys_get_temp_dir().'/tmr-smart-input-'.bin2hex(random_bytes(10)).'.jpg';
                    try {
                        $this->downloadToFile($analysisMedia,$sourceImage);
                        if(!is_file($sourceImage) || filesize($sourceImage)>4*1024*1024){
                            @unlink($sourceImage);$sourceImage=null;
                        }
                    } catch(\Throwable $e){
                        @unlink($sourceImage);$sourceImage=null;
                    }
                }
                $formatted=SmartFormatting::prepare($text,$rule,$sourceImage,$setting['output_mode']);
            }
        } catch(\Throwable $error){
            error_log('TMR_SMART_FORMAT_FALLBACK '.get_class($error));
            $formatted=null;
        } finally {
            if($sourceImage!==null)@unlink($sourceImage);
        }
        if($formatted!==null){
            $newText=$formatted['caption'];
            $output=$formatted['mode'];
            $card=$formatted['image'];
            if($output==='card' && $card!==null){
                try {
                    $summary='⚽ APOSTA VIP'."\n".mb_substr($newText,0,480,'UTF-8');
                    $this->messages->sendMedia(
                        peer:$peer,
                        media:['_'=>'inputMediaUploadedPhoto','file'=>$card],
                        message:$summary,
                        entities:[]
                    );
                    $this->deliveryMethod='ai_vip_card';
                } finally {
                    @unlink($card);
                }
                return;
            }
            if($output==='text' || $deliveryMedia===null){
                // Telegram's text cap is 4096 UTF-16 units. Do not truncate analysis.
                $complete=$newText.SmartFormatting::signature();
                $units=(int)(strlen(mb_convert_encoding($complete,'UTF-16LE','UTF-8'))/2);
                if($units<=4096){
                    $this->messages->sendMessage(peer:$peer,message:$complete,entities:[]);
                    $this->deliveryMethod='ai_formatted_text';
                    return;
                }
            } elseif($output==='caption') {
                $this->sendMediaWithSafeCaption($peer,$deliveryMedia,$newText,[]);
                $this->deliveryMethod='ai_formatted_caption';
                return;
            }
        }
        // Bit-for-bit existing behavior for all disabled rules and AI failures.
        if($deliveryMedia===null){
            $this->messages->sendMessage(peer:$peer,message:$text,entities:$entities);
        } else {
            $this->sendMediaWithSafeCaption($peer,$deliveryMedia,$text,$entities);
        }
    }

CODE;
    $router=$replaceOne($router,'    private function resolveDestination(string $peer): void',$method.'    private function resolveDestination(string $peer): void','NEW_METHOD');
} catch(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

$paths=[$indexPath=>$index,$routerPath=>$router];
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
echo "TMR_SMART_FORMAT_V1_INSTALLED\n";
