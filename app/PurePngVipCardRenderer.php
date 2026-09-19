<?php declare(strict_types=1);
namespace App;

/**
 * Zero-dependency PNG renderer used when GD/Imagick are unavailable.
 * It draws a compact 5x7 bitmap font directly into RGB scanlines and
 * writes a standards-compliant PNG using zlib. Exact original text stays
 * available in the Telegram caption; the card is a visual summary.
 */
final class PurePngVipCardRenderer
{
    private int $width;
    private int $height;
    /** @var array<int,string> */
    private array $rows=[];

    private const FONT=[
        'A'=>['01110','10001','10001','11111','10001','10001','10001'],
        'B'=>['11110','10001','10001','11110','10001','10001','11110'],
        'C'=>['01111','10000','10000','10000','10000','10000','01111'],
        'D'=>['11110','10001','10001','10001','10001','10001','11110'],
        'E'=>['11111','10000','10000','11110','10000','10000','11111'],
        'F'=>['11111','10000','10000','11110','10000','10000','10000'],
        'G'=>['01111','10000','10000','10111','10001','10001','01111'],
        'H'=>['10001','10001','10001','11111','10001','10001','10001'],
        'I'=>['11111','00100','00100','00100','00100','00100','11111'],
        'J'=>['00111','00010','00010','00010','10010','10010','01100'],
        'K'=>['10001','10010','10100','11000','10100','10010','10001'],
        'L'=>['10000','10000','10000','10000','10000','10000','11111'],
        'M'=>['10001','11011','10101','10101','10001','10001','10001'],
        'N'=>['10001','11001','10101','10011','10001','10001','10001'],
        'O'=>['01110','10001','10001','10001','10001','10001','01110'],
        'P'=>['11110','10001','10001','11110','10000','10000','10000'],
        'Q'=>['01110','10001','10001','10001','10101','10010','01101'],
        'R'=>['11110','10001','10001','11110','10100','10010','10001'],
        'S'=>['01111','10000','10000','01110','00001','00001','11110'],
        'T'=>['11111','00100','00100','00100','00100','00100','00100'],
        'U'=>['10001','10001','10001','10001','10001','10001','01110'],
        'V'=>['10001','10001','10001','10001','10001','01010','00100'],
        'W'=>['10001','10001','10001','10101','10101','10101','01010'],
        'X'=>['10001','10001','01010','00100','01010','10001','10001'],
        'Y'=>['10001','10001','01010','00100','00100','00100','00100'],
        'Z'=>['11111','00001','00010','00100','01000','10000','11111'],
        '0'=>['01110','10001','10011','10101','11001','10001','01110'],
        '1'=>['00100','01100','00100','00100','00100','00100','01110'],
        '2'=>['01110','10001','00001','00010','00100','01000','11111'],
        '3'=>['11110','00001','00001','01110','00001','00001','11110'],
        '4'=>['00010','00110','01010','10010','11111','00010','00010'],
        '5'=>['11111','10000','10000','11110','00001','00001','11110'],
        '6'=>['01110','10000','10000','11110','10001','10001','01110'],
        '7'=>['11111','00001','00010','00100','01000','01000','01000'],
        '8'=>['01110','10001','10001','01110','10001','10001','01110'],
        '9'=>['01110','10001','10001','01111','00001','00001','01110'],
        ' '=>['00000','00000','00000','00000','00000','00000','00000'],
        '-'=>['00000','00000','00000','11111','00000','00000','00000'],
        '/'=>['00001','00010','00010','00100','01000','01000','10000'],
        ':'=>['00000','00100','00100','00000','00100','00100','00000'],
        '.'=>['00000','00000','00000','00000','00000','00110','00110'],
        ','=>['00000','00000','00000','00000','00110','00110','00100'],
        '('=>['00010','00100','01000','01000','01000','00100','00010'],
        ')'=>['01000','00100','00010','00010','00010','00100','01000'],
        '+'=>['00000','00100','00100','11111','00100','00100','00000'],
        '%'=>['11001','11010','00100','01000','10110','00110','00000'],
        '='=>['00000','00000','11111','00000','11111','00000','00000'],
        '?'=>['01110','10001','00001','00010','00100','00000','00100'],
        '!'=>['00100','00100','00100','00100','00100','00000','00100'],
        '#'=>['01010','11111','01010','01010','11111','01010','00000'],
        '*'=>['00000','10101','01110','11111','01110','10101','00000'],
        "'"=>['00100','00100','00000','00000','00000','00000','00000'],
        '"'=>['01010','01010','00000','00000','00000','00000','00000'],
    ];

