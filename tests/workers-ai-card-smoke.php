<?php declare(strict_types=1);
namespace App {
    final class WorkersAITranslation {
        public static function token(): string {return 'secret-test-token-not-for-output';}
        public static function account(): string {return '0123456789abcdef0123456789abcdef';}
        public static function model(): string {return '@cf/meta/llama-3.1-8b-instruct-fp8';}
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
    if(str_contains($output,'secret-test-token-not-for-output'))
        throw new \RuntimeException('Provider card exposed secret');
    if(substr_count($output,'class="saas-card translation-provider-card')!==1)
        throw new \RuntimeException('Provider layout differs from existing cards');
    // v10 headers use four grid cells: icon (pseudo-element), content,
    // configuration badge, and chevron (pseudo-element). A generic
    // form-status breaks this grid by stretching across the whole row.
    if(!preg_match('/<div class="provider-head">\\s*<div>.*?<\\/div>\\s*<span class="provider-badge is-configured">\\s*Configurado\\s*<\\/span>\\s*<\\/div>/s',$output))
        throw new \RuntimeException('Workers AI header does not match the v10 provider grid');
    if(str_contains($output,'class="form-status"') || str_contains($output,'class="saas-card-head"'))
        throw new \RuntimeException('Workers AI is using legacy header/badge classes');
    $css=(string)file_get_contents(__DIR__.'/../assets/brand/workers-ai-provider.css');
    if(!str_contains($css,'content:"CF"!important') || !str_contains($css,'--provider-accent:#f48120!important'))
        throw new \RuntimeException('Workers AI provider icon or color absent');
    echo "WORKERS_AI_CARD_V10_VISUAL_PARITY_TESTS_PASSED\n";
    echo "WORKERS_AI_CARD_RENDER_TESTS_PASSED\n";
}
