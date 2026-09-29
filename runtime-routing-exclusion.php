<?php declare(strict_types=1);

/**
 * Adds a negative trigger to routing rules without changing the existing
 * positive-trigger semantics. One exclusion per line (OR); "+" inside a line
 * means all terms are required (AND). The veto runs before translation/AI.
 */
$root=__DIR__;
$paths=[
    'db'=>$root.'/app/Database.php',
    'repo'=>$root.'/app/Repository.php',
    'router'=>$root.'/app/TelegramRouter.php',
    'schema'=>$root.'/database/schema.sql',
    'index'=>$root.'/index.php',
];
foreach($paths as $label=>$path){
    if(!is_file($path)){fwrite(STDERR,"TMR_ROUTING_EXCLUSION_SOURCE_MISSING_".strtoupper($label)."\n");exit(1);}
}
$src=[];
foreach($paths as $key=>$path){
    $value=@file_get_contents($path);
    if(!is_string($value)){fwrite(STDERR,"TMR_ROUTING_EXCLUSION_READ_FAILED_".strtoupper($key)."\n");exit(1);}
    $src[$key]=$value;
}

$complete=
    str_contains($src['db'],'ADD COLUMN exclude_text TEXT NULL AFTER trigger_text')
    && str_contains($src['repo'],'trigger_text,exclude_text,destination_chat')
    && str_contains($src['router'],'private static function exclusionMatch')
    && str_contains($src['schema'],'exclude_text TEXT NULL')
    && str_contains($src['index'],'name="exclude_text"');
if($complete){
    echo "TMR_ROUTING_NEGATIVE_TRIGGER_V1_ALREADY_APPLIED\n";
    exit(0);
}

$replaceOnce=static function(string $body,string $find,string $replace,string $label): string {
    if(substr_count($body,$find)!==1){
        throw new RuntimeException('TMR_ROUTING_EXCLUSION_ANCHOR_'.$label);
    }
    return str_replace($find,$replace,$body);
};

