<?php declare(strict_types=1);
namespace App;

/**
 * Deterministic emergency renderer for mandatory-card rules.
 * It never invents betting fields. When structured AI extraction is unavailable,
 * it preserves the translated text and, when present, the original receipt image.
 */
final class ContingencyCardRenderer
{
    /** The receipt is the only verified source of selections for image contingencies. */
    public static function groundedText(string $candidate,?string $sourceImage): string
    {
        if($sourceImage!==null && is_file($sourceImage)){
            return 'Confira os mercados, as seleções e as odds no comprovante original. Stake: '
                .SmartFormatting::FIXED_STAKE;
        }
        return $candidate;
    }

    public static function render(string $text,?string $sourceImage): ?string
    {
        // Defence in depth: never draw unverified Telegram-caption selections
        // beside an attached receipt, even when called outside the router.
        $text=self::groundedText($text,$sourceImage);
        if(extension_loaded('gd') && function_exists('imagettftext')){
            try{
                $path=self::renderWithGd($text,$sourceImage);
                if($path!==null)return $path;
                error_log('TMR_CONTINGENCY_CARD_GD_UNAVAILABLE');
            }catch(\Throwable $error){
                error_log('TMR_CONTINGENCY_CARD_GD_ERROR '.preg_replace('/[^A-Za-z0-9_]/','_',get_class($error)).' line='.$error->getLine());
            }
        }

        // Renderer-level fallback: still return a valid VIP card and use
        // explicit placeholders instead of fabricating a market, odd or match.
        $excerpt=self::visualExcerpt($text);
        return VipCardRenderer::render([
            'sport'=>'',
            'status'=>'',
            'match'=>'Aposta recebida',
            'league'=>'',
            'market'=>'Detalhes da aposta',
            'selection'=>'Consulte o conteúdo abaixo',
            'odd'=>'—',
            'stake'=>SmartFormatting::FIXED_STAKE,
            'analysis'=>$excerpt!==''?$excerpt:'Conteúdo preservado no comprovante recebido.'
        ]);
    }

    public static function caption(string $text): string
    {
        $text=trim($text);
        if($text==='')return "📌 Detalhes da aposta disponíveis no comprovante.";
        return "📌 Detalhes da aposta\n\n".$text;
    }

    private static function renderWithGd(string $text,?string $sourceImage): ?string
    {
        $font=self::font(false);$bold=self::font(true);
        if($font===null||$bold===null)return null;

        $source=null;$sourceW=0;$sourceH=0;
        if($sourceImage!==null && is_file($sourceImage)){
            $bytes=@file_get_contents($sourceImage);
            if(is_string($bytes)&&$bytes!==''){
                $candidate=@imagecreatefromstring($bytes);
                if($candidate instanceof \GdImage){
                    $source=$candidate;
                    $sourceW=imagesx($candidate);$sourceH=imagesy($candidate);
                    if($sourceW<=0||$sourceH<=0){unset($source);$source=null;}
                }
            }
        }

        $excerpt=self::visualExcerpt($text);
        $textLines=$excerpt!==''?self::wrap($excerpt,$font,19,906):[];
        if($textLines===null)$textLines=['Conteúdo completo preservado na legenda.'];
        if(count($textLines)>18){
            $textLines=array_slice($textLines,0,17);
            $textLines[]='… conteúdo completo preservado na legenda';
        }

        $w=1080;$contentWidth=934;$contentX=73;$contentTop=172;
        $sourceDrawW=0;$sourceDrawH=0;
        if($source instanceof \GdImage){
            $scale=min($contentWidth/$sourceW,1500/$sourceH,1.0);
            $sourceDrawW=max(1,(int)round($sourceW*$scale));
            $sourceDrawH=max(1,(int)round($sourceH*$scale));
        }
        $textPanelH=$textLines!==[]?72+count($textLines)*30:0;
        $gap=($sourceDrawH>0&&$textPanelH>0)?24:0;
        $contentH=max(190,$sourceDrawH+$gap+$textPanelH);
        $dividerY=$contentTop+$contentH+28;
        $h=$dividerY+112;
        if($h>4000)return null;

        $im=imagecreatetruecolor($w,$h);
        if(!$im)return null;
        imagealphablending($im,true);imagesavealpha($im,true);
        $color=static fn(string $hex): int=>imagecolorallocate(
            $im,
            hexdec(substr($hex,1,2)),
            hexdec(substr($hex,3,2)),
            hexdec(substr($hex,5,2))
        );
        $bg=$color('#161d24');$white=$color('#f3f6fa');$muted=$color('#a8bdcd');
        $green=$color('#87efc9');$panel=$color('#1d2935');$line=$color('#3c4d5b');
        $gold=$color('#ffc94e');$vipBackground=$color('#29251c');

        imagefilledrectangle($im,0,0,$w,$h,$bg);
        self::roundRect($im,22,18,1058,$h-18,25,$line);
        self::roundRect($im,24,20,1056,$h-20,23,$bg);

        self::roundRect($im,53,43,260,85,20,$panel);
        self::txt($im,76,70,'DETALHES DA APOSTA',14,$green,$bold);

        self::roundRect($im,817,42,1027,85,20,$gold);
        self::roundRect($im,819,44,1025,83,18,$vipBackground);
        self::drawCrown($im,835,54,$gold);
        self::txt($im,869,71,'APOSTA VIP',16,$gold,$bold);

        self::txt($im,53,132,'Conteúdo preservado',31,$white,$bold);
        self::txt($im,53,159,'Card de contingência do TelegramRouter',16,$muted,$font);

        $cursor=$contentTop;
        if($source instanceof \GdImage && $sourceDrawW>0 && $sourceDrawH>0){
            $drawX=$contentX+(int)(($contentWidth-$sourceDrawW)/2);
            self::roundRect($im,$contentX,$cursor,$contentX+$contentWidth,$cursor+$sourceDrawH,18,$panel);
            imagecopyresampled(
                $im,$source,
                $drawX,$cursor,
                0,0,
                $sourceDrawW,$sourceDrawH,
                $sourceW,$sourceH
            );
            $cursor+=$sourceDrawH+$gap;
            unset($source);
        }

        if($textPanelH>0){
            self::roundRect($im,$contentX,$cursor,$contentX+$contentWidth,$cursor+$textPanelH,17,$panel);
            self::txt($im,$contentX+20,$cursor+34,'DETALHES',15,$muted,$bold);
            $y=$cursor+68;
            foreach($textLines as $lineText){
                self::txt($im,$contentX+20,$y,$lineText,19,$white,$font);
                $y+=30;
            }
        }elseif($sourceDrawH===0){
            self::roundRect($im,$contentX,$cursor,$contentX+$contentWidth,$cursor+170,17,$panel);
            self::txt($im,$contentX+20,$cursor+60,'Conteúdo recebido sem texto estruturado.',21,$white,$font);
            self::txt($im,$contentX+20,$cursor+101,'O comprovante original foi preservado no fluxo.',18,$muted,$font);
        }

        imageline($im,53,$dividerY,1027,$dividerY,$line);
        $footer='TelegramRouter • Aposta encaminhada';
        $box=imagettfbbox(16,0,$font,$footer);
        $width=is_array($box)?abs($box[2]-$box[0]):365;
        $footerX=(int)(($w-$width-34)/2);
        imagefilledpolygon($im,[
            $footerX,$dividerY+37,$footerX+17,$dividerY+37,
            $footerX+10,$dividerY+50,$footerX+22,$dividerY+50,
            $footerX-3,$dividerY+74,$footerX+4,$dividerY+56,
            $footerX-4,$dividerY+56
        ],$gold);
        self::txt($im,$footerX+32,$dividerY+62,$footer,16,$green,$font);

        $path=sys_get_temp_dir().'/tmr-contingency-'.bin2hex(random_bytes(12)).'.png';
        $ok=imagepng($im,$path,7);unset($im);
        if(!$ok||!is_file($path)){@unlink($path);return null;}
        @chmod($path,0600);
        return $path;
    }

