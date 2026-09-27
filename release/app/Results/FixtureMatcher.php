<?php declare(strict_types=1);
namespace App\Results;
final class FixtureMatcher {
 public function __construct(private readonly ApiFootballClient $api){}
 public function match(?string $home,?string $away):?int{if(!$home||!$away)return null;$date=(new \DateTimeImmutable('now',new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');foreach([-1,0,1] as $d){$day=(new \DateTimeImmutable($date))->modify(($d>=0?'+':'').$d.' day')->format('Y-m-d');$rows=$this->api->fixturesByDate($day);foreach($rows as $f){$h=(string)($f['teams']['home']['name']??'');$a=(string)($f['teams']['away']['name']??'');if(self::sim($home,$h)>=0.72&&self::sim($away,$a)>=0.72)return (int)($f['fixture']['id']??0)?:null;}}return null;}
 private static function sim(string $a,string $b):float{$n=fn($s)=>preg_replace('/[^a-z0-9]+/','',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',mb_strtolower($s,'UTF-8'))?:'')??'';$a=$n($a);$b=$n($b);if($a===''||$b==='')return 0;similar_text($a,$b,$p);return$p/100;}
}
