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
        $brandKey=(string)($bet['bookmaker_key']??'unknown');

        if(in_array($brandKey,['betano','bet365'],true)){
            return self::drawKnownCard($bet,$font,$bold);
        }
        return self::drawUnknownCard($bet,$font,$bold);
    }

    /**
     * Approved branded layout used by Betano and bet365 previews.
     * Neon green is the interface accent; bookmaker colors are restricted to
     * the brand mark. Game time is intentionally absent everywhere.
     */
    private static function drawKnownCard(array $bet,string $font,string $bold): ?string
    {
        $legs=$bet['legs'];
        $kind=(string)($bet['kind']??'simple');
        $builder=$kind==='bet_builder';
        $brandKey=(string)($bet['bookmaker_key']??'unknown');
        $bookmaker=trim((string)($bet['bookmaker']??''))?:'Casa desconhecida';
        $odd=trim((string)($bet['odd']??''))?:'—';

        $width=1080;
        $margin=22;
        $contentX=34;
        $contentW=1012;
        $headerH=148;
        $y=$headerH+18;

        $analysisLines=self::wrap((string)$bet['analysis'],$font,20,850);
        if($analysisLines===null)return null;

        $layout=[];
        if($builder){
            $first=$legs[0];
            $eventH=188;
            $layout[]=['builder_event',$y,$eventH,$first];
            $y+=$eventH+18;

            $rows=[];
            $rowsH=82;
            foreach($legs as $index=>$leg){
                $market=self::wrap((string)$leg['market'],$bold,23,770);
                $selection=self::wrap((string)$leg['selection'],$bold,25,770);
                if($market===null||$selection===null)return null;
                $rh=max(96,42+count($market)*31+count($selection)*34);
                $rows[]=['builder_selection',$index,$leg,$market,$selection,$rh];
                $rowsH+=$rh;
            }
            $selectionH=$rowsH+24;
            $layout[]=['builder_selections',$y,$selectionH,$rows];
            $y+=$selectionH+20;
        }else{
            foreach($legs as $index=>$leg){
                $eventH=236;
                $layout[]=['event',$y,$eventH,$index,$leg];
                $y+=$eventH+16;
            }
        }

        $analysisY=$y;
        $analysisH=max(170,92+count($analysisLines)*31);
        $footerY=$analysisY+$analysisH+20;
        $footerH=142;
        $ctaY=$footerY+$footerH+18;
        $ctaH=104;
        $height=$ctaY+$ctaH+34;
        if($height>3500)return null;

        $im=imagecreatetruecolor($width,$height);
        if($im===false)return null;
        imagealphablending($im,true);

        $bg=self::color($im,'#020b0f');
        $panel=self::color($im,'#06161c');
        $panel2=self::color($im,'#082029');
        $border=self::color($im,'#124352');
        $green=self::color($im,'#39ff88');
        $greenSoft=self::color($im,'#76ffae');
        $white=self::color($im,'#f5f7fa');
        $muted=self::color($im,'#aeb9d7');
        $dim=self::color($im,'#758397');
        $yellow=self::color($im,'#f4d90b');
        $orange=self::color($im,'#ff612d');
        imagefill($im,0,0,$bg);

        self::rounded($im,16,16,1048,$height-32,30,$green);
        self::rounded($im,19,19,1042,$height-38,27,$bg);

        // Header: dark green glow similar to the approved mockups.
        self::rounded($im,$margin,$margin,1036,$headerH-20,24,$panel2);
        for($i=0;$i<8;$i++){
            $g=self::color($im,sprintf('#%02x%02x%02x',2,38+$i*4,30+$i*2));
            imagefilledellipse($im,900+$i*9,62,330-$i*18,145-$i*7,$g);
        }
        imagefilledrectangle($im,$margin,$margin,1058,27,$green);

        self::brandText($im,58,103,$bookmaker,$brandKey,$bold,$white,$orange,$yellow);

        $type=self::kindLabel($kind);
        $typeW=max(218,min(300,self::width($type,$bold,22)+92));
        $typeX=820-$typeW;
        self::rounded($im,$typeX,50,$typeW,58,18,$green);
        self::rounded($im,$typeX+3,53,$typeW-6,52,16,$panel);
        self::linkIcon($im,$typeX+28,79,$green,2);
        self::text($im,$typeX+64,88,$type,22,$green,$bold);

        self::rounded($im,832,38,202,88,22,$green);
        self::rounded($im,835,41,196,82,19,$panel);
        self::text($im,860,69,'ODD TOTAL',15,$greenSoft,$bold);
        self::textFit($im,864,111,$odd,39,$white,$bold,142);

        foreach($layout as $block){
            if($block[0]==='builder_event'){
                [, $top,$h,$leg]=$block;
                self::rounded($im,$contentX,$top,$contentW,$h,24,$border);
                self::rounded($im,$contentX+2,$top+2,$contentW-4,$h-4,22,$panel);
                self::drawMatchRow($im,62,$top+70,(string)$leg['match'],$font,$bold,$white,$muted);
                $league=trim((string)($leg['league']??''));
                if($league!=='')self::textFit($im,165,$top+119,$league,19,$muted,$font,760);
                if(!empty($leg['date'])){
                    self::calendarIcon($im,163,$top+151,$muted);
                    self::text($im,205,$top+162,'Data: '.$leg['date'],18,$muted,$font);
                }
            }elseif($block[0]==='builder_selections'){
                [, $top,$h,$rows]=$block;
                self::rounded($im,$contentX,$top,$contentW,$h,24,$border);
                self::rounded($im,$contentX+2,$top+2,$contentW-4,$h-4,22,$panel);
                self::barsIcon($im,66,$top+50,$green);
                self::text($im,112,$top+57,'SELEÇÕES ('.count($legs).')',22,$muted,$bold);
                self::linkIcon($im,830,$top+49,$green,2);
                self::text($im,866,$top+57,'Bet Builder',19,$green,$bold);
                imageline($im,60,$top+76,1022,$top+76,$border);

                $cursor=$top+91;
                foreach($rows as $rowIndex=>$row){
                    [, $index,$leg,$marketLines,$selectionLines,$rh]=$row;
                    $cy=$cursor+42;
                    self::circleNumber($im,83,$cy,$index+1,$green,$panel,$bold);
                    if($rowIndex<count($rows)-1)imageline($im,83,$cy+25,83,$cursor+$rh+16,$green);
                    $ty=$cursor+34;
                    foreach($marketLines as $line){
                        self::text($im,138,$ty,$line,23,$white,$bold);$ty+=31;
                    }
                    foreach($selectionLines as $line){
                        self::text($im,138,$ty,$line,25,$green,$bold);$ty+=34;
                    }
                    if($rowIndex<count($rows)-1)imageline($im,138,$cursor+$rh-2,1004,$cursor+$rh-2,$border);
                    // Bet Builder intentionally has NO odd beside each selection.
                    $cursor+=$rh;
                }
            }else{
                [, $top,$h,$index,$leg]=$block;
                self::rounded($im,$contentX,$top,$contentW,$h,24,$border);
                self::rounded($im,$contentX+2,$top+2,$contentW-4,$h-4,22,$panel);

                self::drawMatchRow($im,60,$top+62,(string)$leg['match'],$font,$bold,$white,$muted);
                $league=trim((string)($leg['league']??''));
                if($league!=='')self::textFit($im,165,$top+107,$league,18,$muted,$font,660);
                if(!empty($leg['date'])){
                    self::calendarIcon($im,163,$top+137,$muted);
                    self::text($im,205,$top+149,$leg['date'],17,$muted,$font);
                }

                $pill='SELEÇÃO '.($index+1);
                $pillW=158;
                self::rounded($im,856,$top+26,$pillW,46,16,$green);
                self::rounded($im,859,$top+29,$pillW-6,40,14,$panel);
                self::text($im,880,$top+57,$pill,17,$green,$bold);

                self::rounded($im,52,$top+164,976,58,16,$border);
                self::rounded($im,54,$top+166,972,54,14,$bg);
                self::circleNumber($im,89,$top+193,$index+1,$green,$bg,$bold);
                self::textFit($im,142,$top+188,(string)$leg['market'],21,$white,$bold,560);
                self::textFit($im,142,$top+215,(string)$leg['selection'],22,$green,$bold,690);
                // Approved Betano/bet365 models expose only the total price.
            }
        }

        self::rounded($im,$contentX,$analysisY,$contentW,$analysisH,24,$border);
        self::rounded($im,$contentX+2,$analysisY+2,$contentW-4,$analysisH-4,22,$panel);
        self::barsIcon($im,66,$analysisY+54,$green);
        self::text($im,112,$analysisY+59,'ANÁLISE',24,$green,$bold);
        $cursor=$analysisY+100;
        foreach($analysisLines as $line){self::text($im,112,$cursor,$line,20,$white,$font);$cursor+=31;}

        self::rounded($im,$contentX,$footerY,$contentW,$footerH,24,$border);
        self::rounded($im,$contentX+2,$footerY+2,$contentW-4,$footerH-4,22,$panel2);

        self::coinsIcon($im,72,$footerY+72,$green);
        self::text($im,118,$footerY+45,'STAKE',15,$muted,$bold);
        self::text($im,118,$footerY+92,self::FIXED_STAKE,34,$white,$bold);
        self::text($im,165,$footerY+92,'unidades',20,$white,$bold);
        imageline($im,350,$footerY+28,350,$footerY+116,$border);

        self::barsIcon($im,400,$footerY+75,$green);
        self::text($im,457,$footerY+45,'ODD TOTAL',15,$muted,$bold);
        self::textFit($im,457,$footerY+94,$odd,36,$white,$bold,180);
        imageline($im,675,$footerY+28,675,$footerY+116,$border);

        self::targetIcon($im,722,$footerY+77,$green);
        self::text($im,779,$footerY+45,'TIPO',15,$muted,$bold);
        $footerType=$kind==='bet_builder'
            ?(count($legs)===2?'Dupla (Bet Builder)':'Bet Builder')
            :ucfirst(mb_strtolower($type,'UTF-8'));
        self::textFit($im,779,$footerY+94,$footerType,23,$white,$bold,245);

        self::rounded($im,$contentX,$ctaY,$contentW,$ctaH,24,$green);
        self::rounded($im,$contentX+3,$ctaY+3,$contentW-6,$ctaH-6,21,self::color($im,'#006e3b'));
        for($i=0;$i<6;$i++){
            $shade=self::color($im,sprintf('#%02x%02x%02x',0,108+$i*7,58+$i*5));
            imagefilledellipse($im,880+$i*20,$ctaY+50,500-$i*45,110-$i*8,$shade);
        }
        self::telegramIcon($im,315,$ctaY+52,$greenSoft);
        self::text($im,378,$ctaY+66,'APOSTAR NA',27,$white,$bold);
        if($brandKey==='bet365'){
            self::text($im,603,$ctaY+66,'bet',31,$white,$bold);
            self::text($im,660,$ctaY+66,'365',31,$yellow,$bold);
        }else{
            self::text($im,604,$ctaY+66,'BETANO',31,$white,$bold);
        }

        $path=sys_get_temp_dir().'/tmr-approved-card-'.bin2hex(random_bytes(12)).'.png';
        $ok=imagepng($im,$path,7);
        unset($im);
        if(!$ok){@unlink($path);return null;}
        @chmod($path,0600);
        return $path;
    }

    /**
     * Approved neutral model. Unknown bookmakers keep the compact preview:
     * question mark brand, green accents and no bookmaker-specific CTA.
     */
    private static function drawUnknownCard(array $bet,string $font,string $bold): ?string
    {
        $legs=$bet['legs'];
        $kind=(string)($bet['kind']??'simple');
        $builder=$kind==='bet_builder';
        $bookmaker=trim((string)($bet['bookmaker']??''))?:'Casa desconhecida';
        $odd=trim((string)($bet['odd']??''))?:'—';

        $width=1080;
        $contentX=28;
        $contentW=1024;
        $headerH=118;
        $y=138;

        $blocks=[];
        if($builder){
            $first=$legs[0];
            $eventH=155;
            $blocks[]=['builder_event',$y,$eventH,$first];
            $y+=$eventH+12;
            $rows=[];
            $rowsH=20;
            foreach($legs as $index=>$leg){
                $market=self::wrap((string)$leg['market'],$bold,20,700);
                $selection=self::wrap((string)$leg['selection'],$bold,22,700);
                if($market===null||$selection===null)return null;
                $rh=max(82,28+count($market)*27+count($selection)*31);
                $rows[]=['builder_selection',$index,$leg,$market,$selection,$rh];
                $rowsH+=$rh;
            }
            $blocks[]=['builder_selections',$y,$rowsH+24,$rows];
            $y+=$rowsH+36;
        }else{
            foreach($legs as $index=>$leg){
                $h=188;
                $blocks[]=['event',$y,$h,$index,$leg];
                $y+=$h+12;
            }
        }

        $analysisLines=self::wrap((string)$bet['analysis'],$font,20,820);
        if($analysisLines===null)return null;
        $analysisY=$y+4;
        $analysisH=max(156,82+count($analysisLines)*31);
        $footerY=$analysisY+$analysisH+14;
        $footerH=132;
        $height=$footerY+$footerH+26;
        if($height>3500)return null;

        $im=imagecreatetruecolor($width,$height);
        if($im===false)return null;
        $bg=self::color($im,'#020c10');
        $panel=self::color($im,'#06171d');
        $panel2=self::color($im,'#082029');
        $border=self::color($im,'#164552');
        $green=self::color($im,'#39ff88');
        $white=self::color($im,'#f5f7fa');
        $muted=self::color($im,'#b0bad4');
        imagefill($im,0,0,$bg);

        self::rounded($im,16,16,1048,$height-32,26,$green);
        self::rounded($im,19,19,1042,$height-38,23,$bg);

        self::rounded($im,28,26,1024,92,22,$panel2);
        for($i=0;$i<6;$i++){
            $shade=self::color($im,sprintf('#%02x%02x%02x',0,58+$i*6,44+$i*3));
            imagefilledellipse($im,815+$i*18,60,390-$i*40,100-$i*7,$shade);
        }
        self::questionIcon($im,72,72,$green,$muted,$bold);
        self::textFit($im,130,83,$bookmaker,30,$white,$bold,540);
        $type=self::kindLabel($kind);
        $tw=max(180,min(265,self::width($type,$bold,20)+58));
        self::rounded($im,1024-$tw,42,$tw,52,16,$green);
        self::rounded($im,1027-$tw,45,$tw-6,46,14,$panel);
        self::text($im,1024-$tw+(int)(($tw-self::width($type,$bold,20))/2),76,$type,20,$green,$bold);

        foreach($blocks as $block){
            if($block[0]==='builder_event'){
                [, $top,$h,$leg]=$block;
                self::rounded($im,$contentX,$top,$contentW,$h,20,$border);
                self::rounded($im,$contentX+2,$top+2,$contentW-4,$h-4,18,$panel);
                self::drawMatchRow($im,55,$top+55,(string)$leg['match'],$font,$bold,$white,$muted);
                if(!empty($leg['league']))self::textFit($im,155,$top+96,(string)$leg['league'],17,$muted,$font,760);
                if(!empty($leg['date'])){
                    self::calendarIcon($im,154,$top+122,$muted);
                    self::text($im,192,$top+133,$leg['date'],16,$muted,$font);
                }
            }elseif($block[0]==='builder_selections'){
                [, $top,$h,$rows]=$block;
                self::rounded($im,$contentX,$top,$contentW,$h,20,$border);
                self::rounded($im,$contentX+2,$top+2,$contentW-4,$h-4,18,$panel);
                $cursor=$top+19;
                foreach($rows as $rowIndex=>$row){
                    [, $index,$leg,$markets,$selections,$rh]=$row;
                    $cy=$cursor+33;
                    self::circleNumber($im,78,$cy,$index+1,$green,$panel,$bold);
                    if($rowIndex<count($rows)-1)imageline($im,78,$cy+23,78,$cursor+$rh+8,$green);
                    $ty=$cursor+28;
                    foreach($markets as $line){self::text($im,126,$ty,$line,20,$white,$bold);$ty+=27;}
                    foreach($selections as $line){self::text($im,126,$ty,$line,22,$green,$bold);$ty+=31;}
                    // No individual odds in Bet Builder, including unknown houses.
                    $cursor+=$rh;
                }
            }else{
                [, $top,$h,$index,$leg]=$block;
                self::rounded($im,$contentX,$top,$contentW,$h,20,$border);
                self::rounded($im,$contentX+2,$top+2,$contentW-4,$h-4,18,$panel);
                self::drawMatchRow($im,55,$top+50,(string)$leg['match'],$font,$bold,$white,$muted);
                if(!empty($leg['league']))self::textFit($im,155,$top+91,(string)$leg['league'],16,$muted,$font,690);
                if(!empty($leg['date'])){
                    self::calendarIcon($im,154,$top+116,$muted);
                    self::text($im,192,$top+128,$leg['date'],15,$muted,$font);
                }
                self::targetIcon($im,65,$top+157,$green);
                self::textFit($im,120,$top+151,(string)$leg['market'],19,$white,$bold,600);
                self::textFit($im,120,$top+178,(string)$leg['selection'],20,$green,$bold,600);
                if(!$builder && !empty($leg['odd'])){
                    self::rounded($im,890,$top+123,126,52,12,$border);
                    self::rounded($im,893,$top+126,120,46,10,$bg);
                    self::textFit($im,917,$top+159,(string)$leg['odd'],24,$white,$bold,76);
                }
            }
        }

        self::rounded($im,$contentX,$analysisY,$contentW,$analysisH,20,$border);
        self::rounded($im,$contentX+2,$analysisY+2,$contentW-4,$analysisH-4,18,$panel);
        self::barsIcon($im,64,$analysisY+50,$green);
        self::text($im,112,$analysisY+56,'ANÁLISE',23,$green,$bold);
        $cursor=$analysisY+92;
        foreach($analysisLines as $line){self::text($im,112,$cursor,$line,20,$white,$font);$cursor+=31;}

        self::rounded($im,$contentX,$footerY,$contentW,$footerH,20,$border);
        self::rounded($im,$contentX+2,$footerY+2,$contentW-4,$footerH-4,18,$panel2);
        self::coinsIcon($im,65,$footerY+69,$green);
        self::text($im,110,$footerY+42,'STAKE',14,$muted,$bold);
        self::text($im,110,$footerY+83,self::FIXED_STAKE,31,$white,$bold);
        self::text($im,150,$footerY+83,'unidades',18,$white,$font);
        imageline($im,344,$footerY+23,344,$footerY+108,$border);
        self::barsIcon($im,390,$footerY+69,$green);
        self::text($im,445,$footerY+42,'ODD TOTAL',14,$muted,$bold);
        self::textFit($im,445,$footerY+85,$odd,32,$white,$bold,170);
        imageline($im,682,$footerY+23,682,$footerY+108,$border);
        self::homeIcon($im,727,$footerY+66,$green);
        self::text($im,778,$footerY+42,'CASA',14,$muted,$bold);
        self::textFit($im,778,$footerY+81,$bookmaker,21,$white,$bold,245);

        $path=sys_get_temp_dir().'/tmr-approved-neutral-'.bin2hex(random_bytes(12)).'.png';
        $ok=imagepng($im,$path,7);
        unset($im);
        if(!$ok){@unlink($path);return null;}
        @chmod($path,0600);
        return $path;
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
