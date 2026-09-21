<?php declare(strict_types=1);
/**
 * Opt-in production overlay for the live SmartFormatting.php reconstructed
 * by the Railway start command.  This module never changes routing, card
 * rendering, translations, provider order, or existing UI files.
 *
 * The model continues to run without memory unless AI_LEARNING_ENABLED=1.
 * The installer fails closed on unknown source rather than attempting a
 * best-effort patch of an unverified production file.
 */
$path=__DIR__.'/app/SmartFormatting.php';
$memoryClass=__DIR__.'/app/AiLearningMemory.php';
if(!is_file($memoryClass)||!is_file($path)){
    fwrite(STDERR,"AI_LEARNING_FILES_MISSING\n");return;
}
$original=file_get_contents($path);
if(!is_string($original)){
    fwrite(STDERR,"AI_LEARNING_SOURCE_UNREADABLE\n");return;
}
$marker='TMR_AI_LEARNING_CONTEXT_V1';
if(str_contains($original,$marker)){
    echo "AI_LEARNING_ALREADY_INSTALLED\n";
    return;
}
// The first production release already ships the seven memory-context changes
// in app/SmartFormatting.php, so the older unpatched hash is NOT expected there.
// Accept that exact, validated source as already wired; do not patch it twice.
$sourceHash=substr(hash('sha256',$original),0,12);
if($sourceHash==='aef5fc1567f6'){
    $wiredAnchors=[
        'AiLearningMemory::contextFor($sourceText,(int)($rule[\'id\']??0))',
        'self::requestWorkers($sourceText,$localImage,$inputLanguage,$fields,null,$memoryExamples)',
        'self::request($key,$sourceText,$localImage,$inputLanguage,$fields,$memoryExamples)',
        '($memoryExamples!==\'\'?$memoryExamples:\'\')',
    ];
    foreach($wiredAnchors as $anchor){
        if(!str_contains($original,$anchor)){
            fwrite(STDERR,"AI_LEARNING_PREWIRED_ANCHOR_MISSING\n");return;
        }
    }
    echo "AI_LEARNING_RUNTIME_READY_ALREADY_WIRED\n";
    return;
}
// A separately supported older runtime needs the guarded overlay below.
// Unknown formatter versions are never rewritten.
$expectedPrefix='c05edc1c025c';
if($sourceHash!==$expectedPrefix){
    fwrite(STDERR,"AI_LEARNING_BASE_HASH_MISMATCH\n");return;
}
$replace=function(string $before,string $after,string $label) use (&$original): void {
    if(substr_count($original,$before)!==1){
        throw new RuntimeException('AI_LEARNING_ANCHOR_MISMATCH_'.$label);
    }
    $original=str_replace($before,$after,$original);
};
try {
    $replace(
        "        // Use the EXACT primary/fallback resolution from the translation rule.",
        "        // TMR_AI_LEARNING_CONTEXT_V1: approved context only; never modifies the original tip.\n".
        "        \$memoryExamples=AiLearningMemory::contextFor(\$sourceText,(int)(\$rule['id']??0));\n".
        "        // Use the EXACT primary/fallback resolution from the translation rule.",
        'BEFORE_PROVIDERS'
    );
    $replace(
        'self::requestWorkers($sourceText,$localImage,$inputLanguage,$fields);',
        'self::requestWorkers($sourceText,$localImage,$inputLanguage,$fields,null,$memoryExamples);',
        'WORKERS_CALL'
    );
    $replace(
        'self::request($key,$sourceText,$localImage,$inputLanguage,$fields);',
        'self::request($key,$sourceText,$localImage,$inputLanguage,$fields,$memoryExamples);',
        'GEMINI_CALL'
    );
    $replace(
        'private static function requestWorkers(string $text,?string $image,string $language,array $fields,?string $forcedTextModel=null): ?array',
        'private static function requestWorkers(string $text,?string $image,string $language,array $fields,?string $forcedTextModel=null,string $memoryExamples=\'\'): ?array',
        'WORKERS_SIGNATURE'
    );
    $replace(
        '            $language." TEXTO ORIGINAL:\n".$text;',
        '            $language." ".($memoryExamples!==\'\'?$memoryExamples:\'\')." TEXTO ORIGINAL:\n".$text;',
        'WORKERS_PROMPT'
    );
    $replace(
        'private static function request(string $key,string $text,?string $image,string $language,array $fields): ?array',
        'private static function request(string $key,string $text,?string $image,string $language,array $fields,string $memoryExamples=\'\'): ?array',
        'GEMINI_SIGNATURE'
    );
    $replace(
        '            "Não mencione o nome do roteador no JSON. ".$language." ".'. "\n".
        '            "TEXTO ORIGINAL:\n".$text;',
        '            "Não mencione o nome do roteador no JSON. ".$language." ".'. "\n".
        '            ($memoryExamples!==\'\'?$memoryExamples:\'\').'. "\n".
        '            "TEXTO ORIGINAL:\n".$text;',
        'GEMINI_PROMPT'
    );
    $temp=$path.'.ai-learning-candidate';
    if(file_put_contents($temp,$original,LOCK_EX)===false)throw new RuntimeException('AI_LEARNING_WRITE_FAILED');
    $output=[];$status=0;
    exec('php -l '.escapeshellarg($temp).' 2>&1',$output,$status);
    if($status!==0)throw new RuntimeException('AI_LEARNING_LINT_FAILED');
    if(!rename($temp,$path))throw new RuntimeException('AI_LEARNING_REPLACE_FAILED');
    echo "AI_LEARNING_RUNTIME_READY\n";
}catch(Throwable $e){
    if(isset($temp)&&is_file($temp))unlink($temp);
    fwrite(STDERR,preg_replace('/[^A-Z0-9_]/','_',strtoupper($e->getMessage()))."\n");
    // Never replace the original script on an unknown runtime.
}
