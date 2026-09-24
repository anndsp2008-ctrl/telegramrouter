<?php declare(strict_types=1);

/**
 * Replaces the legacy Microsoft Azure provider slot with OpenAI while keeping
 * the remaining integrations, routing UI and database structure intact.
 *
 * The live database is migrated safely:
 * - existing Azure rules become OpenAI rules;
 * - historical Azure telemetry is preserved;
 * - new rule/provider writes use OpenAI.
 */
$root=__DIR__;
$files=[
    'index'=>$root.'/index.php',
    'repository'=>$root.'/app/Repository.php',
    'translation'=>$root.'/app/TranslationService.php',
    'schema'=>$root.'/database/schema.sql',
];
$cssPath=$root.'/assets/brand/integrations-v10.css';
foreach($files as $label=>$path){
    if(!is_file($path)){
        fwrite(STDERR,'OPENAI_REPLACE_MISSING_'.strtoupper($label)."\n");
        return;
    }
}
if(!is_file($root.'/app/OpenAIProvider.php')){
    fwrite(STDERR,"OPENAI_REPLACE_PROVIDER_CLASS_MISSING\n");
    return;
}

$replaceOne=static function(string $source,string $old,string $new,string $label): string {
    $count=substr_count($source,$old);
    if($count!==1)throw new RuntimeException('OPENAI_REPLACE_ANCHOR_'.$label.'_COUNT_'.$count);
    return str_replace($old,$new,$source);
};

$index=(string)file_get_contents($files['index']);
$repository=(string)file_get_contents($files['repository']);
$translation=(string)file_get_contents($files['translation']);
$schema=(string)file_get_contents($files['schema']);
$css=is_file($cssPath)?(string)file_get_contents($cssPath):'';

