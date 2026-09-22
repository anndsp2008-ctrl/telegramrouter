<?php declare(strict_types=1);
namespace App;

use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Explicit, authenticated ZIP import of the nine synthetic betting examples.
 * Each entry stays PENDING; imported labels are suggestions, not ground truth.
 * No ZIP entry is ever extracted into the web root.
 */
final class AiLearningZipImporter
{
    public function __construct(private PDO $pdo,private AiLearningMemory $memory) {}

    private function migrate(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS tmr_ai_learning_import_hashes (
            image_sha256 CHAR(64) NOT NULL PRIMARY KEY,
            example_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX tmr_import_example (example_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Read a bounded entry without extracting files or trusting paths from the ZIP. */
    private static function entry(string $zipPath,string $name,int $limit): string
    {
        if(class_exists(ZipArchive::class)){
            $zip=new ZipArchive();
            if($zip->open($zipPath)!==true)throw new RuntimeException('Não foi possível abrir o ZIP.');
            try {
                $stream=$zip->getStream($name);
                if(!is_resource($stream))throw new RuntimeException('Arquivo ausente no ZIP: '.$name);
                try { $bytes=stream_get_contents($stream,$limit+1); }
                finally { fclose($stream); }
                if(!is_string($bytes)||strlen($bytes)>$limit)throw new RuntimeException('Arquivo muito grande dentro do ZIP.');
                return $bytes;
            } finally { $zip->close(); }
        }
        if(!function_exists('proc_open'))throw new RuntimeException('Suporte a ZIP indisponível no servidor.');
        $proc=@proc_open(['unzip','-p',$zipPath,$name],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('Leitura de ZIP indisponível no servidor.');
        fclose($pipes[0]);
        $bytes='';
        while(!feof($pipes[1])&&strlen($bytes)<=$limit){
            $chunk=fread($pipes[1],min(65536,$limit+1-strlen($bytes)));
            if($chunk===false)break;
            $bytes.=$chunk;
            if($chunk==='')break;
        }
        $oversize=strlen($bytes)>$limit;
        if($oversize)proc_terminate($proc);
        fclose($pipes[1]);
        $error=stream_get_contents($pipes[2],4096);
        fclose($pipes[2]);
        $code=proc_close($proc);
        if($oversize)throw new RuntimeException('Arquivo muito grande dentro do ZIP.');
        if($code!==0)throw new RuntimeException('Falha na leitura de arquivo do ZIP.');
        return $bytes;
    }

    private static function names(string $path): array
    {
        if(class_exists(ZipArchive::class)){
            $zip=new ZipArchive();
            if($zip->open($path)!==true)throw new RuntimeException('ZIP inválido.');
            try {
                if($zip->numFiles>60)throw new RuntimeException('O pacote tem arquivos demais.');
                $names=[];
                for($i=0;$i<$zip->numFiles;$i++)$names[]=(string)$zip->getNameIndex($i);
                return $names;
            } finally { $zip->close(); }
        }
        if(!function_exists('proc_open'))throw new RuntimeException('Suporte a ZIP indisponível.');
        $proc=@proc_open(['unzip','-Z','-1',$path],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('Suporte a ZIP indisponível.');
        fclose($pipes[0]);
        $listing=stream_get_contents($pipes[1],65537);
        if(!is_string($listing)||strlen($listing)>65536)proc_terminate($proc);
        fclose($pipes[1]);
        $error=stream_get_contents($pipes[2],4096);
        fclose($pipes[2]);
        $code=proc_close($proc);
        if($code!==0||!is_string($listing)||strlen($listing)>65536)throw new RuntimeException('ZIP inválido.');
        $names=array_values(array_filter(explode("\n",str_replace("\r",'',$listing)),'strlen'));
        if(count($names)>60)throw new RuntimeException('O pacote tem arquivos demais.');
        return $names;
    }

    private static function labelFor(array $dataset): array
    {
        $selections=$dataset['selections']??null;
        if(!is_array($selections)||count($selections)<1||count($selections)>4)
            throw new RuntimeException('Bilhete sem seleções válidas.');
        $isMultiple=count($selections)>1;
        $label=['sport'=>'Futebol','status'=>($dataset['status']??null)==='live'?'Ao vivo no bilhete':'',
            'match'=>'','league'=>'','market'=>'','selection'=>'','odd'=>'','time'=>'','day'=>'',
            'bookmaker'=>(string)($dataset['bookmaker']??''),'analysis'=>''];
        if($isMultiple)return $label; // Do not flatten separate events into one selection.
        $leg=$selections[0];
        $home=trim((string)($leg['home_team']??''));
        $away=trim((string)($leg['away_team']??''));
        if($home===''||$away==='')throw new RuntimeException('Confronto incompleto no rótulo.');
        $label['match']=$home.' x '.$away;
        $markets=['total_goals'=>'Total de gols','total_corners'=>'Total de escanteios',
            'double_chance'=>'Dupla chance','match_result'=>'Resultado da partida',
            'both_teams_to_score'=>'Ambas as equipes marcam',
            'half_time_full_time'=>'Intervalo/Final'];
        $kind=(string)($leg['market']??'');
        $label['market']=$markets[$kind]??'';
        $raw=trim((string)($leg['selection_verbatim']??''));
        $side=(string)($leg['selection']??'');
        $line=$leg['line']??null;
        if(in_array($side,['under','over'],true) && is_numeric($line)){
            $label['selection']=($side==='under'?'Menos':'Mais').' de '.
                str_replace('.',',',(string)$line).($kind==='total_corners'?' escanteios':' gols');
        }elseif($kind==='both_teams_to_score'){
            $label['selection']=mb_strtolower($raw,'UTF-8')==='sí'?'Sim':$raw;
        }elseif($kind==='double_chance'){
            $label['selection']=str_replace(' o ',' ou ',$raw);
        }else{$label['selection']=$kind==='half_time_full_time'?str_replace(' - ',' / ',$raw):$raw;}
        $label['odd']=isset($leg['odds'])?(string)$leg['odds']:'';
        $label['day']=trim((string)($leg['event_date_verbatim']??''));
        $label['time']=trim((string)($leg['event_time']??''));
        return $label;
    }

    private static function sourceFor(array $dataset): string
    {
        $lines=['[EXEMPLO SINTÉTICO — transcrição reconstruída dos rótulos; conferir no print antes de aprovar]',
            'Casa: '.(string)($dataset['bookmaker']??''),
            'Tipo: '.(count($dataset['selections'])>1?'Múltipla':'Simples')];
        foreach($dataset['selections'] as $i=>$leg){
            $lines[]='Seleção '.($i+1).': '.(string)$leg['home_team'].' x '.(string)$leg['away_team'].
                ' | Mercado original: '.(string)($leg['market_verbatim']??'').
                ' | Escolha original: '.(string)($leg['selection_verbatim']??'').
                ' | Odd: '.(isset($leg['odds'])?(string)$leg['odds']:'não indicada').
                ' | Data: '.(string)($leg['event_date_verbatim']??'').
                ' | Hora: '.(string)($leg['event_time']??'');
        }
        if(isset($dataset['combined_odds']))$lines[]='Odd total rotulada: '.(string)$dataset['combined_odds'];
        return implode("\n",$lines);
    }

    public function import(string $tmpPath,string $storage,int $zipSize): array
    {
        if($zipSize<100||$zipSize>24*1024*1024||!is_file($tmpPath))
            throw new RuntimeException('Envie o ZIP de treino válido (até 24 MB).');
        $names=self::names($tmpPath);
        $labels=[];
        foreach($names as $name){
            if(preg_match('~^([A-Za-z0-9_-]+/)?labels/(00[1-9])\.json$~D',$name,$matches)){
                if(isset($labels[$matches[2]]))throw new RuntimeException('Rótulo duplicado no ZIP.');
                $labels[$matches[2]]=['name'=>$name,'prefix'=>$matches[1]??''];
            }
        }
        if(count($labels)!==9)throw new RuntimeException('O pacote deve conter os nove rótulos 001–009.');
        $pending=[];$totalBytes=0;
        for($number=1;$number<=9;$number++){
            $id=str_pad((string)$number,3,'0',STR_PAD_LEFT);
            if(!isset($labels[$id]))throw new RuntimeException('Rótulo ausente: '.$id);
            $info=$labels[$id];
            $json=self::entry($tmpPath,$info['name'],20000);
            try{$record=json_decode($json,true,32,JSON_THROW_ON_ERROR);}
            catch(Throwable $e){throw new RuntimeException('JSON inválido no rótulo '.$id);}
            if(!is_array($record)||($record['id']??null)!==$id||($record['source_type']??null)!=='synthetic')
                throw new RuntimeException('Identificação inválida no rótulo '.$id);
            $relative=(string)($record['image']??'');
            if(!preg_match('~^images/'.$id.'_[a-z0-9_]+\.(?:png|jpg|webp)$~D',$relative))
                throw new RuntimeException('Nome de imagem não permitido no rótulo '.$id);
            $fullName=$info['prefix'].$relative;
            if(count(array_filter($names,static fn($n)=>$n===$fullName))!==1)
                throw new RuntimeException('Imagem ausente ou duplicada no ZIP: '.$id);
            $bytes=self::entry($tmpPath,$fullName,4*1024*1024);
            $totalBytes+=strlen($bytes);
            if($bytes===''||$totalBytes>36*1024*1024)
                throw new RuntimeException('Tamanho das imagens inválido.');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $extension=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]??null;
            if($extension===null||!str_ends_with($relative,'.'.$extension)||@getimagesizefromstring($bytes)===false)
                throw new RuntimeException('Imagem inválida no bilhete '.$id);
            $pending[]=['id'=>$id,'sha'=>hash('sha256',$bytes),
                'bytes'=>$bytes,'ext'=>$extension,'source'=>self::sourceFor($record),
                'label'=>self::labelFor($record)];
        }

        // Only now are writes allowed: the entire ZIP has been validated first.
        $this->migrate();
        if(!is_dir($storage)&&!@mkdir($storage,0700,true)&&!is_dir($storage))
            throw new RuntimeException('Armazenamento de exemplos indisponível.');
        $existing=[];
        $stmt=$this->pdo->query('SELECT id,image_name FROM tmr_ai_learning_examples
            WHERE image_name IS NOT NULL ORDER BY id DESC LIMIT 150');
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $item){
            $name=(string)$item['image_name'];
            if(!preg_match('/^[a-f0-9]{32}\.(?:png|jpg|webp)\.enc$/D',$name))continue;
            $path=$storage.'/'.$name;
            if(!is_file($path))continue;
            try{$bytes=AiLearningMemory::decryptImage((string)file_get_contents($path));}
            catch(Throwable $e){continue;}
            $existing[hash('sha256',$bytes)]=true;
        }
        $done=0;$skipped=0;
        $check=$this->pdo->prepare('SELECT example_id FROM tmr_ai_learning_import_hashes WHERE image_sha256=?');
        $insert=$this->pdo->prepare('INSERT INTO tmr_ai_learning_import_hashes (image_sha256,example_id) VALUES(?,?)');
        foreach($pending as $item){
            $check->execute([$item['sha']]);
            if(isset($existing[$item['sha']])||$check->fetchColumn()!==false){$skipped++;continue;}
            $name=bin2hex(random_bytes(16)).'.'.$item['ext'].'.enc';
            $final=$storage.'/'.$name;
            $temp=$final.'.part';
            $this->pdo->beginTransaction();
            try{
                $sealed=AiLearningMemory::encryptImage($item['bytes']);
                if(file_put_contents($temp,$sealed,LOCK_EX)===false||!@rename($temp,$final))
                    throw new RuntimeException('Falha ao armazenar imagem de treino.');
                @chmod($final,0600);
                $id=$this->memory->savePending($item['source'],$item['label'],$name,null);
                $insert->execute([$item['sha'],$id]);
                $this->pdo->commit();
                $existing[$item['sha']]=true;$done++;
            }catch(Throwable $e){
                if($this->pdo->inTransaction())$this->pdo->rollBack();
                @unlink($temp);@unlink($final);
                throw $e;
            }
        }
        return ['created'=>$done,'skipped'=>$skipped];
    }
}
