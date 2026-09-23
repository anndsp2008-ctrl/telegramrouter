<?php declare(strict_types=1);
/**
 * Display only. Runs after all startup restorations and UI patches.
 * Authentication credentials and login username remain unchanged.
 */
$path=__DIR__.'/index.php';
$source=@file_get_contents($path);
if(!is_string($source)){fwrite(STDERR,"TMR_GREETING_INDEX_MISSING\n");exit(1);}

$avatarPattern='~<span class="saas-avatar">(?:AD|AN)</span>\s*<span>(?:<\?=sh\(config\(\x27panel_username\x27\)\)\?>|<\?=sh\(\$saudacaoNome\)\?>|Anderson)</span>~';
$replacement='<span class="saas-avatar">AN</span><span><?=sh($saudacaoNome)?></span>';
$source=preg_replace($avatarPattern,$replacement,$source,1,$count);
if($source===null||$count!==1){fwrite(STDERR,"TMR_GREETING_HEADER_NOT_FOUND\n");exit(1);}

$init="\$saudacaoHora=(int)(new \\DateTimeImmutable('now',new \\DateTimeZone('America/Sao_Paulo')))->format('G');\n"
    ."\$saudacaoNome=(\$saudacaoHora<12?'Bom dia':(\$saudacaoHora<18?'Boa tarde':'Boa noite')).', Anderson';\n";
if(!str_contains($source,'$saudacaoNome=(')){
    $anchor="Auth::requireLogin();\n";
    if(substr_count($source,$anchor)!==1){fwrite(STDERR,"TMR_GREETING_INIT_ANCHOR_NOT_FOUND\n");exit(1);}
    $source=str_replace($anchor,$anchor.$init,$source);
}

$temp=$path.'.greeting-candidate';
if(@file_put_contents($temp,$source)===false){fwrite(STDERR,"TMR_GREETING_WRITE_FAILED\n");exit(1);}
exec('php -l '.escapeshellarg($temp).' 2>&1',$lint,$code);
if($code!==0){@unlink($temp);fwrite(STDERR,"TMR_GREETING_LINT_FAILED ".implode(" ",$lint)."\n");exit(1);}
if(!@rename($temp,$path)){fwrite(STDERR,"TMR_GREETING_RENAME_FAILED\n");exit(1);}
echo "TMR_GREETING_RUNTIME_APPLIED\n";
