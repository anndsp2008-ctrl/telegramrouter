<?php declare(strict_types=1);
namespace App\Results;
final class TelegramReportSender {
 public static function send(string $chat,string $text):void{if(trim($chat)==='')throw new \RuntimeException('Canal do relatório não configurado.');$credentials=\App\Repository::credentials();$session=(string)($credentials['telegram_session']??config('telegram_session'));if($session==='')throw new \RuntimeException('Sessão Telegram indisponível.');$api=new \danog\MadelineProto\API(__DIR__.'/../../storage/sessions/telegram.madeline');$api->start();$api->messages->sendMessage(peer:$chat,message:$text,entities:[]);}
}
