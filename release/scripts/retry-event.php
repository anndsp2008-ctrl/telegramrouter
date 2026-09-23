<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/Crypto.php';
require dirname(__DIR__) . '/app/Repository.php';
require dirname(__DIR__) . '/app/Transform.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use App\Database;
use App\Repository;
use App\Transform;

$eventId=(int)($argv[1]??0);
if($eventId<1) throw new RuntimeException('Informe o ID do evento.');
$pdo=Database::pdo();
$q=$pdo->prepare("SELECT e.*,r.* FROM router_events e JOIN router_rules r ON r.source_chat=e.source_chat AND r.destination_chat=e.destination_chat AND r.trigger_text=e.trigger_text WHERE e.id=? AND e.status='failed' LIMIT 1");
$q->execute([$eventId]); $event=$q->fetch();
if(!$event) throw new RuntimeException('Evento falho não encontrado.');
$credentials=Repository::credentials();
$settings=new Settings; $settings->setAppInfo((new AppInfo)->setApiId((int)$credentials['telegram_api_id'])->setApiHash((string)$credentials['telegram_api_hash']));
$api=new API(dirname(__DIR__).'/storage/sessions/telegram.madeline',$settings);
$api->start();
$history=$api->messages->getHistory(peer:$event['source_chat'],offset_id:(int)$event['message_id']+1,add_offset:-1,limit:1);
$message=$history['messages'][0]??null;
if(!$message || (int)($message['id']??0)!==(int)$event['message_id']) throw new RuntimeException('Mensagem original não encontrada no canal de origem.');
$text=Transform::translate(Transform::clean((string)($message['message']??''),$event),$event);
if($text==='') throw new RuntimeException('Texto vazio após limpeza.');
$api->getInfo((string)$event['destination_chat']);
$api->messages->sendMessage(peer:$event['destination_chat'],message:$text);
$u=$pdo->prepare("UPDATE router_events SET status='forwarded',details='Mensagem reprocessada após correção do peer de destino',updated_at=NOW() WHERE id=? AND status='failed'");
$u->execute([$eventId]);
echo "forwarded\n";
