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
    foreach(['HTTP_401_UNAUTHORIZED','HTTP_429','MODEL_LICENSE_REQUIRED_5016'] as $reason){
        if(\App\SmartFormatting::workerVisualRescueEligible($reason))
            throw new \RuntimeException('Visual rescue must not bypass provider access errors');
    }
    echo "WORKERS_AI_VISION_RESCUE_TESTS_PASSED\n";
    echo "SMART_PROVIDER_PRIMARY_FALLBACK_TESTS_PASSED\n";
}
