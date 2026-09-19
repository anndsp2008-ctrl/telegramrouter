<?php declare(strict_types=1);
namespace App;

/** Purely presentational VIP ticket renderer. Never alters the source bet. */
final class VipCardRenderer
{
    private const BG='#141b23';
    private const GREEN='#87efc9';

    /** Render using the premium font when possible; a GD-specific failure
     * never turns an otherwise valid bet back into a raw forwarded message.
     * The emergency renderer retains all analysis in the Telegram caption.
     */
    public static function render(array $bet): ?string
    {
        if(getenv('VIP_CARD_FORCE_PURE')==='1' || !extension_loaded('gd') || !function_exists('imagettftext')){
            return PurePngVipCardRenderer::render($bet);
        }
        try {
            $path=self::renderWithGd($bet);
            if($path!==null)return $path;
            error_log('TMR_VIP_CARD_GD_UNAVAILABLE');
        } catch(\Throwable $e){
            // Do not log source tips, payloads, file paths or personal data.
            error_log('TMR_VIP_CARD_GD_ERROR '.preg_replace('/[^A-Za-z0-9_]/','_',get_class($e)).' line='.$e->getLine());
        }
        return PurePngVipCardRenderer::render($bet);
    }
    /** Card-only presentation. The original message and extracted fields stay unchanged. */
    private static function renderWithGd(array $bet): ?string
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

        // Two premium header seals: an icon matching the detected sport and
        // a golden VIP crown. Only the card presentation is changed.
        [$sport,$sportId]=self::sportInfo((string)($bet['sport']??''));
        $sportLabelSize=self::fitSize($sport,$bold,15,12,210)??12;
        $sportWidth=min(295,max(145,86+(int)abs((imagettfbbox($sportLabelSize,0,$bold,$sport)[2]??0))));
        self::roundRect($im,53,43,53+$sportWidth,84,20,$panel);
        self::drawSportIcon($im,76,63,$sportId,$white,$bg,$color('#ffac55'),$color('#d2fb6e'));
        self::txt($im,98,70,$sport,$sportLabelSize,$green,$bold);
        // Draw LIVE alongside the existing VIP seal only for an explicitly
        // identified live match; an unsettled ticket alone is not evidence.
        $isLive=self::isLiveStatus((string)($bet['status']??''));
        if($isLive){
            $live=$color('#ff9255');$liveBg=$color('#39261e');
            self::roundRect($im,668,42,807,85,20,$live);
            self::roundRect($im,670,44,805,83,18,$liveBg);
            imagefilledellipse($im,687,63,10,10,$live);
            self::txt($im,704,70,'AO VIVO',14,$live,$bold);
        }
        $gold=$color('#ffc94e');$vipBackground=$color('#29251c');
        self::roundRect($im,817,42,1027,85,20,$gold);
        self::roundRect($im,819,44,1025,83,18,$vipBackground);
        self::drawCrown($im,835,54,$gold);
        self::txt($im,869,71,'APOSTA VIP',16,$gold,$bold);

        $title=trim((string)($bet['match']??''));
        $league=trim((string)($bet['league']??''));
        $market=trim((string)($bet['market']??''));
        $selection=trim((string)($bet['selection']??''));
        foreach([[$title,$bold,33,18,950],[$league,$font,19,14,950],
            [$market,$bold,23,16,950],[$selection,$bold,24,16,950]] as [$value,$face,$preferred,$min,$width]){
            if($value!=='' && self::fitSize($value,$face,$preferred,$min,$width)===null){
                unset($im);return null;
            }
        }
        self::txt($im,53,142,$title, self::fitSize($title,$bold,33,18,950)??33,$white,$bold);
        if($league!=='')self::txt($im,53,185,$league,self::fitSize($league,$font,19,14,950)??19,$muted,$font);
        imageline($im,53,218,1027,218,$line);

        self::txt($im,53,261,'MERCADO',16,$muted,$font);
        self::txt($im,53,295,$market,self::fitSize($market,$bold,23,16,950)??23,$white,$bold);
        // Section separators match the reference without crowding the text.
        imageline($im,53,317,1027,317,$line);
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
        if($oddSize===null||$stakeSize===null){unset($im);return null;}
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
        // Align the full footer group to the card, independently of the font.
        $footer='TelegramRouter • Aposta encaminhada';
        $footerBox=imagettfbbox(16,0,$font,$footer);
        $footerWidth=is_array($footerBox)?abs($footerBox[2]-$footerBox[0]):365;
        $footerX=(int)(($w-$footerWidth-34)/2);
        imagefilledpolygon($im,[$footerX,$dividerY+37,$footerX+17,$dividerY+37,
            $footerX+10,$dividerY+50,$footerX+22,$dividerY+50,
            $footerX-3,$dividerY+74,$footerX+4,$dividerY+56,
            $footerX-4,$dividerY+56],$color('#ffce4f'));
        self::txt($im,$footerX+32,$dividerY+62,$footer,16,$green,$font);

