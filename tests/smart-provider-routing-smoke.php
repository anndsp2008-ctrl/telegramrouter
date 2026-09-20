<?php declare(strict_types=1);
namespace App {
    final class TranslationService {
        public static function resolveProviders(string $ruleProvider): array {
            return match($ruleProvider){
                'workers_ai'=>['workers_ai','gemini'],
                'gemini'=>['gemini','workers_ai'],
                'azure'=>['azure','workers_ai'],
                'google_cloud'=>['google_cloud',null],
                default=>['workers_ai','gemini']
            };
        }
    }
}
namespace {
    require __DIR__.'/../app/SmartFormatting.php';
    foreach([
        [['translation_enabled'=>1,'translation_provider'=>'workers_ai'],['workers_ai','gemini']],
        [['translation_enabled'=>1,'translation_provider'=>'gemini'],['gemini','workers_ai']],
        [['translation_enabled'=>1,'translation_provider'=>'azure'],['azure','workers_ai']],
        [['translation_enabled'=>1,'translation_provider'=>'google_cloud'],['google_cloud']],
        [['translation_enabled'=>1,'translation_provider'=>''],['workers_ai','gemini']],
        [['translation_enabled'=>0,'translation_provider'=>'workers_ai'],['workers_ai','gemini']],
        [['translation_enabled'=>0,'translation_provider'=>'azure'],['azure','workers_ai']]
    ] as [$rule,$expected]){
        $actual=\App\SmartFormatting::cardProviders($rule);
        if($actual!==$expected)
            throw new \RuntimeException('Smart card ignored translation primary/fallback selection');
    }
    $source=(string)file_get_contents(__DIR__.'/../app/SmartFormatting.php');
    $transport=(string)file_get_contents(__DIR__.'/../scripts/smart-workers-isolated.php');
    if(!str_contains($source,'foreach($providers as $provider)')||
       !str_contains($source,'if($provider===\'workers_ai\')')||
       !str_contains($source,'elseif($provider===\'gemini\')')||
       !str_contains($source,'PROVIDER_NOT_GENERATIVE_')||
       !str_contains($source,'ALL_CONFIGURED_PROVIDERS_FAILED')||
       !str_contains($source,'$candidate=self::sentenceCaseBet($candidate)')||
       !str_contains($source,'$candidate[\'analysis\']=self::ensureSportsAnalysis')||
       !str_contains($source,'$bet=$candidate')||
       !str_contains($source,'WorkersAITranslation::VISION_MODEL')||
       !str_contains($transport,"'type'=>'image_url'")||
       !str_contains($transport,"'image_url'=>['url'=>\$image]")||
       !str_contains($transport,'parseTip($response)')){
        throw new \RuntimeException('Workers AI visual/text extraction or fallback pipeline missing');
    }
    foreach(['RESPONSE_MISSING_TEXT','RESPONSE_NOT_JSON'] as $reason){
        if(!\App\SmartFormatting::workerVisualRescueEligible($reason))
            throw new \RuntimeException('Expected visual rescue eligibility');
    }
    foreach(['HTTP_401_UNAUTHORIZED','HTTP_429','HTTP_503','TIMEOUT','MODEL_LICENSE_REQUIRED_5016'] as $reason){
        if(\App\SmartFormatting::workerVisualRescueEligible($reason))
            throw new \RuntimeException('Visual rescue must not bypass provider access errors');
    }
    if(!str_contains($source,'WORKERS_AI_VISION_RESCUE_STARTED')||
       !str_contains($source,'WORKERS_AI_VISION_RESCUE_SUCCEEDED')||
       !str_contains($source,'WORKERS_VISION_RESCUE_MODEL')||
       !str_contains($transport,'guided_json')){
        throw new \RuntimeException('Cloudflare visual rescue is not wired through the same provider');
    }
    // Text tips cannot spend 75 seconds invoking the default Vision model.
    // Verify the 8B text model uses the established chat payload, including
    // the retry path; photo-only Vision behavior must remain in place.
    if(!str_contains($source,'?WorkersAITranslation::PREVIOUS_DEFAULT_MODEL:$configuredModel')||
       !str_contains($transport,"'messages'=>[['role'=>'user','content'=>")||
       !str_contains($transport,'$timeouts=[25,10]')||
       !str_contains($transport,"['messages'][0]['content']=")||
       !str_contains($transport,'in_array($http,[500,502,503,504],true)')){
        throw new \RuntimeException('Text model routing, bounded retry or 429 fail-fast missing');
    }
    $geminiTransport=(string)file_get_contents(__DIR__.'/../scripts/smart-gemini-isolated.php');
    if(!str_contains($geminiTransport,'in_array($http,[500,502,503,504],true)')||
       str_contains($geminiTransport,'in_array($http,[429,500,502,503,504],true)')){
        throw new \RuntimeException('Gemini HTTP 429 is still retried immediately');
    }
    echo "SMART_TEXT_8B_TIMEOUT_AND_429_TESTS_PASSED\n";
    // The image-only failing case (non-JSON primary, incomplete Scout)
    // must attempt a SAME-CLOUDFLARE text structuring pass only when the
    // Vision outputs contain usable observations. No new provider or raw send.
    if(!str_contains($source,'WORKERS_AI_TEXT_RESCUE_STARTED')||
       !str_contains($source,'WORKERS_AI_TEXT_RESCUE_SUCCEEDED')||
       !str_contains($source,'WORKERS_AI_TEXT_RESCUE_FAILED')||
       !str_contains($source,'$visionEvidence!==\'\'')||
       !str_contains($source,'$forcedTextModel')||
       !str_contains($source,'WorkersAITranslation::PREVIOUS_DEFAULT_MODEL);')||
       !str_contains($transport,'collectVisualEvidence($result,$visualEvidence)')||
       !str_contains($transport,"'evidence'=>")||
       !str_contains($transport,'reply(false,$reason,null,$visualEvidence')){
        throw new \RuntimeException('Same-account Vision evidence rescue not wired');
    }
    // Regression for message #1437: primary Vision returned non-JSON with
    // image evidence, then Scout timed out before the fallback could finish.
    // The already observed content must be sent to the same-account text
    // formatter BEFORE any second Vision inference is attempted.
    $textFirst=strpos($source,'$textRescueAttempted=true;');
    $scoutNext=strpos($source,'if($index===0 && isset($models[1]))continue;');
    if($textFirst===false || $scoutNext===false || $textFirst>$scoutNext ||
       !str_contains($source,"'evidence_rescue'=>\$forcedTextModel!==null") ||
       !str_contains($source,'$visionEvidence!==\'\' && !$textRescueAttempted') ||
       !str_contains($transport,'if($image!==null && $visualEvidence!==\'\'') ||
       !str_contains($transport,'reply(false,$reason,null,$visualEvidence);') ||
       !str_contains($transport,'$timeouts=[24,8]') ||
       !str_contains($transport,'$timeouts=[14,5]') ||
       !str_contains($transport,'$timeouts=[12,5]')){
        throw new \RuntimeException('First Vision evidence is not recovered before bounded Scout fallback');
    }
    // Regression for message #1441: image extraction must not request a
    // lengthy, newly invented analysis before match/market/selection are known.
    // If SAME-account text evidence recovery times out, stop serial Vision
    // rescues and reach the configured Gemini fallback without a raw send.
    if(!str_contains($source,'if($hasImage || $forcedTextModel!==null)') ||
       !str_contains($source,'Não crie nova análise neste passo') ||
       !str_contains($source,'$textTimedOut=end(self::$failureCodes)') ||
       !str_contains($source,'WORKERS_AI_VISION_RESCUE_SKIPPED_AFTER_TEXT_TIMEOUT') ||
       !str_contains($source,'if($textTimedOut){') ||
       !str_contains($source,'return null;') ||
       !str_contains($transport,'$payload[\'max_tokens\']=strlen($prompt)>6500?2300:1700;') ||
       !str_contains($transport,'$payload[\'max_tokens\']=strlen($prompt)>6500?1650:1150;')){
        throw new \RuntimeException('Message #1441 bounded image extraction / fallback guards missing');
    }
    echo "SMART_IMAGE_1441_EXTRACTION_FIRST_AND_FALLBACK_GUARDS_PASSED\n";
    echo "SMART_IMAGE_1437_EVIDENCE_FIRST_AND_TIMEOUT_GUARDS_PASSED\n";
    echo "WORKERS_AI_VISION_EVIDENCE_TEXT_RESCUE_TESTS_PASSED\n";
    echo "WORKERS_AI_VISION_RESCUE_TESTS_PASSED\n";
    echo "SMART_PROVIDER_PRIMARY_FALLBACK_TESTS_PASSED\n";
}