try{
    if(!str_contains($repository,"'openai'")){
        $repository=str_replace(
            "['azure','gemini','google_cloud','workers_ai']",
            "['openai','gemini','google_cloud','workers_ai']",
            $repository
        );
        $repository=str_replace(
            "['azure','gemini','google_cloud','workers_ai','none']",
            "['openai','gemini','google_cloud','workers_ai','none']",
            $repository
        );
        $repository=str_replace(
            "'Selecione Azure, Gemini, Google Cloud ou Workers AI.'",
            "'Selecione OpenAI, Gemini, Google Cloud ou Workers AI.'",
            $repository
        );
        $repository=str_replace("?\$value:'azure';","?\$value:'openai';",$repository);
    }

    if(!str_contains($translation,"'openai'=>self::openAI")){
        $translation=str_replace(
            "['azure','gemini','google_cloud','workers_ai']",
            "['openai','gemini','google_cloud','workers_ai']",
            $translation
        );
        $translation=str_replace(
            "['azure','gemini','google_cloud','workers_ai','none']",
            "['openai','gemini','google_cloud','workers_ai','none']",
            $translation
        );
        $translation=str_replace(
            "'azure'=>self::azure(\$text,\$source,\$target,\$fallback,\$context),",
            "'openai'=>self::openAI(\$text,\$target,\$fallback,\$context),",
            $translation
        );
        $translation=str_replace(
            "'azure'=>'Azure Translator'",
            "'openai'=>'OpenAI GPT-5.6'",
            $translation
        );

        $openaiMethod=<<<'PHP'

    private static function openAI(string $text,string $target,bool $fallback,array $context): array
    {
        $overrides=(array)($context['overrides']??[]);
        $key=trim((string)($overrides['openai_api_key']??OpenAIProvider::apiKey()));
        $modelOverride=array_key_exists('openai_model',$overrides)
            ?trim((string)$overrides['openai_model'])
            :null;
        if($key==='') throw self::failure('openai',0,null,$target,'OpenAI não configurada: informe a API Key no módulo Integrações.',$fallback);
        if($modelOverride!==null && !OpenAIProvider::validModel($modelOverride)) throw self::failure('openai',0,null,$target,'Modelo OpenAI inválido.',$fallback);
        if(!OpenAIProvider::enabled() && empty($overrides)) throw self::failure('openai',0,null,$target,'OpenAI está desativada no módulo Integrações.',$fallback);

        $result=OpenAIProvider::translate($text,$target,$key,$modelOverride);
        $latency=(int)($result['latency_ms']??0);
        $http=isset($result['http_code'])?(int)$result['http_code']:null;
        if(empty($result['ok'])){
            $reason=(string)($result['reason']??'OPENAI_FAILED');
            throw self::failure('openai',$latency,$http,$target,'OpenAI recusou ou não concluiu a solicitação: '.$reason,$fallback);
        }
        return [
            'provider'=>'openai','success'=>true,'text'=>(string)($result['text']??''),
            'latency_ms'=>$latency,'http_code'=>$http,'source_language'=>null,
            'target_language'=>$target,'error_text'=>null,'fallback_used'=>$fallback
        ];
    }
PHP;
        $anchor="\n    private static function azure(string \$text,string \$source,string \$target,bool \$fallback,array \$context): array\n";
        if(!str_contains($translation,$anchor))throw new RuntimeException('OPENAI_REPLACE_TRANSLATION_METHOD_ANCHOR');
        $translation=str_replace($anchor,$openaiMethod.$anchor,$translation);

        $translation=preg_replace(
            "~if \(\$primary==='gemini' && \$fallback===null\) \{.*?\n        \}~s",
            "if (\$primary==='gemini' && \$fallback===null) {\n            \$fallbackNote=trim(OpenAIProvider::apiKey())===''\n                ? 'OpenAI não configurada.' : 'OpenAI não habilitada como fallback em Integrações.';\n        }",
            $translation,
            1
        )??$translation;
        $translation=preg_replace(
            "~if \(\$isFallback && \$provider==='azure'.*?\n            \}~s",
            "if (\$isFallback && \$provider==='openai' && trim(OpenAIProvider::apiKey())==='') {\n                \$fallbackNote='OpenAI não configurada.';\n                continue;\n            }",
            $translation,
            1
        )??$translation;
    }

    // Backend save/test branches: provider id is OpenAI and model controls are isolated
    // to the former Azure slot.
    if(!str_contains($index,"\$provider==='openai'")){
        $index=preg_replace(
            '~if\s*\(\s*\$provider\s*===\s*\'azure\'\s*\)\s*\{.*?\}\s*elseif\s*\(\s*\$provider\s*===\s*\'gemini\'\s*\)\s*\{~s',
            "if(\$provider==='openai'){\n".
            "                \$newKey=trim((string)(\$_POST['openai_api_key']??''));\n".
            "                \$enabled=isset(\$_POST['openai_enabled'])?'1':'0';\n".
            "                \$model=trim((string)(\$_POST['openai_model']??'gpt-5.6-luna'));\n".
            "                \$fallbackEnabled=isset(\$_POST['openai_fallback_enabled'])?'1':'0';\n".
            "                \$fallbackModel=trim((string)(\$_POST['openai_fallback_model']??'gpt-5.6-terra'));\n".
            "                if(!\\App\\OpenAIProvider::validModel(\$model)) throw new RuntimeException('Modelo principal OpenAI inválido.');\n".
            "                if(!\\App\\OpenAIProvider::validModel(\$fallbackModel)) throw new RuntimeException('Modelo de fallback OpenAI inválido.');\n".
            "                if(\$newKey!=='') Repository::saveIntegration('openai_api_key',\$newKey);\n".
            "                Repository::saveIntegration('openai_enabled',\$enabled);\n".
            "                Repository::saveIntegration('openai_model',\$model);\n".
            "                Repository::saveIntegration('openai_fallback_enabled',\$fallbackEnabled);\n".
            "                Repository::saveIntegration('openai_fallback_model',\$fallbackModel);\n".
            "                \$notice='OpenAI atualizada com segurança.';\n".
            "            } elseif(\$provider==='gemini'){",
            $index,1,$saveCount
        )??$index;
        if(($saveCount??0)!==1)throw new RuntimeException('OPENAI_REPLACE_INDEX_SAVE');

        $index=preg_replace(
            '~if\s*\(\s*\$provider\s*===\s*\'azure\'\s*\)\s*\{.*?\}\s*elseif\s*\(\s*\$provider\s*===\s*\'gemini\'\s*\)\s*\{~s',
            "if(\$provider==='openai'){\n".
            "                foreach(['openai_api_key','openai_model'] as \$field){\$value=trim((string)(\$_POST[\$field]??''));if(\$value!=='')\$overrides[\$field]=\$value;}\n".
            "            } elseif(\$provider==='gemini'){",
            $index,1,$testCount
        )??$index;
        if(($testCount??0)!==1)throw new RuntimeException('OPENAI_REPLACE_INDEX_TEST');
    }

    $oldVars="\$azureKey=Repository::integration('azure_translator_api_key');\n\$azureRegion=Repository::integration('azure_translator_region');\n\$azureEndpoint=Repository::integration('azure_translator_endpoint')?:'https://api.cognitive.microsofttranslator.com';";
    $newVars="\$openaiKey=\\App\\OpenAIProvider::apiKey();\n\$openaiEnabled=\\App\\OpenAIProvider::enabled();\n\$openaiModel=\\App\\OpenAIProvider::primaryModel();\n\$openaiFallbackEnabled=\\App\\OpenAIProvider::fallbackEnabled();\n\$openaiFallbackModel=\\App\\OpenAIProvider::fallbackModel();";
    if(str_contains($index,$oldVars))$index=str_replace($oldVars,$newVars,$index);

    $index=str_replace("\$azureStats=Repository::translationProviderStats('azure');","\$openaiStats=Repository::translationProviderStats('openai');",$index);
    $index=str_replace("\$azureTest=Repository::providerTestStatus('azure');","\$openaiTest=Repository::providerTestStatus('openai');",$index);
    $index=str_replace('!empty($azureKey)','!empty($openaiKey)',$index);

    $index=str_replace('<option value="azure" <?=$formRule[\'translation_provider\']===\'azure\'?\'selected\':\'\'?>>Microsoft Azure Translator</option>',
        '<option value="openai" <?=$formRule[\'translation_provider\']===\'openai\'?\'selected\':\'\'?>>OpenAI GPT-5.6</option>',$index);
    $index=str_replace('<option value="azure" <?=$primaryProvider===\'azure\'?\'selected\':\'\'?>>Microsoft Azure Translator</option>',
        '<option value="openai" <?=$primaryProvider===\'openai\'?\'selected\':\'\'?>>OpenAI GPT-5.6</option>',$index);
    $index=str_replace('<option value="azure" <?=$fallbackProvider===\'azure\'?\'selected\':\'\'?>>Microsoft Azure Translator</option>',
        '<option value="openai" <?=$fallbackProvider===\'openai\'?\'selected\':\'\'?>>OpenAI GPT-5.6</option>',$index);

    $openaiCard=<<<'HTML'
<section class="saas-card translation-provider-card openai-provider-card">
  <div class="provider-head">
    <div><span class="saas-kicker">OPENAI</span><h2>OpenAI GPT-5.6</h2>
      <p>Interpretação multimodal e tradução dos cards do Telegram Router.</p></div>
    <span class="form-status <?=$openaiKey&&$openaiEnabled?'is-configured':'is-empty'?>"><i></i><?=$openaiKey&&$openaiEnabled?'Configurado':'Não configurado'?></span>
  </div>
<form method="post" class="provider-form"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="provider" value="openai"><div class="saas-form-grid">
<label class="field-wide">API Key<span class="field-help"><?=$openaiKey?'Atual: '.sh(TranslationService::maskSecret($openaiKey)).'. Digite uma nova chave apenas para substituir.':'Informe uma API Key da OpenAI.'?></span><input name="openai_api_key" type="password" autocomplete="new-password" placeholder="<?=$openaiKey?'••••••••••••••••':'Cole a API Key da OpenAI'?>"></label>
<label class="field-wide openai-toggle"><input type="checkbox" name="openai_enabled" value="1" <?=$openaiEnabled?'checked':''?>><span class="openai-toggle-copy"><b>Ativar OpenAI</b><small>Habilita este provedor para interpretação, tradução e geração dos cards.</small></span></label>
<fieldset class="field-wide openai-models"><legend>Modelo principal</legend>
<label><input type="radio" name="openai_model" value="gpt-5.6-luna" <?=$openaiModel==='gpt-5.6-luna'?'checked':''?>><span><b>GPT-5.6 Luna</b><small>Econômico · processamento rápido e menor custo.</small></span></label>
<label><input type="radio" name="openai_model" value="gpt-5.6-terra" <?=$openaiModel==='gpt-5.6-terra'?'checked':''?>><span><b>GPT-5.6 Terra</b><small>Equilíbrio entre custo e interpretação de bilhetes complexos.</small></span></label>
<label><input type="radio" name="openai_model" value="gpt-5.6-sol" <?=$openaiModel==='gpt-5.6-sol'?'checked':''?>><span><b>GPT-5.6 Sol</b><small>Modelo avançado para interpretação complexa.</small></span></label>
</fieldset>
<label class="field-wide openai-toggle"><input type="checkbox" name="openai_fallback_enabled" value="1" <?=$openaiFallbackEnabled?'checked':''?>><span class="openai-toggle-copy"><b>Ativar fallback automático</b><small>Utiliza o modelo secundário caso o principal falhe.</small></span></label>
<label class="field-wide">Modelo de fallback<span class="field-help">Usado somente se o modelo principal não concluir a solicitação.</span><select name="openai_fallback_model"><option value="gpt-5.6-luna" <?=$openaiFallbackModel==='gpt-5.6-luna'?'selected':''?>>GPT-5.6 Luna</option><option value="gpt-5.6-terra" <?=$openaiFallbackModel==='gpt-5.6-terra'?'selected':''?>>GPT-5.6 Terra</option><option value="gpt-5.6-sol" <?=$openaiFallbackModel==='gpt-5.6-sol'?'selected':''?>>GPT-5.6 Sol</option></select></label>
</div><div class="provider-actions"><button class="saas-primary" name="action" value="save_translation_provider">Salvar</button><button class="saas-secondary" name="action" value="test_translation_provider">Testar conexão</button></div></form>
<div class="provider-test <?=$openaiTest?((int)$openaiTest['last_test_ok']?'ok':'bad'):'neutral'?>"><b>Último teste</b><span><?=$openaiTest?(int)$openaiTest['last_test_ok']?'Conexão válida':'Falha':'Ainda não testado'?></span><small><?=$openaiTest?sh(dataHoraBrasil($openaiTest['last_test_at'])).' · '.(int)$openaiTest['last_test_latency_ms'].' ms':'—'?></small><?php if($openaiTest&&!$openaiTest['last_test_ok']&&!empty($openaiTest['last_test_error'])):?><em><?=sh($openaiTest['last_test_error'])?></em><?php endif;?></div>
<div class="provider-stats"><div><span>Traduções</span><b><?=sh($openaiStats['total'])?></b></div><div><span>Sucessos</span><b><?=sh($openaiStats['successful'])?></b></div><div><span>Falhas</span><b><?=sh($openaiStats['failures'])?></b></div><div><span>Latência média</span><b><?=sh($openaiStats['avg_latency'])?> ms</b></div><div><span>Última latência</span><b><?=sh($openaiStats['last_latency'])?> ms</b></div><div><span>Último uso</span><b><?=sh(dataHoraBrasil($openaiStats['last_use']))?></b></div></div></section>
HTML;

    if(str_contains($index,'MICROSOFT AZURE')){
        $index=preg_replace(
            '~<section class="saas-card translation-provider-card"><div class="saas-card-head"><div><span class="saas-kicker">MICROSOFT AZURE</span>.*?</section>\s*(?=<section class="saas-card translation-provider-card"><div class="saas-card-head"><div><span class="saas-kicker">GOOGLE AI</span>)~s',
            $openaiCard."\n",
            $index,1,$cardCount
        )??$index;
        if(($cardCount??0)!==1)throw new RuntimeException('OPENAI_REPLACE_INDEX_CARD');
    }

    // The base source archive may still contain the former Azure CSS selectors.
    // Patch only that provider slot so OpenAI inherits exactly the same card
    // grid, badge, icon and responsive behavior as the other integrations.
    if($css!==''){
        $css=str_replace(
            '.translation-provider-grid>*:has(input[name="provider"][value="azure"])',
            '.translation-provider-grid>*:has(input[name="provider"][value="openai"])',
            $css
        );
        $css=str_replace('content:"AZ"!important','content:"AI"!important',$css);

        // The release snapshot does not contain the OpenAI form controls.
        // Reapply only their scoped styles after startup restores that snapshot.
        if(!str_contains($css,'/* OpenAI form layout v2')){
            $css.=<<<'CSS'

/* OpenAI form layout v2 — scoped to this integration only */
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-models{
  display:block!important;
  box-sizing:border-box!important;
  width:100%!important;
  min-width:0!important;
  max-width:100%!important;
  margin:0!important;
  padding:14px 16px!important;
  border:1px solid rgba(148,163,184,.16)!important;
  border-radius:14px!important;
}
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-models legend{
  padding:0 7px!important;
  color:#e5eef8!important;
  font-size:13px!important;
  font-weight:700!important;
}
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-models > label,
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-toggle{
  display:grid!important;
  grid-template-columns:20px minmax(0,1fr)!important;
  grid-auto-rows:auto!important;
  align-items:start!important;
  justify-items:stretch!important;
  column-gap:12px!important;
  width:100%!important;
  min-width:0!important;
  max-width:100%!important;
  margin:0!important;
  padding:13px 4px!important;
  box-sizing:border-box!important;
  cursor:pointer!important;
  text-align:left!important;
}
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-models > label + label{
  border-top:1px solid rgba(148,163,184,.10)!important;
}
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-models > label > input[type="radio"],
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-toggle > input[type="checkbox"]{
  appearance:auto!important;
  display:block!important;
  box-sizing:border-box!important;
  width:18px!important;
  min-width:18px!important;
  max-width:18px!important;
  height:18px!important;
  min-height:18px!important;
  max-height:18px!important;
  padding:0!important;
  margin:2px 0 0!important;
  justify-self:start!important;
  align-self:start!important;
  flex:0 0 18px!important;
  background:initial!important;
  border:initial!important;
  box-shadow:none!important;
  accent-color:#3b82f6;
}
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-models > label > span,
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-toggle > .openai-toggle-copy{
  display:flex!important;
  flex-direction:column!important;
  align-items:flex-start!important;
  gap:4px!important;
  width:auto!important;
  min-width:0!important;
  max-width:100%!important;
  margin:0!important;
  padding:0!important;
  text-align:left!important;
  white-space:normal!important;
  overflow-wrap:break-word!important;
}
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-models > label > span > b,
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-toggle > .openai-toggle-copy > b{
  display:block!important;
  margin:0!important;
  color:#eaf2ff!important;
  font-size:13px!important;
  font-weight:700!important;
  line-height:1.4!important;
  white-space:normal!important;
}
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-models > label > span > small,
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-toggle > .openai-toggle-copy > small{
  display:block!important;
  width:auto!important;
  min-width:0!important;
  max-width:100%!important;
  margin:0!important;
  color:rgba(203,213,225,.65)!important;
  font-size:12px!important;
  line-height:1.45!important;
  white-space:normal!important;
  overflow-wrap:break-word!important;
}
body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-toggle{
  border-radius:10px!important;
  background:rgba(148,163,184,.035)!important;
  padding:12px!important;
}
@media(max-width:640px){
  body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-models{
    padding:11px 12px!important;
  }
  body:has(.translation-provider-grid) .translation-provider-grid > .openai-provider-card .provider-form .openai-models > label{
    padding:12px 0!important;
  }
}

CSS;
        }

    }

    // Fresh installs use OpenAI instead of Azure. Existing telemetry keeps the
    // historical Azure enum value so old rows are never coerced or deleted.
    $schema=str_replace(
        "translation_provider ENUM('azure','gemini','google_cloud','workers_ai') NOT NULL DEFAULT 'azure'",
        "translation_provider ENUM('openai','gemini','google_cloud','workers_ai') NOT NULL DEFAULT 'openai'",
        $schema
    );
    $schema=str_replace(
        "provider ENUM('azure','gemini','google_cloud','workers_ai') NOT NULL",
        "provider ENUM('azure','openai','gemini','google_cloud','workers_ai') NOT NULL",
        $schema
    );

    $candidates=[
        $files['index']=>$index,
        $files['repository']=>$repository,
        $files['translation']=>$translation,
        $files['schema']=>$schema,
    ];
    if($css!=='')$candidates[$cssPath]=$css;
    $temps=[];
    foreach($candidates as $dest=>$body){
        $temp=$dest.'.openai-candidate';
        if(file_put_contents($temp,$body)===false)throw new RuntimeException('OPENAI_REPLACE_WRITE');
        if(str_ends_with($dest,'.php')){
            $lint=[];$status=0;
            exec('php -l '.escapeshellarg($temp).' 2>&1',$lint,$status);
            if($status!==0)throw new RuntimeException('OPENAI_REPLACE_LINT '.implode(' ',$lint));
        }
        $temps[$dest]=$temp;
    }
    foreach($temps as $dest=>$temp){
        if(!rename($temp,$dest))throw new RuntimeException('OPENAI_REPLACE_RENAME');
    }

    require_once $root.'/bootstrap.php';
    $pdo=\App\Database::pdo();

    // Temporarily allow both values, migrate live rules, then remove Azure from
    // new rule writes. Historical translation_attempts keeps Azure for audit.
    $pdo->exec("ALTER TABLE router_rules MODIFY translation_provider ENUM('azure','openai','gemini','google_cloud','workers_ai') NOT NULL DEFAULT 'openai'");
    $pdo->exec("UPDATE router_rules SET translation_provider='openai' WHERE translation_provider='azure'");
    $pdo->exec("ALTER TABLE router_rules MODIFY translation_provider ENUM('openai','gemini','google_cloud','workers_ai') NOT NULL DEFAULT 'openai'");
    $pdo->exec("ALTER TABLE translation_attempts MODIFY provider ENUM('azure','openai','gemini','google_cloud','workers_ai') NOT NULL");

    if(\App\Repository::integration('translation_primary_provider')==='azure'){
        \App\Repository::saveIntegration('translation_primary_provider','openai');
    }
    if(\App\Repository::integration('translation_fallback_provider')==='azure'){
        \App\Repository::saveIntegration('translation_fallback_provider','openai');
    }

    // Conservative default: provider stays off until the user explicitly saves
    // the OpenAI card with an API key.
    if(\App\Repository::integration('openai_enabled')===''){
        \App\Repository::saveIntegration('openai_enabled','0');
    }
    if(\App\Repository::integration('openai_model')===''){
        \App\Repository::saveIntegration('openai_model','gpt-5.6-luna');
    }
    if(\App\Repository::integration('openai_fallback_enabled')===''){
        \App\Repository::saveIntegration('openai_fallback_enabled','1');
    }
    if(\App\Repository::integration('openai_fallback_model')===''){
        \App\Repository::saveIntegration('openai_fallback_model','gpt-5.6-terra');
    }

    echo "OPENAI_REPLACED_AZURE_READY\n";
}catch(Throwable $e){
    foreach(glob($root.'/*.openai-candidate')?:[] as $tmp)@unlink($tmp);
    foreach(glob($root.'/app/*.openai-candidate')?:[] as $tmp)@unlink($tmp);
    fwrite(STDERR,'OPENAI_REPLACE_FAILED '.$e->getMessage()."\n");
    throw $e;
}
