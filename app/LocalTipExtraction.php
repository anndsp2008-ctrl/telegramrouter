<?php declare(strict_types=1);
namespace App;

/**
 * Conservative offline extraction of an explicit, SINGLE betting selection.
 * No inferred team form, market, stake or selection. Never logs OCR text.
 */
final class LocalTipExtraction
{
    public static function ocrAvailable(): bool
    {
        return function_exists('proc_open') && is_executable('/usr/bin/tesseract');
    }

    public static function readImage(string $path, int $maxMilliseconds=4000): ?string
    {
        if(!self::ocrAvailable()||!is_file($path)||!is_readable($path))return null;
        $size=filesize($path);
        if(!is_int($size)||$size<1||$size>4*1024*1024)return null;
        $mime=mime_content_type($path)?:'';
        if(!in_array($mime,['image/png','image/jpeg','image/webp'],true))return null;
        // Bound image dimensions before invoking an external program.
        $dimensions=@getimagesize($path);
        if(!is_array($dimensions)||($dimensions[0]??0)<1||($dimensions[1]??0)<1||
           ($dimensions[0]??0)*($dimensions[1]??0)>12000000)return null;
        $pipes=[];
        $process=@proc_open(
            ['/usr/bin/tesseract',$path,'stdout','-l','por+spa+eng','--psm','6'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],
            $pipes
        );
        if(!is_resource($process))return null;
        fclose($pipes[0]);
        stream_set_blocking($pipes[1],false);
        $output='';$ended=false;$expired=false;
        $deadline=microtime(true)+max(0.2,min(5,$maxMilliseconds/1000));
        try{
            while(microtime(true)<$deadline){
                $chunk=@fread($pipes[1],4096);
                if(is_string($chunk))$output.=$chunk;
                if(strlen($output)>16000){$expired=true;break;}
                $status=proc_get_status($process);
                if(!$status['running']){
                    $ended=true;
                    $tail=@stream_get_contents($pipes[1],4096);
                    if(is_string($tail))$output.=$tail;
                    break;
                }
                usleep(25000);
            }
            if(!$ended)$expired=true;
        } finally {
            if($expired)@proc_terminate($process,9);
            fclose($pipes[1]);
            @proc_close($process);
        }
        return !$expired && trim($output)!=='' ? mb_substr($output,0,12000,'UTF-8'):null;
    }

    /**
     * Parse only one explicit event and one explicitly selected market.
     * Multiple matches/markets or an absent BTTS yes/no choice are ambiguous.
     * @return array<string,string>|null
     */
    public static function parse(string $source): ?array
    {
        $source=trim($source);
        if($source===''||mb_strlen($source,'UTF-8')>18000)return null;
        $lines=preg_split('/\R/u',$source);
        if(!is_array($lines)||count($lines)>140)return null;
        $matches=[];$markets=[];
        foreach($lines as $i=>$original){
            $line=trim(preg_replace('/\s+/u',' ',$original)??'');
            if($line===''||mb_strlen($line,'UTF-8')>170)continue;
            // Event is only recognized with an explicit two-sided delimiter.
            if(preg_match('/^(?:⚽\s*)?(?:partida|jogo|partido|match|evento)\s*[:\-]\s*/iu',$line)){
                $line=preg_replace('/^(?:⚽\s*)?(?:partida|jogo|partido|match|evento)\s*[:\-]\s*/iu','',$line)??$line;
            }
            if(preg_match('/^([\p{L}\p{N}][\p{L}\p{M}\p{N} .\x{27}’()\-]{1,48})\s+(?:x|vs?\.?|×)\s+([\p{L}\p{N}][\p{L}\p{M}\p{N} .\x{27}’()\-]{1,48})$/iu',$line,$m)){
                $a=trim($m[1]);$b=trim($m[2]);
                if(mb_strlen($a,'UTF-8')>=2&&mb_strlen($b,'UTF-8')>=2)
                    $matches[mb_strtolower($a.'|'.$b,'UTF-8')]=$a.' × '.$b;
            }
            if(preg_match('/\b(?:ambos\s+(?:(?:os|las|los)\s+)?(?:times|equipos|equipes)\s+(?:marcam|marcan)|ambas\s+equipes\s+marcam|both\s+teams\s+(?:to\s+)?score|btts)\b/iu',$line)){
                $choice=self::explicitYesNo($line);
                if($choice===null && isset($lines[$i+1]))$choice=self::explicitYesNo(trim($lines[$i+1]),true);
                if($choice!==null)$markets['btts:'.$choice]=['market'=>'Ambos marcam','selection'=>$choice];
                else $markets['ambiguous_btts_'.(string)$i]=null;
                continue;
            }
            $kind=null;
            if(preg_match('/\b(?:corners?|escanteios?|tiros de esquina)\b/iu',$line))$kind='Escanteios';
            elseif(preg_match('/\b(?:goals?|gols?|goles)\b/iu',$line))$kind='Gols';
            elseif(preg_match('/\b(?:cards?|cart[oõ]es?|tarjetas)\b/iu',$line))$kind='Cartões';
            if($kind!==null&&preg_match('/\b(over|under|mais de|menos de|m[aá]s de|menos de)\s*(\d{1,2}(?:[.,]\d)?)\b/iu',$line,$m)){
                $side=in_array(mb_strtolower($m[1],'UTF-8'),['over','mais de','más de','mas de'],true)?'Mais de':'Menos de';
                $num=str_replace('.',',',$m[2]);
                $markets[$kind.':'.$side.':'.$num]=[
                    'market'=>'Total de '.$kind,
                    'selection'=>$side.' '.$num
                ];
            }
        }
        if(count($matches)!==1||count($markets)!==1)return null;
        $market=array_values($markets)[0];
        if(!is_array($market))return null;
        $match=array_values($matches)[0];
        $odd='';
        if(preg_match('/\b(?:odd|odds|cuota|cota[cç][aã]o)\s*[:\-]?\s*(\d{1,2}[.,]\d{1,3})\b/iu',$source,$m))
            $odd=$m[1];
        return [
            'sport'=>'Futebol','match'=>$match,'market'=>$market['market'],
            'selection'=>$market['selection'],'odd'=>$odd,'analysis'=>''
        ];
    }

    private static function explicitYesNo(string $line,bool $wholeLine=false): ?string
    {
        if($wholeLine){
            $line=trim($line);
            if(preg_match('/^(?:sim|s[ií]|yes|n[aã]o|no)$/iu',$line)!==1)return null;
        }
        $yes=preg_match('/(?:^|[\s:()\-])(?:sim|s[ií]|yes)(?:$|[\s:()\-])/iu',$line)===1;
        $no=preg_match('/(?:^|[\s:()\-])(?:n[aã]o|no)(?:$|[\s:()\-])/iu',$line)===1;
        if($yes===$no)return null;
        return $yes?'Sim':'Não';
    }
}
