<?php declare(strict_types=1);
namespace App {
    final class AiLearningMemory { public static function contextFor(string $text,int $id): string {return '';} }
    final class TranslationService { public static function resolveProviders(string $provider): array {return ['openai',null];} }
    final class Repository {
        public static function recordTranslationAttempt(array $data): void {}
    }
    final class OpenAIProvider {
        public static array $data=[];
        public static function enabled(): bool {return true;}
        public static function structured(string $prompt,?string $image): array {
            if(!str_contains($prompt,'double_legs')||!str_contains($prompt,'NUNCA handicap'))throw new \RuntimeException('Double extraction instructions missing');
            return ['ok'=>true,'data'=>self::$data];
        }
    }
}
namespace {
    require __DIR__.'/../app/SmartFormatting.php';
    require __DIR__.'/../app/VipCardRenderer.php';
    require __DIR__.'/../app/PurePngVipCardRenderer.php';
    use App\DoubleVipCardRenderer as DoubleCard;
    $check=static function(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);};
    $legs=[
        ['match'=>'Dinamarca × País de Gales','market'=>'Total de escanteios','selection'=>'Mais de 7,5 escanteios','odd'=>'1.35','market_evidence'=>'Más/Menos Córners','selection_evidence'=>'Más de 7.5','promotion'=>''],
        ['match'=>'Alemanha × Grécia','market'=>'Resultado da partida','selection'=>'Alemanha vence','odd'=>'1.45','market_evidence'=>'Resultado del partido','selection_evidence'=>'Alemania +2','promotion'=>'betano_early_payout_2']
    ];
    $data=['sport'=>'Futebol','visual_bet_kind'=>'multiple','visual_selections_count'=>'2','visual_multiple_evidence'=>'Doble','visual_multiple_details'=>"1. Dinamarca × País de Gales: Mais de 7,5 escanteios\n2. Alemanha × Grécia: Alemanha vence",'double_legs'=>json_encode($legs,JSON_UNESCAPED_UNICODE),'odd'=>'1.95','analysis'=>'Invented analysis must not appear'];
    $bet=DoubleCard::extract($data,true);
    $check($bet!==null,'Betano double rejected');
    $check($bet['odd']==='1.95','Displayed total must be preserved');
    $check(App\SmartFormatting::isMultipleTicket($data,'',true),'Doble header not recognized');
    foreach(['Dupla chance','Double chance','Doble oportunidad'] as $source)$check(!DoubleCard::sourceIsDouble($source),'Single double-chance misclassified');
    foreach(['Dupla','Doble 1.95','Double','2 seleções'] as $source)$check(DoubleCard::sourceIsDouble($source),'Double header missing');
    foreach(['1','3'] as $count){$bad=$data;$bad['visual_selections_count']=$count;$check(DoubleCard::extract($bad,true)===null,'Wrong leg count accepted');}
    $bad=$data;$bad['double_legs']='{broken';$check(DoubleCard::extract($bad,true)===null,'Malformed JSON accepted');
    $badLegs=$legs;$badLegs[1]['selection']='Alemanha +2';$bad=$data;$bad['double_legs']=json_encode($badLegs);$check(DoubleCard::extract($bad,true)===null,'Promo treated as handicap');
    $badLegs=$legs;$badLegs[1]['match']=$legs[0]['match'];$bad['double_legs']=json_encode($badLegs);$check(DoubleCard::extract($bad,true)===null,'Same-game builder rendered as double');
    $badLegs=$legs;$badLegs[1]['selection']='';$bad['double_legs']=json_encode($badLegs);$check(DoubleCard::extract($bad,true)===null,'Incomplete selection accepted');
    $handicap=$legs;$handicap[1]['market']='Handicap asiático';$handicap[1]['selection']='Alemanha +2';$handicap[1]['market_evidence']='Handicap asiático';$handicap[1]['promotion']='';$valid=$data;$valid['double_legs']=json_encode($handicap);$check(DoubleCard::extract($valid,true)!==null,'Real handicap rejected');
    $missing=$data;$missing['odd']='';$missingLegs=$legs;$missingLegs[0]['odd']='';$missing['double_legs']=json_encode($missingLegs);$missingBet=DoubleCard::extract($missing,true);$check($missingBet!==null&&str_contains(DoubleCard::caption($missingBet),'Odd total: —'),'Missing odds invented');
    $path=DoubleCard::render($bet);$check($path!==null,'Double render failed');$size=getimagesize($path);$check($size[0]===1080&&$size[1]<1800,'Unexpected card dimensions');
    if(getenv('DOUBLE_CARD_PREVIEW'))copy($path,getenv('DOUBLE_CARD_PREVIEW'));
    unlink($path);
    $long=$bet;$long['legs'][0]['match']='Clube Atlético de uma cidade com nome muito extenso × Associação Esportiva de outra cidade';$long['legs'][0]['selection']='Mais de 7,5 escanteios no tempo regulamentar da partida';$path=DoubleCard::render($long);$check($path!==null,'Long labels must wrap');unlink($path);
    App\OpenAIProvider::$data=$data;
    $photo=tempnam(sys_get_temp_dir(),'double-fixture-');file_put_contents($photo,'fixture');
    $rule=['id'=>1,'translation_enabled'=>1,'translation_target_language'=>'pt-BR','translation_provider'=>'openai'];
    $prepared=App\SmartFormatting::prepare('',$rule,$photo,'card');
    $check($prepared!==null&&is_file($prepared['image']),'Double not connected to actual card pipeline');
    $check(!str_contains($prepared['caption'],'Invented')&&str_contains($prepared['caption'],'Alemanha vence')&&str_contains($prepared['caption'],'Stake: 10'),'Double caption incorrect');unlink($prepared['image']);
    putenv('VIP_CARD_FORCE_PURE=1');$check(App\SmartFormatting::prepare('',$rule,$photo,'card')===null,'Unavailable renderer must preserve original');$check(App\SmartFormatting::multipleDetails()!=='','Contingency lost original selections');putenv('VIP_CARD_FORCE_PURE');
    App\OpenAIProvider::$data=['sport'=>'Futebol','match'=>'Time A x Time B','market'=>'Dupla chance','selection'=>'Time A ou empate','odd'=>'1.40','analysis'=>'','visual_bet_kind'=>'single','visual_selections_count'=>'1'];
    $single=App\SmartFormatting::prepare('',$rule,$photo,'card');$check($single!==null&&is_file($single['image']),'Single-card pipeline regressed');$check(!str_contains($single['caption'],'2 seleções'),'Single rendered as double');unlink($single['image']);unlink($photo);
    echo "DOUBLE_CARD_SMOKE_PASSED\n";
}
