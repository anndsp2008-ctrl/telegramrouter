<?php declare(strict_types=1);
namespace App;

final class AdaptiveVipCardRenderer
{
    private const FIXED_STAKE='10';

    public static function extractionInstruction(): string
    {
        return 'CARD ADAPTATIVO: identifique a casa de aposta SOMENTE quando houver nome ou logotipo inequívoco no texto ou na imagem. '
            .'No campo bookmaker use o nome canônico (ex.: Betano, bet365); se não for possível identificar, deixe vazio. '
            .'Preencha card_legs como STRING contendo um array JSON de 1 a 16 objetos na ordem real do bilhete. '
            .'Cada objeto deve ter somente strings: match, league, date, market, selection, odd. '
            .'Nunca inclua horário em card_legs; date pode conter apenas a data explicitamente visível, sem hora. '
            .'Para aposta simples, card_legs tem 1 objeto. Para dupla, 2 seleções de jogos distintos. '
            .'Para múltipla, 3 ou mais seleções/jogos. Para Bet Builder/Criar Aposta/Crear Apuesta, inclua cada condição do mesmo jogo como um objeto separado, repetindo match quando necessário. '
            .'Não invente jogos, mercados, seleções, datas ou odds. odd no nível principal é a odd total exibida no bilhete; não recalcule. '
            .'Em analysis, preserve a análise do autor quando existir; quando não existir, siga a política global e gere a análise profissional também para simples, dupla, múltipla e Bet Builder. '
            .'O card nunca deve exibir horário do jogo. ';
    }

    public static function extract(array $data,string $sourceText='',bool $hasImage=false): ?array
    {
        $legs=self::parseLegs((string)($data['card_legs']??''));

        if($legs===[]){
            $doubleRaw=(string)($data['double_legs']??'');
            if($doubleRaw!=='')$legs=self::parseLegacyDouble($doubleRaw);
        }

        if($legs===[]){
            $match=trim((string)($data['match']??''));
            $market=trim((string)($data['market']??''));
            $selection=trim((string)($data['selection']??''));
            if($match!==''&&$market!==''&&$selection!==''){
                $legs=[[
                    'match'=>$match,
                    'league'=>trim((string)($data['league']??'')),
                    'date'=>self::dateOnly((string)($data['day']??'')),
                    'market'=>$market,
                    'selection'=>$selection,
                    'odd'=>self::cleanOdd((string)($data['odd']??''))
                ]];
            }
        }

        if($legs===[]||count($legs)>16)return null;

        $kind=self::detectKind($data,$sourceText,$legs,$hasImage);
        if($kind==='simple'&&count($legs)!==1)return null;
        if($kind==='double'&&count($legs)!==2)return null;
        if($kind==='multiple'&&count($legs)<3)return null;
        if($kind==='bet_builder'&&count($legs)<2)return null;

        if($kind==='bet_builder'){
            $firstMatch=mb_strtolower(trim((string)$legs[0]['match']),'UTF-8');
            foreach($legs as $leg){
                if(mb_strtolower(trim((string)$leg['match']),'UTF-8')!==$firstMatch)return null;
            }
        }

        $bookmaker=self::normalizeBookmaker((string)($data['bookmaker']??''),$sourceText);
        $odd=self::cleanOdd((string)($data['odd']??''));
        if($odd===''&&count($legs)===1)$odd=$legs[0]['odd'];

        return [
            'kind'=>$kind,
            'bookmaker'=>$bookmaker['name'],
            'bookmaker_key'=>$bookmaker['key'],
            'sport'=>trim((string)($data['sport']??'')),
            'status'=>trim((string)($data['status']??'')),
            'odd'=>$odd,
            'stake'=>self::FIXED_STAKE,
            'analysis'=>trim((string)($data['analysis']??'')),
            'legs'=>$legs
        ];
    }

