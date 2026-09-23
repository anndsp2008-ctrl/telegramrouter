<?php declare(strict_types=1);
namespace App;

final class Transform
{
    /** @param array<int,mixed> $entities @return array{text:string,entities:array<int,array>} */
    public static function cleanWithEntities(string $text, array $rule, array $entities=[]): array
    {
        $chars=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY) ?: [];
        $starts=[]; $units=0;
        foreach($chars as $i=>$char){$starts[$i]=$units; $units+=self::utf16Length($char);}
        $totalUnits=$units; $intervals=[];
        if(!empty($rule['remove_links'])){
            $pattern='~\[[^\]\n]+\]\((?:(?:https?://|tg://|www\.)[^)\n]+)\)|<a\b[^>]*href\s*=\s*[\'\"][^\'\"]+[\'\"][^>]*>.*?</a>|(?:https?://|tg://|www\.|t\.me/|telegram\.me/)[^\s<>]+|\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b|\b(?:[a-z0-9-]+\.)+[a-z]{2,}(?:/[^\s<>]*)?~isu';
            if(preg_match_all($pattern,$text,$matches,PREG_OFFSET_CAPTURE)) foreach($matches[0] as [$value,$byte]) $intervals[]=self::byteRangeToUnits($text,(int)$byte,strlen((string)$value));
            foreach($entities as $entity){
                $raw=is_object($entity)&&method_exists($entity,'toMTProto')?$entity->toMTProto():(array)$entity;
                $type=strtolower((string)($raw['_']??(is_object($entity)?get_class($entity):'')));
                if(!str_contains($type,'url')&&!str_contains($type,'email')&&!str_contains($type,'phone')&&!str_contains($type,'mention')&&!str_contains($type,'hashtag')) continue;
                $intervals[]=[(int)($raw['offset']??0),(int)($raw['offset']??0)+(int)($raw['length']??0)];
            }
        }
        $custom = preg_split('/\R/u', (string)($rule['custom_removals'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($custom as $item) {
            $item = trim($item);
            if ($item === '') continue;
            $charOffset = 0;
            $itemLength = mb_strlen($item, 'UTF-8');
            while (($found = mb_stripos($text, $item, $charOffset, 'UTF-8')) !== false) {
                // mb_stripos retorna posição em caracteres; mb_substr mantém
                // o cálculo correto mesmo após emojis e letras acentuadas.
                $before = mb_substr($text, 0, $found, 'UTF-8');
                $value = mb_substr($text, $found, $itemLength, 'UTF-8');
                $start = (int)(strlen(mb_convert_encoding($before, 'UTF-16LE', 'UTF-8')) / 2);
                $size = (int)(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) / 2);
                $intervals[] = [$start, $start + $size];
                $charOffset = $found + max(1, $itemLength);
            }
        }
        if(!empty($rule['remove_emojis'])){
            // Analisa cada agrupamento Unicode (\X), preservando o texto e
            // removendo também sequências ZWJ, tons de pele, bandeiras,
            // variações visuais e emojis de teclas/números.
            if(preg_match_all('/\X/u', $text, $clusters, PREG_OFFSET_CAPTURE)) foreach($clusters[0] as [$cluster,$byte]) {
                $isEmoji = preg_match('/(?:\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}]|[\x{1F3FB}-\x{1F3FF}]|\x{20E3})/u', (string)$cluster);
                if($isEmoji) $intervals[]=self::byteRangeToUnits($text,(int)$byte,strlen((string)$cluster));
            }
        }
        $merged=self::mergeIntervals($intervals);
        $removed=[]; $out=''; $newUnits=0;
        foreach($chars as $i=>$char){$u0=$starts[$i];$u1=$u0+self::utf16Length($char);$drop=false;foreach($merged as [$a,$b])if($u0<$b&&$u1>$a){$drop=true;break;}if(!$drop){$out.=$char;$removed[$i]=false;$newUnits+=self::utf16Length($char);}else $removed[$i]=true;}
        $outEntities=[];
        foreach($entities as $entity){
            $raw=is_object($entity)&&method_exists($entity,'toMTProto')?$entity->toMTProto():(array)$entity;
            $offset=(int)($raw['offset']??0); $length=(int)($raw['length']??0); $end=$offset+$length;
            if($length<=0) continue;
            $segmentStart=null; $segmentUnits=0;
            foreach($chars as $i=>$char){
                $u0=$starts[$i]; $u1=$u0+self::utf16Length($char);
                if($u1<=$offset || $u0>=$end) continue;
                if(empty($removed[$i])) {
                    if($segmentStart===null) $segmentStart=$u0;
                    $segmentUnits += self::utf16Length($char);
                } elseif($segmentStart!==null) {
                    $before=0; foreach($chars as $j=>$beforeChar){$bu=$starts[$j]; if($bu>=$segmentStart) break; if(empty($removed[$j])) $before+=self::utf16Length($beforeChar);}
                    $copy=$raw; $copy['offset']=$before; $copy['length']=$segmentUnits; $outEntities[]=$copy;
                    $segmentStart=null; $segmentUnits=0;
                }
            }
            if($segmentStart!==null && $segmentUnits>0){
                $before=0; foreach($chars as $j=>$beforeChar){$bu=$starts[$j]; if($bu>=$segmentStart) break; if(empty($removed[$j])) $before+=self::utf16Length($beforeChar);}
                $copy=$raw; $copy['offset']=$before; $copy['length']=$segmentUnits; $outEntities[]=$copy;
            }
        }
        return ['text'=>$out,'entities'=>$outEntities];
    }

    /** @param array<int,mixed> $entities */
    public static function clean(string $text, array $rule, array $entities=[]): string { return self::cleanWithEntities($text,$rule,$entities)['text']; }
    private static function utf16Length(string $char): int { return strlen(mb_convert_encoding($char,'UTF-16LE','UTF-8'))/2; }
    /** @return array{0:int,1:int} */
    private static function byteRangeToUnits(string $text,int $byte,int $length): array { $before=substr($text,0,$byte);$value=substr($text,$byte,$length);$start=(int)(strlen(mb_convert_encoding($before,'UTF-16LE','UTF-8'))/2);$size=(int)(strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2);return [$start,$start+$size]; }
    /** @param array<int,array{0:int,1:int}> $intervals @return array<int,array{0:int,1:int}> */
    private static function mergeIntervals(array $intervals): array { if(!$intervals)return [];usort($intervals,static fn($a,$b)=>$a[0]<=>$b[0]);$out=[];foreach($intervals as $r){if(!$out||$r[0]>$out[array_key_last($out)][1])$out[]=$r;else$out[array_key_last($out)][1]=max($out[array_key_last($out)][1],$r[1]);}return $out; }

    public static function translate(string $text, array $rule): string
    {
        return TranslationService::translate($text, $rule)['text'];
    }

    /** @return array{text:string,translated:bool,details:string,attempts:array<int,array>,fallback_used:bool,original_preserved:bool} */
    public static function translateDetailed(string $text, array $rule, array $context=[]): array
    {
        return TranslationService::translate($text, $rule, $context);
    }

    private static function removeLinkMarkup(string $text): string
    {
        $patterns = [
            '~\[[^\]\n]+\]\((?:(?:https?://|tg://|www\.)[^)\n]+)\)~isu',
            '~<a\b[^>]*href\s*=\s*[\'\"][^\'\"]+[\'\"][^>]*>.*?</a>~isu',
            '~(?:https?://|tg://|www\.|t\.me/|telegram\.me/)[^\s<>]+~iu',
            '~\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b~iu',
            '~\b(?:[a-z0-9-]+\.)+[a-z]{2,}(?:/[^\s<>]*)?~iu',
        ];
        foreach ($patterns as $pattern) $text = preg_replace($pattern, '', $text) ?? $text;
        return $text;
    }

    /** @param array<int,mixed> $entities */
    private static function removeEntityContent(string $text, array $entities): string
    {
        $chars=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY) ?: [];
        $remove=[];
        foreach ($entities as $entity) {
            $type=(string)(is_array($entity)?($entity['_']??''):(isset($entity->_)?$entity->_:get_class($entity)));
            $normalized=strtolower($type);
            if (!str_contains($normalized,'url') && !str_contains($normalized,'email') && !str_contains($normalized,'phone') && !str_contains($normalized,'mention') && !str_contains($normalized,'hashtag')) continue;
            $offset=(int)(is_array($entity)?($entity['offset']??0):($entity->offset??0));
            $length=(int)(is_array($entity)?($entity['length']??0):($entity->length??0));
            if ($length<=0) continue;
            [$start,$end]=self::utf16RangeToCharacters($text,$offset,$length);
            for($i=$start;$i<$end;$i++) $remove[$i]=true;
        }
        if (!$remove) return $text;
        $out=''; foreach($chars as $i=>$char) if(empty($remove[$i])) $out.=$char;
        return $out;
    }

