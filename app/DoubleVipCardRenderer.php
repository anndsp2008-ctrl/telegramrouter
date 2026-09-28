<?php declare(strict_types=1);
namespace App;
require_once __DIR__.'/MatchNameFormatter.php';

final class DoubleVipCardRenderer
{
    public static function sourceIsDouble(string $source): bool
    {
        return preg_match('~(?:^|\R)\s*(?:aposta\s+)?(?:dupla(?!\s+chance)|doble(?!\s+oportunidad)|double(?!\s+chance)|2\s+sele[cç][oõ]es)\b~iu',$source)===1;
    }

    public static function extractionInstruction(): string
    {
        return 'DUPLAS: Doble também é cabeçalho de aposta dupla. Para exatamente 2 seleções de jogos distintos, preencha double_legs como STRING contendo um array JSON de exatamente 2 objetos, na ordem do bilhete. Cada objeto tem strings match, market, selection, odd, market_evidence, selection_evidence, promotion. Use apenas os jogos e odds visíveis na imagem quando houver imagem; a legenda não adiciona seleções. Traduza match/market/selection conforme o idioma solicitado, mas copie as evidências sem tradução. Todo confronto deve usar exatamente o formato Time A vs Time B; x, X, ×, v e traços são aceitos somente na entrada. promotion deve ser betano_early_payout_2 somente quando o selo promocional +2 estiver claramente visível em Resultado da partida da Betano; caso contrário use string vazia. O selo +2 da Betano é pagamento antecipado, NUNCA handicap: a seleção é o time vencer, não vencer/empatar/perder por um gol. Handicap real deve manter sua linha quando o MERCADO indicar handicap. odd no nível principal é a odd total EXIBIDA, nunca a recalcule. Não invente odds ausentes. Para simples, mais de 2 seleções ou Bet Builder do mesmo jogo, double_legs deve ser vazio. Para duplas sem análise do autor, analysis deve ser vazio, prevalecendo sobre a instrução de gerar análise. ';
    }

    public static function isDouble(array $data,bool $hasImage): bool
    {
        $prefix=$hasImage?'visual_':'';
        return (string)($data[$prefix.'selections_count']??'')==='2'
            && in_array(mb_strtolower(trim((string)($data[$prefix.'bet_kind']??'')),'UTF-8'),
                ['multiple','multi','dupla','double','doble','múltipla','multipla','múltiple','combinada','acumulada','parlay','acca'],true);
    }

    public static function extract(array $data,bool $hasImage): ?array
    {
        if(!self::isDouble($data,$hasImage))return null;
        $raw=$data['double_legs']??'';
        if(!is_string($raw)||strlen($raw)>12000)return null;
        $legs=json_decode($raw,true,8);
        if(!is_array($legs)||!array_is_list($legs)||count($legs)!==2)return null;
        foreach($legs as &$leg){
            if(!is_array($leg))return null;
            foreach(['match','market','selection','odd','market_evidence','selection_evidence','promotion'] as $field){
                if(!is_string($leg[$field]??''))return null;
                $leg[$field]=trim($leg[$field]??'');
                if(mb_strlen($leg[$field],'UTF-8')>300)return null;
            }
            $leg['match']=MatchNameFormatter::normalize($leg['match']);
            foreach(['match','market','selection'] as $field)if($leg[$field]==='')return null;
            if($hasImage && ($leg['market_evidence']===''||$leg['selection_evidence']===''))return null;
            if($leg['odd']!=='' && !self::validOdd($leg['odd']))return null;
            if($leg['promotion']!=='' && $leg['promotion']!=='betano_early_payout_2')return null;
            if(preg_match('~resultado (?:da|del) parti(?:da|do)|match result|resultado final~iu',$leg['market_evidence'])
                && preg_match('~\+\s*2\b~u',$leg['selection']))return null;
            if($leg['promotion']==='betano_early_payout_2'){
                if(!preg_match('~resultado (?:da|del) parti(?:da|do)|match result|resultado final~iu',$leg['market_evidence']))return null;
                if(!preg_match('~\+\s*2\b~u',$leg['selection_evidence']))return null;
                // A promotional badge cannot silently become a handicap selection.
                if(preg_match('~handicap|\+\s*2\b|empatar|empate|perder~iu',$leg['selection']))return null;
            }
        }
        unset($leg);
        if(mb_strtolower($legs[0]['match'])===mb_strtolower($legs[1]['match']))return null;
        $odd=trim((string)($data['odd']??''));
        if($odd!==''&&!self::validOdd($odd))return null;
        return ['sport'=>trim((string)($data['sport']??'')),'legs'=>$legs,'odd'=>$odd,'analysis'=>''];
    }

