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

    public static function render(array $bet): ?string
    {
        if(!function_exists('gzcompress'))return null;
        $analysis=trim((string)($bet['analysis']??''));
        $analysisLines=self::wrap($analysis,72,42);
        if($analysis!=='' && $analysisLines===null)return null;

        $extra=[];
        foreach([
            'day'=>'DIA DA PARTIDA',
            'stake'=>'STAKE',
            'bookmaker'=>'CASA DE APOSTAS',
            'stake_amount'=>'VALOR APOSTADO',
            'potential_return'=>'RETORNO POTENCIAL',
        ] as $key=>$label){
            $value=trim((string)($bet[$key]??''));
            if($value!=='')$extra[]=[$label,$value];
        }
        $extra=array_slice($extra,0,5);

        $analysisCount=max(2,count($analysisLines??[]));
        $analysisHeight=62+($analysisCount*22)+24;
        $extraRows=(int)ceil(count($extra)/2);
        $height=760+($extraRows*74)+$analysisHeight+105;
        if($height>1900)return null;

        $c=new self(1080,$height,'#111821');
        $c->roundedRect(18,18,1044,$height-36,22,'#1d2a37');
        $c->roundedRect(24,24,1032,$height-48,19,'#111821');

        // Header tags.
        $c->roundedRect(48,48,178,38,16,'#153a2f');
        $c->text(64,59,'FUTEBOL',2,'#63e6be');
        $c->roundedRect(822,48,210,38,16,'#172b43');
        $c->text(842,59,'APOSTA VIP',2,'#76b7ff');

        $match=trim((string)($bet['match']??''))?:'APOSTA ESPORTIVA';
        $c->textFit(48,119,$match,4,'#f4f8fc',35);
        $league=trim((string)($bet['league']??''));
        if($league!=='')$c->textFit(48,174,$league,2,'#a6bbcc',70);
        $c->rect(48,218,984,2,'#304252');

        $c->text(48,253,'MERCADO',2,'#8fa7ba');
        $c->textFit(48,286,(string)($bet['market']??''),3,'#f4f8fc',53);
        $c->text(48,338,'SELECAO',2,'#8fa7ba');
        $c->textFit(48,371,(string)($bet['selection']??''),3,'#63e6be',53);

        $c->roundedRect(48,421,472,116,14,'#183431');
        $c->roundedRect(560,421,472,116,14,'#183431');
        $c->text(66,443,'ODD',2,'#8fa7ba');
        $c->textFit(66,479,trim((string)($bet['odd']??''))?:'-',5,'#63e6be',15);
        $c->text(578,443,'HORARIO',2,'#8fa7ba');
        $c->textFit(578,479,trim((string)($bet['time']??''))?:'-',5,'#63e6be',15);

        $y=570;
        for($i=0;$i<count($extra);$i+=2){
            foreach([0,1] as $col){
                $idx=$i+$col;if(!isset($extra[$idx]))continue;
                [$label,$value]=$extra[$idx];
                $x=$col===0?48:560;
                $c->text($x,$y,$label,2,'#8fa7ba');
                $c->textFit($x,$y+30,$value,2,'#f4f8fc',38);
            }
            $y+=74;
        }

        $analysisY=$y+10;
        $c->roundedRect(48,$analysisY,984,$analysisHeight,14,'#202f3f');
        $c->text(66,$analysisY+18,'ANALISE ORIGINAL',2,'#8fa7ba');
        $cursor=$analysisY+51;
        $lines=$analysisLines?:['ANALISE NAO FORNECIDA NO CONTEUDO ORIGINAL.'];
        foreach($lines as $line){
            $c->text(66,$cursor,$line,2,'#f4f8fc');
            $cursor+=22;
        }

        $lineY=$analysisY+$analysisHeight+30;
        $c->rect(48,$lineY,984,2,'#304252');
        $c->lightning(302,$lineY+36,'#63e6be');
        $c->text(326,$lineY+31,'TELEGRAMROUTER - APOSTA ENCAMINHADA',2,'#63e6be');

        $path=sys_get_temp_dir().'/tmr-vip-pure-'.bin2hex(random_bytes(12)).'.png';
        return $c->save($path)?$path:null;
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
