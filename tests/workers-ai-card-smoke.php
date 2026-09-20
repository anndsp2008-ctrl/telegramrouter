<?php declare(strict_types=1);
namespace App {
    final class WorkersAITranslation {
        public static function token(): string {return 'secret-test-token-not-for-output';}
        public static function account(): string {return '0123456789abcdef0123456789abcdef';}
        public static function model(): string {return '@cf/meta/llama-3.2-11b-vision-instruct';}
    }
    final class Repository {
        public static function providerTestStatus(string $provider): ?array {
            if($provider!=='workers_ai')throw new \RuntimeException('Wrong provider test');
            return null;
        }
        public static function translationProviderStats(string $provider): array {
            if($provider!=='workers_ai')throw new \RuntimeException('Wrong provider metrics');
            return ['total'=>0,'successful'=>0,'failures'=>0,'avg_latency'=>0,'last_latency'=>0,'last_use'=>null];
        }
    }
    final class TranslationService {
        public static function maskSecret(string $secret): string { return '••••••••••••'; }
    }
    final class Auth { public static function csrf(): string {return 'csrf-test-value';} }
}
namespace {
    function sh(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
    function dataHoraBrasil(mixed $date): string {return $date===null?'—':(string)$date;}
    ob_start();
    try {require __DIR__.'/../app/workers-ai-card.php';$output=(string)ob_get_clean();}
    catch(\Throwable $error){ob_end_clean();throw $error;}
    foreach(['Cloudflare','Workers AI','value="workers_ai"','name="workers_ai_api_token"',
             'name="workers_ai_account_id"','name="workers_ai_model"',
             'value="save_translation_provider"','value="test_translation_provider"',
             'provider-test','provider-stats','csrf-test-value'] as $required){
        if(!str_contains($output,$required))throw new \RuntimeException('Missing Workers AI control: '.$required);
    }
    if(!str_contains($output,'value="@cf/meta/llama-3.2-11b-vision-instruct"')||
       !str_contains($output,'Modelo de IA para tradução e interpretação'))
        throw new \RuntimeException('Workers AI UI is not set to Llama Vision model');
    if(!str_contains($output,'O teste verifica o modelo de texto e o modelo Vision')||
       !str_contains($output,'llama-3.2-11b-vision-instruct/'))
        throw new \RuntimeException('Workers AI test does not explain Vision model license');
    if(str_contains($output,'secret-test-token-not-for-output'))
        throw new \RuntimeException('Provider card exposed secret');
    if(substr_count($output,'class="saas-card translation-provider-card')!==1)
        throw new \RuntimeException('Provider layout differs from existing cards');
    // v10 headers use four grid cells: icon (pseudo-element), content,
    // configuration badge, and chevron (pseudo-element). A generic
    // form-status breaks this grid by stretching across the whole row.
    if(!preg_match('/<div class="provider-head">\s*<div>.*?<p class="workers-ai-observation">.*?<\/p><\/div>\s*<span class="form-status provider-badge is-configured">\s*Configurado\s*<\/span>\s*<\/div>/s',$output))
        throw new \RuntimeException('Workers AI explanation and badge are not inside the shared provider header');
    if(substr_count($output,'Se o Llama 3.2 Vision retornar uma imagem')!==1 ||
       str_contains($output,'<p class="field-help">Se o Llama 3.2 Vision')){
        throw new \RuntimeException('Workers AI observation is duplicated or outside the content column');
    }
    if(str_contains($output,'class="saas-card-head"'))
        throw new \RuntimeException('Workers AI provider header structure changed');
    $css=(string)file_get_contents(__DIR__.'/../assets/brand/workers-ai-provider.css');
    if(!str_contains($css,'content:"CF"!important') || !str_contains($css,'--provider-accent:#f48120!important'))
        throw new \RuntimeException('Workers AI provider icon or color absent');
    $installer=(string)file_get_contents(__DIR__.'/../runtime-workers-ai.php');
    if(!str_contains($installer,'workers-ai-provider.css?v=4') ||
       !str_contains($css,'> .form-status') ||
       str_contains($css,'.provider-badge.is-configured') ||
       str_contains($css,'.provider-badge.is-configured::before')){
        throw new \RuntimeException('Workers AI provider cache or status styling may be stale');
    }
    $layout=(string)file_get_contents(__DIR__.'/../assets/brand/integrations-v10.css');
    $responsive=(string)file_get_contents(__DIR__.'/../assets/brand/mobile-visual-audit.css');
    if(!str_contains($layout,'> div > .workers-ai-observation') ||
       !str_contains($layout,'> .form-status.provider-badge') ||
       !str_contains($responsive,'grid-column:3 / 4!important;') ||
       !str_contains($responsive,'grid-row:1 / 2!important;') ||
       !str_contains($responsive,'> .workers-ai-provider-card > .provider-head > div > .workers-ai-observation')){
        throw new \RuntimeException('Workers AI shared text column or compact tablet/mobile status alignment missing');
    }
    if(!str_contains($css,'> .provider-test.bad > em') ||
       !str_contains($css,'display:block!important;') ||
       !str_contains($css,'grid-column:1/-1!important;'))
        throw new \RuntimeException('Workers AI failed connection explanation still hidden by v10');
    echo "WORKERS_AI_CARD_V10_VISUAL_PARITY_TESTS_PASSED\n";
    echo "WORKERS_AI_CARD_RENDER_TESTS_PASSED\n";
}
