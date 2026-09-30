<?php declare(strict_types=1);

namespace App;

/**
 * Optional Stake Sports Data odds validator.
 *
 * Fail-open by design: any API, fixture, market, selection or line uncertainty
 * preserves the original source odd.
 */
final class StakeOddsProvider
{
    private const BASE_URL='https://odds-data.stake.com';
    private const CONNECT_TIMEOUT_MS=1800;
    private const REQUEST_TIMEOUT_MS=5500;
    private const FIXTURE_CACHE_TTL=30;
    private const DETAIL_CACHE_TTL=20;

    /** @var array<string,array{expires:int,payload:array}> */
    private static array $cache=[];
    private static ?string $workingAuthMode=null;

    public static function enabled(): bool
    {
        return filter_var((string)getenv('STAKE_ODDS_API_ENABLED'), FILTER_VALIDATE_BOOLEAN)
            && self::apiKey()!=='';
    }

    private static function apiKey(): string
    {
        if (class_exists(SportsApiIntegration::class)) {
            try {
                return SportsApiIntegration::stakeKey();
            } catch (\Throwable) {
            }
        }
        return trim((string)(getenv('STAKE_ODDS_API_KEY') ?: ''));
    }

    /** @param array<string,mixed> $bet @return array<string,mixed> */
    public static function applyToSingle(array $bet): array
    {
        $result=self::validateLeg($bet);
        self::log(
            (string)$result['status'],
            (bool)$result['changed'],
            (string)$result['error'],
            ['scope'=>'single']
        );
        return $result['bet'];
    }

    /**
     * Mandatory Stake pass for every structured ticket.
     * Double/multiple legs are checked independently. A failed lookup never
     * contaminates other legs: that leg simply keeps its source odd.
     * Bet Builder selections are checked too, but the combined source price is
     * preserved because correlated same-game prices must not be multiplied.
     *
     * @param array<string,mixed> $ticket
     * @param null|callable(array<string,mixed>):array{bet:array,status:string,changed:bool,error:string} $validator
     * @return array<string,mixed>
     */
    public static function applyToTicket(array $ticket, ?callable $validator=null): array
    {
        $legs=is_array($ticket['legs']??null)?array_values($ticket['legs']):[];
        if($legs===[]){
            return self::applyToSingle($ticket);
        }

        $kind=mb_strtolower(trim((string)($ticket['kind']??(count($legs)===1?'simple':'multiple'))),'UTF-8');
        if(!in_array($kind,['simple','double','multiple','bet_builder'],true)){
            $kind=count($legs)===1?'simple':(count($legs)===2?'double':'multiple');
        }

        $validator??=static fn(array $leg): array=>self::validateLeg($leg);
        $validatedCount=0;
        $fallbackCount=0;
        $changedCount=0;

        foreach($legs as $index=>$leg){
            if(!is_array($leg))continue;

            $probe=[
                'sport'=>(string)($leg['sport']??$ticket['sport']??''),
                'match'=>(string)($leg['match']??''),
                'league'=>(string)($leg['league']??''),
                'date'=>(string)($leg['date']??$leg['day']??''),
                'market'=>(string)($leg['market']??''),
                'selection'=>(string)($leg['selection']??''),
                'odd'=>(string)($leg['odd']??''),
            ];

            $result=$validator($probe);
            if(!is_array($result)
                ||!is_array($result['bet']??null)
                ||!isset($result['status'],$result['changed'],$result['error'])){
                $result=[
                    'bet'=>$probe,
                    'status'=>'validator_invalid',
                    'changed'=>false,
                    'error'=>'INVALID_VALIDATOR_RESULT',
                ];
            }

            $status=(string)$result['status'];
            $changed=(bool)$result['changed'];
            if($status==='validated')$validatedCount++; else $fallbackCount++;
            if($changed)$changedCount++;

            self::log(
                $status,
                $changed,
                (string)$result['error'],
                [
                    'scope'=>'ticket_leg',
                    'kind'=>$kind,
                    'leg'=>$index+1,
                    'legs'=>count($legs),
                ]
            );

            // Bet Builder keeps per-selection prices hidden. The mandatory check
            // still happens, but the source combined odd remains authoritative.
            if($kind==='bet_builder')continue;

            // Preserve the source odd byte-for-byte on every fallback
            // and when Stake confirms the same price. Only a genuinely changed,
            // validated Stake price is written back to the published leg.
            if($status==='validated' && $changed){
                $finalOdd=self::normalizeOdd((string)($result['bet']['odd']??''));
                if($finalOdd!=='')$ticket['legs'][$index]['odd']=$finalOdd;
            }
        }

        if($kind==='simple' && isset($ticket['legs'][0])){
            $singleOdd=self::normalizeOdd((string)($ticket['legs'][0]['odd']??''));
            if($singleOdd!=='')$ticket['odd']=$singleOdd;
        }elseif(in_array($kind,['double','multiple'],true)){
            $combined=self::combinedOdd((array)$ticket['legs']);
            if($combined!==null)$ticket['odd']=$combined;
        }

        error_log('TMR_STAKE_TICKET '.json_encode([
            'kind'=>$kind,
            'legs'=>count($legs),
            'validated'=>$validatedCount,
            'fallback'=>$fallbackCount,
            'changed'=>$changedCount,
            'total_recalculated'=>in_array($kind,['double','multiple'],true)
                && self::combinedOdd((array)$ticket['legs'])!==null,
        ],JSON_UNESCAPED_SLASHES));

        return $ticket;
    }

