<?php declare(strict_types=1);
namespace App;

/**
 * Source content still follows the channel's cleanup rule; only icons created
 * by TelegramRouter as card labels are protected from its emoji filter.
 */
final class CardLayoutEmojis
{
    public static function clean(string $caption,array $rule,bool $contingency=false): string
    {
        if(empty($rule['remove_emojis']))return Transform::clean($caption,$rule,[]);

        $protected=[];
        $protect=static function(string $value,array $patterns) use (&$protected): string {
            foreach($patterns as $pattern=>$emoji){
                $value=preg_replace_callback($pattern,static function() use (&$protected,$emoji): string {
                    $marker='TMRCARDLAYOUTICON'.count($protected).'END';
                    $protected[$marker]=$emoji;
                    return $marker;
                },$value,1)??$value;
            }
            return $value;
        };

        if($contingency){
            $caption=$protect($caption,[
                '/\\A📌(?=[ \\t]+Detalhes da aposta(?:\\R|$))/u'=>'📌'
            ]);
        } else {
            // The author's analysis begins after this heading. Never shield
            // emojis occurring inside the author's prose, even if a line looks
            // like another betting field.
            $analysisStart=null;
            if(preg_match('/^📝[ \\t]+(?:Análise original|Original analysis):/mu',
                $caption,$match,PREG_OFFSET_CAPTURE)===1){
                $analysisStart=$match[0][1];
            }
            $header=$analysisStart===null?$caption:substr($caption,0,$analysisStart);
            $body=$analysisStart===null?'':substr($caption,$analysisStart);
            $header=$protect($header,[
                '/\\A⚽(?=[ \\t])/u'=>'⚽',
                '/^🏆(?=[ \\t])/mu'=>'🏆',
                '/^🔴(?=[ \\t]AO VIVO)/mu'=>'🔴',
                '/^🎯(?=[ \\t](?:Mercado|Market):)/mu'=>'🎯',
                '/^✅(?=[ \\t](?:Seleção|Selection):)/mu'=>'✅',
                '/^📈(?=[ \\t]Odd:)/mu'=>'📈',
                '/^🕒(?=[ \\t](?:Horário|Time):)/mu'=>'🕒',
                '/^📅(?=[ \\t](?:Dia|Day):)/mu'=>'📅',
                '/^📍(?=[ \\t]Stake:)/mu'=>'📍',
                '/^🏦(?=[ \\t](?:Casa de apostas|Bookmaker):)/mu'=>'🏦',
                '/^💶(?=[ \\t](?:Valor apostado|Amount staked):)/mu'=>'💶',
                '/^💰(?=[ \\t](?:Retorno potencial|Potential return):)/mu'=>'💰',
                '/^💵(?=[ \\t](?:Lucro potencial|Potential profit):)/mu'=>'💵'
            ]);
            if($body!==''){
                $body=$protect($body,[
                    '/\\A📝(?=[ \\t]+(?:Análise original|Original analysis):)/u'=>'📝'
                ]);
            }
            $caption=$header.$body;
        }

        $cleaned=Transform::clean($caption,$rule,[]);
        return strtr($cleaned,$protected);
    }

    public static function cleanSystemDecoration(string $text,array $rule): string
    {
        $rule['remove_emojis']=false;
        return Transform::clean($text,$rule,[]);
    }
}
