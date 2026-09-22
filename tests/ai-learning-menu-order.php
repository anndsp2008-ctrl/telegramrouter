<?php declare(strict_types=1);
// Verify the actual rendered navigation fragment; do not modify any menu entry.
$path=$argv[1]??'';
$html=$path!==''?@file_get_contents($path):false;
if(!is_string($html))throw new RuntimeException('MENU_FILE_UNREADABLE');
if(!preg_match('~<nav class="saas-nav">.*?</nav>~s',$html,$m))
    throw new RuntimeException('MENU_NOT_FOUND');
$nav=$m[0];
if(preg_match_all('~href="/ai-learning\\.php"~',$nav)!==1)
    throw new RuntimeException('LEARNING_LINK_COUNT_INVALID');
$rules='~href="/\\?page=rules"[^>]*>.*?</a>\\s*<a\\b[^>]*href="/ai-learning\\.php"[^>]*>.*?</a>~s';
if(!preg_match($rules,$nav))throw new RuntimeException('LEARNING_LINK_NOT_DIRECTLY_AFTER_RULES');
echo "AI_LEARNING_NAV_ORDER_PASSED\n";
