<?php declare(strict_types=1);
namespace App;

/** Purely presentational VIP ticket renderer. Never alters the source bet. */
final class VipCardRenderer
{
    private const BG='#141b23';
    private const GREEN='#87efc9';

    /** Returns a private PNG file path, or null when the host lacks a usable renderer. */
    public static function render(array $bet): ?string
    {
        if (!extension_loaded('gd') || !function_exists('imagettftext')) return null;
        $font=self::font(false); $bold=self::font(true);
        if (!$font || !$bold) return null;
        $w=1080; $pad=46;
        $analysis=trim((string)($bet['analysis']??''));
        $analysisLines=self::lines($analysis,83);
        if($analysisLines===null)return null; // Never truncate the author's analysis.
        $analysisHeight=max(100,count($analysisLines)*30+48);
        $h=min(4000,1010+$analysisHeight);
        $im=imagecreatetruecolor($w,$h);
        if (!$im) return null;
        imagealphablending($im,true); imagesavealpha($im,true);
        $color=static fn(string $hex): int => imagecolorallocate($im,hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2)));
        $bg=$color(self::BG); $white=$color('#f2f7fb'); $muted=$color('#9ab0c2');
        $green=$color(self::GREEN); $panel=$color('#1b302f'); $analysisBg=$color('#222f3e');$line=$color('#405261');
        imagefilledrectangle($im,0,0,$w,$h,$bg);
        self::roundRect($im,30,27,$w-30,$h-27,28,$color('#1b2632'));
        self::roundRect($im,36,33,$w-36,$h-33,25,$bg);
        self::roundRect($im,58,57,216,98,19,$panel);
        self::txt($im,73,86,'FUTEBOL',17,$green,$bold);
        self::roundRect($im,827,57,1022,98,19,$color('#1a293a'));
        self::txt($im,841,86,'APOSTA VIP',16,$color('#93c5fd'),$bold);
        $title=trim((string)($bet['match']??'')) ?: 'Aposta esportiva';
        self::txt($im,58,159,self::fit($title,36),31,$white,$bold);
        $league=trim((string)($bet['league']??''));
        if($league!=='')self::txt($im,58,209,self::fit($league,65),19,$muted,$font);
        imageline($im,58,247,1021,247,$line);
        self::txt($im,58,293,'MERCADO',16,$muted,$font);
        self::txt($im,58,329,self::fit((string)($bet['market']??''),61),23,$white,$bold);
        self::txt($im,58,381,'SELEÇÃO',16,$muted,$font);
        self::txt($im,58,422,self::fit((string)($bet['selection']??''),59),28,$green,$bold);
        self::roundRect($im,58,455,527,584,17,$panel);
        self::roundRect($im,540,455,1022,584,17,$panel);
        self::txt($im,78,491,'ODD',17,$muted,$font);
        self::txt($im,78,552,trim((string)($bet['odd']??'')) ?: '—',43,$green,$bold);
        self::txt($im,562,491,'HORÁRIO',17,$muted,$font);
        self::txt($im,562,552,trim((string)($bet['time']??'')) ?: '—',43,$green,$bold);
        $y=633;
        foreach (['day'=>'DIA DA PARTIDA','stake'=>'STAKE','bookmaker'=>'CASA DE APOSTAS','stake_amount'=>'VALOR APOSTADO','potential_return'=>'RETORNO POTENCIAL'] as $key=>$label) {
            $value=trim((string)($bet[$key]??'')); if($value==='')continue;
            self::txt($im,58,$y,$label,15,$muted,$font);
            self::txt($im,58,$y+33,self::fit($value,80),21,$white,$bold);
            $y+=69;
            if($y>765)break;
        }
        $sectionY=max(790,$y+30);
        $required=$sectionY+$analysisHeight+102;
        if($required>$h){imagedestroy($im);return null;}
        self::roundRect($im,58,$sectionY,1022,$sectionY+$analysisHeight,15,$analysisBg);
        self::txt($im,78,$sectionY+32,'ANÁLISE ORIGINAL',15,$muted,$font);
        $cursor=$sectionY+66;
        foreach($analysisLines as $part){self::txt($im,78,$cursor,$part,19,$white,$font);$cursor+=30;}
        imageline($im,58,$sectionY+$analysisHeight+32,1022,$sectionY+$analysisHeight+32,$line);
        self::txt($im,300,$sectionY+$analysisHeight+84,'⚡ TelegramRouter • Aposta encaminhada',16,$green,$font);
        $path=sys_get_temp_dir().'/tmr-vip-'.bin2hex(random_bytes(12)).'.png';
        $ok=imagepng($im,$path,7);imagedestroy($im);
        if(!$ok || !is_file($path)){@unlink($path);return null;}
        @chmod($path,0600);
        return $path;
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