    /**
     * @param array<string,mixed> $bet
     * @return array{bet:array<string,mixed>,status:string,changed:bool,error:string}
     */
    private static function validateLeg(array $bet): array
    {
        $originalOdd=self::normalizeOdd((string)($bet['odd']??''));

        if(!self::enabled()){
            return ['bet'=>$bet,'status'=>'disabled','changed'=>false,'error'=>''];
        }

        if(!self::isFootball((string)($bet['sport']??''),(string)($bet['league']??''))){
            return ['bet'=>$bet,'status'=>'not_applicable','changed'=>false,'error'=>''];
        }

        if(trim((string)($bet['match']??''))===''
            ||trim((string)($bet['market']??''))===''
            ||trim((string)($bet['selection']??''))===''){
            return ['bet'=>$bet,'status'=>'incomplete_leg','changed'=>false,'error'=>''];
        }

        try{
            $sportSlug=trim((string)getenv('STAKE_ODDS_SPORT_SLUG'))?:'soccer';
            if(!preg_match('/^[a-z0-9-]{2,60}$/D',$sportSlug))$sportSlug='soccer';

            $fixtures=self::cached(
                'fixtures:'.$sportSlug,
                self::FIXTURE_CACHE_TTL,
                static fn(): array=>self::request('/sport/'.rawurlencode($sportSlug).'/fixture')
            );

            $fixture=self::selectFixture(
                $fixtures,
                (string)($bet['match']??''),
                (string)($bet['league']??''),
                (string)($bet['date']??$bet['day']??'')
            );

            // The broad fixture feed can use different display names and may
            // not be the best lookup surface for every event. Before declaring
            // fixture_not_found, retry against Stake's sport schedule feed.
            if($fixture===null){
                $schedule=self::cached(
                    'schedule:'.$sportSlug,
                    self::FIXTURE_CACHE_TTL,
                    static fn(): array=>self::request('/schedule/sport/'.rawurlencode($sportSlug))
                );
                $fixture=self::selectFixture(
                    $schedule,
                    (string)($bet['match']??''),
                    (string)($bet['league']??''),
                    (string)($bet['date']??$bet['day']??'')
                );
                if($fixture!==null){
                    error_log('TMR_STAKE_FIXTURE_MATCH schedule_fallback');
                }
            }

            if($fixture===null){
                return ['bet'=>$bet,'status'=>'fixture_not_found','changed'=>false,'error'=>''];
            }

            $slug=trim((string)($fixture['slug']??''));
            if($slug==='' || !preg_match('/^[A-Za-z0-9._:-]{1,200}$/D',$slug)){
                return ['bet'=>$bet,'status'=>'fixture_slug_missing','changed'=>false,'error'=>''];
            }

            $detail=self::cached(
                'fixture:'.$slug,
                self::DETAIL_CACHE_TTL,
                static fn(): array=>self::request('/fixtures/'.rawurlencode($slug))
            );

            $stakeOdd=self::selectOdd(
                $detail,
                (string)($bet['market']??''),
                (string)($bet['selection']??'')
            );
            if($stakeOdd===null){
                return ['bet'=>$bet,'status'=>'market_or_line_not_found','changed'=>false,'error'=>''];
            }

            // Never invent a missing published odd. Exact Stake matching is still
            // recorded as validated, but replacement happens only when an origin
            // odd exists for this leg.
            $changed=false;
            if($originalOdd!==''){
                $changed=$stakeOdd!==$originalOdd;
                $bet['odd']=$stakeOdd;
            }

            return ['bet'=>$bet,'status'=>'validated','changed'=>$changed,'error'=>''];
        }catch(\Throwable $e){
            return [
                'bet'=>$bet,
                'status'=>'api_unavailable',
                'changed'=>false,
                'error'=>get_class($e),
            ];
        }
    }

