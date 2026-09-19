<?php declare(strict_types=1);
namespace App;

/** Purely presentational VIP ticket renderer. Never alters the source bet. */
final class VipCardRenderer
{
    private const BG='#141b23';
    private const GREEN='#87efc9';

    /** Card-only presentation. The original message and extracted fields stay unchanged. */
    public static function render(array $bet): ?string
    {
        if(getenv('VIP_CARD_FORCE_PURE')==='1' || !extension_loaded('gd') || !function_exists('imagettftext')){
            return PurePngVipCardRenderer::render($bet);
        }
        $font=self::font(false); $bold=self::font(true);
        if(!$font || !$bold)return PurePngVipCardRenderer::render($bet);

        $analysis=trim((string)($bet['analysis']??''));
        $displayAnalysis=$analysis!==''?$analysis:'Análise não fornecida no conteúdo original.';
        $analysisLines=self::wrapAnalysis($displayAnalysis,$font,19,930);
        if($analysisLines===null)return null; // Never truncate the author's analysis.

        $w=1080; $analysisY=523;
        $analysisHeight=max(114,75+count($analysisLines)*30);
        $dividerY=$analysisY+$analysisHeight+28;
        $h=$dividerY+105;
        if($h>4000)return null;
        $im=imagecreatetruecolor($w,$h);
        if(!$im)return null;
        imagealphablending($im,true);imagesavealpha($im,true);
        $color=static fn(string $hex): int=>imagecolorallocate($im,hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2)));
        $bg=$color('#161d24');$white=$color('#f3f6fa');$muted=$color('#a8bdcd');
        $green=$color('#87efc9');$panel=$color('#1b3230');$analysisBg=$color('#1d2935');
        $line=$color('#3c4d5b');
        imagefilledrectangle($im,0,0,$w,$h,$bg);
        self::roundRect($im,22,18,1058,$h-18,25,$line);
        self::roundRect($im,24,20,1056,$h-20,23,$bg);

        // Header and typography match the approved compact dark card.
        self::roundRect($im,53,44,179,79,17,$panel);
        self::txt($im,64,69,'FUTEBOL',15,$green,$bold);
        self::txt($im,864,70,'APOSTA DO DIA',15,$muted,$font);

        $title=trim((string)($bet['match']??''));
        $league=trim((string)($bet['league']??''));
        $market=trim((string)($bet['market']??''));
        $selection=trim((string)($bet['selection']??''));
        foreach([[$title,$bold,33,18,950],[$league,$font,19,14,950],
            [$market,$bold,23,16,950],[$selection,$bold,24,16,950]] as [$value,$face,$preferred,$min,$width]){
            if($value!=='' && self::fitSize($value,$face,$preferred,$min,$width)===null){
                imagedestroy($im);return null;
            }
        }
        self::txt($im,53,142,$title, self::fitSize($title,$bold,33,18,950)??33,$white,$bold);
        if($league!=='')self::txt($im,53,185,$league,self::fitSize($league,$font,19,14,950)??19,$muted,$font);
        imageline($im,53,218,1027,218,$line);

        self::txt($im,53,261,'MERCADO',16,$muted,$font);
        self::txt($im,53,295,$market,self::fitSize($market,$bold,23,16,950)??23,$white,$bold);
        self::txt($im,53,344,'SELEÇÃO',16,$muted,$font);
        self::txt($im,53,382,$selection,self::fitSize($selection,$bold,24,16,950)??24,$white,$bold);

        self::roundRect($im,53,407,532,505,15,$panel);
        self::roundRect($im,547,407,1027,505,15,$panel);
        self::txt($im,71,437,'ODD',16,$muted,$font);
        self::txt($im,565,437,'STAKE',16,$muted,$font);
        $odd=trim((string)($bet['odd']??''))?:'—';
        $stake=trim((string)($bet['stake']??''))?:'—';
        $oddSize=self::fitSize($odd,$bold,42,22,435);
        $stakeSize=self::fitSize($stake,$bold,42,22,435);
        if($oddSize===null||$stakeSize===null){imagedestroy($im);return null;}
        self::txt($im,71,490,$odd,$oddSize,$green,$bold);
        self::txt($im,565,490,$stake,$stakeSize,$green,$bold);

        self::roundRect($im,53,$analysisY,1027,$analysisY+$analysisHeight,17,$analysisBg);
        self::txt($im,72,$analysisY+35,'ANÁLISE ORIGINAL',16,$muted,$font);
        $cursor=$analysisY+72;
        foreach($analysisLines as $part){
            self::txt($im,72,$cursor,$part,19,$white,$font);
            $cursor+=30;
        }
        imageline($im,53,$dividerY,1027,$dividerY,$line);
        // Draw the lightning emblem separately to avoid unsupported emoji font glyphs.
        imagefilledpolygon($im,[380,$dividerY+38,392,$dividerY+38,387,$dividerY+49,
            398,$dividerY+49,378,$dividerY+75,385,$dividerY+56,375,$dividerY+56],8,$color('#ffce4f'));
        self::txt($im,406,$dividerY+62,'TelegramRouter • Aposta encaminhada',16,$green,$font);

        $path=sys_get_temp_dir().'/tmr-vip-'.bin2hex(random_bytes(12)).'.png';
        $ok=imagepng($im,$path,7);imagedestroy($im);
        if(!$ok||!is_file($path)){@unlink($path);return null;}
        @chmod($path,0600);
        return $path;
    }
    /** Fit non-analysis labels without silently cutting betting information. */
    private static function fitSize(string $text,string $font,int $preferred,int $min,int $maxWidth): ?int
    {
        if($text==='')return $preferred;
        for($size=$preferred;$size>=$min;$size--){
            $bounds=imagettfbbox($size,0,$font,$text);
            if(is_array($bounds)&&abs($bounds[2]-$bounds[0])<=$maxWidth)return $size;
        }
        return null;
    }
    /** @return list<string>|null */
    private static function wrapAnalysis(string $text,string $font,int $size,int $maxWidth): ?array
    {
        $lines=[];
        foreach(preg_split('/\\R/u',$text)?:[] as $paragraph){
            $words=preg_split('/\\s+/u',trim($paragraph))?:[];
            $line='';
            foreach($words as $word){
                if($word==='')continue;
                $candidate=$line===''?$word:$line.' '.$word;
                $bounds=imagettfbbox($size,0,$font,$candidate);
                if(!is_array($bounds))return null;
                if(abs($bounds[2]-$bounds[0])>$maxWidth){
                    if($line==='')return null;
                    $lines[]=$line;$line=$word;
                    $single=imagettfbbox($size,0,$font,$line);
                    if(!is_array($single)||abs($single[2]-$single[0])>$maxWidth)return null;
                }else{$line=$candidate;}
                if(count($lines)>100)return null;
            }
            if($line!=='')$lines[]=$line;
            else if($paragraph==='')$lines[]='';
        }
        return count($lines)<=100?$lines:null;
    }
    private static function font(bool $bold): ?string
    {
        foreach($bold?[
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf'
        ]:[
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf'
        ] as $candidate)if(is_file($candidate))return $candidate;
        return null;
    }
    private static function fit(string $str,int $max): string
    {
        return mb_strlen($str,'UTF-8')<=$max?$str:mb_substr($str,0,$max-1,'UTF-8').'…';
    }
    /** @return list<string>|null */
    private static function lines(string $str,int $max): ?array
    {
        $words=preg_split('/\s+/u',trim($str))?:[];
        $lines=[];$line='';
        foreach($words as $word){
            if($word==='')continue;
            if(mb_strlen($word,'UTF-8')>$max)return null;
            $candidate=$line===''?$word:$line.' '.$word;
            if(mb_strlen($candidate,'UTF-8')>$max&&$line!==''){$lines[]=$line;$line=$word;}
            else $line=$candidate;
            if(count($lines)>65)return null;
        }
        if($line!=='')$lines[]=$line;
        return $lines?:['Análise não fornecida no conteúdo original.'];
    }
    private static function txt(\GdImage $im,int $x,int $y,string $text,int $size,int $color,string $font): void
    {
        imagettftext($im,$size,0,$x,$y,$color,$font,$text);
    }
    private static function roundRect(\GdImage $im,int $x1,int $y1,int $x2,int $y2,int $r,int $color): void
    {
        imagefilledrectangle($im,$x1+$r,$y1,$x2-$r,$y2,$color);
        imagefilledrectangle($im,$x1,$y1+$r,$x2,$y2-$r,$color);
        foreach([[$x1+$r,$y1+$r],[$x2-$r,$y1+$r],[$x1+$r,$y2-$r],[$x2-$r,$y2-$r]] as [$cx,$cy]){
            imagefilledellipse($im,$cx,$cy,$r*2,$r*2,$color);
        }
    }
}