    /** Compact card presentation, including the preserved author analysis. */
    public static function render(array $bet): ?string
    {
        if(!function_exists('gzcompress'))return null;
        $analysis=trim((string)($bet['analysis']??''));
        $displayAnalysis=$analysis!==''?$analysis:'Análise não fornecida no conteúdo original.';
        $analysisLines=self::wrap($displayAnalysis,76,75);
        if($analysisLines===null)return null; // No visual truncation of original analysis.
        $analysisY=525;
        $analysisHeight=max(116,74+count($analysisLines)*27);
        $dividerY=$analysisY+$analysisHeight+28;
        $height=$dividerY+103;
        if($height>3500)return null;

        $c=new self(1080,$height,'#161d24');
        $c->roundedRect(18,18,1044,$height-36,22,'#3c4d5b');
        $c->roundedRect(21,21,1038,$height-42,20,'#161d24');

        // Render only the fields approved for the card. No source-tip time,
        // bookmaker, currency amount, return, profit or receipt sections.
        $sport=self::sportLabel((string)($bet['sport']??''));
        $c->roundedRect(52,43,210,42,18,'#1b3230');
        // Small vector sport icon, rather than a missing Unicode glyph.
        $c->roundedRect(66,55,17,17,8,$sport==='BASQUETE'?'#ffac55':'#f3f6fa');
        if($sport==='FUTEBOL'){
            $c->roundedRect(73,61,5,5,2,'#161d24');
            $c->rect(69,56,2,3,'#161d24');
            $c->rect(80,66,2,3,'#161d24');
        }elseif($sport==='BASQUETE'){
            $c->rect(74,55,2,17,'#161d24');
            $c->rect(66,63,17,2,'#161d24');
        }elseif($sport==='TENIS'){
            $c->rect(70,55,2,17,'#161d24');
            $c->rect(78,55,2,17,'#161d24');
        }else{
            $c->rect(72,62,5,3,'#161d24');
        }
        $c->textFit(92,56,$sport,2,'#87efc9',16);
        // Gold VIP seal with a crown, drawn as simple vector primitives.
        $c->roundedRect(820,43,211,42,19,'#ffc94e');
        $c->roundedRect(822,45,207,38,17,'#29251c');
        $c->rect(836,56,22,4,'#ffc94e');
        $c->rect(839,53,16,4,'#ffc94e');
        $c->rect(836,52,4,4,'#ffc94e');
        $c->rect(845,47,4,9,'#ffc94e');
        $c->rect(854,52,4,4,'#ffc94e');
        $c->rect(837,62,20,3,'#ffc94e');
        $c->text(866,56,'APOSTA VIP',2,'#ffc94e');

        $match=trim((string)($bet['match']??''))?:'APOSTA ESPORTIVA';
        $c->textFit(53,131,$match,4,'#f3f6fa',40);
        $league=trim((string)($bet['league']??''));
        if($league!=='')$c->textFit(53,187,$league,2,'#a8bdcd',75);
        $c->rect(53,219,974,2,'#3c4d5b');

        $c->text(53,260,'MERCADO',2,'#a8bdcd');
        $c->textFit(53,295,(string)($bet['market']??''),3,'#f3f6fa',53);
        $c->rect(53,317,974,2,'#3c4d5b');
        $c->text(53,341,'SELECAO',2,'#a8bdcd');
        $c->textFit(53,377,(string)($bet['selection']??''),3,'#f3f6fa',53);

        $c->roundedRect(53,407,476,98,15,'#1b3230');
        $c->roundedRect(547,407,480,98,15,'#1b3230');
        $c->text(71,429,'ODD',2,'#a8bdcd');
        $c->text(565,429,'STAKE',2,'#a8bdcd');
        $c->textFit(71,456,trim((string)($bet['odd']??''))?:'-',5,'#87efc9',14);
        $c->textFit(565,456,trim((string)($bet['stake']??''))?:'-',5,'#87efc9',14);

        $c->roundedRect(53,$analysisY,974,$analysisHeight,17,'#1d2935');
        $c->text(71,$analysisY+24,'ANALISE ORIGINAL',2,'#a8bdcd');
        $cursor=$analysisY+61;
        foreach($analysisLines as $line){
            $c->text(71,$cursor,$line,2,'#f3f6fa');
            $cursor+=27;
        }
        $c->rect(53,$dividerY,974,2,'#3c4d5b');
        $c->lightning(338,$dividerY+31,'#ffce4f');
        $c->text(366,$dividerY+38,'TELEGRAMROUTER - APOSTA ENCAMINHADA',2,'#87efc9');

        $path=sys_get_temp_dir().'/tmr-vip-pure-'.bin2hex(random_bytes(12)).'.png';
        return $c->save($path)?$path:null;
    }