        $path=sys_get_temp_dir().'/tmr-vip-'.bin2hex(random_bytes(12)).'.png';
        // GD images are PHP objects: the old explicit destructor raises a deprecation
        // on PHP 8.5, which MadelineProto promotes into an exception. Let the
        // object go out of scope after writing the antialiased TrueType card.
        $ok=imagepng($im,$path,7);unset($im);
        if(!$ok||!is_file($path)){@unlink($path);return null;}
        @chmod($path,0600);
        return $path;
    }
    /** Return a live seal only when the source explicitly marks the match live. */
    private static function isLiveStatus(string $raw): bool
    {
        $status=mb_strtoupper(trim($raw),'UTF-8');
        return in_array($status,['AO VIVO','LIVE','EN VIVO','IN PLAY','IN-PLAY'],true);
    }
    /** Only known sports get specific icons. Unknown sports are clearly generic. */
    private static function sportInfo(string $raw): array
    {
        $sport=mb_strtolower(trim($raw),'UTF-8');
        if(preg_match('/futebol|football|soccer/u',$sport))return ['FUTEBOL','football'];
        if(preg_match('/basquete|basketball|nba/u',$sport))return ['BASQUETE','basketball'];
        if(preg_match('/tênis|tenis|tennis/u',$sport))return ['TÊNIS','tennis'];
        if(preg_match('/vôlei|volei|volleyball/u',$sport))return ['VÔLEI','volleyball'];
        if(preg_match('/hóquei|hoquei|hockey/u',$sport))return ['HÓQUEI','hockey'];
        if(preg_match('/beisebol|baseball/u',$sport))return ['BEISEBOL','baseball'];
        return ['ESPORTE','generic'];
    }
    private static function drawSportIcon(\GdImage $im,int $x,int $y,string $sport,int $white,int $dark,int $orange,int $yellow): void
    {
        if($sport==='generic'){
            imageellipse($im,$x,$y,22,22,$white);
            imageline($im,$x-5,$y,$x+5,$y,$white);
            imageline($im,$x,$y-5,$x,$y+5,$white);
            return;
        }
        // PHP 8.4 deprecates the polygon vertex-count argument; using its
        // modern three-argument signature avoids MadelineProto warning exceptions.
        $ball=$sport==='basketball'?$orange:($sport==='tennis'?$yellow:$white);
        imagefilledellipse($im,$x,$y,22,22,$ball);
        if($sport==='football'){
            imagefilledpolygon($im,[$x,$y-5,$x+6,$y-2,$x+5,$y+4,$x,$y+7,
                $x-5,$y+4,$x-6,$y-2],$dark);
            foreach([[0,-11],[10,-3],[7,9],[-7,9],[-10,-3]] as [$dx,$dy]){
                imagefilledellipse($im,$x+$dx,$y+$dy,5,5,$dark);
            }
        }elseif($sport==='basketball'){
            imageline($im,$x-11,$y,$x+11,$y,$dark);
            imageline($im,$x,$y-11,$x,$y+11,$dark);
            imagearc($im,$x-6,$y,14,22,265,95,$dark);
            imagearc($im,$x+6,$y,14,22,85,275,$dark);
        }elseif($sport==='tennis'){
            imagearc($im,$x-7,$y,17,26,275,85,$white);
            imagearc($im,$x+7,$y,17,26,95,265,$white);
        }elseif($sport==='volleyball'){
            imagearc($im,$x,$y,18,18,20,155,$dark);
            imagearc($im,$x,$y,18,18,145,280,$dark);
            imagearc($im,$x,$y,18,18,265,400,$dark);
        }elseif($sport==='baseball'){
            imagearc($im,$x-7,$y,15,25,280,80,$orange);
            imagearc($im,$x+7,$y,15,25,100,260,$orange);
        }elseif($sport==='hockey'){
            imagefilledellipse($im,$x,$y,22,12,$dark);
            imageellipse($im,$x,$y,22,12,$white);
        }
    }
    private static function drawCrown(\GdImage $im,int $x,int $y,int $gold): void
    {
        imagefilledpolygon($im,[$x,$y+6,$x+7,$y+11,$x+12,$y+1,
            $x+17,$y+11,$x+25,$y+6,$x+21,$y+22,$x+4,$y+22],$gold);
        imagefilledrectangle($im,$x+4,$y+24,$x+21,$y+26,$gold);
        foreach([[$x,$y+5],[$x+12,$y],[$x+25,$y+5]] as [$cx,$cy])
            imagefilledellipse($im,$cx,$cy,4,4,$gold);
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
        // Liberation Sans is closer to the approved modern sans-serif reference.
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