    /** @param list<array<string,mixed>> $legs */
    private static function combinedOdd(array $legs): ?string
    {
        if($legs===[])return null;
        $product=1.0;
        foreach($legs as $leg){
            if(!is_array($leg))return null;
            $odd=self::normalizeOdd((string)($leg['odd']??''));
            if($odd==='' || !is_numeric($odd) || (float)$odd<=1.0)return null;
            $product*=(float)$odd;
            if(!is_finite($product)||$product>99999)return null;
        }
        // Combined ticket odds follow the publishing rule requested for
        // doubles/multiples: multiply every final leg odd and truncate (never
        // round up) to two decimal places. Example: 1.30 x 1.35 = 1.755 -> 1.75.
        $truncated=floor(($product+1.0e-9)*100.0)/100.0;
        return number_format($truncated,2,'.','');
    }

    /** @return array<string,mixed> */
    private static function cached(string $key,int $ttl,callable $loader): array
    {
        $now=time();
        if(isset(self::$cache[$key]) && self::$cache[$key]['expires']>$now){
            return self::$cache[$key]['payload'];
        }
        $payload=$loader();
        self::$cache[$key]=['expires'=>$now+$ttl,'payload'=>$payload];
        return $payload;
    }

    /** @return array<string,mixed> */
    private static function request(string $path): array
    {
        $key=self::apiKey();
        if($key==='')throw new \RuntimeException('STAKE_KEY_MISSING');
        if(!function_exists('curl_init'))throw new \RuntimeException('STAKE_CURL_MISSING');

        $modes=self::$workingAuthMode!==null
            ?[self::$workingAuthMode]
            :['x-api-key','apiKey','bearer'];

        $lastHttp=0;
        foreach($modes as $mode){
            [$payload,$http,$authFailure]=self::requestWithAuth($path,$key,$mode);
            $lastHttp=$http;
            if($payload!==null){
                self::$workingAuthMode=$mode;
                return $payload;
            }
            if(!$authFailure)break;
        }

        throw new \RuntimeException('STAKE_HTTP_'.($lastHttp?:0));
    }

