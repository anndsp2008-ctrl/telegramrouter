<?php declare(strict_types=1);
/** One synthetic dry-run of the optional AI formatter: no Telegram sends. */
require dirname(__DIR__).'/bootstrap.php';
try {
    $sample='Football Italy Serie A. Venezia vs Lazio. Full time result, Lazio to win. Odd 2.00. The tipster believes Lazio can win because Lazio arrives unbeaten and Venezia is at the bottom of the table. Saturday 19:45.';
    $rule=['translation_enabled'=>true,'translation_target_language'=>'pt-BR'];
    $r=\App\SmartFormatting::prepare($sample,$rule,null,'card');
    if($r!==null && is_string($r['image']??null)){
        @unlink($r['image']);
        echo "TMR_SMART_FORMAT_SYNTHETIC_OK\n";
    } else {
        echo "TMR_SMART_FORMAT_SYNTHETIC_FAILED\n";
    }
} catch(\Throwable $e){
    echo "TMR_SMART_FORMAT_SYNTHETIC_EXCEPTION ".preg_replace('/[^A-Za-z0-9_]/','_',get_class($e))."\n";
}
