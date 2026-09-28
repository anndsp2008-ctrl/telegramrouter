<?php declare(strict_types=1);
// CARD_MODELS_RELEASE_20260927_V1

$root=__DIR__;
$smartPath=$root.'/app/SmartFormatting.php';
$doublePath=$root.'/app/DoubleVipCardRenderer.php';
$adaptivePath=$root.'/app/AdaptiveVipCardRenderer.php';

foreach([$smartPath,$doublePath,$adaptivePath] as $path){
    if(!is_file($path)){fwrite(STDERR,'CARD_MODELS_SOURCE_MISSING '.basename($path)."\n");exit(1);}
}

$smart=file_get_contents($smartPath);
$double=file_get_contents($doublePath);
if(!is_string($smart)||!is_string($double)){fwrite(STDERR,"CARD_MODELS_READ_FAILED\n");exit(1);}

$replaceOne=static function(string $source,string $old,string $new,string $label): string {
    $count=substr_count($source,$old);
    if($count!==1){
        throw new RuntimeException('CARD_MODELS_ANCHOR_'.$label.'_COUNT_'.$count);
    }
    return str_replace($old,$new,$source);
};

try{
    if(!str_contains($smart,"require_once __DIR__.'/AdaptiveVipCardRenderer.php';")){
        $smart=$replaceOne(
            $smart,
            "require_once __DIR__.'/DoubleVipCardRenderer.php';",
            "require_once __DIR__.'/DoubleVipCardRenderer.php';\nrequire_once __DIR__.'/AdaptiveVipCardRenderer.php';",
            'REQUIRE'
        );
    }

    if(!str_contains($smart,"'double_legs','card_legs'")){
        $smart=$replaceOne(
            $smart,
            "'visual_market_evidence','visual_selection_evidence','double_legs'];",
            "'visual_market_evidence','visual_selection_evidence','double_legs','card_legs'];",
            'FIELDS'
        );
    }

    if(!str_contains($smart,'AdaptiveVipCardRenderer::extractionInstruction()')){
        $needle="self::multipleScopeInstruction(\$hasImage).DoubleVipCardRenderer::extractionInstruction().";
        $count=substr_count($smart,$needle);
        if($count!==3)throw new RuntimeException('CARD_MODELS_PROMPT_ANCHOR_COUNT_'.$count);
        $smart=str_replace(
            $needle,
            "self::multipleScopeInstruction(\$hasImage).DoubleVipCardRenderer::extractionInstruction().AdaptiveVipCardRenderer::extractionInstruction().",
            $smart
        );
    }

    $directBlock=<<<'PHP'
        if($mode==='card' && !$hasImage
            && self::sourceIndicatesMultiple($sourceText)
            && !DoubleVipCardRenderer::sourceIsDouble($sourceText)){
            self::$multipleDetected=true;
            self::$aiFinishedAt=microtime(true);
            self::diag('MULTIPLE_TEXT_DIRECT_CONTINGENCY');
            return null;
        }
PHP;
    if(str_contains($smart,$directBlock)){
        $smart=str_replace(
            $directBlock,
            "        // CARD_MODELS_V1: text-only multiple tips continue through structured AI extraction.\n",
            $smart
        );
    }

    if(!str_contains($smart,'CARD_MODELS_V1_ADAPTIVE_RENDER')){
        $anchor="                if(\$mode==='card' && DoubleVipCardRenderer::isDouble(\$json,\$hasImage)){";
        if(substr_count($smart,$anchor)!==1)throw new RuntimeException('CARD_MODELS_ADAPTIVE_ANCHOR_MISMATCH');

        $adaptive=<<<'PHP'
                /** CARD_MODELS_V1_ADAPTIVE_RENDER */
                if($mode==='card'){
                    $adaptive=AdaptiveVipCardRenderer::extract($json,$sourceText,$hasImage);
                    if($adaptive!==null){
                        if(($adaptive['kind']??'simple')!=='simple')self::$multipleDetected=true;

                        $localizedLegs=[];
                        foreach($adaptive['legs'] as $leg){
                            $localized=self::enforcePortugueseOutput([
                                'sport'=>(string)($adaptive['sport']??''),
                                'league'=>(string)($leg['league']??''),
                                'market'=>(string)($leg['market']??''),
                                'selection'=>(string)($leg['selection']??''),
                                'analysis'=>''
                            ],$rule,$translate);
                            if($localized===null){$localizedLegs=[];break;}
                            $leg['league']=(string)($localized['league']??$leg['league']);
                            $leg['market']=(string)($localized['market']??$leg['market']);
                            $leg['selection']=(string)($localized['selection']??$leg['selection']);
                            if(($adaptive['sport']??'')==='' && ($localized['sport']??'')!==''){
                                $adaptive['sport']=(string)$localized['sport'];
                            }
                            $localizedLegs[]=$leg;
                        }

                        if(count($localizedLegs)===count($adaptive['legs'])){
                            $adaptive['legs']=$localizedLegs;
                            $first=$adaptive['legs'][0];
                            $analysisHolder=[
                                'sport'=>(string)($adaptive['sport']??''),
                                'league'=>(string)($first['league']??''),
                                'market'=>(string)($first['market']??''),
                                'selection'=>(string)($first['selection']??''),
                                'analysis'=>trim((string)($json['analysis']??''))
                            ];
                            $analysisHolder=self::enforcePortugueseOutput($analysisHolder,$rule,$translate);
                            if($analysisHolder!==null){
                                $analysisHolder=self::preserveSourceAnalysisOutput(
                                    $analysisHolder,$sourceAnalysis,$rule,$translate
                                );
                            }
                            if($analysisHolder!==null && $sourceAnalysis==='' && trim((string)($analysisHolder['analysis']??''))!==''){
                                $analysisHolder['analysis']=self::correctGeneratedAnalysis(
                                    (string)$analysisHolder['analysis'],$sourceText
                                );
                            }
                            if($analysisHolder!==null){
                                $analysisHolder['analysis']=self::sanitizeAnalysis(
                                    (string)($analysisHolder['analysis']??'')
                                );
                                $adaptive['analysis']=(string)$analysisHolder['analysis'];
                                if(($adaptive['sport']??'')==='')$adaptive['sport']=(string)($analysisHolder['sport']??'');
                            }

                            if($analysisHolder!==null && trim((string)($adaptive['analysis']??''))!==''){
                                $caption=AdaptiveVipCardRenderer::caption($adaptive);
                                $captionUnits=(int)(strlen(mb_convert_encoding($caption,'UTF-16LE','UTF-8'))/2);
                                $renderStarted=microtime(true);
                                $image=$captionUnits<=4700?AdaptiveVipCardRenderer::render($adaptive):null;
                                self::$renderMs=max(0,(int)round((microtime(true)-$renderStarted)*1000));
                                if($image!==null){
                                    self::$aiFinishedAt=microtime(true);
                                    self::recordProviderAttempt(
                                        $provider,$attemptStarted,$attemptFailureOffset,true,$logicalAttempt
                                    );
                                    self::diag('ADAPTIVE_CARD_READY_'.strtoupper((string)$adaptive['kind']));
                                    error_log('TMR_CARD_MODEL_READY '.json_encode([
                                        'kind'=>(string)$adaptive['kind'],
                                        'bookmaker_key'=>(string)($adaptive['bookmaker_key']??'unknown'),
                                        'legs'=>count($adaptive['legs']),
                                        'has_date'=>count(array_filter($adaptive['legs'],static fn(array $leg): bool=>trim((string)($leg['date']??''))!==''))
                                    ],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE));
                                    return ['caption'=>$caption,'image'=>$image,'mode'=>'card'];
                                }
                                self::diag('ADAPTIVE_CARD_RENDER_FAILED');
                            }else{
                                self::diag('ADAPTIVE_CARD_ANALYSIS_MISSING');
                            }
                        }else{
                            self::diag('ADAPTIVE_CARD_LOCALIZATION_FAILED');
                        }
                    }
                }

PHP;
        $smart=str_replace($anchor,$adaptive.$anchor,$smart);
    }

    $oldDouble='Para duplas sem análise do autor, analysis deve ser vazio, prevalecendo sobre a instrução de gerar análise. ';
    if(str_contains($double,$oldDouble)){
        $double=str_replace(
            $oldDouble,
            'Para duplas sem análise do autor, analysis deve seguir a política global de análise e ser gerada normalmente. ',
            $double
        );
    }

    $candidates=[
        $smartPath.'.card-models-candidate'=>$smart,
        $doublePath.'.card-models-candidate'=>$double
    ];
    foreach($candidates as $path=>$source){
        if(file_put_contents($path,$source)===false)throw new RuntimeException('CARD_MODELS_WRITE_FAILED');
        $out=[];$status=0;
        exec('php -l '.escapeshellarg($path).' 2>&1',$out,$status);
        if($status!==0)throw new RuntimeException('CARD_MODELS_LINT_FAILED '.implode(' ',$out));
    }

    if(!rename($smartPath.'.card-models-candidate',$smartPath))throw new RuntimeException('CARD_MODELS_SMART_REPLACE_FAILED');
    if(!rename($doublePath.'.card-models-candidate',$doublePath))throw new RuntimeException('CARD_MODELS_DOUBLE_REPLACE_FAILED');

    $out=[];$status=0;
    exec('php -l '.escapeshellarg($adaptivePath).' 2>&1',$out,$status);
    if($status!==0)throw new RuntimeException('CARD_MODELS_RENDERER_LINT_FAILED '.implode(' ',$out));

    echo "TMR_CARD_MODELS_V1_APPLIED\n";
}catch(Throwable $e){
    @unlink($smartPath.'.card-models-candidate');
    @unlink($doublePath.'.card-models-candidate');
    fwrite(STDERR,$e->getMessage()."\n");
    exit(1);
}