try {
    $new=$src;

    $dbAnchor='            try { self::$pdo->exec("ALTER TABLE router_rules ADD COLUMN custom_removals TEXT NULL AFTER remove_emojis"); } catch (\\PDOException $e) { if ((int)($e->errorInfo[1] ?? 0) !== 1060) throw $e; }';
    $new['db']=$replaceOnce(
        $new['db'],
        $dbAnchor,
        $dbAnchor."\n".'            try { self::$pdo->exec("ALTER TABLE router_rules ADD COLUMN exclude_text TEXT NULL AFTER trigger_text"); } catch (\\PDOException $e) { if ((int)($e->errorInfo[1] ?? 0) !== 1060) throw $e; }',
        'DB'
    );

    $insertSql='$sql=\'INSERT INTO router_rules (source_chat,trigger_text,destination_chat,media_mode,remove_links,remove_emojis,custom_removals,translation_enabled,translation_provider,translation_source_language,translation_target_language,translation_fallback_original,enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)\';';
    $insertSqlNew='$sql=\'INSERT INTO router_rules (source_chat,trigger_text,exclude_text,destination_chat,media_mode,remove_links,remove_emojis,custom_removals,translation_enabled,translation_provider,translation_source_language,translation_target_language,translation_fallback_original,enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)\';';
    $new['repo']=$replaceOnce($new['repo'],$insertSql,$insertSqlNew,'REPO_INSERT_SQL');

    $values="            (string)\$in['source_chat'],(string)(\$in['trigger_text']??''),(string)\$in['destination_chat'],(string)\$in['media_mode'],\n"
        ."            (int)!empty(\$in['remove_links']),(int)!empty(\$in['remove_emojis']),trim((string)(\$in['custom_removals']??''),\" \\t\\r\\n\"),";
    $valuesNew="            (string)\$in['source_chat'],(string)(\$in['trigger_text']??''),trim((string)(\$in['exclude_text']??'')),(string)\$in['destination_chat'],(string)\$in['media_mode'],\n"
        ."            (int)!empty(\$in['remove_links']),(int)!empty(\$in['remove_emojis']),trim((string)(\$in['custom_removals']??''),\" \\t\\r\\n\"),";
    if(substr_count($new['repo'],$values)!==2)throw new RuntimeException('TMR_ROUTING_EXCLUSION_ANCHOR_REPO_VALUES');
    $new['repo']=str_replace($values,$valuesNew,$new['repo']);

    $updateSql='$sql=\'UPDATE router_rules SET source_chat=?,trigger_text=?,destination_chat=?,media_mode=?,remove_links=?,remove_emojis=?,custom_removals=?,translation_enabled=?,translation_provider=?,translation_source_language=?,translation_target_language=?,translation_fallback_original=?,enabled=? WHERE id=?\';';
    $updateSqlNew='$sql=\'UPDATE router_rules SET source_chat=?,trigger_text=?,exclude_text=?,destination_chat=?,media_mode=?,remove_links=?,remove_emojis=?,custom_removals=?,translation_enabled=?,translation_provider=?,translation_source_language=?,translation_target_language=?,translation_fallback_original=?,enabled=? WHERE id=?\';';
    $new['repo']=$replaceOnce($new['repo'],$updateSql,$updateSqlNew,'REPO_UPDATE_SQL');

    $loop=<<<'CODE'
        $identifiers=ChatMatcher::identifiers($source,$peerInfo);
        foreach(Repository::allRules() as $rule) {
            if(!(int)$rule['enabled'] || !$this->matches($identifiers,(string)$rule['source_chat'],$text,(string)$rule['trigger_text'])) continue;
            $this->process($message,$source,$rule);
            break;
        }
CODE;
    $loopNew=<<<'CODE'
        $identifiers=ChatMatcher::identifiers($source,$peerInfo);
        foreach(Repository::allRules() as $rule) {
            if(!(int)$rule['enabled'] || !$this->matches($identifiers,(string)$rule['source_chat'],$text,(string)$rule['trigger_text'])) continue;

            // TMR_ROUTING_NEGATIVE_TRIGGER_V1: hard veto before translation,
            // AI, media download or any other paid processing.
            $excluded=self::exclusionMatch($text,(string)($rule['exclude_text']??''));
            if($excluded!==null){
                $this->recordExcluded($source,(int)$message->id,$rule,$excluded);
                break;
            }

            $this->process($message,$source,$rule);
            break;
        }
CODE;
    $new['router']=$replaceOnce($new['router'],$loop,$loopNew,'ROUTER_LOOP');

    $processAnchor='    private function process(Message $message,string $source,array $rule): void'."\n    {";
    $methods=<<<'CODE'
    // Uma exclusão por linha. Linhas são OR entre si; dentro da linha,
    // "termo 1 + termo 2" preserva a mesma semântica AND do gatilho positivo.
    private static function exclusionMatch(string $text,string $exclude): ?string
    {
        $exclude=trim($exclude);
        if($exclude==='') return null;

        $rules=preg_split('/\R+/u',$exclude);
        if(!is_array($rules)) return null;

        foreach($rules as $rule){
            $rule=trim($rule);
            if($rule!=='' && self::triggerMatches($text,$rule)) return $rule;
        }
        return null;
    }

    private function recordExcluded(string $source,int $messageId,array $rule,string $matched): void
    {
        $matched=mb_substr(trim($matched),0,240,'UTF-8');
        $details='Mensagem ignorada: condição de exclusão encontrada: '.$matched
            .' | Nenhum encaminhamento, tradução ou IA foi executado.';
        try {
            $stmt=Database::pdo()->prepare(
                "INSERT INTO router_events(source_chat,message_id,destination_chat,trigger_text,status,details)
                 VALUES(?,?,?,?, 'skipped',?)"
            );
            $stmt->execute([
                $source,
                $messageId,
                (string)($rule['destination_chat']??''),
                (string)($rule['trigger_text']??''),
                $details,
            ]);
        } catch(\PDOException $e) {
            if((int)($e->errorInfo[1]??0)===1062) return;
            error_log('TMR_ROUTING_EXCLUSION_LOG_FAILED '.get_class($e));
        } catch(\Throwable $e) {
            error_log('TMR_ROUTING_EXCLUSION_LOG_FAILED '.get_class($e));
        }
    }

CODE;
    $new['router']=$replaceOnce($new['router'],$processAnchor,$methods.$processAnchor,'ROUTER_METHODS');

    $schemaAnchor="  trigger_text VARCHAR(500) NOT NULL,\n  destination_chat VARCHAR(255) NOT NULL,";
    $new['schema']=$replaceOnce(
        $new['schema'],
        $schemaAnchor,
        "  trigger_text VARCHAR(500) NOT NULL,\n  exclude_text TEXT NULL,\n  destination_chat VARCHAR(255) NOT NULL,",
        'SCHEMA'
    );

    $defaultNeedle="'source_chat'=>'','trigger_text'=>'','destination_chat'=>";
    $new['index']=$replaceOnce(
        $new['index'],
        $defaultNeedle,
        "'source_chat'=>'','trigger_text'=>'','exclude_text'=>'','destination_chat'=>",
        'INDEX_DEFAULT'
    );

    $formNeedle='<input name="trigger_text" value="<?=sh($formRule[\'trigger_text\'])?>" placeholder="Ex.: CUPOM, OFERTA, SINAL…" required></label><label class="activation-field">';
    $formReplacement='<input name="trigger_text" value="<?=sh($formRule[\'trigger_text\'])?>" placeholder="Ex.: CUPOM, OFERTA, SINAL…" required></label>'
        .'<label class="field-wide">Não encaminhar se contiver<span class="field-help">Uma condição por linha. Linhas funcionam como OU; use + para exigir todos os termos da mesma linha. Não diferencia maiúsculas e minúsculas.</span>'
        .'<textarea name="exclude_text" rows="4" placeholder="Ex.: CASHOUT&#10;RESULTADO FINAL&#10;APOSTA + FINALIZADA"><?=sh($formRule[\'exclude_text\']??\'\')?></textarea></label>'
        .'<label class="activation-field">';
    $new['index']=$replaceOnce($new['index'],$formNeedle,$formReplacement,'INDEX_FORM');

    $tagNeedle='<?=trim((string)($r[\'custom_removals\']??\'\'))!==\'\'?\'<span>Remoções personalizadas</span>\':\'\'?>';
    $tagReplacement='<?=trim((string)($r[\'exclude_text\']??\'\'))!==\'\'?\'<span>Exclusão configurada</span>\':\'\'?>'.$tagNeedle;
    $new['index']=$replaceOnce($new['index'],$tagNeedle,$tagReplacement,'INDEX_TAG');

    foreach(['db','repo','router','index'] as $key){
        $temp=$paths[$key].'.routing-exclusion-candidate';
        if(@file_put_contents($temp,$new[$key])===false)throw new RuntimeException('TMR_ROUTING_EXCLUSION_WRITE_'.$key);
        $lint=[];$exit=0;
        exec('php -l '.escapeshellarg($temp).' 2>&1',$lint,$exit);
        if($exit!==0)throw new RuntimeException('TMR_ROUTING_EXCLUSION_LINT_'.$key.'_'.implode('_',$lint));
    }

    $schemaTemp=$paths['schema'].'.routing-exclusion-candidate';
    if(@file_put_contents($schemaTemp,$new['schema'])===false)throw new RuntimeException('TMR_ROUTING_EXCLUSION_WRITE_SCHEMA');

    foreach($paths as $key=>$dest){
        $temp=$dest.'.routing-exclusion-candidate';
        if(!@rename($temp,$dest))throw new RuntimeException('TMR_ROUTING_EXCLUSION_APPLY_'.$key);
    }

    echo "TMR_ROUTING_NEGATIVE_TRIGGER_V1_APPLIED\n";
} catch(Throwable $e) {
    foreach($paths as $dest)@unlink($dest.'.routing-exclusion-candidate');
    fwrite(STDERR,preg_replace('/[^A-Z0-9_]/','_',strtoupper(substr($e->getMessage(),0,180)))."\n");
    exit(1);
}
