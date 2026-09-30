<?php declare(strict_types=1);

$root=dirname(__DIR__);
$checks=[
    $root.'/index.php'=>[
        "save_sports_api_provider",
        "test_sports_api_provider",
        "stake_odds_api_key",
        "api_football_key",
        "football_data_token",
        "football_data",
        "sports-api-provider-grid",
    ],
    $root.'/app/StakeOddsProvider.php'=>[
        "SportsApiIntegration::stakeKey()",
    ],
    $root.'/app/Reporting/ResultWorker.php'=>[
        "SportsApiIntegration::apiFootballKey()",
        "SportsApiIntegration::footballDataKey()",
        "FootballDataClient",
        "settlement_provider",
    ],
    $root.'/app/Reporting/FootballDataClient.php'=>[
        "X-Auth-Token:",
        "requestBudget",
        "statistics",
        "_provider",
    ],
    $root.'/assets/brand/integrations-v10.js'=>[
        "document.querySelectorAll('.translation-provider-grid')",
        "test_sports_api_provider",
    ],
    $root.'/assets/brand/integrations-v10.css'=>[
        'input[name="provider"][value="stake"]',
        'input[name="provider"][value="api_football"]',
    ],
];

foreach($checks as $path=>$needles){
    if(!is_file($path)) throw new RuntimeException('Missing file: '.$path);
    $body=(string)file_get_contents($path);
    foreach($needles as $needle){
        if(!str_contains($body,$needle)){
            throw new RuntimeException('Missing integration wiring: '.$needle.' in '.basename($path));
        }
    }
}

require_once $root.'/app/SportsApiIntegration.php';
$invalid=\App\SportsApiIntegration::test('invalid-provider');
if(($invalid['ok']??true)!==false){
    throw new RuntimeException('Invalid sports provider must fail closed in the test endpoint.');
}

echo "SPORTS_API_INTEGRATIONS_TESTS_PASSED\n";