    /** @return array{0:?array,1:int,2:bool} */
    private static function requestWithAuth(string $path,string $key,string $mode): array
    {
        $headers=['Accept: application/json'];
        if($mode==='x-api-key')$headers[]='X-API-Key: '.$key;
        elseif($mode==='apiKey')$headers[]='apiKey: '.$key;
        else $headers[]='Authorization: Bearer '.$key;

        $ch=curl_init(self::BASE_URL.$path);
        if($ch===false)return [null,0,false];

        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_CONNECTTIMEOUT_MS=>self::CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS=>self::REQUEST_TIMEOUT_MS,
            CURLOPT_NOSIGNAL=>true,
            CURLOPT_ENCODING=>'',
            CURLOPT_FOLLOWLOCATION=>false,
        ]);

        $body=curl_exec($ch);
        $errno=curl_errno($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        unset($ch);

        if($body===false || $errno!==0)return [null,$http,false];
        $decoded=json_decode((string)$body,true);
        if($http>=200 && $http<300 && is_array($decoded))return [$decoded,$http,false];

        return [null,$http,in_array($http,[401,403],true)];
    }

    /** @param array<string,mixed> $payload @return ?array<string,mixed> */
    private static function selectFixture(array $payload,string $match,string $league,string $date): ?array
    {
        $sides=self::matchSides($match);
        if($sides===null)return null;
        [$wantedA,$wantedB]=$sides;

        $rows=self::fixtureRows($payload);
        if($rows===[])return null;

        $candidates=[];
        foreach($rows as $fixture){
            if(!is_array($fixture))continue;
            $actualA='';$actualB='';
            $competitors=$fixture['competitors']??[];
            if(is_array($competitors) && count($competitors)>=2){
                $actualA=self::competitorName($competitors[0]);
                $actualB=self::competitorName($competitors[1]);
            }
            if($actualA===''||$actualB===''){
                $fixtureSides=self::matchSides((string)($fixture['name']??''));
                if($fixtureSides!==null){
                    [$actualA,$actualB]=$fixtureSides;
                }
            }
            if($actualA===''||$actualB==='')continue;

            $direct=(self::teamSimilarity($wantedA,$actualA)+self::teamSimilarity($wantedB,$actualB))/2;
            $reverse=(self::teamSimilarity($wantedA,$actualB)+self::teamSimilarity($wantedB,$actualA))/2;
            $score=max($direct,$reverse);

            $tournamentValue=$fixture['tournament']??'';
            $actualLeague=is_string($tournamentValue)?trim($tournamentValue):'';
            if($league!=='' && $actualLeague!==''){
                $score=($score*0.94)+(self::similarity($league,$actualLeague)*0.06);
            }

            $wantedDate=self::dateKey($date);
            $actualDate=self::dateKey((string)($fixture['date']??$fixture['startTime']??''));
            if($wantedDate!==null && $actualDate!==null){
                $score+=($wantedDate===$actualDate)?0.035:-0.08;
            }

            $candidates[]=['score'=>$score,'fixture'=>$fixture];
        }

        if($candidates===[])return null;
        usort($candidates,static fn(array $a,array $b): int=>$b['score']<=>$a['score']);
        $best=$candidates[0];
        $second=(float)($candidates[1]['score']??0.0);

        if((float)$best['score']<0.78)return null;
        if((float)$best['score']<0.94 && ((float)$best['score']-$second)<0.035)return null;

        return $best['fixture'];
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    private static function fixtureRows(array $payload): array
    {
        foreach(['fixture','fixtures'] as $key){
            $rows=$payload[$key]??null;
            if(is_array($rows) && array_is_list($rows)){
                return array_values(array_filter($rows,'is_array'));
            }
        }

        $schedule=$payload['schedule']??null;
        if(is_array($schedule) && array_is_list($schedule)){
            $rows=[];
            foreach($schedule as $bucket){
                if(!is_array($bucket))continue;
                $bucketDate=$bucket['date']??null;
                foreach((array)($bucket['fixture']??$bucket['fixtures']??[]) as $fixture){
                    if(!is_array($fixture))continue;
                    if(!isset($fixture['date']) && $bucketDate!==null)$fixture['date']=$bucketDate;
                    $rows[]=$fixture;
                }
            }
            if($rows!==[])return $rows;
        }

        return array_is_list($payload)
            ?array_values(array_filter($payload,'is_array'))
            :[];
    }

    private static function competitorName(mixed $value): string
    {
        if(is_string($value))return trim($value);
        if(is_array($value))return trim((string)($value['name']??''));
        return '';
    }

    /** @param array<string,mixed> $payload */
    private static function selectOdd(array $payload,string $market,string $selection): ?string
    {
        $fixture=is_array($payload['fixture']??null)?$payload['fixture']:$payload;
        $wantedMarket=self::canonical($market);
        $wantedSelection=self::canonicalSelection($selection);
        if($wantedMarket===''||$wantedSelection==='')return null;

        $wantedLine=self::marketNeedsLine($market,$selection)
            ?self::extractLine($selection.' '.$market)
            :null;

        $matches=[];
        foreach(self::marketRows($fixture) as $row){
            if(!$row['active'])continue;

            $marketScore=self::marketScore($wantedMarket,self::canonical($row['market']));
            if($marketScore<0.62)continue;

            if($wantedLine!==null){
                $candidateLine=self::extractLine(
                    $row['specifier'].' '.$row['market'].' '.$row['selection']
                );
                if($candidateLine===null || abs($candidateLine-$wantedLine)>0.0001)continue;
            }

            $selectionScore=self::selectionScore(
                $wantedSelection,
                self::canonicalSelection($row['selection']),
                $selection,
                $row['selection']
            );
            if($selectionScore<0.70)continue;

            $score=($marketScore*0.48)+($selectionScore*0.52);
            if($wantedLine!==null)$score+=0.06;
            $matches[]=['score'=>$score,'odd'=>$row['odd']];
        }

        if($matches===[])return null;
        usort($matches,static fn(array $a,array $b): int=>$b['score']<=>$a['score']);
        $best=$matches[0];
        $second=(float)($matches[1]['score']??0.0);

        if((float)$best['score']<0.77)return null;
        if((float)$best['score']<0.96 && ((float)$best['score']-$second)<0.025)return null;

        $odd=self::normalizeOdd((string)$best['odd']);
        return $odd!==''?$odd:null;
    }

    /** @param array<string,mixed> $fixture
     *  @return list<array{market:string,selection:string,odd:string,specifier:string,active:bool}>
     */
    private static function marketRows(array $fixture): array
    {
        $rows=[];
        foreach((array)($fixture['groups']??[]) as $group){
            if(!is_array($group))continue;
            $groupName=trim((string)($group['name']??''));

            foreach((array)($group['markets']??[]) as $market){
                if(!is_array($market))continue;
                $marketName=trim($groupName.' '.(string)($market['name']??''));
                $status=mb_strtolower(trim((string)($market['status']??'')),'UTF-8');
                $marketActive=!in_array($status,['inactive','suspended','closed','settled'],true);
                $specifier=trim((string)($market['specifiers']??$market['extendedSpecifiers']??''));

                foreach((array)($market['outcomes']??[]) as $outcome){
                    if(!is_array($outcome)||!is_numeric($outcome['odds']??null))continue;
                    $active=$marketActive && (!array_key_exists('active',$outcome)||(bool)$outcome['active']);
                    $rows[]=[
                        'market'=>$marketName,
                        'selection'=>trim((string)($outcome['name']??'')),
                        'odd'=>(string)$outcome['odds'],
                        'specifier'=>$specifier,
                        'active'=>$active,
                    ];
                }
            }
        }

        foreach((array)($fixture['swishMarkets']??[]) as $swish){
            if(!is_array($swish))continue;
            foreach(['matchMarkets','matchProps','teamProps','playerProps'] as $bucket){
                foreach((array)($swish[$bucket]??[]) as $market){
                    if(!is_array($market))continue;
                    $marketName=trim(implode(' ',array_filter([
                        (string)($market['marketName']??''),
                        (string)($market['teamName']??''),
                        (string)($market['competitorName']??''),
                        (string)($market['playerName']??''),
                    ])));

                    foreach((array)($market['outcomes']??[]) as $outcome){
                        if(!is_array($outcome))continue;
                        $line=$outcome['line']??null;

                        foreach(['over'=>'Over','under'=>'Under'] as $key=>$label){
                            if(!is_numeric($outcome[$key]??null))continue;
                            $rows[]=[
                                'market'=>$marketName,
                                'selection'=>$label.($line!==null?' '.$line:''),
                                'odd'=>(string)$outcome[$key],
                                'specifier'=>$line!==null?'line='.$line:'',
                                'active'=>true,
                            ];
                        }
                    }
                }
            }
        }
        return $rows;
    }

    private static function marketScore(string $a,string $b): float
    {
        if($a===$b)return 1.0;
        $score=self::similarity($a,$b);
        foreach([
            ['corner','corners'],['card','cards'],['goal','goals'],
            ['asianhandicap','handicap'],['doublechance'],
            ['bothteamstoscore','btts'],['matchwinner','matchresult','moneyline'],
            ['total','overunder'],
        ] as $family){
            $left=false;$right=false;
            foreach($family as $term){
                if(str_contains($a,$term))$left=true;
                if(str_contains($b,$term))$right=true;
            }
            if($left&&$right)$score=max($score,0.86);
        }
        return $score;
    }

    private static function selectionScore(string $a,string $b,string $rawA,string $rawB): float
    {
        if($a===$b)return 1.0;

        $directionA=self::direction($rawA);
        $directionB=self::direction($rawB);
        if($directionA!==null && $directionB!==null && $directionA!==$directionB)return 0.0;

        $score=self::similarity($a,$b);
        if($directionA!==null && $directionA===$directionB)$score=max($score,0.92);

        foreach([['yes','sim'],['no','nao'],['draw','empate']] as $family){
            $left=false;$right=false;
            foreach($family as $term){
                if(str_contains($a,$term))$left=true;
                if(str_contains($b,$term))$right=true;
            }
            if($left&&$right)$score=max($score,0.92);
        }
        return $score;
    }

    private static function marketNeedsLine(string $market,string $selection): bool
    {
        $value=self::canonical($market.' '.$selection);
        return self::direction($selection)!==null
            ||str_contains($value,'handicap')
            ||str_contains($value,'corner')
            ||str_contains($value,'card')
            ||str_contains($value,'total');
    }

    private static function direction(string $value): ?string
    {
        $value=self::canonical($value);
        if(str_contains($value,'over'))return 'over';
        if(str_contains($value,'under'))return 'under';
        return null;
    }

    private static function extractLine(string $value): ?float
    {
        $value=str_replace(',','.',$value);
        if(preg_match('/(?:total|line|hcp|handicap)\s*[=:]?\s*([+-]?\d+(?:\.\d+)?)/iu',$value,$m))return (float)$m[1];
        if(preg_match('/(?:over|under|mais\s+de|menos\s+de|acima\s+de|abaixo\s+de)\s*([+-]?\d+(?:\.\d+)?)/iu',$value,$m))return (float)$m[1];
        if(preg_match('/(?:^|\s)([+-]\d+(?:\.\d+)?)(?:\s|$)/u',$value,$m))return (float)$m[1];
        return null;
    }

    /** @return ?array{0:string,1:string} */
    private static function matchSides(string $match): ?array
    {
        $parts=preg_split('/\s+(?:x|×|vs\.?|v\.?|versus|–|—|-)\s+/iu',trim($match),2);
        if(!is_array($parts)||count($parts)!==2)return null;
        $a=trim($parts[0]);$b=trim($parts[1]);
        return $a!==''&&$b!==''?[$a,$b]:null;
    }

    private static function canonicalSelection(string $value): string
    {
        $value=self::canonical($value);
        $value=preg_replace('/(?:vitoria|vence|vencedor|winner|win|ganha|ganhar)/u','',$value)??$value;
        return preg_replace('/[^a-z0-9.+-]+/','',$value)??'';
    }

    private static function canonical(string $value): string
    {
        $value=mb_strtolower(trim($value),'UTF-8');
        $value=strtr($value,[
            'mais de'=>'over','acima de'=>'over','menos de'=>'under','abaixo de'=>'under',
            'escanteios'=>'corners','escanteio'=>'corner','cantos'=>'corners',
            'cartões'=>'cards','cartoes'=>'cards','cartão'=>'card','cartao'=>'card',
            'gols'=>'goals','gol'=>'goal','ambas marcam'=>'both teams to score',
            'dupla chance'=>'double chance','handicap asiático'=>'asian handicap',
            'handicap asiatico'=>'asian handicap','resultado da partida'=>'match result',
            'vencedor da partida'=>'match winner',
        ]);
        $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
        if(is_string($ascii)&&$ascii!=='')$value=strtolower($ascii);
        $value=preg_replace('/\b(?:fc|cf|sc|ac|afc|club|clube)\b/',' ',$value)??$value;
        return preg_replace('/[^a-z0-9.+-]+/','',trim($value))??'';
    }

    private static function similarity(string $a,string $b): float
    {
        $a=self::countryAlias(self::canonical($a));
        $b=self::countryAlias(self::canonical($b));
        if($a===''||$b==='')return 0.0;
        if($a===$b)return 1.0;

        if(str_contains($a,$b)||str_contains($b,$a)){
            return max(0.78,min(strlen($a),strlen($b))/max(strlen($a),strlen($b)));
        }

        similar_text($a,$b,$percent);
        return max(0.0,min(1.0,$percent/100));
    }

    private static function teamSimilarity(string $a,string $b): float
    {
        $a=self::teamComparable($a);
        $b=self::teamComparable($b);
        if($a===''||$b==='')return 0.0;
        if($a===$b)return 1.0;

        if(str_contains($a,$b)||str_contains($b,$a)){
            return max(0.82,min(strlen($a),strlen($b))/max(strlen($a),strlen($b)));
        }

        similar_text($a,$b,$percent);
        return max(0.0,min(1.0,$percent/100));
    }

    /**
     * Team names from Telegram may be translated (Feminino/Feminina) while
     * Stake commonly exposes Women/W or a longer official club name. Remove
     * only presentation-level club/gender markers; keep youth/reserve markers
     * intact so U19/U21/B teams cannot collapse into the senior side.
     */
    private static function teamComparable(string $value): string
    {
        $value=mb_strtolower(trim($value),'UTF-8');
        $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
        if(is_string($ascii)&&$ascii!=='')$value=strtolower($ascii);

        $value=preg_replace(
            '/\\b(?:fc|cf|sc|ac|afc|club|clube|women|woman|womens|ladies|feminino|feminina|feminin|femenino|femenina|fem)\\b/u',
            ' ',
            $value
        )??$value;
        // Parenthesized/suffixed W is a common women's-team marker.
        $value=preg_replace('/(?:\\(\\s*w\\s*\\)|\\b w\\b)$/u',' ',$value)??$value;
        $value=preg_replace('/[^a-z0-9]+/','',trim($value))??'';

        return self::countryAlias($value);
    }

    private static function countryAlias(string $value): string
    {
        return [
            'turquia'=>'turkey','turkiye'=>'turkey','italia'=>'italy','alemanha'=>'germany',
            'espanha'=>'spain','espana'=>'spain','franca'=>'france','inglaterra'=>'england',
            'holanda'=>'netherlands','paisesbaixos'=>'netherlands','belgica'=>'belgium',
            'suica'=>'switzerland','croacia'=>'croatia','grecia'=>'greece','polonia'=>'poland',
            'romenia'=>'romania','servia'=>'serbia','ucrania'=>'ukraine','tchequia'=>'czechia',
            'republicatcheca'=>'czechia','brasil'=>'brazil','uruguai'=>'uruguay',
            'paraguai'=>'paraguay','equador'=>'ecuador','japao'=>'japan',
            'coreiadosul'=>'southkorea','estadosunidos'=>'usa','eua'=>'usa',
            'arabiasaudita'=>'saudiarabia',
        ][$value]??$value;
    }

    private static function isFootball(string $sport,string $league): bool
    {
        $value=self::canonical($sport.' '.$league);
        return str_contains($value,'futebol')||str_contains($value,'football')||str_contains($value,'soccer')
            ||preg_match('/laliga|premierleague|bundesliga|seriea|championsleague|europaleague|libertadores|copadobrasil/',$value)===1;
    }

    private static function dateKey(string $value): ?string
    {
        $value=trim($value);
        if($value==='')return null;

        if(preg_match('/^\d{13}$/D',$value)){
            return gmdate('Y-m-d',(int)floor(((int)$value)/1000));
        }
        if(preg_match('/^\d{10}$/D',$value)){
            return gmdate('Y-m-d',(int)$value);
        }
        if(preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/',$value,$m)){
            return $m[1].'-'.$m[2].'-'.$m[3];
        }
        if(preg_match('/\b(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})\b/',$value,$m)){
            $year=(int)$m[3];if($year<100)$year+=2000;
            return sprintf('%04d-%02d-%02d',$year,(int)$m[2],(int)$m[1]);
        }
        return null;
    }

    private static function normalizeOdd(string $odd): string
    {
        $odd=trim(str_replace(',','.',$odd));
        if(!preg_match('/^\d{1,5}(?:\.\d{1,4})?$/D',$odd))return '';
        $value=(float)$odd;
        if($value<=1.0)return '';
        return rtrim(rtrim(number_format($value,4,'.',''),'0'),'.');
    }

    private static function log(string $status,bool $changed,string $error='',array $context=[]): void
    {
        $payload=['status'=>$status,'changed'=>$changed];
        foreach(['scope','kind','leg','legs'] as $key){
            if(array_key_exists($key,$context))$payload[$key]=$context[$key];
        }
        if($error!=='')$payload['error']=$error;
        error_log('TMR_STAKE_ODDS '.json_encode($payload,JSON_UNESCAPED_SLASHES));
    }
}
