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
            .'REGRA OBRIGATÓRIA DE BET BUILDER: cada objeto de card_legs deve ter odd vazia. Bet Builder possui somente a odd total no campo odd principal; nunca copie a odd total para cada seleção e nunca invente odd individual. '
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
            // Bet Builder has one combined price only. Per-selection odds are
            // never published, even when an OCR/model repeats the total odd.
            foreach($legs as &$leg)$leg['odd']='';
            unset($leg);
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
            if(($bet['kind']??'')!=='bet_builder' && !empty($leg['odd']))$lines[]='📈 Odd: '.$leg['odd'];
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

        $bet['analysis']=$analysis;
        return self::drawPremiumCard($bet,$font,$bold);
    }

    /**
     * Global premium card layout used by every bookmaker and every bet type.
     * It keeps the same visual hierarchy as the approved previews and reserves
     * fixed space for labels/values so text can never sit on top of numbers.
     */
    private static function drawPremiumCard(array $bet,string $font,string $bold): ?string
    {
        $legs=is_array($bet['legs']??null)?$bet['legs']:[];
        if($legs===[])return null;

        $kind=(string)($bet['kind']??'simple');
        $builder=$kind==='bet_builder';
        $brandKey=(string)($bet['bookmaker_key']??'unknown');
        $bookmaker=trim((string)($bet['bookmaker']??''))?:'Casa desconhecida';
        $odd=trim((string)($bet['odd']??''))?:'—';
        $analysis=trim((string)($bet['analysis']??''));
        if($analysis==='')return null;

        $width=1080;
        $outerX=18;
        $contentX=34;
        $contentW=1012;
        $headerY=28;
        $headerH=132;
        $gap=18;
        $y=$headerY+$headerH+$gap;

        $rows=[];
        if($builder){
            $first=$legs[0];
            $matchLines=self::wrap((string)$first['match'],$bold,27,780);
            $leagueLines=self::wrap((string)($first['league']??''),$font,18,760)??[];
            if($matchLines===null)return null;
            $eventH=92+count($matchLines)*37+count($leagueLines)*27+(!empty($first['date'])?36:0);
            $eventH=max(190,$eventH);
            $rows[]=['builder_event',$y,$eventH,$first,$matchLines,$leagueLines];
            $y+=$eventH+$gap;

            $selectionRows=[];
            $selectionBodyH=0;
            foreach($legs as $index=>$leg){
                $marketLines=self::wrap((string)$leg['market'],$bold,22,775);
                $selectionLines=self::wrap((string)$leg['selection'],$bold,25,775);
                if($marketLines===null||$selectionLines===null)return null;
                $rowH=max(102,36+count($marketLines)*29+count($selectionLines)*34);
                $selectionRows[]=[$index,$leg,$marketLines,$selectionLines,$rowH];
                $selectionBodyH+=$rowH;
            }
            $selectionH=92+$selectionBodyH+22;
            $rows[]=['builder_selections',$y,$selectionH,$selectionRows];
            $y+=$selectionH+$gap;
        }else{
            foreach($legs as $index=>$leg){
                $matchLines=self::wrap((string)$leg['match'],$bold,25,670);
                $leagueLines=self::wrap((string)($leg['league']??''),$font,17,650)??[];
                $marketLines=self::wrap((string)$leg['market'],$font,18,690);
                $selectionLines=self::wrap((string)$leg['selection'],$bold,24,690);
                if($matchLines===null||$marketLines===null||$selectionLines===null)return null;
                $rowH=82+count($matchLines)*34+count($leagueLines)*25+(!empty($leg['date'])?29:0)
                    +count($marketLines)*27+count($selectionLines)*33;
                $rowH=max(196,$rowH);
                $rows[]=['event',$y,$rowH,$index,$leg,$matchLines,$leagueLines,$marketLines,$selectionLines];
                $y+=$rowH+14;
            }
            $y+=4;
        }

        $analysisLines=self::wrap($analysis,$font,20,845);
        if($analysisLines===null)return null;
        $analysisY=$y;
        $analysisH=max(176,96+count($analysisLines)*32);
        $footerY=$analysisY+$analysisH+$gap;
        $footerH=160;
        $ctaY=$footerY+$footerH+$gap;
        $ctaH=102;
        $height=$ctaY+$ctaH+34;
        if($height>4300)return null;

        $im=imagecreatetruecolor($width,$height);
        if($im===false)return null;
        imagealphablending($im,true);

        $bg=self::color($im,'#020b0f');
        $panel=self::color($im,'#06171d');
        $panel2=self::color($im,'#071e25');
        $panel3=self::color($im,'#041319');
        $border=self::color($im,'#12424e');
        $borderSoft=self::color($im,'#0d3039');
        $green=self::color($im,'#39ff88');
        $greenSoft=self::color($im,'#78ffad');
        $white=self::color($im,'#f7f8fb');
        $muted=self::color($im,'#aeb8d1');
        $dim=self::color($im,'#728091');
        $orange=self::color($im,'#ff612d');
        $yellow=self::color($im,'#f4d90b');
        imagefill($im,0,0,$bg);

        self::rounded($im,$outerX,16,1044,$height-32,30,$green);
        self::rounded($im,$outerX+3,19,1038,$height-38,27,$bg);

        // Premium header: clean dark surface, brand left, type center, total odd right.
        self::rounded($im,$contentX,$headerY,$contentW,$headerH,24,$border);
        self::rounded($im,$contentX+2,$headerY+2,$contentW-4,$headerH-4,22,$panel2);
        imagefilledrectangle($im,$contentX+18,$headerY+1,$contentX+$contentW-18,$headerY+4,$green);

        self::brandText($im,58,$headerY+86,$bookmaker,$brandKey,$bold,$white,$orange,$yellow);

        $type=self::kindLabel($kind);
        $typeW=max(220,min(292,self::width($type,$bold,20)+84));
        $oddW=194;
        $oddX=$contentX+$contentW-$oddW-18;
        $typeX=$oddX-$typeW-20;
        self::rounded($im,$typeX,$headerY+32,$typeW,58,18,$green);
        self::rounded($im,$typeX+3,$headerY+35,$typeW-6,52,16,$panel3);
        self::linkIcon($im,$typeX+27,$headerY+62,$green,2);
        self::textFit($im,$typeX+61,$headerY+72,$type,20,$green,$bold,$typeW-78);

        self::rounded($im,$oddX,$headerY+22,$oddW,84,20,$green);
        self::rounded($im,$oddX+3,$headerY+25,$oddW-6,78,17,$panel3);
        self::text($im,$oddX+22,$headerY+51,'ODD TOTAL',14,$greenSoft,$bold);
        self::textFit($im,$oddX+22,$headerY+91,$odd,38,$white,$bold,$oddW-44);

        foreach($rows as $row){
            if($row[0]==='builder_event'){
                [, $top,$h,$leg,$matchLines,$leagueLines]=$row;
                self::rounded($im,$contentX,$top,$contentW,$h,24,$border);
                self::rounded($im,$contentX+2,$top+2,$contentW-4,$h-4,22,$panel);
                self::footballIcon($im,70,$top+66,$white,$bg);

                $cursor=$top+61;
                $sides=self::matchSides((string)$leg['match']);
                if($sides!==null && self::flagSpec($sides[0])!==null && self::flagSpec($sides[1])!==null){
                    [$left,$right]=$sides;
                    self::drawFlag($im,122,$top+63,self::flagSpec($left));
                    self::textFit($im,160,$top+72,$left,26,$white,$bold,270);
                    self::text($im,449,$top+72,'x',21,$muted,$bold);
                    self::drawFlag($im,501,$top+63,self::flagSpec($right));
                    self::textFit($im,538,$top+72,$right,26,$white,$bold,390);
                    $cursor=$top+110;
                }else{
                    foreach($matchLines as $line){
                        self::text($im,120,$cursor,$line,27,$white,$bold);$cursor+=37;
                    }
                    $cursor+=4;
                }

                foreach($leagueLines as $line){
                    self::text($im,120,$cursor,$line,18,$muted,$font);$cursor+=27;
                }
                if(!empty($leg['date'])){
                    self::calendarIcon($im,120,$cursor+9,$muted);
                    self::text($im,162,$cursor+20,'Data: '.$leg['date'],18,$muted,$font);
                }
            }elseif($row[0]==='builder_selections'){
                [, $top,$h,$selectionRows]=$row;
                self::rounded($im,$contentX,$top,$contentW,$h,24,$border);
                self::rounded($im,$contentX+2,$top+2,$contentW-4,$h-4,22,$panel);
                self::listIcon($im,64,$top+51,$green);
                self::text($im,116,$top+58,'SELEÇÕES ('.count($legs).')',21,$muted,$bold);
                self::linkIcon($im,830,$top+50,$green,2);
                self::text($im,868,$top+58,'Bet Builder',18,$green,$bold);
                imageline($im,58,$top+81,1020,$top+81,$borderSoft);

                $cursor=$top+92;
                foreach($selectionRows as $rowIndex=>$selectionRow){
                    [$index,$leg,$marketLines,$selectionLines,$rowH]=$selectionRow;
                    $circleY=$cursor+38;
                    imageellipse($im,84,$circleY,30,30,$green);
                    imageellipse($im,84,$circleY,27,27,$green);
                    if($rowIndex<count($selectionRows)-1)imageline($im,84,$circleY+16,84,$cursor+$rowH+4,$green);

                    $ty=$cursor+32;
                    foreach($marketLines as $line){
                        self::text($im,132,$ty,$line,22,$white,$bold);$ty+=29;
                    }
                    foreach($selectionLines as $line){
                        self::text($im,132,$ty,$line,25,$green,$bold);$ty+=34;
                    }
                    if($rowIndex<count($selectionRows)-1){
                        imageline($im,132,$cursor+$rowH-5,1008,$cursor+$rowH-5,$borderSoft);
                    }
                    // Global Bet Builder rule: never draw a per-selection odd.
                    $cursor+=$rowH;
                }
            }else{
                [, $top,$h,$index,$leg,$matchLines,$leagueLines,$marketLines,$selectionLines]=$row;
                self::rounded($im,$contentX,$top,$contentW,$h,22,$border);
                self::rounded($im,$contentX+2,$top+2,$contentW-4,$h-4,20,$panel);

                self::circleNumber($im,72,$top+48,$index+1,$green,$panel,$bold);
                $cursor=$top+44;
                foreach($matchLines as $line){
                    self::text($im,118,$cursor,$line,25,$white,$bold);$cursor+=34;
                }
                foreach($leagueLines as $line){
                    self::text($im,118,$cursor,$line,17,$muted,$font);$cursor+=25;
                }
                if(!empty($leg['date'])){
                    self::calendarIcon($im,118,$cursor+7,$muted);
                    self::text($im,158,$cursor+18,'Data: '.$leg['date'],16,$muted,$font);
                    $cursor+=29;
                }
                $cursor+=8;
                foreach($marketLines as $line){
                    self::text($im,118,$cursor,$line,18,$muted,$font);$cursor+=27;
                }
                foreach($selectionLines as $line){
                    self::text($im,118,$cursor,$line,24,$green,$bold);$cursor+=33;
                }

                if(!empty($leg['odd'])){
                    self::rounded($im,862,$top+32,150,74,16,$border);
                    self::rounded($im,865,$top+35,144,68,13,$panel3);
                    self::text($im,889,$top+58,'ODD',13,$muted,$bold);
                    self::textFit($im,889,$top+93,(string)$leg['odd'],27,$white,$bold,96);
                }
            }
        }

        self::rounded($im,$contentX,$analysisY,$contentW,$analysisH,24,$border);
        self::rounded($im,$contentX+2,$analysisY+2,$contentW-4,$analysisH-4,22,$panel);
        self::barsIcon($im,62,$analysisY+57,$green);
        self::text($im,116,$analysisY+62,'ANÁLISE',23,$green,$bold);
        $cursor=$analysisY+103;
        foreach($analysisLines as $line){
            self::text($im,116,$cursor,$line,20,$white,$font);
            $cursor+=32;
        }

        // Footer columns use independent fixed bounds. Values are rendered once
        // inside each column to avoid "10unidades" and similar collisions.
        self::rounded($im,$contentX,$footerY,$contentW,$footerH,24,$border);
        self::rounded($im,$contentX+2,$footerY+2,$contentW-4,$footerH-4,22,$panel2);

        $col1X=62;$col1W=286;
        $col2X=388;$col2W=250;
        $col3X=704;$col3W=304;
        imageline($im,365,$footerY+27,365,$footerY+$footerH-27,$border);
        imageline($im,678,$footerY+27,678,$footerY+$footerH-27,$border);

        self::coinsIcon($im,$col1X+12,$footerY+85,$green);
        self::text($im,$col1X+54,$footerY+48,'STAKE',14,$muted,$bold);
        self::textFit($im,$col1X+54,$footerY+104,self::FIXED_STAKE.' unidades',28,$white,$bold,$col1W-62);

        self::barsIcon($im,$col2X+6,$footerY+87,$green);
        self::text($im,$col2X+58,$footerY+48,'ODD TOTAL',14,$muted,$bold);
        self::textFit($im,$col2X+58,$footerY+104,$odd,33,$white,$bold,$col2W-66);

        self::targetIcon($im,$col3X+13,$footerY+83,$green);
        self::text($im,$col3X+61,$footerY+48,'TIPO',14,$muted,$bold);
        $footerType=$kind==='bet_builder'
            ?(count($legs)===2?'Dupla (Bet Builder)':'Bet Builder')
            :ucfirst(mb_strtolower($type,'UTF-8'));
        self::textFit($im,$col3X+61,$footerY+102,$footerType,21,$white,$bold,$col3W-72);

        self::rounded($im,$contentX,$ctaY,$contentW,$ctaH,24,$green);
        self::rounded($im,$contentX+3,$ctaY+3,$contentW-6,$ctaH-6,21,self::color($im,'#004e31'));
        self::telegramIcon($im,310,$ctaY+51,$greenSoft);
        self::drawCtaLabel($im,$ctaY+63,$bookmaker,$brandKey,$bold,$white,$greenSoft,$yellow);

        $path=sys_get_temp_dir().'/tmr-premium-global-'.bin2hex(random_bytes(12)).'.png';
        $ok=imagepng($im,$path,7);
        unset($im);
        if(!$ok){@unlink($path);return null;}
        @chmod($path,0600);
        return $path;
    }

    private static function drawCtaLabel($im,int $baseline,string $bookmaker,string $brandKey,string $bold,int $white,int $green,int $yellow): void
    {
        $prefix=$brandKey==='unknown'||$brandKey==='generic'?'APOSTA ENCAMINHADA':'APOSTAR NA ';
        if($prefix==='APOSTA ENCAMINHADA'){
            $size=26;
            $w=self::width($prefix,$bold,$size);
            self::text($im,540-(int)($w/2),$baseline,$prefix,$size,$white,$bold);
            return;
        }

        $brand=$brandKey==='bet365'?'bet365':mb_strtoupper($bookmaker,'UTF-8');
        $size=26;
        $prefixW=self::width($prefix,$bold,$size);
        $brandW=self::width($brand,$bold,$size);
        $gap=10;
        $start=540-(int)(($prefixW+$gap+$brandW)/2);
        self::text($im,$start,$baseline,$prefix,$size,$white,$bold);
        $brandColor=$brandKey==='bet365'?$yellow:$green;
        self::text($im,$start+$prefixW+$gap,$baseline,$brand,$size,$brandColor,$bold);
    }

    private static function listIcon($im,int $x,int $y,int $color): void
    {
        for($i=0;$i<3;$i++){
            $yy=$y-18+$i*13;
            imagefilledellipse($im,$x,$yy,6,6,$color);
            imagefilledrectangle($im,$x+12,$yy-2,$x+38,$yy+2,$color);
        }
    }

    /** Draws the football icon, flags for known national teams, and match names. */
    private static function drawMatchRow($im,int $x,int $baseline,string $match,string $font,string $bold,int $white,int $muted): void
    {
        self::footballIcon($im,$x,$baseline-16,$white,self::color($im,'#071419'));
        $sides=self::matchSides($match);
        if($sides!==null){
            [$left,$right]=$sides;
            $leftFlag=self::flagSpec($left);
            $rightFlag=self::flagSpec($right);
            if($leftFlag!==null && $rightFlag!==null){
                self::drawFlag($im,$x+72,$baseline-16,$leftFlag);
                self::textFit($im,$x+108,$baseline,$left,25,$white,$bold,260);
                self::text($im,$x+405,$baseline,'x',22,$muted,$bold);
                self::drawFlag($im,$x+456,$baseline-16,$rightFlag);
                self::textFit($im,$x+492,$baseline,$right,25,$white,$bold,330);
                return;
            }
        }
        self::textFit($im,$x+62,$baseline,$match,27,$white,$bold,820);
    }

    /** @return array{0:string,1:string}|null */
    private static function matchSides(string $match): ?array
    {
        $parts=preg_split('~\s+(?:x|×|vs\.?|v)\s+~iu',trim($match),2);
        if(!is_array($parts)||count($parts)!==2)return null;
        $a=trim($parts[0]);$b=trim($parts[1]);
        return $a!==''&&$b!==''?[$a,$b]:null;
    }

    /** @return array{type:string,colors:list<string>}|null */
    private static function flagSpec(string $team): ?array
    {
        $t=mb_strtolower(trim($team),'UTF-8');
        if(preg_match('~inglaterra|england~u',$t))return ['type'=>'england','colors'=>['#ffffff','#d71e28']];
        if(preg_match('~espanha|españa|spain~u',$t))return ['type'=>'h3','colors'=>['#c60b1e','#ffc400','#c60b1e']];
        if(preg_match('~alemanha|germany|deutschland~u',$t))return ['type'=>'h3','colors'=>['#111111','#dd0000','#ffce00']];
        if(preg_match('~frança|franca|france~u',$t))return ['type'=>'v3','colors'=>['#0055a4','#ffffff','#ef4135']];
        if(preg_match('~itália|italia|italy~u',$t))return ['type'=>'v3','colors'=>['#009246','#ffffff','#ce2b37']];
        if(preg_match('~bélgica|belgica|belgium~u',$t))return ['type'=>'v3','colors'=>['#111111','#ffd90c','#ef3340']];
        if(preg_match('~argentina~u',$t))return ['type'=>'h3','colors'=>['#75aadb','#ffffff','#75aadb']];
        if(preg_match('~brasil|brazil~u',$t))return ['type'=>'brazil','colors'=>['#009c3b','#ffdf00','#002776']];
        return null;
    }

    private static function drawFlag($im,int $cx,int $cy,array $spec): void
    {
        $diam=40;
        $outline=self::color($im,'#c9d6e3');
        imagefilledellipse($im,$cx,$cy,$diam+4,$diam+4,$outline);
        $type=$spec['type'];
        $colors=$spec['colors'];
        $base=self::color($im,$colors[0]);
        imagefilledellipse($im,$cx,$cy,$diam,$diam,$base);
        if($type==='england'){
            $red=self::color($im,$colors[1]);
            imagefilledrectangle($im,$cx-4,$cy-18,$cx+4,$cy+18,$red);
            imagefilledrectangle($im,$cx-18,$cy-4,$cx+18,$cy+4,$red);
        }elseif($type==='h3'){
            for($i=0;$i<3;$i++){
                $c=self::color($im,$colors[$i]);
                imagefilledrectangle($im,$cx-17,$cy-15+$i*10,$cx+17,$cy-6+$i*10,$c);
            }
        }elseif($type==='v3'){
            for($i=0;$i<3;$i++){
                $c=self::color($im,$colors[$i]);
                imagefilledrectangle($im,$cx-15+$i*10,$cy-17,$cx-6+$i*10,$cy+17,$c);
            }
        }elseif($type==='brazil'){
            $yellow=self::color($im,$colors[1]);
            $blue=self::color($im,$colors[2]);
            imagefilledpolygon($im,[$cx,$cy-13,$cx+15,$cy,$cx,$cy+13,$cx-15,$cy],$yellow);
            imagefilledellipse($im,$cx,$cy,14,14,$blue);
        }
    }

    private static function footballIcon($im,int $cx,int $cy,int $white,int $dark): void
    {
        imagefilledellipse($im,$cx,$cy,42,42,$white);
        imageellipse($im,$cx,$cy,42,42,self::color($im,'#8ea0b1'));
        imagefilledpolygon($im,[$cx,$cy-7,$cx+7,$cy-2,$cx+4,$cy+7,$cx-4,$cy+7,$cx-7,$cy-2],$dark);
        imageline($im,$cx-7,$cy-2,$cx-15,$cy-9,$dark);
        imageline($im,$cx+7,$cy-2,$cx+15,$cy-9,$dark);
        imageline($im,$cx+4,$cy+7,$cx+11,$cy+15,$dark);
        imageline($im,$cx-4,$cy+7,$cx-11,$cy+15,$dark);
    }

    private static function calendarIcon($im,int $x,int $y,int $color): void
    {
        imagerectangle($im,$x,$y-15,$x+26,$y+8,$color);
        imagefilledrectangle($im,$x+5,$y-20,$x+8,$y-12,$color);
        imagefilledrectangle($im,$x+18,$y-20,$x+21,$y-12,$color);
        imagefilledrectangle($im,$x+4,$y-8,$x+22,$y-5,$color);
        foreach([[6,0],[13,0],[20,0],[6,6],[13,6],[20,6]] as [$dx,$dy]){
            imagefilledellipse($im,$x+$dx,$y-1+$dy,2,2,$color);
        }
    }

    private static function linkIcon($im,int $x,int $y,int $color,int $thickness=2): void
    {
        for($i=0;$i<$thickness;$i++){
            imageellipse($im,$x,$y,22+$i,14+$i,$color);
            imageellipse($im,$x+17,$y-10,22+$i,14+$i,$color);
        }
        imageline($im,$x+6,$y-3,$x+29,$y-7,$color);
    }

    private static function coinsIcon($im,int $x,int $y,int $color): void
    {
        for($i=0;$i<4;$i++){
            $yy=$y-$i*7;
            imageellipse($im,$x,$yy,34,11,$color);
            imageline($im,$x-17,$yy,$x-17,$yy+7,$color);
            imageline($im,$x+17,$yy,$x+17,$yy+7,$color);
        }
    }

    private static function targetIcon($im,int $x,int $y,int $color): void
    {
        imageellipse($im,$x,$y,40,40,$color);
        imageellipse($im,$x,$y,24,24,$color);
        imagefilledellipse($im,$x,$y,8,8,$color);
        imageline($im,$x+5,$y-5,$x+21,$y-21,$color);
        imageline($im,$x+21,$y-21,$x+20,$y-10,$color);
        imageline($im,$x+21,$y-21,$x+10,$y-20,$color);
    }

    private static function homeIcon($im,int $x,int $y,int $color): void
    {
        imagefilledpolygon($im,[$x-22,$y,$x,$y-22,$x+22,$y,$x+16,$y,$x+16,$y+22,$x-16,$y+22,$x-16,$y],$color);
        imagefilledrectangle($im,$x-5,$y+7,$x+5,$y+22,self::color($im,'#071419'));
    }

    private static function questionIcon($im,int $x,int $y,int $accent,int $muted,string $bold): void
    {
        imageellipse($im,$x,$y,54,54,$muted);
        imageellipse($im,$x,$y,46,46,$muted);
        self::text($im,$x-9,$y+12,'?',29,$muted,$bold);
    }

    private static function telegramIcon($im,int $cx,int $cy,int $color): void
    {
        imagefilledpolygon($im,[
            $cx-23,$cy-5,
            $cx+28,$cy-24,
            $cx+15,$cy+28,
            $cx-2,$cy+11,
            $cx-12,$cy+22,
            $cx-10,$cy+5
        ],$color);
        imageline($im,$cx-9,$cy+5,$cx+15,$cy-11,self::color($im,'#006e3b'));
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

    private static function brandText($im,int $x,int $y,string $name,string $key,string $bold,int $white,int $betanoOrange,int $bet365Yellow): void
    {
        if($key==='bet365'){
            self::text($im,$x,$y,'bet',57,$white,$bold);
            $w=self::width('bet',$bold,57);
            self::text($im,$x+$w+2,$y,'365',57,$bet365Yellow,$bold);
            return;
        }
        if($key==='betano'){
            self::textFit($im,$x,$y,'Betano',54,$betanoOrange,$bold,500);
            return;
        }
        self::textFit($im,$x,$y,$name,45,$white,$bold,560);
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
