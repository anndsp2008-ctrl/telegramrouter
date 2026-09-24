<?php declare(strict_types=1);

$transform=__DIR__.'/../app/Transform.php';
if(!is_file($transform))$transform=__DIR__.'/../release/app/Transform.php';
if(!is_file($transform))throw new RuntimeException('Transform dependency unavailable');
require_once $transform;
require_once __DIR__.'/../app/CardLayoutEmojis.php';

use App\CardLayoutEmojis;

$rule=[
    'remove_emojis'=>1,
    'remove_links'=>1,
    'custom_removals'=>'PATROCINADO'
];
$normal="⚽ Time A 🥇 x Time B\n🏆 Série A 🏅\n\n".
    "🎯 Mercado: Ambas marcam 🎲\n✅ Seleção: Sim 🔥\n".
    "📈 Odd: 1.60\n📍 Stake: 10\n\n".
    "📝 Análise original:\n⚽ Análise do autor 📊 PATROCINADO https://exemplo.com\n".
    "🎯 Mercado: mensagem do autor";

$clean=CardLayoutEmojis::clean($normal,$rule);
foreach([
    '⚽ Time A x Time B',
    '🏆 Série A',
    '🎯 Mercado: Ambas marcam',
    '✅ Seleção: Sim',
    '📈 Odd: 1.60',
    '📍 Stake: 10',
    '📝 Análise original:'
] as $part){
    if(!str_contains($clean,$part)){
        throw new RuntimeException('Generated presentation icon was lost: '.$part);
    }
}
foreach(['🥇','🏅','🎲','🔥','📊','PATROCINADO','https://exemplo.com'] as $forbidden){
    if(str_contains($clean,$forbidden))
        throw new RuntimeException('Source content bypassed cleanup: '.$forbidden);
}
$analysis=explode('📝 Análise original:', $clean,2)[1]??'';
if(str_contains($analysis,'⚽')||str_contains($analysis,'🎯')||
   !str_contains($analysis,'Mercado: mensagem do autor')){
    throw new RuntimeException('Emoji from original analysis was preserved');
}

$contingency=CardLayoutEmojis::clean(
    "📌 Detalhes da aposta\n\n⚽ Texto original 📊\nStake: 10",
    $rule,
    true
);
if(!str_starts_with($contingency,"📌 Detalhes da aposta\n\n")||
   str_contains($contingency,'⚽')||str_contains($contingency,'📊')||
   !str_contains($contingency,'Stake: 10')){
    throw new RuntimeException('Contingency heading/source emoji boundary failed');
}

$unfiltered=CardLayoutEmojis::clean($normal,[
    'remove_emojis'=>0,'remove_links'=>0,'custom_removals'=>''
]);
if($unfiltered!==$normal)throw new RuntimeException('Disabled emoji cleaning was modified');

$footer=CardLayoutEmojis::cleanSystemDecoration(
    "\n\n━━━━━━━━━━━━\n⚡ TelegramRouter • Aposta encaminhada",
    $rule
);
if(!str_contains($footer,'⚡ TelegramRouter'))
    throw new RuntimeException('Service footer decoration was removed');

echo "CARD_LAYOUT_EMOJIS_TESTS_PASSED\n";
