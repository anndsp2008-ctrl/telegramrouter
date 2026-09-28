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
            && trim((string)getenv('STAKE_ODDS_API_KEY'))!=='';
    }

    /** @param array<string,mixed> $bet @return array<string,mixed> */
    public static function applyToSingle(array $bet): array
    {
        $originalOdd=self::normalizeOdd((string)($bet['odd']??''));
        if($originalOdd==='' || !self::enabled())return $bet;

        if(!self::isFootball((string)($bet['sport']??''),(string)($bet['league']??''))){
            self::log('not_applicable',false);
            return $bet;
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
            if($fixture===null){
                self::log('fixture_not_found',false);
                return $bet;
            }

            $slug=trim((string)($fixture['slug']??''));
            if($slug==='' || !preg_match('/^[A-Za-z0-9._:-]{1,200}$/D',$slug)){
                self::log('fixture_slug_missing',false);
                return $bet;
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
                self::log('market_or_line_not_found',false);
                return $bet;
            }

            $bet['odd']=$stakeOdd;
            self::log('validated',$stakeOdd!==$originalOdd);
            return $bet;
        }catch(\Throwable $e){
            self::log('api_unavailable',false,get_class($e));
            return $bet;
        }
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
        $key=trim((string)getenv('STAKE_ODDS_API_KEY'));
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

        $rows=$payload['fixture']??$payload['fixtures']??$payload;
        if(!is_array($rows) || !array_is_list($rows))return null;

        $candidates=[];
        foreach($rows as $fixture){
            if(!is_array($fixture))continue;
            $competitors=$fixture['competitors']??[];
            if(!is_array($competitors) || count($competitors)<2)continue;

            $actualA=self::competitorName($competitors[0]);
            $actualB=self::competitorName($competitors[1]);
            if($actualA===''||$actualB==='')continue;

            $direct=(self::similarity($wantedA,$actualA)+self::similarity($wantedB,$actualB))/2;
            $reverse=(self::similarity($wantedA,$actualB)+self::similarity($wantedB,$actualA))/2;
            $score=max($direct,$reverse);

            $actualLeague=trim((string)($fixture['tournament']??''));
            if($league!=='' && $actualLeague!==''){
                $score=($score*0.94)+(self::similarity($league,$actualLeague)*0.06);
            }

            $wantedDate=self::dateKey($date);
            $actualDate=self::dateKey((string)($fixture['startTime']??$fixture['date']??''));
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

    private static function log(string $status,bool $changed,string $error=''): void
    {
        $payload=['status'=>$status,'changed'=>$changed];
        if($error!=='')$payload['error']=$error;
        error_log('TMR_STAKE_ODDS '.json_encode($payload,JSON_UNESCAPED_SLASHES));
    }
}