    /** @return list<array{match:string,league:string,date:string,market:string,selection:string,odd:string}> */
    private static function parseLegs(string $raw): array
    {
        $raw=trim($raw);
        if($raw===''||strlen($raw)>30000)return [];
        $decoded=json_decode($raw,true,16);
        if(!is_array($decoded)||!array_is_list($decoded)||$decoded===[]||count($decoded)>16)return [];
        $out=[];
        foreach($decoded as $row){
            if(!is_array($row))return [];
            $match=self::cleanText($row['match']??'',240);
            $league=self::cleanText($row['league']??'',180);
            $date=self::dateOnly((string)($row['date']??''));
            $market=self::cleanText($row['market']??'',220);
            $selection=self::cleanText($row['selection']??'',260);
            $odd=self::cleanOdd((string)($row['odd']??''));
            if($match===''||$market===''||$selection==='')return [];
            $out[]=[
                'match'=>$match,'league'=>$league,'date'=>$date,
                'market'=>$market,'selection'=>$selection,'odd'=>$odd
            ];
        }
        return $out;
    }

    /** @return list<array{match:string,league:string,date:string,market:string,selection:string,odd:string}> */
    private static function parseLegacyDouble(string $raw): array
    {
        if(strlen($raw)>16000)return [];
        $decoded=json_decode($raw,true,8);
        if(!is_array($decoded)||!array_is_list($decoded)||count($decoded)!==2)return [];
        $out=[];
        foreach($decoded as $row){
            if(!is_array($row))return [];
            $match=self::cleanText($row['match']??'',240);
            $market=self::cleanText($row['market']??'',220);
            $selection=self::cleanText($row['selection']??'',260);
            if($match===''||$market===''||$selection==='')return [];
            $out[]=[
                'match'=>$match,'league'=>'','date'=>'',
                'market'=>$market,'selection'=>$selection,
                'odd'=>self::cleanOdd((string)($row['odd']??''))
            ];
        }
        return $out;
    }

    private static function detectKind(array $data,string $sourceText,array $legs,bool $hasImage): string
    {
        $count=count($legs);
        if($count===1)return 'simple';

        $prefix=$hasImage?'visual_':'';
        $hint=mb_strtolower(trim((string)($data[$prefix.'bet_kind']??$data['bet_kind']??'')),'UTF-8');
        $evidence=mb_strtolower(trim((string)($data['visual_multiple_evidence']??'')),'UTF-8');
        $source=mb_strtolower($sourceText,'UTF-8');

        $builderCue=preg_match('~bet\s*builder|same[- ]game\s+parlay|criar\s+aposta|crear\s+apuesta|construtor\s+de\s+aposta~iu',$hint.' '.$evidence.' '.$source)===1;
        $sameMatch=true;
        $first=mb_strtolower(trim((string)$legs[0]['match']),'UTF-8');
        foreach($legs as $leg){
            if(mb_strtolower(trim((string)$leg['match']),'UTF-8')!==$first){$sameMatch=false;break;}
        }
        if($builderCue||$sameMatch)return 'bet_builder';
        if($count===2)return 'double';
        return 'multiple';
    }

    /** @return array{name:string,key:string} */
    private static function normalizeBookmaker(string $raw,string $sourceText=''): array
    {
        $candidate=trim($raw);
        $hay=mb_strtolower($candidate.' '.$sourceText,'UTF-8');
        if(preg_match('~\bbetano\b~iu',$hay))return ['name'=>'Betano','key'=>'betano'];
        if(preg_match('~\bbet\s*365\b|\bbet365\b~iu',$hay))return ['name'=>'bet365','key'=>'bet365'];

        foreach([
            'Sportingbet'=>'sportingbet','Superbet'=>'superbet','Betfair'=>'betfair',
            'Betnacional'=>'betnacional','Novibet'=>'novibet','Betsson'=>'betsson',
            'KTO'=>'kto','Stake'=>'stake','1xBet'=>'1xbet','Pixbet'=>'pixbet',
            'Esportes da Sorte'=>'esportesdasorte','Bet7k'=>'bet7k'
        ] as $name=>$key){
            $pattern='~\b'.preg_quote(mb_strtolower($name,'UTF-8'),'~').'\b~iu';
            if(preg_match($pattern,$hay))return ['name'=>$name,'key'=>$key];
        }

        $candidate=self::cleanText($candidate,50);
        if($candidate===''||preg_match('~^(?:unknown|desconhecid[ao]|n/?a|-)$~iu',$candidate)){
            return ['name'=>'Casa desconhecida','key'=>'unknown'];
        }
        return ['name'=>$candidate,'key'=>'generic'];
    }

