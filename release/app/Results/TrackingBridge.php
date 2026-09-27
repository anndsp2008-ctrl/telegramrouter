<?php declare(strict_types=1);
namespace App\Results;
final class TrackingBridge {
 public static function afterForward(array $rule,string $source,int $messageId,string $rawTip):void{try{if(!TrackingStore::enabledForRule($rule))return;$card=\App\SmartFormatting::lastStructuredBet();if(!is_array($card))return;$bet=BetNormalizer::fromCard($card);if($bet===null)return;TrackingStore::register($bet,['rule_id'=>(int)($rule['id']??0),'source_chat'=>$source,'destination_chat'=>(string)($rule['destination_chat']??''),'message_id'=>$messageId,'raw_tip'=>$rawTip]);}catch(\Throwable $e){error_log('TMR_RESULT_TRACKING_NON_FATAL '.get_class($e));}}
}