    private static function visualExcerpt(string $text): string
    {
        $text=SmartFormatting::stripCardEmojis($text);
        $text=trim(preg_replace('/\s+/u',' ',strip_tags($text))??$text);
        if($text==='')return '';
        if(mb_strlen($text,'UTF-8')<=1400)return $text;
        return rtrim(mb_substr($text,0,1397,'UTF-8')).'…';
    }

    /** @return list<string>|null */
    private static function wrap(string $text,string $font,int $size,int $maxWidth): ?array
    {
        $words=preg_split('/\s+/u',trim($text))?:[];
        $lines=[];$line='';
        foreach($words as $word){
            if($word==='')continue;
            $candidate=$line===''?$word:$line.' '.$word;
            $box=imagettfbbox($size,0,$font,$candidate);
            if(!is_array($box))return null;
            if(abs($box[2]-$box[0])>$maxWidth){
                if($line==='')return null;
                $lines[]=$line;$line=$word;
                $single=imagettfbbox($size,0,$font,$line);
                if(!is_array($single)||abs($single[2]-$single[0])>$maxWidth)return null;
            }else{
                $line=$candidate;
            }
        }
        if($line!=='')$lines[]=$line;
        return $lines;
    }

    private static function font(bool $bold): ?string
    {
        foreach($bold?[
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf'
        ]:[
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf'
        ] as $candidate)if(is_file($candidate))return $candidate;
        return null;
    }

    private static function txt(\GdImage $im,int $x,int $y,string $text,int $size,int $color,string $font): void
    {
        imagettftext($im,$size,0,$x,$y,$color,$font,$text);
    }

    private static function roundRect(\GdImage $im,int $x1,int $y1,int $x2,int $y2,int $radius,int $color): void
    {
        $radius=max(1,min($radius,(int)(($x2-$x1)/2),(int)(($y2-$y1)/2)));
        imagefilledrectangle($im,$x1+$radius,$y1,$x2-$radius,$y2,$color);
        imagefilledrectangle($im,$x1,$y1+$radius,$x2,$y2-$radius,$color);
        imagefilledellipse($im,$x1+$radius,$y1+$radius,$radius*2,$radius*2,$color);
        imagefilledellipse($im,$x2-$radius,$y1+$radius,$radius*2,$radius*2,$color);
        imagefilledellipse($im,$x1+$radius,$y2-$radius,$radius*2,$radius*2,$color);
        imagefilledellipse($im,$x2-$radius,$y2-$radius,$radius*2,$radius*2,$color);
    }

    private static function drawCrown(\GdImage $im,int $x,int $y,int $gold): void
    {
        imagefilledpolygon($im,[
            $x,$y+6,$x+7,$y+11,$x+12,$y+1,
            $x+17,$y+11,$x+25,$y+6,$x+21,$y+22,$x+4,$y+22
        ],$gold);
        imagefilledrectangle($im,$x+4,$y+24,$x+21,$y+26,$gold);
        foreach([[$x,$y+5],[$x+12,$y],[$x+25,$y+5]] as [$cx,$cy]){
            imagefilledellipse($im,$cx,$cy,4,4,$gold);
        }
    }
}
