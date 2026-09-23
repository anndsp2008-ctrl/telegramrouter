<?php declare(strict_types=1);
/**
 * Adds the configured visual signature once, after the LAST part of a
 * media+caption delivery. Does not change triggering, media, translations,
 * Telegram entities, original message text or text-only deliveries.
 */
$path=__DIR__.'/app/TelegramRouter.php';
$source=@file_get_contents($path);
if(!is_string($source)){fwrite(STDERR,"TMR_CAPTION_FOOTER_SOURCE_MISSING\n");exit(1);}
$marker='TMR_CAPTION_FOOTER_V1';
if(str_contains($source,$marker)){echo "TMR_CAPTION_FOOTER_ALREADY_APPLIED\n";exit(0);}
$anchor='[$caption,$rest,$captionEntities,$restEntities]=$this->splitCaption($text,$entities,1024);';
if(substr_count($source,$anchor)!==1){fwrite(STDERR,"TMR_CAPTION_FOOTER_SPLIT_POINT_MISMATCH\n");exit(1);}
$insertion=<<<'CODE'
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
CODE;
$source=str_replace($anchor,$anchor."\n".$insertion,$source);
$tmp=$path.'.footer-candidate';
if(@file_put_contents($tmp,$source)===false){fwrite(STDERR,"TMR_CAPTION_FOOTER_WRITE_FAILED\n");exit(1);}
$lint=[];$code=0;
exec('php -l '.escapeshellarg($tmp).' 2>&1',$lint,$code);
if($code!==0){@unlink($tmp);fwrite(STDERR,"TMR_CAPTION_FOOTER_LINT_FAILED ".implode(" ",$lint)."\n");exit(1);}
if(!@rename($tmp,$path)){fwrite(STDERR,"TMR_CAPTION_FOOTER_RENAME_FAILED\n");exit(1);}
echo "TMR_CAPTION_FOOTER_V1_APPLIED\n";
