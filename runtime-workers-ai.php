<?php declare(strict_types=1);
/**
 * Workers AI opt-in installer. Run only after all existing routing and UI patches.
 * A failure leaves the previous providers and the Telegram routing untouched.
 */
$root=__DIR__;
$paths=[
 'repo'=>$root.'/app/Repository.php',
 'translator'=>$root.'/app/TranslationService.php',
 'index'=>$root.'/index.php',
 'schema'=>$root.'/database/schema.sql'
];
foreach($paths as $label=>$path){
    if(!is_file($path)){echo "WORKERS_AI_SKIPPED_SOURCE_".strtoupper($label)."\n";return;}
}
$old=[];$new=[];
foreach($paths as $key=>$path){
    $contents=@file_get_contents($path);
    if(!is_string($contents)){echo "WORKERS_AI_SKIPPED_READ\n";return;}
    $old[$key]=$new[$key]=$contents;
}
if(str_contains($old['repo'],"'workers_ai'") && str_contains($old['index'],'workers-ai-card.php')){
    echo "WORKERS_AI_ALREADY_APPLIED\n";return;
}
$replace=static function(string &$src,string $find,string $replacement,string $name): void {
    if(substr_count($src,$find)!==1)throw new \RuntimeException('WORKERS_AI_ANCHOR_'.$name);
    $src=str_replace($find,$replacement,$src);
};
try{
    $providerList="['azure','gemini','google_cloud']";
    if(substr_count($new['repo'],$providerList)<4)throw new \RuntimeException('WORKERS_AI_REPO_PROVIDER_LIST');
    $new['repo']=str_replace($providerList,"['azure','gemini','google_cloud','workers_ai']",$new['repo']);
    $replace($new['repo'],"['azure','gemini','google_cloud','none']",
        "['azure','gemini','google_cloud','workers_ai','none']",'REPO_FALLBACK_LIST_1');
    // The configured fallback list appears both in the public getter and validator.
    // Replace the exact remaining occurrence if it exists as part of the original.
} catch(\RuntimeException $e){
    // The full patch is assembled below using a repeated-list aware method.
    $new=$old;
}
try {
    $withNone="['azure','gemini','google_cloud','none']";
    $plain="['azure','gemini','google_cloud']";
    if(substr_count($new['repo'],$plain)!==0 || substr_count($new['repo'],$withNone)!==1){
        // Start from unmodified source: provider whitelist may be reused across methods.
        $new['repo']=$old['repo'];
    }
    if(substr_count($new['repo'],$plain)<4 || substr_count($new['repo'],$withNone)<2)
        throw new \RuntimeException('WORKERS_AI_REPOSITORY_PROVIDER_ANCHORS');
    $new['repo']=str_replace($plain,"['azure','gemini','google_cloud','workers_ai']",$new['repo']);
    $new['repo']=str_replace($withNone,"['azure','gemini','google_cloud','workers_ai','none']",$new['repo']);
    $replace($new['repo'],'Selecione Azure Translator, Google Gemini ou Google Cloud Translation.',
        'Selecione Azure, Gemini, Google Cloud ou Workers AI.','REPO_LABEL');

    $new['translator']=$old['translator'];
    if(substr_count($new['translator'],$plain)<2)throw new \RuntimeException('WORKERS_AI_TRANSLATOR_PROVIDER_ANCHORS');
    $new['translator']=str_replace($plain,"['azure','gemini','google_cloud','workers_ai']",$new['translator']);
    $replace($new['translator'],"'google_cloud'=>self::googleCloud(\$text,\$target,\$fallback,\$context),",
        "'google_cloud'=>self::googleCloud(\$text,\$target,\$fallback,\$context),\n            'workers_ai'=>WorkersAITranslation::attempt(\$text,\$target,\$fallback,\$context),",
        'TRANSLATOR_ATTEMPT');
    $replace($new['translator'],"'google_cloud'=>'Google Cloud Translation',",
        "'google_cloud'=>'Google Cloud Translation','workers_ai'=>'Cloudflare Workers AI',",
        'TRANSLATOR_LABEL');

    $new['index']=$old['index'];
    $replace($new['index'],
        "} else throw new RuntimeException('Provedor de tradução inválido.');",
        "} elseif(\$provider==='workers_ai'){\n".
        "                \$newToken=trim((string)(\$_POST['workers_ai_api_token']??''));\n".
        "                \$account=trim((string)(\$_POST['workers_ai_account_id']??''));\n".
        "                \$model=trim((string)(\$_POST['workers_ai_model']??''));\n".
        "                if(\$account!==''&&!preg_match('/^[a-f0-9]{32}\$/Di',\$account)) throw new RuntimeException('ID da conta Cloudflare inválido.');\n".
        "                if(\$model!==''&&!\\App\\WorkersAITranslation::validModel(\$model)) throw new RuntimeException('Modelo Workers AI inválido.');\n".
        "                if(\$newToken!=='') Repository::saveIntegration('workers_ai_api_token',\$newToken);\n".
        "                if(\$account!=='') Repository::saveIntegration('workers_ai_account_id',\$account);\n".
        "                if(\$model!=='') Repository::saveIntegration('workers_ai_model',\$model);\n".
        "                \$notice='Workers AI atualizado com segurança.';\n".
        "            } else throw new RuntimeException('Provedor de tradução inválido.');",
        'SAVE_PROVIDER');

    $replace($new['index'],"            \$test=TranslationService::testProvider(\$provider,\$overrides);",
        "            if(\$provider==='workers_ai'){\n".
        "                foreach(['workers_ai_api_token','workers_ai_account_id','workers_ai_model'] as \$field){\n".
        "                    \$value=trim((string)(\$_POST[\$field]??''));\n".
        "                    if(\$value!=='')\$overrides[\$field]=\$value;\n".
        "                }\n".
        "            }\n".
        "            \$test=TranslationService::testProvider(\$provider,\$overrides);",
        'TEST_PROVIDER');

    $replace($new['index'],
        "\$primaryProvider=Repository::translationPrimaryProvider();",
        "\$workersAIKey=\\App\\WorkersAITranslation::token();\n".
        "\$workersAIAccount=\\App\\WorkersAITranslation::account();\n".
        "\$primaryProvider=Repository::translationPrimaryProvider();",
        'INTEGRATION_VARS');
    // integrations v10 rebuilds the summary below, including Workers AI.
    // Do not patch the obsolete summary markup from the production snapshot.

    $matchCount=0;
    $new['index']=preg_replace_callback(
        '~(<select\b[^>]*\bname="(translation_provider|translation_primary_provider|translation_fallback_provider)"[^>]*>)(.*?)(</select>)~s',
        static function(array $m)use(&$matchCount): string{
            $matchCount++;
            if(str_contains($m[3],'value="workers_ai"'))throw new \RuntimeException('WORKERS_AI_ALREADY_IN_SELECT');
            $selected=match($m[2]){
                'translation_provider'=>"\$formRule['translation_provider']==='workers_ai'",
                'translation_primary_provider'=>"\$primaryProvider==='workers_ai'",
                default=>"\$fallbackProvider==='workers_ai'"
            };
            return $m[1].$m[3].'<option value="workers_ai" <?=('.$selected.')?\'selected\':\'\'?>>Cloudflare Workers AI</option>'.$m[4];
        },$new['index']);
    if($matchCount!==3)throw new \RuntimeException('WORKERS_AI_SELECT_ANCHORS');

    $replace($new['index'],
        "</div>\n<script>document.addEventListener('DOMContentLoaded',()=>{const p=document.getElementById('translation-primary')",
        "<?php require __DIR__.'/app/workers-ai-card.php'; ?>\n</div>\n<script>document.addEventListener('DOMContentLoaded',()=>{const p=document.getElementById('translation-primary')",
        'PROVIDER_CARD');
    $replace($new['index'],'</head>',
        '<link rel="stylesheet" href="/assets/brand/workers-ai-provider.css?v=1"></head>',
        'PROVIDER_CSS');

    // Strict syntax checks on private candidates before any persistent mutation.
    // Keep fresh installs compatible with the added provider as well.
    $new['schema']=$old['schema'];
    if(substr_count($new['schema'],"ENUM('azure','gemini','google_cloud') NOT NULL DEFAULT 'azure'")!==1)
        throw new \RuntimeException('WORKERS_AI_SCHEMA_RULE_ANCHOR');
    $new['schema']=str_replace("ENUM('azure','gemini','google_cloud') NOT NULL DEFAULT 'azure'",
        "ENUM('azure','gemini','google_cloud','workers_ai') NOT NULL DEFAULT 'azure'",$new['schema']);
    if(substr_count($new['schema'],"provider ENUM('azure','gemini','google_cloud') NOT NULL")!==1)
        throw new \RuntimeException('WORKERS_AI_SCHEMA_ATTEMPT_ANCHOR');
    $new['schema']=str_replace("provider ENUM('azure','gemini','google_cloud') NOT NULL",
        "provider ENUM('azure','gemini','google_cloud','workers_ai') NOT NULL",$new['schema']);

    foreach($paths as $key=>$dest){
        $temp=$dest.'.workers-candidate';
        if(@file_put_contents($temp,$new[$key])===false)throw new \RuntimeException('WORKERS_AI_WRITE_'.$key);
        $lint=[];$exit=0;
        exec('php -l '.escapeshellarg($temp).' 2>&1',$lint,$exit);
        if($exit!==0)throw new \RuntimeException('WORKERS_AI_LINT_'.$key);
    }
    if(getenv('WORKERS_AI_TEST_ONLY')!=='1'){
        require_once $root.'/bootstrap.php';
        $pdo=\App\Database::pdo();
        // ENUM contains three existing providers. Append one without deleting
        // a single existing rule, metric, or integration.
        foreach([
            ['router_rules','translation_provider',"ENUM('azure','gemini','google_cloud','workers_ai') NOT NULL DEFAULT 'azure'"],
            ['translation_attempts','provider',"ENUM('azure','gemini','google_cloud','workers_ai') NOT NULL"]
        ] as [$table,$column,$definition]){
            $status=$pdo->query("SHOW COLUMNS FROM \`$table\` LIKE ".$pdo->quote($column))->fetch();
            if(!is_array($status)||!isset($status['Type']))throw new \RuntimeException('WORKERS_AI_DB_COLUMN_'.$table);
            if(!str_contains(strtolower((string)$status['Type']),"'workers_ai'")){
                $pdo->exec("ALTER TABLE \`$table\` MODIFY COLUMN \`$column\` $definition");
            }
        }
    }
    foreach($paths as $key=>$dest){
        if(!@rename($dest.'.workers-candidate',$dest))
            throw new \RuntimeException('WORKERS_AI_APPLY_'.$key);
    }
    echo "WORKERS_AI_INSTALLED\n";
} catch(\Throwable $e){
    foreach($paths as $dest)@unlink($dest.'.workers-candidate');
    // Never print submitted credentials or upstream responses.
    error_log('WORKERS_AI_INSTALL_SKIPPED '.preg_replace('/[^A-Z0-9_]/','_',substr($e->getMessage(),0,90)));
    echo "WORKERS_AI_SKIPPED_PREVIOUS_CONFIGURATION_PRESERVED\n";
}
