<?php declare(strict_types=1);
/* Offline check of the deployed login after runtime-brand.php. No external calls. */
$loginPath=$argv[1]??'';
$login=$loginPath!==''?file_get_contents($loginPath):false;
if(!is_string($login))throw new RuntimeException('Login fixture unavailable');
if(substr_count($login,'src="/assets/tmr-logo.svg?v=4"')!==1
    ||str_contains($login,'src="/assets/tmr-logo.svg"'))
    throw new RuntimeException('Login still requests the unversioned logo cached by the PWA');
if(!str_contains($login,'<form') || !str_contains($login,'type="password"'))
    throw new RuntimeException('Login form markup changed unexpectedly');
$root=dirname($loginPath);
$canonical=$root.'/assets/brand/mark.svg';
$alias=$root.'/assets/tmr-logo.svg';
if(!is_file($canonical)||!is_file($alias)
    ||!hash_equals(hash_file('sha256',$canonical),hash_file('sha256',$alias)))
    throw new RuntimeException('Login logo alias does not match canonical mark');
echo "LOGIN_LOGO_CACHE_REGRESSION_PASSED\n";