    private static function validOdd(string $odd): bool
    {
        return preg_match('/^\d{1,5}(?:[.,]\d{1,3})?$/D',$odd)===1 && (float)str_replace(',','.',$odd)>1;
    }

    private static function odd(string $value): string
    {
        return $value===''?'—':str_replace(',','.',$value);
    }

    public static function caption(array $bet): string
    {
        $lines=['🏆 DUPLA • 2 seleções',''];
        foreach($bet['legs'] as $index=>$leg){
            $lines[]='⚽ '.($index+1).'. '.MatchNameFormatter::normalize((string)$leg['match']);
            $lines[]='🎯 Mercado: '.$leg['market'];
            $lines[]='✅ Seleção: '.$leg['selection'];
            $lines[]='📈 Odd: '.self::odd($leg['odd']);
            if($leg['promotion']==='betano_early_payout_2')$lines[]='⚡ +2 • Pagamento antecipado';
            $lines[]='';
        }
        $lines[]='📈 Odd total: '.self::odd($bet['odd']);
        $lines[]='📍 Stake: '.SmartFormatting::FIXED_STAKE;
        if(($bet['analysis']??'')!=='')$lines[]="\n📝 Análise original:\n".$bet['analysis'];
        return implode("\n",$lines);
    }

    public static function render(array $bet): ?string
    {
        if(getenv('VIP_CARD_FORCE_PURE')==='1'||!extension_loaded('gd')||!function_exists('imagettftext'))return null;
        $font=self::font(false);$bold=self::font(true);
        if($font===null||$bold===null)return null;
        try {
            return self::draw($bet,$font,$bold);
        } catch(\Throwable $e){
            error_log('TMR_DOUBLE_CARD_RENDER_FAILED '.get_class($e));
            return null;
        }
    }

