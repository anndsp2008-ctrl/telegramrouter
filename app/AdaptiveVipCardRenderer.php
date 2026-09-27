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
            .'REGRA SEMÂNTICA DE BET BUILDER: Bet Builder é UMA ÚNICA APOSTA composta por duas ou mais condições/seleções do MESMO JOGO. Na análise, nunca descreva essas condições como duas apostas, duas entradas, apostas separadas ou apostas independentes. Use termos como "uma única aposta", "duas condições da mesma aposta" ou "seleções combinadas no mesmo Bet Builder". '
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
        $kindKey=(string)($bet['kind']??'simple');
        $kind=self::kindLabel($kindKey);
        $bookmaker=trim((string)($bet['bookmaker']??''))?:'Casa desconhecida';
        $legs=is_array($bet['legs']??null)?$bet['legs']:[];
        $lines=['🎟️ '.$kind.' • '.$bookmaker,''];

        if($kindKey==='bet_builder' && $legs!==[]){
            // A Bet Builder is one single wager with several conditions in the
            // same match. Show the event once so Telegram text cannot look like
            // two independent bets.
            $first=$legs[0];
            $lines[]='⚽ '.($first['match']??'');
            if(!empty($first['league']))$lines[]='🏆 '.$first['league'];
            if(!empty($first['date']))$lines[]='📅 '.$first['date'];
            $lines[]='';
            $lines[]='🧩 Seleções ('.count($legs).') da mesma aposta:';

            foreach($legs as $leg){
                $lines[]='🎯 Mercado: '.($leg['market']??'');
                $lines[]='✅ Seleção: '.($leg['selection']??'');
                $lines[]='';
            }
        }else{
            foreach($legs as $index=>$leg){
                $lines[]='⚽ '.($index+1).'. '.($leg['match']??'');
                if(!empty($leg['league']))$lines[]='🏆 '.$leg['league'];
                if(!empty($leg['date']))$lines[]='📅 '.$leg['date'];
                $lines[]='🎯 Mercado: '.($leg['market']??'');
                $lines[]='✅ Seleção: '.($leg['selection']??'');
                if(!empty($leg['odd']))$lines[]='📈 Odd: '.$leg['odd'];
                $lines[]='';
            }
        }

        if(!empty($bet['odd']))$lines[]='📊 Odd total: '.$bet['odd'];
        $lines[]='📍 Stake: '.self::FIXED_STAKE;
        $analysis=self::stripEmojis(trim((string)($bet['analysis']??'')));
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
     * Reference premium layout used globally for every generated betting card.
     * Visual target: the approved neon-black reference card. Dynamic content is
     * allowed to grow vertically, but spacing, hierarchy and surfaces stay the same.
     * Game times are never rendered; only a sanitized date can be displayed.
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

        // Match the approved reference proportions.
        $width=1199;
        $outerX=38;
        $contentX=50;
        $contentW=1099;
        $headerY=20;
        $headerH=132;
        $gap=24;
        $y=$headerY+$headerH+$gap;

        $sections=[];

        if($builder || count($legs)===1){
            $first=$legs[0];
            $matchLines=self::wrap((string)$first['match'],$bold,28,830);
            $leagueLines=self::wrap((string)($first['league']??''),$font,20,820)??[];
            if($matchLines===null)return null;

            $eventH=116+count($matchLines)*38+count($leagueLines)*30+(!empty($first['date'])?39:0);
            $eventH=max(177,$eventH);
            $sections[]=['event_summary',$y,$eventH,$first,$matchLines,$leagueLines];
            $y+=$eventH+$gap;
        }

        $selectionRows=[];
        $selectionBodyH=0;
        foreach($legs as $index=>$leg){
            if($builder){
                $matchLines=[];
                $marketLines=self::wrap((string)$leg['market'],$bold,23,820);
                $selectionLines=self::wrap((string)$leg['selection'],$bold,26,820);
                $leagueLines=[];
            }elseif(count($legs)===1){
                $matchLines=[];
                $marketLines=self::wrap((string)$leg['market'],$bold,23,760);
                $selectionLines=self::wrap((string)$leg['selection'],$bold,26,760);
                $leagueLines=[];
            }else{
                $matchLines=self::wrap((string)$leg['match'],$bold,22,650);
                $leagueLines=self::wrap((string)($leg['league']??''),$font,16,650)??[];
                $marketLines=self::wrap((string)$leg['market'],$font,18,650);
                $selectionLines=self::wrap((string)$leg['selection'],$bold,23,650);
            }
            if($matchLines===null||$marketLines===null||$selectionLines===null)return null;

            $rowH=$builder||count($legs)===1
                ?max(108,38+count($marketLines)*31+count($selectionLines)*35)
                :max(154,45+count($matchLines)*30+count($leagueLines)*23
                    +(!empty($leg['date'])?27:0)+count($marketLines)*27+count($selectionLines)*32);

            $selectionRows[]=[$index,$leg,$matchLines,$leagueLines,$marketLines,$selectionLines,$rowH];
            $selectionBodyH+=$rowH;
        }

        $selectionH=88+$selectionBodyH+22;
        $sections[]=['selections',$y,$selectionH,$selectionRows];
        $y+=$selectionH+$gap;

        $analysisLines=self::wrap($analysis,$font,21,865);
        if($analysisLines===null)return null;
        $analysisY=$y;
        $analysisH=max(194,102+count($analysisLines)*34);
        $footerY=$analysisY+$analysisH+$gap;
        $footerH=168;
        $ctaY=$footerY+$footerH+$gap;
        $ctaH=100;
        $height=$ctaY+$ctaH+34;
        if($height>5000)return null;

        $im=imagecreatetruecolor($width,$height);
        if($im===false)return null;
        imagealphablending($im,true);

        $bg=self::color($im,'#02090d');
        $panel=self::color($im,'#06151b');
        $panelAlt=self::color($im,'#071b22');
        $panelDeep=self::color($im,'#031116');
        $border=self::color($im,'#173a43');
        $borderSoft=self::color($im,'#102d35');
        $green=self::color($im,'#45f56f');
        $greenSoft=self::color($im,'#80ff9c');
        $greenDark=self::color($im,'#004d31');
        $white=self::color($im,'#f6f7fa');
        $muted=self::color($im,'#b4b8ce');
        $dim=self::color($im,'#7f879b');
        $orange=self::color($im,'#ff6536');
        $yellow=self::color($im,'#f1dc16');

        imagefill($im,0,0,$bg);

        // Outer double neon frame, matching the approved reference.
        self::rounded($im,$outerX,14,1123,$height-28,31,$green);
        self::rounded($im,$outerX+3,17,1117,$height-34,28,$bg);
        self::rounded($im,$outerX+12,26,1099,$height-52,24,self::color($im,'#0b242b'));
        self::rounded($im,$outerX+14,28,1095,$height-56,22,$bg);

        // Keep the header background flat and clean. Deliberately no glow/
        // ellipse overlays: they created visible dark/green smudges after PNG
        // scaling/compression in Telegram previews.

        self::brandText($im,80,$headerY+91,$bookmaker,$brandKey,$bold,$white,$orange,$yellow);

        $type=self::kindLabel($kind);
        $typeW=max(250,min(294,self::width($type,$bold,22)+92));
        $oddW=208;
        $oddX=$contentX+$contentW-$oddW-16;
        $typeX=$oddX-$typeW-44;

        self::rounded($im,$typeX,$headerY+40,$typeW,66,20,$green);
        self::rounded($im,$typeX+3,$headerY+43,$typeW-6,60,17,$panelDeep);
        self::linkIcon($im,$typeX+34,$headerY+73,$green,3);
        self::textFit($im,$typeX+76,$headerY+84,$type,22,$green,$bold,$typeW-94);

        self::rounded($im,$oddX,$headerY+12,$oddW,118,24,$green);
        self::rounded($im,$oddX+3,$headerY+15,$oddW-6,112,21,$panelDeep);
        self::text($im,$oddX+33,$headerY+50,'ODD TOTAL',17,$green,$bold);
        self::textFit($im,$oddX+39,$headerY+105,$odd,44,$white,$bold,$oddW-70);

        foreach($sections as $section){
            if($section[0]==='event_summary'){
                [, $top,$h,$leg,$matchLines,$leagueLines]=$section;

                self::rounded($im,$contentX,$top,$contentW,$h,28,$border);
                self::rounded($im,$contentX+2,$top+2,$contentW-4,$h-4,26,$panel);

                $eventIconY=$top+55;
                self::footballIcon($im,88,$eventIconY,$white,$bg);

                $cursor=$top+65;
                $sides=self::matchSides((string)$leg['match']);
                if($sides!==null && self::flagSpec($sides[0])!==null && self::flagSpec($sides[1])!==null){
                    [$left,$right]=$sides;
                    $leftSpec=self::flagSpec($left);
                    $rightSpec=self::flagSpec($right);

                    self::drawFlag($im,168,$eventIconY,$leftSpec);
                    self::textFit($im,205,$top+72,$left,29,$white,$bold,260);
                    self::text($im,446,$top+72,'x',24,$muted,$bold);
                    self::drawFlag($im,508,$eventIconY,$rightSpec);
                    self::textFit($im,548,$top+72,$right,29,$white,$bold,430);
                    $cursor=$top+116;
                }else{
                    foreach($matchLines as $line){
                        self::text($im,145,$cursor,$line,28,$white,$bold);
                        $cursor+=38;
                    }
                    $cursor+=3;
                }

                foreach($leagueLines as $line){
                    self::text($im,224,$cursor,$line,20,$muted,$font);
                    $cursor+=30;
                }

                if(!empty($leg['date'])){
                    self::calendarIcon($im,178,$cursor+10,$muted);
                    // Rule: date only. Never append or infer a match time here.
                    self::text($im,224,$cursor+22,(string)$leg['date'],20,$muted,$font);
                }
            }elseif($section[0]==='selections'){
                [, $top,$h,$selectionRows]=$section;
                self::rounded($im,$contentX,$top,$contentW,$h,28,$border);
                self::rounded($im,$contentX+2,$top+2,$contentW-4,$h-4,26,$panel);

                self::listIcon($im,83,$top+50,$green);
                self::text($im,145,$top+58,'SELEÇÕES ('.count($legs).')',22,$muted,$bold);

                if($builder){
                    self::linkIcon($im,934,$top+50,$green,3);
                    self::text($im,975,$top+58,'Bet Builder',20,$green,$bold);
                }else{
                    $label=$kind==='simple'?'Simples':($kind==='double'?'Dupla':'Múltipla');
                    self::textFit($im,925,$top+58,$label,19,$green,$bold,180);
                }

                imageline($im,69,$top+80,1127,$top+80,$borderSoft);

                $cursor=$top+88;
                foreach($selectionRows as $rowIndex=>$selectionRow){
                    [$index,$leg,$matchLines,$leagueLines,$marketLines,$selectionLines,$rowH]=$selectionRow;
                    $circleY=$cursor+42;

                    // Open selection marker from the reference.
                    imageellipse($im,106,$circleY,29,29,$green);
                    imageellipse($im,106,$circleY,25,25,$green);
                    if($rowIndex<count($selectionRows)-1){
                        imageline($im,106,$circleY+16,106,$cursor+$rowH+4,$green);
                    }

                    $textX=166;
                    $ty=$cursor+34;

                    if(!$builder && count($legs)>1){
                        foreach($matchLines as $line){
                            self::text($im,$textX,$ty,$line,22,$white,$bold);$ty+=30;
                        }
                        foreach($leagueLines as $line){
                            self::text($im,$textX,$ty,$line,16,$dim,$font);$ty+=23;
                        }
                        if(!empty($leg['date'])){
                            self::calendarIcon($im,$textX,$ty+7,$dim);
                            self::text($im,$textX+41,$ty+18,(string)$leg['date'],16,$dim,$font);
                            $ty+=29;
                        }
                        foreach($marketLines as $line){
                            self::text($im,$textX,$ty,$line,18,$muted,$font);$ty+=27;
                        }
                    }else{
                        foreach($marketLines as $line){
                            self::text($im,$textX,$ty,$line,23,$white,$bold);$ty+=31;
                        }
                    }

                    foreach($selectionLines as $line){
                        self::text($im,$textX,$ty,$line,26,$green,$bold);$ty+=35;
                    }

                    if(!$builder && !empty($leg['odd'])){
                        $oddBoxW=135;
                        self::rounded($im,975,$cursor+30,$oddBoxW,70,16,$border);
                        self::rounded($im,978,$cursor+33,$oddBoxW-6,64,13,$panelDeep);
                        self::text($im,998,$cursor+55,'ODD',12,$muted,$bold);
                        self::textFit($im,998,$cursor+88,(string)$leg['odd'],25,$white,$bold,91);
                    }

                    if($rowIndex<count($selectionRows)-1){
                        imageline($im,$textX,$cursor+$rowH-7,1125,$cursor+$rowH-7,$borderSoft);
                    }

                    // Global Bet Builder rule: each selection has no individual odd.
                    $cursor+=$rowH;
                }
            }
        }

        self::rounded($im,$contentX,$analysisY,$contentW,$analysisH,28,$border);
        self::rounded($im,$contentX+2,$analysisY+2,$contentW-4,$analysisH-4,26,$panel);

        self::barsIcon($im,80,$analysisY+62,$green);
        self::text($im,164,$analysisY+61,'ANÁLISE',24,$green,$bold);

        $cursor=$analysisY+105;
        foreach($analysisLines as $line){
            self::text($im,164,$cursor,$line,21,$white,$font);
            $cursor+=34;
        }

        // Reference-style metrics footer: value and unit occupy separate vertical
        // lines so there is never any crowding or overlap.
        self::rounded($im,$contentX,$footerY,$contentW,$footerH,28,$border);
        self::rounded($im,$contentX+2,$footerY+2,$contentW-4,$footerH-4,26,$panelAlt);

        $sep1=$contentX+318;
        $sep2=$contentX+647;
        imageline($im,$sep1,$footerY+30,$sep1,$footerY+$footerH-30,$border);
        imageline($im,$sep2,$footerY+30,$sep2,$footerY+$footerH-30,$border);

        // Stake column.
        self::coinsIcon($im,96,$footerY+71,$green);
        self::text($im,158,$footerY+48,'STAKE',15,$muted,$bold);
        self::text($im,158,$footerY+104,self::FIXED_STAKE,38,$white,$bold);
        self::text($im,158,$footerY+142,'unidades',20,$muted,$font);

        // Total odd column.
        self::barsIcon($im,408,$footerY+85,$green);
        self::text($im,506,$footerY+48,'ODD TOTAL',15,$muted,$bold);
        self::textFit($im,506,$footerY+108,$odd,38,$white,$bold,150);

        // Type column.
        self::targetIcon($im,744,$footerY+77,$green);
        self::text($im,844,$footerY+48,'TIPO',15,$muted,$bold);
        $footerType=$kind==='bet_builder'
            ?(count($legs)===2?'Dupla (Bet Builder)':'Bet Builder')
            :ucfirst(mb_strtolower($type,'UTF-8'));
        self::textFit($im,844,$footerY+106,$footerType,24,$white,$bold,272);

        // Global brand footer required by the project.
        self::rounded($im,$contentX,$ctaY,$contentW,$ctaH,26,$green);
        self::rounded($im,$contentX+3,$ctaY+3,$contentW-6,$ctaH-6,23,$greenDark);
        // Global footer is text-only. No Telegram icon.
        self::drawCtaLabel($im,$ctaY+63,$bold,$greenSoft);

        $path=sys_get_temp_dir().'/tmr-reference-premium-'.bin2hex(random_bytes(12)).'.png';
        $ok=imagepng($im,$path,7);
        unset($im);
        if(!$ok){@unlink($path);return null;}
        @chmod($path,0600);
        return $path;
    }

    /**
     * Global card footer identity. It is intentionally independent from the
     * bookmaker and bet type so every published card carries the same label.
     */
    private static function drawCtaLabel($im,int $baseline,string $bold,int $color): void
    {
        $label='Telegram Router - Apostas VIP';
        $size=28;
        $maxWidth=610;
        while($size>20 && self::width($label,$bold,$size)>$maxWidth)$size--;
        $labelWidth=self::width($label,$bold,$size);
        // Center across the full CTA width now that the icon is removed.
        $start=($contentCenter=600)-(int)($labelWidth/2);
        self::text($im,$start,$baseline,$label,$size,$color,$bold);
    }

    private static function listIcon($im,int $x,int $y,int $color): void
    {
        for($i=0;$i<3;$i++){
            $yy=$y-18+$i*13;
            imagefilledellipse($im,$x,$yy,7,7,$color);
            imagefilledrectangle($im,$x+14,$yy-3,$x+44,$yy+3,$color);
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
            self::text($im,$x,$y,'bet',63,$white,$bold);
            $w=self::width('bet',$bold,63);
            self::text($im,$x+$w+2,$y,'365',63,$bet365Yellow,$bold);
            return;
        }
        if($key==='betano'){
            $brandFont=self::brandFont()??$bold;
            self::textFit($im,$x,$y,'Betano',72,$betanoOrange,$brandFont,430);
            return;
        }
        self::textFit($im,$x,$y,$name,52,$white,$bold,500);
    }

    private static function brandFont(): ?string
    {
        foreach([
            '/usr/share/fonts/truetype/liberation2/LiberationSans-BoldItalic.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-BoldOblique.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-BoldItalic.ttf'
        ] as $path){
            if(is_file($path))return $path;
        }
        return null;
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