    private static function cleanText(mixed $value,int $max): string
    {
        if(!is_string($value)&&!is_numeric($value))return '';
        $text=trim((string)$value);
        $text=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$text)??'';
        $text=preg_replace('/[ \t]{2,}/u',' ',$text)??$text;
        if(mb_strlen($text,'UTF-8')>$max)$text=mb_substr($text,0,$max,'UTF-8');
        return trim($text);
    }

    private static function cleanOdd(string $odd): string
    {
        $odd=trim($odd);
        if($odd==='')return '';
        if(!preg_match('/^\d{1,5}(?:[.,]\d{1,3})?$/D',$odd))return '';
        return str_replace('.',',',$odd);
    }

    private static function dateOnly(string $value): string
    {
        $value=trim($value);
        if($value==='')return '';
        if(preg_match('~\b(\d{1,2}[/-]\d{1,2}(?:[/-]\d{2,4})?)\b~u',$value,$m))return $m[1];
        if(preg_match('~\b(\d{4}-\d{2}-\d{2})\b~u',$value,$m))return $m[1];
        if(preg_match('~\b(?:\d{1,2}:\d{2}|\d{1,2}h\d{0,2})\b~iu',$value))return '';
        return mb_strlen($value,'UTF-8')<=30?$value:'';
    }

    public static function caption(array $bet): string
    {
        $kind=self::kindLabel((string)($bet['kind']??'simple'));
        $bookmaker=trim((string)($bet['bookmaker']??''))?:'Casa desconhecida';
        $lines=['🎟️ '.$kind.' • '.$bookmaker,''];
        foreach(($bet['legs']??[]) as $index=>$leg){
            $lines[]='⚽ '.($index+1).'. '.($leg['match']??'');
            if(!empty($leg['league']))$lines[]='🏆 '.$leg['league'];
            if(!empty($leg['date']))$lines[]='📅 '.$leg['date'];
            $lines[]='🎯 Mercado: '.($leg['market']??'');
            $lines[]='✅ Seleção: '.($leg['selection']??'');
            if(!empty($leg['odd']))$lines[]='📈 Odd: '.$leg['odd'];
            $lines[]='';
        }
        if(!empty($bet['odd']))$lines[]='📊 Odd total: '.$bet['odd'];
        $lines[]='📍 Stake: '.self::FIXED_STAKE;
        $analysis=trim((string)($bet['analysis']??''));
        if($analysis!==''){
            $lines[]='';
            $lines[]='📝 Análise:';
            $lines[]=$analysis;
        }
        return implode("\n",$lines);
    }

    public static function render(array $bet): ?string
    {
        if(!extension_loaded('gd')||!function_exists('imagettftext'))return null;
        $font=self::font(false);$bold=self::font(true);
        if($font===null||$bold===null)return null;
        try{
            return self::draw($bet,$font,$bold);
        }catch(\Throwable $e){
            error_log('TMR_ADAPTIVE_CARD_RENDER_FAILED '.get_class($e));
            return null;
        }
    }

    private static function draw(array $bet,string $font,string $bold): ?string
    {
        $legs=is_array($bet['legs']??null)?$bet['legs']:[];
        if($legs===[])return null;

        $analysis=self::stripEmojis(trim((string)($bet['analysis']??'')));
        if($analysis==='')return null;

        $kind=(string)($bet['kind']??'simple');
        $bookmaker=trim((string)($bet['bookmaker']??''))?:'Casa desconhecida';
        $brandKey=(string)($bet['bookmaker_key']??'unknown');
        [$accent,$brand2]=self::theme($brandKey);

        $width=1080;
        $headerH=155;
        $rowData=[];
        $y=$headerH+26;
        $builder=$kind==='bet_builder';

        if($builder){
            $first=$legs[0];
            $matchLines=self::wrap((string)$first['match'],$bold,31,780);
            if($matchLines===null)return null;
            $leagueLines=self::wrap((string)($first['league']??''),$font,18,780)??[];
            $headHeight=82+count($matchLines)*40+count($leagueLines)*28+(!empty($first['date'])?30:0);
            $rowData[]=['builder_head',$y,$headHeight,$first,$matchLines,$leagueLines];
            $y+=$headHeight+14;
            foreach($legs as $index=>$leg){
                $market=self::wrap((string)$leg['market'],$font,18,735);
                $selection=self::wrap((string)$leg['selection'],$bold,28,735);
                if($market===null||$selection===null)return null;
                $height=max(130,42+count($market)*28+count($selection)*39);
                $rowData[]=['builder_leg',$y,$height,$index,$leg,$market,$selection];
                $y+=$height+12;
            }
        }else{
            foreach($legs as $index=>$leg){
                $match=self::wrap((string)$leg['match'],$bold,29,720);
                $league=self::wrap((string)($leg['league']??''),$font,17,720)??[];
                $market=self::wrap((string)$leg['market'],$font,17,720);
                $selection=self::wrap((string)$leg['selection'],$bold,28,720);
                if($match===null||$market===null||$selection===null)return null;
                $height=max(190,70+count($match)*39+count($league)*26+(!empty($leg['date'])?26:0)+count($market)*27+count($selection)*39);
                $rowData[]=['leg',$y,$height,$index,$leg,$match,$league,$market,$selection];
                $y+=$height+14;
            }
        }

        $analysisLines=self::wrap($analysis,$font,20,900);
        if($analysisLines===null)return null;
        $analysisY=$y+10;
        $analysisH=max(150,82+count($analysisLines)*31);
        $footerY=$analysisY+$analysisH+18;
        $footerH=150;
        $bottomH=72;
        $height=$footerY+$footerH+$bottomH+28;
        if($height>3500)return null;

        $im=imagecreatetruecolor($width,$height);
        if($im===false)return null;
        imagealphablending($im,true);
        $bg=self::color($im,'#071419');
        $panel=self::color($im,'#0b1d23');
        $panel2=self::color($im,'#0f252c');
        $border=self::color($im,'#1f4650');
        $white=self::color($im,'#f5f7fa');
        $muted=self::color($im,'#9fb3c4');
        $accentC=self::color($im,$accent);
        $brand2C=self::color($im,$brand2);
        imagefill($im,0,0,$bg);

        self::rounded($im,18,16,1044,$height-32,28,$border);
        self::rounded($im,21,19,1038,$height-38,25,$bg);

        self::rounded($im,22,20,1036,126,24,$panel2);
        imagefilledrectangle($im,22,20,1058,27,$accentC);
        self::brandText($im,58,91,$bookmaker,$brandKey,$bold,$white,$accentC,$brand2C);

        $type=self::kindLabel($kind);
        $typeWidth=max(180,min(280,self::width($type,$bold,21)+54));
        self::rounded($im,690,46,$typeWidth,46,16,$accentC);
        self::rounded($im,693,49,$typeWidth-6,40,14,$panel);
        self::text($im,690+(int)(($typeWidth-self::width($type,$bold,21))/2),79,$type,21,$accentC,$bold);

        $odd=(string)($bet['odd']??'');
        self::text($im,904,61,'ODD TOTAL',14,$muted,$bold);
        self::textFit($im,900,106,$odd!==''?$odd:'—',40,$white,$bold,145);

        foreach($rowData as $row){
            if($row[0]==='leg'){
                [, $top,$h,$index,$leg,$match,$league,$market,$selection]=$row;
                self::rounded($im,42,$top,996,$h,20,$border);
                self::rounded($im,44,$top+2,992,$h-4,18,$panel);
                self::circleNumber($im,76,$top+42,$index+1,$accentC,$bg,$bold);
                $cursor=$top+55;
                foreach($match as $line){self::text($im,125,$cursor,$line,29,$white,$bold);$cursor+=39;}
                foreach($league as $line){self::text($im,125,$cursor,$line,17,$muted,$font);$cursor+=26;}
                if(!empty($leg['date'])){self::text($im,125,$cursor,'Data: '.$leg['date'],16,$muted,$font);$cursor+=27;}
                $cursor+=5;
                foreach($market as $line){self::text($im,125,$cursor,$line,17,$muted,$font);$cursor+=27;}
                foreach($selection as $line){self::text($im,125,$cursor,$line,28,$accentC,$bold);$cursor+=39;}
                if(!empty($leg['odd'])){
                    self::rounded($im,865,$top+30,145,64,14,$border);
                    self::rounded($im,868,$top+33,139,58,12,$bg);
                    self::textFit($im,887,$top+73,$leg['odd'],27,$white,$bold,103);
                }
            }elseif($row[0]==='builder_head'){
                [, $top,$h,$leg,$match,$league]=$row;
                self::rounded($im,42,$top,996,$h,20,$border);
                self::rounded($im,44,$top+2,992,$h-4,18,$panel);
                $cursor=$top+54;
                foreach($match as $line){self::text($im,72,$cursor,$line,31,$white,$bold);$cursor+=40;}
                foreach($league as $line){self::text($im,72,$cursor,$line,18,$muted,$font);$cursor+=28;}
                if(!empty($leg['date']))self::text($im,72,$cursor,'Data: '.$leg['date'],16,$muted,$font);
            }else{
                [, $top,$h,$index,$leg,$market,$selection]=$row;
                self::rounded($im,62,$top,956,$h,18,$border);
                self::rounded($im,64,$top+2,952,$h-4,16,$panel);
                self::circleNumber($im,96,$top+43,$index+1,$accentC,$bg,$bold);
                $cursor=$top+48;
                foreach($market as $line){self::text($im,145,$cursor,$line,18,$muted,$font);$cursor+=28;}
                foreach($selection as $line){self::text($im,145,$cursor,$line,28,$accentC,$bold);$cursor+=39;}
                if(!empty($leg['odd']))self::textFit($im,875,$top+58,$leg['odd'],24,$white,$bold,110);
            }
        }

        self::rounded($im,42,$analysisY,996,$analysisH,20,$border);
        self::rounded($im,44,$analysisY+2,992,$analysisH-4,18,$panel);
        self::barsIcon($im,72,$analysisY+48,$accentC);
        self::text($im,122,$analysisY+49,'ANÁLISE',23,$accentC,$bold);
        $cursor=$analysisY+88;
        foreach($analysisLines as $line){self::text($im,72,$cursor,$line,20,$white,$font);$cursor+=31;}

        self::rounded($im,42,$footerY,996,$footerH,20,$border);
        self::rounded($im,44,$footerY+2,992,$footerH-4,18,$panel2);
        $cols=[['STAKE',self::FIXED_STAKE.' unidades'],['ODD TOTAL',$odd!==''?$odd:'—'],['CASA',$bookmaker]];
        $x=[72,395,710];
        foreach($cols as $i=>$pair){
            self::text($im,$x[$i],$footerY+44,$pair[0],15,$muted,$bold);
            self::textFit($im,$x[$i],$footerY+96,$pair[1],$i===2?24:32,$white,$bold,$i===2?280:220);
            if($i<2)imageline($im,$x[$i]+265,$footerY+28,$x[$i]+265,$footerY+120,$border);
        }

        $bottomY=$footerY+$footerH+18;
        self::rounded($im,42,$bottomY,996,54,18,$accentC);
        self::rounded($im,45,$bottomY+3,990,48,16,$bg);
        self::text($im,318,$bottomY+35,'TELEGRAM ROUTER • APOSTA ENCAMINHADA',18,$accentC,$bold);

        $path=sys_get_temp_dir().'/tmr-adaptive-'.bin2hex(random_bytes(12)).'.png';
        $ok=imagepng($im,$path,7);
        unset($im);
        if(!$ok){@unlink($path);return null;}
        @chmod($path,0600);
        return $path;
    }

    private static function kindLabel(string $kind): string
    {
        return match($kind){
            'double'=>'DUPLA',
            'multiple'=>'MÚLTIPLA',
            'bet_builder'=>'BET BUILDER',
            default=>'SIMPLES'
        };
    }

    /** @return array{0:string,1:string} */
    private static function theme(string $key): array
    {
        return match($key){
            'betano'=>['#ff642d','#ff8a3d'],
            'bet365'=>['#20e38b','#f4d90b'],
            default=>['#39ff88','#8effbd']
        };
    }

    private static function brandText($im,int $x,int $y,string $name,string $key,string $bold,int $white,int $accent,int $brand2): void
    {
        if($key==='bet365'){
            self::text($im,$x,$y,'bet',48,$white,$bold);
            $w=self::width('bet',$bold,48);
            self::text($im,$x+$w+2,$y,'365',48,$brand2,$bold);
            return;
        }
        self::textFit($im,$x,$y,$name,46,$key==='betano'?$accent:$white,$bold,560);
    }

    private static function stripEmojis(string $text): string
    {
        $clean=preg_replace(
            '/(?:[\x{1F1E6}-\x{1F1FF}]{2}|[\x{203C}-\x{3299}]|[\x{1F000}-\x{1FAFF}])(?:\x{FE0F}|\x{FE0E})?(?:\x{200D}(?:[\x{203C}-\x{3299}]|[\x{1F000}-\x{1FAFF}])(?:\x{FE0F}|\x{FE0E})?)*|[\x{FE0F}\x{FE0E}\x{200D}]/u',
            '',
            $text
        );
        return trim(is_string($clean)?$clean:$text);
    }

    private static function font(bool $bold): ?string
    {
        $paths=$bold?[
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
        ]:[
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
        ];
        foreach($paths as $path)if(is_file($path))return $path;
        return null;
    }

    private static function color($im,string $hex): int
    {
        $hex=ltrim($hex,'#');
        return imagecolorallocate($im,hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2)));
    }

    private static function text($im,int $x,int $y,string $text,int $size,int $color,string $font): void
    {
        imagettftext($im,$size,0,$x,$y,$color,$font,$text);
    }

    private static function textFit($im,int $x,int $y,string $text,int $size,int $color,string $font,int $maxWidth): void
    {
        $text=trim($text);
        if($text==='')return;
        while($size>12&&self::width($text,$font,$size)>$maxWidth)$size--;
        if(self::width($text,$font,$size)>$maxWidth){
            while(mb_strlen($text,'UTF-8')>3&&self::width($text.'…',$font,$size)>$maxWidth){
                $text=mb_substr($text,0,-1,'UTF-8');
            }
            $text.='…';
        }
        self::text($im,$x,$y,$text,$size,$color,$font);
    }

    private static function width(string $text,string $font,int $size): int
    {
        $box=imagettfbbox($size,0,$font,$text);
        if(!is_array($box))return mb_strlen($text,'UTF-8')*$size;
        return abs($box[2]-$box[0]);
    }

    /** @return list<string>|null */
    private static function wrap(string $text,string $font,int $size,int $maxWidth): ?array
    {
        $text=trim($text);
        if($text==='')return [];
        $paragraphs=preg_split('/\R+/u',$text)?:[$text];
        $lines=[];
        foreach($paragraphs as $paragraph){
            $words=preg_split('/\s+/u',trim($paragraph))?:[];
            $line='';
            foreach($words as $word){
                if($word==='')continue;
                $candidate=$line===''?$word:$line.' '.$word;
                if(self::width($candidate,$font,$size)<=$maxWidth){
                    $line=$candidate;continue;
                }
                if($line!==''){$lines[]=$line;$line='';}
                if(self::width($word,$font,$size)<=$maxWidth){$line=$word;continue;}
                $chunk='';
                foreach(preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY)?:[] as $char){
                    $next=$chunk.$char;
                    if(self::width($next,$font,$size)>$maxWidth&&$chunk!==''){
                        $lines[]=$chunk;$chunk=$char;
                    }else{$chunk=$next;}
                }
                $line=$chunk;
            }
            if($line!=='')$lines[]=$line;
        }
        return count($lines)>80?null:$lines;
    }

    private static function rounded($im,int $x,int $y,int $w,int $h,int $r,int $color): void
    {
        $r=max(1,min($r,(int)floor(min($w,$h)/2)));
        imagefilledrectangle($im,$x+$r,$y,$x+$w-$r,$y+$h,$color);
        imagefilledrectangle($im,$x,$y+$r,$x+$w,$y+$h-$r,$color);
        imagefilledellipse($im,$x+$r,$y+$r,$r*2,$r*2,$color);
        imagefilledellipse($im,$x+$w-$r,$y+$r,$r*2,$r*2,$color);
        imagefilledellipse($im,$x+$r,$y+$h-$r,$r*2,$r*2,$color);
        imagefilledellipse($im,$x+$w-$r,$y+$h-$r,$r*2,$r*2,$color);
    }

    private static function circleNumber($im,int $cx,int $cy,int $number,int $accent,int $bg,string $bold): void
    {
        imagefilledellipse($im,$cx,$cy,48,48,$accent);
        $s=(string)$number;
        self::text($im,$cx-(int)(self::width($s,$bold,18)/2),$cy+7,$s,18,$bg,$bold);
    }

    private static function barsIcon($im,int $x,int $y,int $color): void
    {
        imagefilledrectangle($im,$x,$y-9,$x+7,$y+8,$color);
        imagefilledrectangle($im,$x+13,$y-19,$x+20,$y+8,$color);
        imagefilledrectangle($im,$x+26,$y-29,$x+33,$y+8,$color);
    }
}
