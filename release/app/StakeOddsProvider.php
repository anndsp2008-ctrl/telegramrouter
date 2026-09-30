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
                $fixture=self::discoverFixtureByCategory(
                    $sportSlug,
                    (string)($bet['match']??''),
                    (string)($bet['league']??''),
                    (string)($bet['date']??$bet['day']??'')
                );
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

            if($stakeOdd===null && self::marketRowsFromPayload($detail)===[]){
                // Stake also documents a dedicated /odds/{fixture} endpoint.
                // Some providers/fixtures may expose the market payload there
                // even when /fixtures/{slug} contains only fixture metadata.
                $oddsDetail=self::cached(
                    'odds:'.$slug,
                    self::DETAIL_CACHE_TTL,
                    static fn(): array=>self::request('/odds/'.rawurlencode($slug))
                );
                $stakeOdd=self::selectOdd(
                    $oddsDetail,
                    (string)($bet['market']??''),
                    (string)($bet['selection']??'')
                );
                if($stakeOdd!==null){
                    error_log('TMR_STAKE_ODDS_SOURCE odds_endpoint');
                }
            }
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

    /** @return ?array<string,mixed> */
    private static function discoverFixtureByCategory(
        string $sportSlug,
        string $match,
        string $league,
        string $date
    ): ?array {
        $payload=self::cached(
            'categories:'.$sportSlug,
            300,
            static fn(): array=>self::request('/sports/'.rawurlencode($sportSlug).'/categories')
        );

        $categories=is_array($payload['categories']??null)?$payload['categories']:[];
        if($categories===[])return null;

        $ordered=self::rankStakeCategories($categories,$league);
        $categoryAttempts=0;
        $tournamentFixtureAttempts=0;

        foreach($ordered as $category){
            if($categoryAttempts>=6)break;
            if(!is_array($category))continue;

            $slug=trim((string)($category['slug']??''));
            if($slug==='' || !preg_match('/^[A-Za-z0-9._:-]{1,160}$/D',$slug))continue;
            $categoryAttempts++;

            try{
                $fixtures=self::cached(
                    'category-fixtures:'.$sportSlug.':'.$slug,
                    self::FIXTURE_CACHE_TTL,
                    static fn(): array=>self::request(
                        '/sport/'.rawurlencode($sportSlug)
                        .'/category/'.rawurlencode($slug)
                        .'/fixture'
                    )
                );
                $fixture=self::selectFixture($fixtures,$match,$league,$date);
                if($fixture!==null){
                    error_log('TMR_STAKE_FIXTURE_MATCH category_fallback '.json_encode([
                        'category'=>mb_substr((string)($category['name']??$slug),0,80),
                        'attempts'=>$categoryAttempts,
                    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                    return $fixture;
                }
            }catch(\Throwable $e){
                error_log('TMR_STAKE_CATEGORY_LOOKUP '.json_encode([
                    'category'=>mb_substr((string)($category['name']??$slug),0,80),
                    'status'=>'fixture_feed_failed',
                    'error'=>get_class($e),
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            }

            // Some competitions (notably international club / women's events)
            // are not present in the broad category fixture feed. Follow the
            // documented category -> tournament -> fixtures hierarchy before
            // declaring fixture_not_found.
            try{
                $tournamentPayload=self::cached(
                    'tournaments:'.$sportSlug.':'.$slug,
                    300,
                    static fn(): array=>self::request(
                        '/sports/'.rawurlencode($sportSlug)
                        .'/'.rawurlencode($slug)
                        .'/tournaments'
                    )
                );
                $tournaments=is_array($tournamentPayload['tournaments']??null)
                    ?$tournamentPayload['tournaments']:[];
                foreach(self::rankStakeTournaments($tournaments,$league,$match) as $tournament){
                    if($tournamentFixtureAttempts>=10)break 2;
                    if(!is_array($tournament))continue;

                    $tournamentSlug=trim((string)($tournament['slug']??''));
                    if($tournamentSlug==='' || !preg_match('/^[A-Za-z0-9._:-]{1,180}$/D',$tournamentSlug))continue;
                    $tournamentFixtureAttempts++;

                    try{
                        $tournamentFixtures=self::cached(
                            'tournament-fixtures:'.$sportSlug.':'.$slug.':'.$tournamentSlug,
                            self::FIXTURE_CACHE_TTL,
                            static fn(): array=>self::request(
                                '/sports/'.rawurlencode($sportSlug)
                                .'/'.rawurlencode($slug)
                                .'/'.rawurlencode($tournamentSlug)
                                .'/fixtures'
                            )
                        );
                        $tournamentFixtures=self::withTournamentContext(
                            $tournamentFixtures,
                            (string)($tournament['name']??$tournamentSlug)
                        );
                        $fixture=self::selectFixture($tournamentFixtures,$match,$league,$date);
                        if($fixture!==null){
                            error_log('TMR_STAKE_FIXTURE_MATCH tournament_fallback '.json_encode([
                                'category'=>mb_substr((string)($category['name']??$slug),0,80),
                                'tournament'=>mb_substr((string)($tournament['name']??$tournamentSlug),0,100),
                                'attempts'=>$tournamentFixtureAttempts,
                            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                            return $fixture;
                        }
                    }catch(\Throwable $e){
                        error_log('TMR_STAKE_TOURNAMENT_LOOKUP '.json_encode([
                            'tournament'=>mb_substr((string)($tournament['name']??$tournamentSlug),0,100),
                            'status'=>'fixture_feed_failed',
                            'error'=>get_class($e),
                        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                    }
                }
            }catch(\Throwable $e){
                error_log('TMR_STAKE_CATEGORY_LOOKUP '.json_encode([
                    'category'=>mb_substr((string)($category['name']??$slug),0,80),
                    'status'=>'tournaments_failed',
                    'error'=>get_class($e),
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            }
        }

        return null;
    }

    /** @param list<mixed> $categories @return list<array<string,mixed>> */
    private static function rankStakeCategories(array $categories,string $league): array
    {
        $target=self::stakeCategoryHint($league);
        $scored=[];

        foreach($categories as $idx=>$category){
            if(!is_array($category))continue;
            $name=trim((string)($category['name']??''));
            $slug=trim((string)($category['slug']??''));
            $label=trim($name.' '.$slug);
            $canonical=self::canonical($label);

            $score=0.0;
            if($target!=='' && str_contains($canonical,$target))$score=1.0;
            elseif($league!=='' && $label!=='')$score=self::similarity($league,$label);

            if(str_contains($canonical,'international'))$score=max($score,0.70);
            $score-=((int)$idx)*0.00001;

            $scored[]=['score'=>$score,'category'=>$category];
        }

        usort($scored,static fn(array $a,array $b): int=>$b['score']<=>$a['score']);
        return array_values(array_map(
            static fn(array $row): array=>$row['category'],
            $scored
        ));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private static function withTournamentContext(array $payload,string $tournamentName): array
    {
        foreach(['fixture','fixtures'] as $key){
            if(!is_array($payload[$key]??null) || !array_is_list($payload[$key]))continue;
            foreach($payload[$key] as $i=>$fixture){
                if(!is_array($fixture))continue;
                $fixture['_stake_tournament_context']=$tournamentName;
                $payload[$key][$i]=$fixture;
            }
        }

        if(array_is_list($payload)){
            foreach($payload as $i=>$fixture){
                if(!is_array($fixture))continue;
                $fixture['_stake_tournament_context']=$tournamentName;
                $payload[$i]=$fixture;
            }
        }
        return $payload;
    }

    /** @param list<mixed> $tournaments @return list<array<string,mixed>> */
    private static function rankStakeTournaments(array $tournaments,string $league,string $match): array
    {
        $hint=self::stakeTournamentHint($league,$match);
        $expectedVariant=self::eventVariant($match,$league);
        $scored=[];

        foreach($tournaments as $idx=>$tournament){
            if(!is_array($tournament))continue;
            $name=trim((string)($tournament['name']??''));
            $slug=trim((string)($tournament['slug']??''));
            if($name==='' && $slug==='')continue;

            $label=trim($name.' '.$slug);
            $canonical=self::canonical($label);
            $score=$league!==''?self::similarity($league,$label):0.0;

            if($hint!=='' && str_contains($canonical,$hint))$score=max($score,1.0);
            elseif($hint!=='' && self::similarity($hint,$canonical)>=0.72)$score=max($score,0.92);

            $labelVariant=self::eventVariant($label,'');
            if($expectedVariant==='women'){
                if($labelVariant==='women')$score+=0.20;
                elseif($labelVariant!==null)$score-=0.30;
            }elseif($expectedVariant!==null && $labelVariant!==null && $expectedVariant!==$labelVariant){
                $score-=0.30;
            }

            $score-=((int)$idx)*0.00001;
            $scored[]=['score'=>$score,'tournament'=>$tournament];
        }

        usort($scored,static fn(array $a,array $b): int=>$b['score']<=>$a['score']);
        return array_values(array_map(
            static fn(array $row): array=>$row['tournament'],
            array_slice($scored,0,8)
        ));
    }

    private static function stakeTournamentHint(string $league,string $match=''): string
    {
        $v=self::canonical($league.' '.$match);
        $women=self::eventVariant($match,$league)==='women';

        if(str_contains($v,'championsleague')
            || str_contains($v,'ligadoscampeoes')
            || str_contains($v,'ligadecampeoes')){
            return $women?'womenschampionsleague':'championsleague';
        }
        if(str_contains($v,'europaleague') || str_contains($v,'ligaeuropa'))return 'europaleague';
        if(str_contains($v,'conferenceleague'))return 'conferenceleague';
        if(str_contains($v,'libertadores'))return 'libertadores';
        if(str_contains($v,'sudamericana'))return 'sudamericana';
        if(str_contains($v,'premierleague'))return $women?'womenssuperleague':'premierleague';
        if(str_contains($v,'bundesliga'))return $women?'womenbundesliga':'bundesliga';
        if(str_contains($v,'primeiraliga') || str_contains($v,'ligaportugal'))return 'primeiraliga';
        return '';
    }

    private static function stakeCategoryHint(string $league): string
    {
        $v=self::canonical($league);
        if($v==='')return 'international';

        foreach([
            'championsleague'=>'international',
            'uefachampionsleague'=>'international',
            'europaleague'=>'international',
            'conferenceleague'=>'international',
            'worldcup'=>'international',
            'mundial'=>'international',
            'libertadores'=>'international',
            'sudamericana'=>'international',
            'premierleague'=>'england',
            'championship'=>'england',
            'laliga'=>'spain',
            'bundesliga'=>'germany',
            'ligue1'=>'france',
            'primeiraliga'=>'portugal',
            'ligaportugal'=>'portugal',
            'eredivisie'=>'netherlands',
            'brasileirao'=>'brazil',
            'copadobrasil'=>'brazil',
            'mls'=>'usa',
            'majorleaguesoccer'=>'usa',
        ] as $needle=>$hint){
            if(str_contains($v,$needle))return $hint;
        }

        return '';
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
        $wantedVariant=self::eventVariant($match,$league);

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

            $tournamentValue=$fixture['tournament']??'';
            $actualLeague=is_string($tournamentValue)?trim($tournamentValue):'';
            $stakeTournamentContext=trim((string)($fixture['_stake_tournament_context']??''));
            $actualVariant=self::eventVariant(
                (string)($fixture['name']??''),
                trim($actualLeague.' '.$stakeTournamentContext)
            );

            if($wantedVariant!==null && $actualVariant!==null && $wantedVariant!==$actualVariant){
                continue;
            }

            $directA=self::teamSimilarity($wantedA,$actualA,$wantedVariant,$actualVariant);
            $directB=self::teamSimilarity($wantedB,$actualB,$wantedVariant,$actualVariant);
            $reverseA=self::teamSimilarity($wantedA,$actualB,$wantedVariant,$actualVariant);
            $reverseB=self::teamSimilarity($wantedB,$actualA,$wantedVariant,$actualVariant);
            $direct=($directA+$directB)/2;
            $reverse=($reverseA+$reverseB)/2;

            if($direct>=$reverse){
                $score=$direct;
                $sideMin=min($directA,$directB);
            }else{
                $score=$reverse;
                $sideMin=min($reverseA,$reverseB);
            }

            if($league!=='' && $actualLeague!==''){
                // League is only a weak tie-breaker because source text can be
                // translated while Stake normally exposes the official name.
                $score=($score*0.97)+(self::similarity($league,$actualLeague)*0.03);
            }

            $wantedDate=self::dateKey($date);
            $actualDate=self::dateKey((string)($fixture['date']??$fixture['startTime']??''));
            $dateDistance=null;
            if($wantedDate!==null && $actualDate!==null){
                $dateDistance=self::dateDistanceDays($wantedDate,$actualDate);
                if($dateDistance===0)$score+=0.03;
                elseif($dateDistance===1)$score-=0.005; // timezone/local-date tolerance
                else $score-=0.10;
            }

            $candidates[]=[
                'score'=>$score,
                'side_min'=>$sideMin,
                'date_distance'=>$dateDistance,
                'fixture'=>$fixture
            ];
        }

        if($candidates===[])return null;
        usort($candidates,static fn(array $a,array $b): int=>$b['score']<=>$a['score']);
        $best=$candidates[0];
        $second=(float)($candidates[1]['score']??0.0);
        $bestScore=(float)$best['score'];
        $sideMin=(float)($best['side_min']??0.0);
        $dateDistance=$best['date_distance']??null;

        $strongPair=$sideMin>=0.88 && ($dateDistance===null || (int)$dateDistance<=1);
        $accepted=$bestScore>=0.78
            && ($bestScore>=0.94 || ($bestScore-$second)>=0.035 || $strongPair);

        if(!$accepted){
            self::logFixtureCandidates($match,$candidates);
            return null;
        }

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
        $fixture=self::fixtureMarketObject($payload);
        if($fixture===[])return null;
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

        if($matches===[]){
            self::logMarketCandidates($fixture,$market,$selection,$wantedLine);
            return null;
        }
        usort($matches,static fn(array $a,array $b): int=>$b['score']<=>$a['score']);
        $best=$matches[0];
        $second=(float)($matches[1]['score']??0.0);

        if((float)$best['score']<0.77){
            self::logMarketCandidates($fixture,$market,$selection,$wantedLine);
            return null;
        }
        if((float)$best['score']<0.96 && ((float)$best['score']-$second)<0.025){
            self::logMarketCandidates($fixture,$market,$selection,$wantedLine);
            return null;
        }

        $odd=self::normalizeOdd((string)$best['odd']);
        return $odd!==''?$odd:null;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private static function fixtureMarketObject(array $payload): array
    {
        if(isset($payload['groups']) || isset($payload['swishMarkets']))return $payload;

        foreach(['fixture','data','odds','result'] as $key){
            $value=$payload[$key]??null;
            if(!is_array($value))continue;

            if(isset($value['groups']) || isset($value['swishMarkets']))return $value;
            if(array_is_list($value)){
                foreach($value as $row){
                    if(is_array($row) && (isset($row['groups']) || isset($row['swishMarkets']))){
                        return $row;
                    }
                }
            }

            $nested=self::fixtureMarketObject($value);
            if($nested!==[])return $nested;
        }

        if(array_is_list($payload)){
            foreach($payload as $row){
                if(!is_array($row))continue;
                if(isset($row['groups']) || isset($row['swishMarkets']))return $row;
                $nested=self::fixtureMarketObject($row);
                if($nested!==[])return $nested;
            }
        }
        return [];
    }

    /** @param array<string,mixed> $payload
     *  @return list<array{market:string,selection:string,odd:string,specifier:string,active:bool}>
     */
    private static function marketRowsFromPayload(array $payload): array
    {
        $fixture=self::fixtureMarketObject($payload);
        return $fixture===[]?[]:self::marketRows($fixture);
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
        $aWinner=str_contains($a,'matchwinner')||str_contains($a,'matchresult')||str_contains($a,'moneyline');
        $bWinner=str_contains($b,'matchwinner')||str_contains($b,'matchresult')||str_contains($b,'moneyline');
        $aBtts=str_contains($a,'bothteamstoscore')||str_contains($a,'btts');
        $bBtts=str_contains($b,'bothteamstoscore')||str_contains($b,'btts');
        if($aWinner&&$bWinner&&$aBtts&&$bBtts)$score=max($score,0.98);

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

        if($directionA===null && $directionB===null
            && preg_match('/[A-Za-zÀ-ÿ]{3}/u',$rawA)
            && preg_match('/[A-Za-zÀ-ÿ]{3}/u',$rawB)){
            $teamA=self::teamComparable(self::withoutBetLine($rawA));
            $teamB=self::teamComparable(self::withoutBetLine($rawB));
            if($teamA!=='' && $teamB!==''){
                if($teamA===$teamB)$score=max($score,0.98);
                elseif(str_contains($teamA,$teamB)||str_contains($teamB,$teamA))$score=max($score,0.94);
                else{
                    similar_text($teamA,$teamB,$teamPercent);
                    $score=max($score,min(0.93,$teamPercent/100));
                }
            }
        }
        $bttsA=self::bttsState($rawA);
        $bttsB=self::bttsState($rawB);
        if($bttsA!==null && $bttsB!==null && $bttsA===$bttsB){
            $teamA=self::teamComparable(self::stripOutcomeSemantics($rawA));
            $teamB=self::teamComparable(self::stripOutcomeSemantics($rawB));
            if($teamA!=='' && $teamB!==''){
                if($teamA===$teamB)$score=max($score,0.99);
                elseif(str_contains($teamA,$teamB)||str_contains($teamB,$teamA))$score=max($score,0.96);
            }
        }

        return $score;
    }

    private static function bttsState(string $value): ?string
    {
        $v=self::canonical($value);
        if(str_contains($v,'bothteamstoscore')){
            if(str_contains($v,'no')||str_contains($v,'nao'))return 'no';
            return 'yes';
        }
        if(preg_match('/(?:^|[^a-z])(yes|sim)(?:$|[^a-z])/iu',$value))return 'yes';
        if(preg_match('/(?:^|[^a-z])(no|nao|não)(?:$|[^a-z])/iu',$value))return 'no';
        return null;
    }

    private static function stripOutcomeSemantics(string $value): string
    {
        $value=preg_replace('/\b(?:vence|vencedor|winner|win|ganha|ganhar|and|e|yes|sim|no|nao|não)\b/iu',' ',$value)??$value;
        $value=preg_replace('/\b(?:ambas\s+(?:as\s+)?equipes\s+marcam|ambas\s+marcam|both\s+teams\s+to\s+score|btts)\b/iu',' ',$value)??$value;
        return trim($value);
    }

    private static function withoutBetLine(string $value): string
    {
        $value=str_replace(',','.',$value);
        $value=preg_replace('/(?:total|line|hcp|handicap)\s*[=:]?\s*[+-]?\d+(?:\.\d+)?/iu',' ',$value)??$value;
        $value=preg_replace('/(?:over|under|mais\s+de|menos\s+de|acima\s+de|abaixo\s+de)\s*[+-]?\d+(?:\.\d+)?/iu',' ',$value)??$value;
        return preg_replace('/(?:^|\s)[+-]?\d+(?:\.\d+)?(?:\s|$)/u',' ',$value)??$value;
    }

    /** @param array<string,mixed> $fixture */
    private static function logMarketCandidates(array $fixture,string $market,string $selection,?float $wantedLine): void
    {
        $rows=[];
        foreach(self::marketRows($fixture) as $row){
            if(!$row['active'])continue;
            $marketScore=self::marketScore(self::canonical($market),self::canonical($row['market']));
            $selectionScore=self::selectionScore(
                self::canonicalSelection($selection),
                self::canonicalSelection($row['selection']),
                $selection,
                $row['selection']
            );
            $candidateLine=self::extractLine($row['specifier'].' '.$row['market'].' '.$row['selection']);
            $score=($marketScore*0.48)+($selectionScore*0.52);
            if($wantedLine!==null && $candidateLine!==null && abs($candidateLine-$wantedLine)<=0.0001)$score+=0.06;
            $rows[]=[
                'score'=>$score,
                'market'=>mb_substr($row['market'],0,100),
                'selection'=>mb_substr($row['selection'],0,100),
                'line'=>$candidateLine,
                'odd'=>$row['odd'],
            ];
        }
        usort($rows,static fn(array $a,array $b): int=>$b['score']<=>$a['score']);
        error_log('TMR_STAKE_MARKET_DIAG '.json_encode([
            'wanted_market'=>mb_substr($market,0,100),
            'wanted_selection'=>mb_substr($selection,0,100),
            'wanted_line'=>$wantedLine,
            'top'=>array_slice($rows,0,5),
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
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
        $value=mb_strtolower(trim($value),'UTF-8');
        $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
        if(is_string($ascii)&&$ascii!=='')$value=strtolower($ascii);

        $value=str_replace(
            ['bayern de munique','bayern munique','bayern munchen','bayern muenchen'],
            ['bayern munich','bayern munich','bayern munich','bayern munich'],
            $value
        );
        $value=preg_replace(
            '/\b(?:women|woman|womens|wfc|ladies|feminino|feminina|femenino|femenina|female)\b/u',
            ' ',
            $value
        )??$value;

        $value=self::canonical($value);
        $value=preg_replace('/(?:vitoria|vence|vencedor|winner|win|ganha|ganhar)/u','',$value)??$value;
        return preg_replace('/[^a-z0-9.+-]+/','',$value)??'';
    }

    private static function canonical(string $value): string
    {
        $value=mb_strtolower(trim($value),'UTF-8');
        $value=strtr($value,[
            'vencedor da partida e ambas as equipes marcam'=>'match winner both teams to score',
            'vencedor da partida + ambas as equipes marcam'=>'match winner both teams to score',
            'ambas as equipes marcam'=>'both teams to score',
            'ambas equipes marcam'=>'both teams to score',
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

    private static function teamSimilarity(
        string $a,
        string $b,
        ?string $expectedVariant=null,
        ?string $actualContextVariant=null
    ): float {
        $variantA=$expectedVariant??self::teamVariant($a);
        $variantB=$actualContextVariant??self::teamVariant($b);
        if($variantA!==$variantB)return 0.0;

        $a=self::teamComparable($a);
        $b=self::teamComparable($b);
        if($a===''||$b==='')return 0.0;
        if($a===$b)return 1.0;

        if(str_contains($a,$b)||str_contains($b,$a)){
            // Official names often include a prefix/suffix absent from tips
            // (Lyon vs Olympique Lyonnais, Inter vs Internazionale, etc.).
            return 0.94;
        }

        similar_text($a,$b,$percent);
        $score=max(0.0,min(1.0,$percent/100));

        // Acronym compatibility for well-formed multi-word official names.
        $rawA=self::teamWords($a);
        $rawB=self::teamWords($b);
        if($rawA!==[] && $rawB!==[]){
            $short=count($rawA)<=count($rawB)?$rawA:$rawB;
            $long=count($rawA)<=count($rawB)?$rawB:$rawA;
            if(count($short)===1 && strlen($short[0])>=3){
                foreach($long as $word){
                    if(str_starts_with($word,$short[0]) || str_starts_with($short[0],$word)){
                        $score=max($score,0.90);
                    }
                }
            }
        }
        return $score;
    }

    private static function eventVariant(string $match,string $league): ?string
    {
        $v=mb_strtolower(trim($match.' '.$league),'UTF-8');
        $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);
        if(is_string($ascii)&&$ascii!=='')$v=strtolower($ascii);

        if(preg_match('/\b(?:women|woman|womens|ladies|feminino|feminina|feminin|femenino|femenina|female|wfc)\b|\(\s*w\s*\)|\b w$/u',$v)){
            return 'women';
        }
        if(preg_match('/\b(?:u|sub)[ -]?(1[5-9]|2[0-3])\b/u',$v,$m)){
            return 'u'.$m[1];
        }
        if(preg_match('/\b(?:reserve|reserves|reservas?|b team|team b|ii)\b/u',$v)){
            return 'reserve';
        }
        return null;
    }

    private static function teamVariant(string $value): string
    {
        $v=mb_strtolower($value,'UTF-8');
        $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);
        if(is_string($ascii)&&$ascii!=='')$v=strtolower($ascii);

        if(preg_match('/\\b(?:women|woman|womens|ladies|feminino|feminina|feminin|femenino|femenina|fem)\\b|\\(\\s*w\\s*\\)|\\b w$/u',$v))return 'women';
        if(preg_match('/\\b(?:u|sub)[ -]?(1[5-9]|2[0-3])\\b/u',$v,$m))return 'u'.$m[1];
        if(preg_match('/\\b(?:reserve|reserves|reservas?|b team|team b|ii)\\b/u',$v))return 'reserve';
        return 'senior';
    }

    /** @return list<string> */
    private static function teamWords(string $canonical): array
    {
        $parts=preg_split('/(?=[A-Z])|[^a-z0-9]+/',$canonical);
        if(!is_array($parts))return [];
        return array_values(array_filter(array_map('strtolower',$parts),static fn(string $v): bool=>$v!==''));
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

        $value=str_replace(
            ['munique','munchen','muenchen'],
            ['munich','munich','munich'],
            $value
        );

        $value=preg_replace(
            '/\\b(?:fc|cf|sc|ac|afc|club|clube|de|da|do|del|women|woman|womens|ladies|feminino|feminina|feminin|femenino|femenina|fem)\\b/u',
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

    private static function dateDistanceDays(string $a,string $b): int
    {
        try{
            $da=new \DateTimeImmutable($a.' 00:00:00',new \DateTimeZone('UTC'));
            $db=new \DateTimeImmutable($b.' 00:00:00',new \DateTimeZone('UTC'));
            return (int)abs((int)$da->diff($db)->format('%r%a'));
        }catch(\Throwable){
            return 99;
        }
    }

    /** @param list<array<string,mixed>> $candidates */
    private static function logFixtureCandidates(string $match,array $candidates): void
    {
        $top=[];
        foreach(array_slice($candidates,0,3) as $candidate){
            $fixture=is_array($candidate['fixture']??null)?$candidate['fixture']:[];
            $top[]=[
                'name'=>mb_substr((string)($fixture['name']??''),0,120),
                'score'=>round((float)($candidate['score']??0.0),3),
                'side_min'=>round((float)($candidate['side_min']??0.0),3),
                'date_distance'=>$candidate['date_distance']??null,
            ];
        }
        error_log('TMR_STAKE_FIXTURE_DIAG '.json_encode([
            'match'=>mb_substr($match,0,120),
            'top'=>$top,
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
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
