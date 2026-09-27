<?php declare(strict_types=1);
require __DIR__.'/../app/Results/SettlementEngine.php';
use App\Results\SettlementEngine as S;
function check(string $name,array $bet,array $stats,string $status,float $profit):void{$r=S::settle($bet,$stats);if($r['status']!==$status||abs($r['profit_units']-$profit)>0.0001){fwrite(STDERR,"FAIL $name ".json_encode($r)."\n");exit(1);}echo "OK $name\n";}
check('over goals green',['market'=>'goals_total','side'=>'over','line'=>2.5,'odds'=>1.5],['goals_total'=>3],S::GREEN,5);
check('over corners red',['market'=>'corners_total','side'=>'over','line'=>8.5,'odds'=>1.6],['corners_total'=>8],S::RED,-10);
check('integer push',['market'=>'corners_total','side'=>'over','line'=>8.0,'odds'=>1.7],['corners_total'=>8],S::VOID,0);
check('asian 8.25 half green',['market'=>'corners_total','side'=>'over','line'=>8.25,'odds'=>1.8],['corners_total'=>9],S::GREEN,8);
check('asian 8.75 half green',['market'=>'corners_total','side'=>'over','line'=>8.75,'odds'=>1.8],['corners_total'=>9],S::HALF_GREEN,4);
check('missing stat',['market'=>'fouls_total','side'=>'over','line'=>20.5,'odds'=>1.5],[],S::PENDING_REVIEW,0);
echo "Settlement tests passed.\n";