    /** @return array{0:int,1:int} */
    private static function utf16RangeToCharacters(string $text,int $offset,int $length): array
    {
        $units=0; $start=0; $end=mb_strlen($text,'UTF-8'); $chars=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $index=>$char) { $size=(strlen(mb_convert_encoding($char,'UTF-16LE','UTF-8'))/2); if($units<$offset) $start=$index+1; $units+=$size; if($units>=$offset+$length){$end=$index+1;break;} }
        return [$start,$end];
    }

    private static function removeVisibleLinkLines(string $text): string
    {
        $hasLink='~(?:https?://|tg://|www\.|t\.me/|telegram\.me/)[^\s<>]+|\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b|\b(?:[a-z0-9-]+\.)+[a-z]{2,}(?:/[^\s<>]*)?~iu';
        $parts=preg_split('/(?<=[.!?])\s+|\R+/u',$text) ?: [$text];
        return implode(' ',array_filter($parts,static fn(string $part):bool=>!preg_match($hasLink,$part)));
    }

    private static function removeEmojis(string $text): string
    {
        $pattern='~(?:[\x{1F1E6}-\x{1F1FF}]{2}|\p{Extended_Pictographic}(?:[\x{FE0F}\x{1F3FB}-\x{1F3FF}])?(?:\x{200D}\p{Extended_Pictographic}(?:[\x{FE0F}\x{1F3FB}-\x{1F3FF}])?)*|[\x{2600}-\x{27BF}](?:\x{FE0F})?|[0-9#*]\x{FE0F}?\x{20E3})~u';
        return preg_replace($pattern,'',$text) ?? $text;
    }
}