    private static function draw(array $bet,string $font,string $bold): ?string
    {
        $rows=[];$y=255;
        foreach($bet['legs'] as $index=>$leg){
            $match=self::wrap(MatchNameFormatter::normalize((string)$leg['match']),$bold,27,635);
            $market=self::wrap(mb_strtoupper($leg['market'],'UTF-8'),$font,17,635);
            $selection=self::wrap($leg['selection'],$bold,30,635);
            if($match===null||$market===null||$selection===null)return null;
            $height=max(210,60+count($match)*40+count($market)*28+count($selection)*44+($leg['promotion']!==''?54:0));
            $rows[]=[$index,$leg,$y,$height,$match,$market,$selection];$y+=$height+20;
        }
        $summaryY=$y;$h=$y+285;
        if($h>3000)return null;
        $im=imagecreatetruecolor(1080,$h);
        $c=static fn(string $hex): int=>imagecolorallocate($im,hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2)));
        $bg=$c('#141e26');$white=$c('#f3f6fa');$muted=$c('#a8bdcd');$mint=$c('#87efcf');$line=$c('#304d5b');$panel=$c('#182a33');$teal=$c('#19383b');$gold=$c('#ffcc61');
        imagefill($im,0,0,$bg);self::rect($im,22,18,1058,$h-18,26,$line);self::rect($im,25,21,1055,$h-21,23,$bg);
        imagefilledellipse($im,80,72,40,40,$mint);
        imagefilledpolygon($im,[80,59,91,67,87,80,73,80,69,67],$bg);
        foreach([[0,-19],[18,-5],[12,15],[-12,15],[-18,-5]] as [$dx,$dy])imagefilledellipse($im,80+$dx,72+$dy,8,8,$bg);
        $sport=mb_strtoupper($bet['sport']?:'ESPORTE','UTF-8');
        if(mb_strlen($sport)>24)$sport='ESPORTE';
        self::text($im,116,83,$sport,23,$muted,$bold);
        self::rect($im,760,42,1027,99,18,$gold);self::rect($im,762,44,1025,97,16,$bg);
        imagefilledpolygon($im,[780,59,790,68,798,53,806,68,817,59,812,82,785,82],$gold);
        self::text($im,832,79,'APOSTA VIP',19,$gold,$bold);
        self::text($im,53,180,'DUPLA',59,$white,$bold);self::text($im,55,224,'2 seleções',26,$muted,$font);
        foreach($rows as [$index,$leg,$top,$height,$match,$market,$selection]){
            self::rect($im,48,$top,1032,$top+$height,20,$line);self::rect($im,50,$top+2,1030,$top+$height-2,18,$bg);
            self::rect($im,73,$top+28,133,$top+80,13,$mint);self::text($im,87,$top+64,sprintf('%02d',$index+1),22,$bg,$bold);
            $cursor=$top+59;
            foreach($match as $part){self::text($im,155,$cursor,$part,27,$white,$bold);$cursor+=40;}
            $cursor+=3;
            foreach($market as $part){self::text($im,155,$cursor,$part,17,$muted,$font);$cursor+=28;}
            $cursor+=12;
            foreach($selection as $part){self::text($im,155,$cursor,$part,30,$mint,$bold);$cursor+=44;}
            if($leg['promotion']!==''){
                self::rect($im,155,$cursor-18,565,$cursor+22,12,$mint);self::rect($im,157,$cursor-16,563,$cursor+20,10,$bg);
                self::text($im,170,$cursor+10,'+2 · Pagamento antecipado',18,$muted,$font);
            }
            self::rect($im,834,$top+32,1007,$top+170,18,$line);self::rect($im,836,$top+34,1005,$top+168,16,$panel);
            self::text($im,887,$top+75,'ODD',18,$muted,$bold);
            $odd=self::odd($leg['odd']);$size=37;
            while($size>16&&self::width($odd,$bold,$size)>147)$size--;
            self::text($im,920-(int)(self::width($odd,$bold,$size)/2),$top+132,$odd,$size,$mint,$bold);
        }
        self::rect($im,48,$summaryY,1032,$summaryY+155,20,$line);self::rect($im,50,$summaryY+2,1030,$summaryY+153,18,$teal);
        self::text($im,83,$summaryY+49,'ODD TOTAL',23,$muted,$bold);
        self::text($im,82,$summaryY+125,self::odd($bet['odd']),51,$mint,$bold);
        imageline($im,605,$summaryY+30,605,$summaryY+126,$line);
        self::text($im,665,$summaryY+49,'TIPO',21,$muted,$bold);self::text($im,663,$summaryY+105,'Dupla',33,$white,$bold);
        self::text($im,57,$summaryY+199,'As duas seleções precisam ser vencedoras.',21,$muted,$font);
        imageline($im,48,$summaryY+223,1032,$summaryY+223,$line);
        self::text($im,61,$summaryY+260,'TelegramRouter  •  Aposta encaminhada',19,$mint,$font);
        $path=sys_get_temp_dir().'/tmr-double-'.bin2hex(random_bytes(12)).'.png';
        $ok=imagepng($im,$path,7);unset($im);
        if(!$ok){@unlink($path);return null;}
        @chmod($path,0600);return $path;
    }

    private static function font(bool $bold): ?string
    {
        foreach(['/usr/share/fonts/truetype/liberation2/LiberationSans-'.($bold?'Bold':'Regular').'.ttf','/usr/share/fonts/truetype/dejavu/DejaVuSans'.($bold?'-Bold':'').'.ttf'] as $path)if(is_file($path))return $path;
        return null;
    }
    private static function width(string $text,string $font,int $size): int
    {
        $box=imagettfbbox($size,0,$font,$text);return abs($box[2]-$box[0]);
    }
    private static function wrap(string $text,string $font,int $size,int $width): ?array
    {
        $lines=[];$line='';
        foreach(preg_split('/\s+/u',trim($text))?:[] as $word){
            if(self::width($word,$font,$size)>$width)return null;
            $next=$line===''?$word:$line.' '.$word;
            if(self::width($next,$font,$size)>$width){$lines[]=$line;$line=$word;}else $line=$next;
        }
        if($line!=='')$lines[]=$line;
        return count($lines)<=6?$lines:null;
    }
    private static function text(\GdImage $im,int $x,int $y,string $text,int $size,int $color,string $font): void
    {
        imagettftext($im,$size,0,$x,$y,$color,$font,$text);
    }
    private static function rect(\GdImage $im,int $x1,int $y1,int $x2,int $y2,int $r,int $color): void
    {
        imagefilledrectangle($im,$x1+$r,$y1,$x2-$r,$y2,$color);imagefilledrectangle($im,$x1,$y1+$r,$x2,$y2-$r,$color);
        foreach([[$x1+$r,$y1+$r],[$x2-$r,$y1+$r],[$x1+$r,$y2-$r],[$x2-$r,$y2-$r]] as [$x,$y])imagefilledellipse($im,$x,$y,$r*2,$r*2,$color);
    }
}