    private static function sportLabel(string $raw): string
    {
        $raw=mb_strtolower(trim($raw),'UTF-8');
        if($raw===''||preg_match('/futebol|football|soccer/u',$raw))return 'FUTEBOL';
        if(preg_match('/basquete|basketball|nba/u',$raw))return 'BASQUETE';
        if(preg_match('/tênis|tenis|tennis/u',$raw))return 'TENIS';
        if(preg_match('/vôlei|volei|volleyball/u',$raw))return 'VOLEI';
        if(preg_match('/hóquei|hoquei|hockey/u',$raw))return 'HOQUEI';
        if(preg_match('/beisebol|baseball/u',$raw))return 'BEISEBOL';
        return 'ESPORTE';
    }

    private function __construct(int $width,int $height,string $background)
    {
        $this->width=$width;$this->height=$height;
        $row=str_repeat(self::rgb($background),$width);
        $this->rows=array_fill(0,$height,$row);
    }

    private static function rgb(string $hex): string
    {
        $hex=ltrim($hex,'#');
        return chr(hexdec(substr($hex,0,2))).chr(hexdec(substr($hex,2,2))).chr(hexdec(substr($hex,4,2)));
    }

    private function rect(int $x,int $y,int $w,int $h,string $hex): void
    {
        if($w<=0||$h<=0)return;
        $x=max(0,$x);$y=max(0,$y);
        $w=min($w,$this->width-$x);$h=min($h,$this->height-$y);
        if($w<=0||$h<=0)return;
        $segment=str_repeat(self::rgb($hex),$w);
        $offset=$x*3;$length=$w*3;
        for($yy=$y;$yy<$y+$h;$yy++){
            $this->rows[$yy]=substr_replace($this->rows[$yy],$segment,$offset,$length);
        }
    }

    private function roundedRect(int $x,int $y,int $w,int $h,int $radius,string $hex): void
    {
        $radius=max(0,min($radius,(int)floor(min($w,$h)/2)));
        if($radius===0){$this->rect($x,$y,$w,$h,$hex);return;}
        for($dy=0;$dy<$h;$dy++){
            $inset=0;
            if($dy<$radius){
                $v=$radius-$dy-0.5;
                $inset=(int)ceil($radius-sqrt(max(0,$radius*$radius-$v*$v)));
            } elseif($dy>=$h-$radius){
                $v=$dy-($h-$radius)+0.5;
                $inset=(int)ceil($radius-sqrt(max(0,$radius*$radius-$v*$v)));
            }
            $this->rect($x+$inset,$y+$dy,$w-($inset*2),1,$hex);
        }
    }

