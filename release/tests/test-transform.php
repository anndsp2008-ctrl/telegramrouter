<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use App\Transform;
$source='Oferta https://exemplo.com/oferta e www.site.com agora';
if (Transform::clean($source, ['remove_links'=>0,'remove_emojis'=>0]) !== $source) throw new RuntimeException('Preservação de links falhou.');
if (Transform::clean('Primeira frase. Acesse https://exemplo.com/oferta agora. Segunda frase.', ['remove_links'=>1,'remove_emojis'=>0]) !== 'Primeira frase. Acesse  agora. Segunda frase.') throw new RuntimeException('URL não removida preservando o entorno.');
if (Transform::clean("Texto válido\nLink da oferta: https://exemplo.com", ['remove_links'=>1,'remove_emojis'=>0]) !== "Texto válido\nLink da oferta: ") throw new RuntimeException('Linha não preservada.');
if (Transform::clean('[clique aqui](https://exemplo.com) agora', ['remove_links'=>1,'remove_emojis'=>0]) !== ' agora') throw new RuntimeException('Markdown não removido.');
if (Transform::clean('<a href="https://exemplo.com">clique aqui</a> agora', ['remove_links'=>1,'remove_emojis'=>0]) !== ' agora') throw new RuntimeException('HTML não removido.');
if (Transform::clean('🐾 GANA 200€ GRÁTIS AQUI', ['remove_links'=>1,'remove_emojis'=>0], [['_'=>'messageEntityTextUrl','offset'=>0,'length'=>25,'url'=>'https://exemplo.com']]) !== '') throw new RuntimeException('Entidade TextUrl não removida.');
if (Transform::clean('Texto 👍🏽 sem link 🇧🇷 ❤️ 👨‍💻 1️⃣', ['remove_links'=>0,'remove_emojis'=>1]) !== 'Texto  sem link    ') throw new RuntimeException('Emojis não removidos corretamente.');
$emojiResult=Transform::clean('Emoji: 😀 😃 🥳 👍🏽 👨‍💻 👩‍❤️‍💋‍👨 🇧🇷 🏳️‍🌈 1️⃣ #️⃣ ©️ ™️ ☎️', ['remove_links'=>0,'remove_emojis'=>1]);
if (preg_match('/(?:\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}]|[\x{1F3FB}-\x{1F3FF}]|\x{20E3})/u', $emojiResult)) throw new RuntimeException('Agrupamentos Unicode de emojis não foram removidos completamente.');
$customResult=Transform::clean('😀 Mensagem com 21:00 e Café 12345', ['remove_links'=>0,'remove_emojis'=>1,'custom_removals'=>"21:00\nCafé\n12345"]);
if ($customResult !== ' Mensagem com  e  ') throw new RuntimeException('Remoções personalizadas com Unicode falharam.');
$formatted=Transform::cleanWithEntities('⚽️ Gols em destaque', ['remove_links'=>0,'remove_emojis'=>1], [['_'=>'messageEntityBold','offset'=>0,'length'=>18]]);
if (($formatted['text'] ?? '') !== ' Gols em destaque' || ($formatted['entities'][0]['_'] ?? '') !== 'messageEntityBold') throw new RuntimeException('Formatação não foi preservada após remover emoji dentro do trecho.');




echo "Especificação de limpeza e preservação aprovada\n";
