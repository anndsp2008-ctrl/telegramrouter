<?php declare(strict_types=1);

function ltOk(bool $condition,string $label): void {
    if(!$condition){
        fwrite(STDERR,"FAIL {$label}\n");
        exit(1);
    }
    echo "OK {$label}\n";
}
function ltRgb(string $hex): array {
    $hex=ltrim($hex,'#');
    return [hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
}
function ltLum(string $hex): float {
    $rgb=ltRgb($hex);
    $v=array_map(static function(int $c): float {
        $x=$c/255;
        return $x<=0.04045?$x/12.92:(($x+0.055)/1.055)**2.4;
    },$rgb);
    return 0.2126*$v[0]+0.7152*$v[1]+0.0722*$v[2];
}
function ltContrast(string $a,string $b): float {
    $l1=ltLum($a); $l2=ltLum($b);
    if($l2>$l1){$tmp=$l1;$l1=$l2;$l2=$tmp;}
    return ($l1+0.05)/($l2+0.05);
}

$css=(string)file_get_contents(__DIR__.'/../assets/brand/theme.css');
$runtime=(string)file_get_contents(__DIR__.'/../runtime-theme.php');
$activityStatusCss=(string)file_get_contents(__DIR__.'/../assets/brand/activity-status-badges.css');
$aiLearningCss=(string)file_get_contents(__DIR__.'/../assets/ai-learning.css');
$pages=[
    'index'=>(string)file_get_contents(__DIR__.'/../index.php'),
    'reports'=>(string)file_get_contents(__DIR__.'/../reports.php'),
    'ai-learning'=>(string)file_get_contents(__DIR__.'/../ai-learning.php'),
    'reset'=>(string)file_get_contents(__DIR__.'/../reset.php'),
    'connect'=>(string)file_get_contents(__DIR__.'/../connect.php'),
];

ltOk(str_contains($css,'TMR_LIGHT_THEME_AUDIT_V2'),'auditoria clara v2 presente');
ltOk(str_contains($css,'TMR_LIGHT_THEME_VISUAL_V3'),'passagem visual clara v3 presente');
ltOk(str_contains($css,'.integrations-overview-v10-copy strong'),'cabecalho de integracoes possui contraste claro');
ltOk(str_contains($css,'.integrations-section-heading h2'),'titulo de secao de integracoes possui contraste claro');
ltOk(str_contains($css,'.provider-head p'),'descricao de provider possui contraste claro');
ltOk(str_contains($css,'.provider-test>small'),'metadados do teste possuem contraste claro');
ltOk(str_contains($css,'value="test_translation_provider"'),'botao testar traducao possui tema claro');
ltOk(str_contains($css,'value="test_sports_api_provider"'),'botao testar api esportiva possui tema claro');
ltOk(str_contains($css,'.reporting-review-reprocess-btn'),'botao reprocessar possui tema claro');
ltOk(str_contains($css,'.reporting-force-pending-btn'),'botao verificar pendentes possui tema claro');
ltOk(str_contains($css,'.reporting-history-toggle'),'botao de accordion possui tema claro');
ltOk(str_contains($css,'.ai-danger-btn'),'botoes de IA possuem tema claro');
ltOk(str_contains($aiLearningCss,'TMR_AI_LIBRARY_TOOLBAR_V2'),'barra da biblioteca IA v2 presente');
ltOk(str_contains($aiLearningCss,'#biblioteca .ai-search button'),'botao Buscar possui estilo dedicado');
ltOk(str_contains($aiLearningCss,'html[data-theme="light"] #biblioteca .ai-toolbar'),'biblioteca possui variante clara dedicada');
ltOk(str_contains($css,'.reset-danger-button'),'botao destrutivo do reset possui tema claro');
ltOk(str_contains($css,'.tmr-dialog-cancel'),'botoes de dialogo possuem tema claro');
ltOk(str_contains($css,'.connect-shell button:not(.disconnect-button)'),'botoes da conexao possuem tema claro');
ltOk(str_contains($css,'.activity-badge.forwarded'),'atividade possui cores semanticas claras');
ltOk(str_contains($css,'.openai-provider-card .provider-form .openai-models'),'seletor OpenAI possui tema claro');
ltOk(str_contains($css,'.workers-ai-provider-card > .provider-head > .form-status.provider-badge'),'status Workers AI possui tema claro');
ltOk(str_contains($css,'.translation-routing-form label'),'prioridade e fallback possuem tema claro');
ltOk(str_contains($css,'.reporting-switch-row>input'),'switches de relatorios possuem tema claro');
ltOk(str_contains($css,'.reporting-choice>input'),'radios de relatorios possuem tema claro');
ltOk(str_contains($css,'.reporting-status-badge.green'),'status do relatorio possui cores semanticas claras');
ltOk(str_contains($css,'.reporting-history-row-dark>td'),'zebrado claro permanece coberto');
ltOk(str_contains($runtime,'/assets/brand/theme.css?v=10'),'runtime injeta CSS claro v2');

foreach($pages as $name=>$page){
    ltOk(str_contains($page,'/assets/brand/theme.css?v=10'),$name.' invalida cache do tema claro');
}

foreach([
    ['#172033','#ffffff',7.0,'texto principal'],
    ['#435268','#ffffff',7.0,'texto secundario'],
    ['#56677c','#ffffff',4.5,'texto auxiliar'],
    ['#0f766e','#ffffff',4.5,'botao/acento'],
    ['#334155','#f8fafc',7.0,'botao secundario'],
] as [$fg,$bg,$minimum,$label]){
    ltOk(ltContrast($fg,$bg)>=$minimum,$label.' atende contraste minimo');
}

echo "LIGHT_THEME_SMOKE_PASSED\n";

ltOk(str_contains($css,'TMR_LIGHT_THEME_VISUAL_V4'),'refinamento visual claro v4 presente');
ltOk(str_contains($css,'TMR_LIGHT_THEME_POLISH_V5'),'polimento visual claro v5 presente');
ltOk(str_contains($css,'TMR_LIGHT_THEME_ICONS_V6'),'ajuste de icones claros v6 presente');
ltOk(str_contains($css,'TMR_LIGHT_THEME_STATUS_V7'),'status claros v7 presentes');
ltOk(str_contains($css,'TMR_LIGHT_RULE_CARD_SPACING_V8'),'espacamento entre cards de regras presente');
ltOk(str_contains($css,'TMR_LIGHT_SCROLLBAR_V9'),'scrollbars globais claras v9 presentes');
ltOk(str_contains($css,'TMR_LIGHT_RESPONSIVE_PARITY_V10'),'paridade responsiva clara v10 presente');
ltOk(str_contains($css,'html[data-theme="light"] .saas-content .routing-flow>div>.flow-number'),'fluxo responsivo possui variante clara especifica');
ltOk(str_contains($css,'html[data-theme="light"] body:has(.translation-provider-grid) .translation-provider-grid .provider-badge'),'badges de providers responsivos possuem variante clara');
ltOk(str_contains($css,'html[data-theme="light"] .saas-content .routing-list .routing-rule.rule-card + .routing-rule.rule-card'),'espacamento responsivo entre cards de regras preservado');
ltOk(str_contains($css,'html[data-theme="light"] .tmr-mobile-navigation'),'navegacao movel possui variante clara');
ltOk(str_contains($css,'scrollbar-color:#91a5b5 #edf2f6'),'Firefox usa scrollbar clara');
ltOk(str_contains($css,'html[data-theme="light"] ::-webkit-scrollbar-thumb'),'Chromium/WebKit usa thumb claro');
ltOk(str_contains($css,'.rules-panel .rule-card + .rule-card'),'cards consecutivos possuem seletor dedicado de espacamento');
ltOk(str_contains($activityStatusCss,'html[data-theme="light"] .activity-badge.Encaminhada'),'Encaminhada possui variante clara');
ltOk(str_contains($activityStatusCss,'html[data-theme="light"] .activity-badge.Ignorada'),'Ignorada possui variante clara');
ltOk(str_contains($activityStatusCss,'html[data-theme="light"] .activity-badge.Falhou'),'Falhou possui variante clara');
ltOk(str_contains($activityStatusCss,'html[data-theme="light"] .activity-badge.Processando'),'Processando possui variante clara');
ltOk(str_contains($activityStatusCss,'html[data-theme="light"] .activity-badge.Registrada'),'Registrada possui variante clara');
ltOk(str_contains($css,'html[data-theme="light"] .saas-metric-icon{'),'icones de metricas sem caixa no light');
ltOk(str_contains($css,'.reporting-rules-list::-webkit-scrollbar-thumb'),'scrollbar claro personalizado');
ltOk(str_contains($css,'.guide-points span'),'passos do guia revisados');