    private function lightning(int $x,int $y,string $hex): void
    {
        $this->rect($x+8,$y,7,12,$hex);
        $this->rect($x+4,$y+9,13,6,$hex);
        $this->rect($x,$y+14,11,6,$hex);
        $this->rect($x+4,$y+18,7,12,$hex);
    }

    private function textFit(int $x,int $y,string $text,int $scale,string $hex,int $maxChars): void
    {
        $clean=self::clean($text);
        if(mb_strlen($clean,'UTF-8')>$maxChars)$clean=mb_substr($clean,0,$maxChars-1,'UTF-8').'.';
        $this->text($x,$y,$clean,$scale,$hex);
    }

    private function text(int $x,int $y,string $text,int $scale,string $hex): void
    {
        $clean=self::clean($text);
        $color=$hex;
        $cursor=$x;
        foreach(str_split($clean) as $ch){
            $glyph=self::FONT[$ch]??self::FONT['?'];
            foreach($glyph as $row=>$bits){
                for($col=0;$col<5;$col++){
                    if(($bits[$col]??'0')==='1')$this->rect($cursor+($col*$scale),$y+($row*$scale),$scale,$scale,$color);
                }
            }
            $cursor+=6*$scale;
            if($cursor>$this->width-(6*$scale))break;
        }
    }

    private static function clean(string $text): string
    {
        $text=strtr($text,[
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
            'ç'=>'c','Ç'=>'C','ñ'=>'n','Ñ'=>'N','ý'=>'y','Ý'=>'Y',
            '×'=>'X','•'=>'-','–'=>'-','—'=>'-','€'=>' EUR ','£'=>' GBP ','$'=>' USD ',
            '&'=>' E ','@'=>' AT ',
        ]);
        $text=mb_strtoupper($text,'UTF-8');
        $text=preg_replace('/[^A-Z0-9 .,:;!?()\-\/+%=\"\'#*]/u','?',$text)??$text;
        return preg_replace('/\s+/',' ',trim($text))??trim($text);
    }

    /** @return list<string>|null */
    private static function wrap(string $text,int $maxChars,int $maxLines): ?array
    {
        $clean=self::clean($text);
        if($clean==='')return [];
        $words=preg_split('/\s+/',$clean)?:[];
        $lines=[];$line='';
        foreach($words as $word){
            if(strlen($word)>$maxChars)return null;
            $candidate=$line===''?$word:$line.' '.$word;
            if(strlen($candidate)>$maxChars && $line!==''){
                $lines[]=$line;$line=$word;
                if(count($lines)>=$maxLines)return null;
            } else $line=$candidate;
        }
        if($line!=='')$lines[]=$line;
        return count($lines)>$maxLines?null:$lines;
    }

    private function save(string $path): bool
    {
        $raw='';
        foreach($this->rows as $row)$raw.="\x00".$row;
        $compressed=gzcompress($raw,7);
        if(!is_string($compressed))return false;
        $png="\x89PNG\r\n\x1a\n";
        $png.=$this->chunk('IHDR',pack('NNCCCCC',$this->width,$this->height,8,2,0,0,0));
        $png.=$this->chunk('IDAT',$compressed);
        $png.=$this->chunk('IEND','');
        $ok=@file_put_contents($path,$png)!==false;
        if($ok)@chmod($path,0600);
        return $ok;
    }

    private function chunk(string $type,string $data): string
    {
        $crc=crc32($type.$data);
        if($crc<0)$crc+=4294967296;
        return pack('N',strlen($data)).$type.$data.pack('N',$crc);
    }
}
