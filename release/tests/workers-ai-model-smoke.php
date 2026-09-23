<?php declare(strict_types=1);
namespace App {
    final class Repository {
        public static string $model='';
        public static function integration(string $key): string {
            return $key==='workers_ai_model'?self::$model:'';
        }
    }
}
namespace {
    require __DIR__.'/../app/WorkersAITranslation.php';
    $class=\App\WorkersAITranslation::class;
    $vision='@cf/meta/llama-3.2-11b-vision-instruct';
    if($class::VISION_MODEL!==$vision||$class::DEFAULT_MODEL!==$vision)
        throw new \RuntimeException('Vision default constant incorrect');
    if($class::model()!==$vision)
        throw new \RuntimeException('New installation default is not Vision');
    \App\Repository::$model='@cf/meta/llama-3.1-8b-instruct-fp8';
    if($class::model()!==$vision)
        throw new \RuntimeException('Existing saved legacy default was not upgraded');
    \App\Repository::$model='@cf/qwen/qwen3-30b-a3b-fp8';
    if($class::model()!=='@cf/qwen/qwen3-30b-a3b-fp8')
        throw new \RuntimeException('Explicit custom provider model was overwritten');
    if($class::model(['workers_ai_model'=>$vision])!==$vision)
        throw new \RuntimeException('Manually selected Vision model not respected');
    echo "WORKERS_AI_VISION_MODEL_SELECTION_TESTS_PASSED\n";
}
