<?php declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/app/AiLearningZipImporter.php';
\App\Auth::requireLogin();

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??null)==='logout'){
    \App\Auth::verifyCsrf($_POST['csrf']??null);
    \App\Auth::logout();
    header('Location:/login.php');
    exit;
}

$credentials=\App\Repository::credentials();
$connectedPhone=(string)($credentials['telegram_phone']??'');
if($connectedPhone!=='' && $connectedPhone[0]!=='+')$connectedPhone='+'.$connectedPhone;
$hour=(int)(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('G');
$greeting=($hour<12?'Bom dia':($hour<18?'Boa tarde':'Boa noite')).', Anderson';

$memory=new \App\AiLearningMemory(\App\Database::pdo());
$memory->migrate(); // Idempotent: this isolated admin page is the sole installer.
$directory=__DIR__.'/storage/ai-learning';
$fields=['sport'=>'Esporte','status'=>'Status da partida','match'=>'Confronto',
    'league'=>'Campeonato','market'=>'Mercado','selection'=>'Seleção','odd'=>'Odd',
    'time'=>'Horário','day'=>'Data ou dia','bookmaker'=>'Casa de apostas','analysis'=>'Análise original'];
$notice='';$error='';
if(isset($_GET['imported'])&&ctype_digit((string)$_GET['imported'])){
    $created=min(9,(int)$_GET['imported']);
    $skipped=min(9,max(0,(int)($_GET['skipped']??0)));
    $notice=$created.' exemplo(s) sintético(s) salvo(s) para revisão; '.$skipped.
        ' duplicado(s) ignorado(s). Nenhum exemplo foi aprovado automaticamente.';
}
function aiEscape(mixed $value): string {
    return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
if(isset($_GET['image'])){
    $record=$memory->get((int)$_GET['image']);
    if(!$record||empty($record['image_name'])){http_response_code(404);exit;}
    $name=(string)$record['image_name'];
    if(!preg_match('/^[a-f0-9]{32}\.(?:png|jpg|webp)\.enc$/D',$name)){http_response_code(404);exit;}
    $path=$directory.'/'.$name;
    if(!is_file($path)){http_response_code(404);exit;}
    try {
        $sealed=file_get_contents($path);
        if(!is_string($sealed))throw new RuntimeException('Imagem indisponível.');
        $plaintext=\App\AiLearningMemory::decryptImage($sealed);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($plaintext);
        if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('Imagem inválida.');
    }catch(Throwable $e){http_response_code(404);exit;}
    header('Content-Type: '.$mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $plaintext;
    exit;
}
// Upload 1-MB pieces because PHP's default file-upload limit is often 2 MB.
// CSRF + admin session are checked for every piece; ZIP bytes stay in /tmp.
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??null)==='import_zip_chunk'){
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try{
        \App\Auth::verifyCsrf($_POST['csrf']??null);
        $index=filter_var($_POST['index']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>0,'max_range'=>23]]);
        if($index===false||$index===null)throw new RuntimeException('Parte inválida do ZIP.');
        $file=$_FILES['chunk']??null;
        if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK
            ||(int)($file['size']??0)<1||(int)($file['size']??0)>1048576
            ||!is_uploaded_file((string)($file['tmp_name']??'')))
            throw new RuntimeException('Falha ao receber parte do ZIP.');
        if($index===0){
            if(isset($_SESSION['tmr_ai_zip_upload']['path']))
                @unlink((string)$_SESSION['tmr_ai_zip_upload']['path']);
            $path=sys_get_temp_dir().'/tmr-ai-zip-'.bin2hex(random_bytes(16)).'.part';
            if(file_put_contents($path,'',LOCK_EX)===false)throw new RuntimeException('Falha ao preparar importação.');
            @chmod($path,0600);
            $_SESSION['tmr_ai_zip_upload']=['path'=>$path,'next'=>0,'size'=>0,'at'=>time()];
        }
        $state=$_SESSION['tmr_ai_zip_upload']??null;
        if(!is_array($state)||($state['next']??null)!==$index
            ||time()-(int)($state['at']??0)>3600
            ||!str_starts_with((string)($state['path']??''),sys_get_temp_dir().'/tmr-ai-zip-'))
            throw new RuntimeException('Envio interrompido. Selecione o ZIP novamente.');
        $part=file_get_contents((string)$file['tmp_name']);
        if(!is_string($part)||strlen($part)!==(int)$file['size']
            ||(int)$state['size']+strlen($part)>24*1024*1024)
            throw new RuntimeException('Arquivo ZIP maior que o limite permitido.');
        if(file_put_contents($state['path'],$part,FILE_APPEND|LOCK_EX)!==strlen($part))
            throw new RuntimeException('Falha ao receber dados do ZIP.');
        $_SESSION['tmr_ai_zip_upload']['size']=(int)$state['size']+strlen($part);
        $_SESSION['tmr_ai_zip_upload']['next']=$index+1;
        $result=null;
        if(($_POST['last']??null)==='1'){
            unset($_SESSION['tmr_ai_zip_upload']);
            try{
                $importer=new \App\AiLearningZipImporter(\App\Database::pdo(),$memory);
                $result=$importer->import($state['path'],$directory,
                    (int)$state['size']+strlen($part));
            } finally { @unlink($state['path']); }
        }
        echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $e){
        http_response_code(400);
        $message=$e instanceof RuntimeException?$e->getMessage():'Não foi possível importar o ZIP.';
        echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try {
        \App\Auth::verifyCsrf($_POST['csrf']??null);
        $action=(string)($_POST['action']??'');
        if($action==='import_zip'){
            $zip=$_FILES['dataset_zip']??null;
            if(!is_array($zip)||($zip['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)
                throw new RuntimeException('Não foi possível receber o ZIP. Verifique o limite de upload do servidor.');
            $tmp=(string)($zip['tmp_name']??'');
            $size=(int)($zip['size']??0);
            if(!is_uploaded_file($tmp))throw new RuntimeException('Arquivo de importação inválido.');
            $importer=new \App\AiLearningZipImporter(\App\Database::pdo(),$memory);
            $result=$importer->import($tmp,$directory,$size);
            $notice=$result['created'].' exemplo(s) sintético(s) salvo(s) para revisão; '.
                $result['skipped'].' imagem(ns) já cadastrada(s) ignorada(s). Nenhum exemplo foi aprovado automaticamente.';
        }elseif($action==='save'){
            $file=$_FILES['image']??null; $filename=null;
            if(is_array($file)&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                if(($file['error']??null)!==UPLOAD_ERR_OK)throw new RuntimeException('Falha ao receber imagem.');
                if((int)($file['size']??0)>4*1024*1024 || (int)($file['size']??0)<=0)
                    throw new RuntimeException('A imagem deve ter até 4 MB.');
                $tmp=(string)($file['tmp_name']??'');
                if(!is_uploaded_file($tmp)||getimagesize($tmp)===false)
                    throw new RuntimeException('Imagem inválida.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
                if(!$ext)throw new RuntimeException('Aceitos apenas JPG, PNG e WebP.');
                if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))
                    throw new RuntimeException('Não foi possível preparar armazenamento.');
                $filename=bin2hex(random_bytes(16)).'.'.$ext.'.enc';
                $bytes=file_get_contents($tmp);
                if(!is_string($bytes))throw new RuntimeException('Não foi possível ler imagem.');
                $sealed=\App\AiLearningMemory::encryptImage($bytes);
                $target=$directory.'/'.$filename;
                if(file_put_contents($target,$sealed,LOCK_EX)===false)
                    throw new RuntimeException('Não foi possível salvar a imagem protegida.');
                chmod($target,0600);
            }
            try{
                $id=$memory->savePending((string)($_POST['source_text']??''),
                    (array)($_POST['label']??[]),$filename,
                    (int)($_POST['rule_id']??0)>0?(int)$_POST['rule_id']:null);
            }catch(Throwable $e){
                if($filename!==null && is_file($directory.'/'.$filename))unlink($directory.'/'.$filename);
                throw $e;
            }
            $notice='Exemplo #'.$id.' salvo para revisão; ainda não faz parte da memória.';
        }elseif($action==='review'){
            $decision=(string)($_POST['decision']??'');
            $memory->review((int)($_POST['id']??0),$decision,(array)($_POST['label']??[]),
                is_string($_POST['source_text']??null)?$_POST['source_text']:null);
            $notice=$decision==='approved'?'Exemplo aprovado e disponível para a memória.':'Exemplo rejeitado.';
        }else throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){
        $error=$e instanceof RuntimeException? $e->getMessage():'Não foi possível concluir esta operação.';
    }
}
$filter=is_string($_GET['status']??null)?$_GET['status']:'all';
if(!in_array($filter,['all','pending','approved','rejected'],true))$filter='all';
$search=is_string($_GET['q']??null)?trim($_GET['q']):'';
if(mb_strlen($search,'UTF-8')>120)$search=mb_substr($search,0,120,'UTF-8');
$page=max(1,(int)($_GET['p']??1));
$counts=$memory->statusCounts();
$library=$memory->browse($filter,$search,$page,12);
$examples=$library['items'];
function aiStatusLabel(string $status): string {
    return match($status){
        'approved'=>'Aprovado',
        'rejected'=>'Rejeitado',
        default=>'Aguardando revisão'
    };
}
function aiUrl(string $status,string $q,int $page=1): string {
    return '/ai-learning.php?'.http_build_query(['status'=>$status,'q'=>$q,'p'=>$page]);
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aprendizado da IA · Telegram Router</title><script src="/assets/brand/theme.js?v=1"></script>
<link rel="stylesheet" href="/assets/saas.css">
<link rel="stylesheet" href="/assets/brand/navigation-shell.css?v=1">
<link rel="stylesheet" href="/assets/responsive.css?v=4">
<link rel="stylesheet" href="/assets/brand/brand.css?v=2">
<link rel="stylesheet" href="/assets/brand/mobile-shell.css?v=2">
<link rel="stylesheet" href="/assets/brand/mobile-visual-audit.css?v=17">
<link rel="stylesheet" href="/assets/brand/mobile-bottom-navigation.css?v=2">
<link rel="stylesheet" href="/assets/brand/mobile-app-header.css?v=2">
<link rel="stylesheet" href="/assets/brand/logout-icon.css?v=1">
<link rel="stylesheet" href="/assets/brand/horizontal-scrollbar.css?v=1">
<link rel="stylesheet" href="/assets/brand/project-scrollbar.css?v=1">
<link rel="stylesheet" href="/assets/brand/smart-format.css?v=1">
<link rel="stylesheet" href="/assets/ai-learning.css?v=1">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMR">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/pwa/apple-touch-icon.png?v=1">
<script defer src="/assets/pwa.js?v=1"></script>
<link rel="stylesheet" href="/assets/brand/theme.css?v=6"></head>
<body>
<div class="saas-shell">
<?php $navActive='ai-learning'; require __DIR__.'/app/project-sidebar.php'; ?>
<div class="saas-main">
<header class="saas-topbar">
  <div class="tmr-app-header-brand" aria-label="TelegramRouter">
    <span class="tmr-app-brand-symbol"><img src="/assets/brand/mark.svg?v=1" alt=""></span>
    <span class="tmr-app-brand-name"><b>Telegram<span>Router</span></b><small>Conecte. Direcione. Automatize.</small></span>
  </div>
  <div class="saas-user">
    <span class="saas-avatar">AN</span>
    <span><?=aiEscape($greeting)?></span>
    <?php if($connectedPhone!==''):?><span class="connected-phone">Telegram: <?=aiEscape($connectedPhone)?></span><?php endif;?>
    <?php require __DIR__.'/app/theme-toggle.php'; ?><form method="post">
      <input type="hidden" name="csrf" value="<?=aiEscape(\App\Auth::csrf())?>">
      <input type="hidden" name="action" value="logout">
      <button class="saas-logout" type="submit" aria-label="Sair" title="Sair">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M14 16l4-4-4-4"/><path d="M18 12H8"/></svg>
      </button>
    </form>
  </div>
</header>
<?php $navActive='ai-learning'; require __DIR__.'/app/project-mobile-nav.php'; ?>

<main class="saas-content ai-learning-page">
  <div class="saas-heading ai-learning-heading">
    <div>
      <span class="saas-kicker">INTELIGÊNCIA ARTIFICIAL</span>
      <h1>Aprendizado da IA</h1>
      <p>Gerencie exemplos revisados que ajudam a IA a interpretar apostas semelhantes com mais contexto.</p>
    </div>
    <div class="saas-heading-actions">
      <a class="saas-secondary" href="/?page=dashboard">← Visão geral</a>
    </div>
  </div>

  <section class="overview-status <?=\App\AiLearningMemory::enabled()?'is-good':'is-alert'?> ai-memory-status">
    <div class="overview-status-icon"><i></i></div>
    <div>
      <span class="saas-kicker">MEMÓRIA CONTEXTUAL</span>
      <h2><?=\App\AiLearningMemory::enabled()?'Memória ativada':'Memória desativada'?></h2>
      <p>Exemplos aprovados podem orientar novas interpretações. Nenhum cadastro entra na memória antes da revisão manual.</p>
    </div>
    <div class="overview-status-meta">
      <span>Exemplos aprovados</span>
      <b><?=aiEscape($counts['approved'])?></b>
      <span class="ai-memory-status-detail"><?=aiEscape($counts['pending'])?> aguardando revisão</span>
    </div>
  </section>

  <section class="saas-metrics ai-learning-metrics" aria-label="Resumo de exemplos">
    <div class="saas-metric"><div class="saas-metric-label">TOTAL <span class="saas-metric-icon">◎</span></div><strong><?=aiEscape($counts['all'])?></strong><small>exemplos cadastrados</small></div>
    <div class="saas-metric"><div class="saas-metric-label">PENDENTES <span class="saas-metric-icon">◷</span></div><strong class="ai-pending"><?=aiEscape($counts['pending'])?></strong><small>aguardando revisão</small></div>
    <div class="saas-metric"><div class="saas-metric-label">APROVADOS <span class="saas-metric-icon">✓</span></div><strong class="ai-approved"><?=aiEscape($counts['approved'])?></strong><small>disponíveis para memória</small></div>
    <div class="saas-metric"><div class="saas-metric-label">REJEITADOS <span class="saas-metric-icon danger">×</span></div><strong class="ai-rejected"><?=aiEscape($counts['rejected'])?></strong><small>fora da memória</small></div>
  </section>

  <div class="saas-card ai-process" aria-label="Etapas do aprendizado">
    <span><b>01</b> Envie o print ou a tip</span><span class="ai-arrow">→</span>
    <span><b>02</b> Confira a interpretação</span><span class="ai-arrow">→</span>
    <span><b>03</b> Aprove o exemplo</span>
  </div>

<?php if($notice!==''):?><div class="saas-flash success" role="status"><?=aiEscape($notice)?></div><?php endif;?>
<?php if($error!==''):?><div class="saas-flash error" role="alert"><?=aiEscape($error)?></div><?php endif;?>

<section class="saas-card ai-section" id="importar-dataset">
  <div class="ai-section-head">
    <div><div class="saas-kicker">Importação · Exemplos sintéticos</div>
      <h2>Importar os nove bilhetes de treinamento</h2>
      <p>Selecione o pacote ZIP rotulado com as nove imagens e seus rótulos. O sistema verifica as imagens, evita duplicatas e cria somente registros pendentes para conferência.</p>
    </div><span class="ai-pill is-pending">Revisão obrigatória</span>
  </div>
  <form id="aiZipImportForm" method="post" enctype="multipart/form-data" class="ai-grid">
    <input type="hidden" name="csrf" value="<?=aiEscape(\App\Auth::csrf())?>">
    <input type="hidden" name="action" value="import_zip">
    <label class="ai-field ai-full"><span>Arquivo ZIP rotulado (até 24 MB)</span>
      <input type="file" name="dataset_zip" accept=".zip,application/zip,application/x-zip-compressed" required>
      <small>O arquivo deve conter labels/001.json a labels/009.json e as imagens correspondentes. Não são importados arquivos executáveis nem extraído conteúdo em pastas públicas.</small>
    </label>
    <div class="ai-form-actions ai-full">
      <p class="ai-hint">As informações foram geradas para treino: confira cada print e seus campos antes de aprovar. Múltiplas exigem revisão de cada seleção.</p>
      <button class="saas-primary" type="submit">Importar bilhetes para revisão →</button>
      <span class="ai-hint" id="aiZipProgress" role="status" aria-live="polite"></span>
    </div>
  </form>
</section>

<section class="saas-card ai-section" id="novo-exemplo">
  <div class="ai-section-head"><div><div class="saas-kicker">01 · Cadastro</div><h2>Novo exemplo de aposta</h2>
    <p>Comece com um print ou uma mensagem de texto. Você pode completar os campos na revisão.</p></div>
    <span class="ai-pill is-pending">Novo · Pendente</span>
  </div>
  <form method="post" enctype="multipart/form-data" class="ai-grid">
    <input type="hidden" name="csrf" value="<?=aiEscape(\App\Auth::csrf())?>">
    <input type="hidden" name="action" value="save">
    <label class="ai-field ai-full"><span>Print da aposta</span>
      <input type="file" name="image" accept="image/jpeg,image/png,image/webp" aria-describedby="al-file-hint">
      <small id="al-file-hint">JPG, PNG ou WebP, até 4 MB. Imagem armazenada de forma criptografada.</small>
    </label>
    <label class="ai-field ai-full"><span>Texto original / transcrição</span>
      <textarea name="source_text" maxlength="12000" placeholder="Ex.: Liverpool x Aston Villa · Más de 10.5 córners · odd 1.72"></textarea>
      <small>Se o print for a única fonte, transcreva o mercado quando puder: a memória busca semelhanças pelo texto, não pela imagem.</small>
    </label>
    <label class="ai-field ai-full"><span>ID da regra (opcional)</span>
      <input type="number" name="rule_id" min="1" placeholder="Vazio = exemplo disponível para todas as regras">
    </label>
    <details class="ai-optional ai-full">
      <summary>Já sabe a interpretação correta? Preencha aqui (opcional)</summary>
      <div class="ai-grid">
      <?php foreach($fields as $key=>$title):?>
        <label class="ai-field <?=in_array($key,['match','market','selection','analysis'],true)?'ai-full':''?>">
          <span><?=aiEscape($title)?></span>
          <?php if($key==='analysis'):?><textarea name="label[<?=aiEscape($key)?>]" maxlength="3000" placeholder="Somente a análise presente na mensagem original"></textarea>
          <?php else:?><input name="label[<?=aiEscape($key)?>]" maxlength="220" placeholder="<?=aiEscape($title)?>">
          <?php endif;?>
        </label>
      <?php endforeach;?>
      </div>
    </details>
    <div class="ai-form-actions ai-full">
      <p class="ai-hint">Salvar não aprova automaticamente. Confira o conteúdo antes de disponibilizá-lo à IA.</p>
      <button class="saas-primary" type="submit">Salvar para revisão →</button>
    </div>
  </form>
</section>

<section class="saas-card ai-section" id="biblioteca">
  <div class="ai-section-head"><div><div class="saas-kicker">02 · Biblioteca</div><h2>Revisar e gerenciar exemplos</h2>
    <p>Abra um registro para conferir a imagem, corrigir os dados, aprovar ou rejeitar.</p></div></div>
  <div class="ai-toolbar">
    <nav class="ai-filters" aria-label="Filtrar exemplos por situação">
      <?php foreach(['all'=>'Todos','pending'=>'Pendentes','approved'=>'Aprovados','rejected'=>'Rejeitados'] as $status=>$text):?>
      <a class="ai-filter" href="<?=aiEscape(aiUrl($status,$search))?>" <?=$filter===$status?'aria-current="page"':''?>>
        <?=aiEscape($text)?> <span><?=aiEscape($counts[$status])?></span>
      </a>
      <?php endforeach;?>
    </nav>
    <form class="ai-search" method="get" role="search">
      <input type="hidden" name="status" value="<?=aiEscape($filter)?>">
      <label for="al-q" class="ai-hint">Buscar</label>
      <input id="al-q" name="q" type="search" value="<?=aiEscape($search)?>" maxlength="120" placeholder="Equipe, mercado ou mensagem">
      <button type="submit">Buscar</button>
    </form>
  </div>
  <p class="ai-results"><?=aiEscape($library['total'])?> exemplo(s) encontrados · Página <?=aiEscape($library['page'])?> de <?=aiEscape($library['pages'])?></p>
  <?php if(!$examples):?>
    <div class="ai-empty"><b>Nenhum exemplo encontrado</b>
      <p>Cadastre uma tip acima ou ajuste os filtros da biblioteca.</p>
      <a class="saas-secondary" href="/ai-learning.php#novo-exemplo">Adicionar exemplo</a>
    </div>
  <?php else:?>
    <div class="ai-records">
    <?php foreach($examples as $example):
      $label=json_decode((string)$example['expected_json'],true)?:[];
      $state=(string)$example['status'];
      $isBlank=trim((string)$example['source_text'])==='';
      $cardTitle=trim((string)($label['match']??''))?:($example['image_name']?'Print aguardando interpretação':'Mensagem aguardando interpretação');
      $subtitle=trim((string)($label['market']??''));
      if($subtitle==='')$subtitle=mb_substr(trim((string)$example['source_text']),0,125,'UTF-8');
      if($subtitle==='')$subtitle='Sem transcrição — adicione o texto para usar na memória';
    ?>
      <details class="ai-record" id="exemplo-<?=aiEscape($example['id'])?>">
        <summary>
          <div class="ai-thumb" aria-hidden="true">
            <?php if($example['image_name']):?><img loading="lazy" src="/ai-learning.php?image=<?=aiEscape($example['id'])?>" alt="">
            <?php else:?>✎<?php endif;?>
          </div>
          <div class="ai-rec-main">
            <strong>#<?=aiEscape($example['id'])?> · <?=aiEscape($cardTitle)?></strong>
            <small><?=aiEscape($subtitle)?> · Regra: <?=aiEscape($example['rule_id']??'Todas')?> · <?=aiEscape($example['created_at'])?></small>
          </div>
          <div class="ai-rec-meta">
            <span class="ai-pill is-<?=aiEscape(in_array($state,['approved','rejected'],true)?$state:'pending')?>"><?=aiEscape(aiStatusLabel($state))?></span>
            <span class="ai-chevron" aria-hidden="true"><svg class="ai-chevron-down" viewBox="0 0 20 20" focusable="false"><path d="m5 7.5 5 5 5-5"/></svg><svg class="ai-chevron-up" viewBox="0 0 20 20" focusable="false"><path d="m5 12.5 5-5 5 5"/></svg></span>
          </div>
        </summary>
        <div class="ai-rec-detail">
          <?php if($example['image_name']):?>
            <img class="ai-preview" loading="lazy" src="/ai-learning.php?image=<?=aiEscape($example['id'])?>" alt="Print da aposta cadastrada no exemplo <?=aiEscape($example['id'])?>">
          <?php endif;?>
          <?php if($isBlank):?><p class="ai-warning">Este exemplo ainda não possui texto original. Complete a transcrição abaixo para que ele possa ser encontrado em apostas semelhantes.</p><?php endif;?>
          <form method="post" class="ai-grid">
            <input type="hidden" name="csrf" value="<?=aiEscape(\App\Auth::csrf())?>">
            <input type="hidden" name="action" value="review">
            <input type="hidden" name="id" value="<?=aiEscape($example['id'])?>">
            <label class="ai-field ai-full"><span>Texto original / transcrição para memória</span>
              <textarea maxlength="12000" name="source_text" placeholder="Transcreva as equipes, o mercado e a linha exibidos no print"><?=aiEscape($example['source_text'])?></textarea>
            </label>
            <?php foreach($fields as $key=>$title):?>
              <label class="ai-field <?=in_array($key,['match','market','selection','analysis'],true)?'ai-full':''?>">
                <span><?=aiEscape($title)?><?=in_array($key,['match','market','selection'],true)?' *':''?></span>
                <?php if($key==='analysis'):?><textarea maxlength="3000" name="label[<?=aiEscape($key)?>]"><?=aiEscape($label[$key]??'')?></textarea>
                <?php else:?><input maxlength="220" name="label[<?=aiEscape($key)?>]" value="<?=aiEscape($label[$key]??'')?>" <?=in_array($key,['match','market','selection'],true)?'required':''?>>
                <?php endif;?>
              </label>
            <?php endforeach;?>
            <div class="ai-form-actions ai-full">
              <p class="ai-hint">* Preenchimento obrigatório para aprovar. Só os exemplos aprovados podem orientar a IA.</p>
              <div class="ai-form-actions">
                <button class="ai-danger-btn" type="submit" name="decision" value="rejected" formnovalidate>Rejeitar</button>
                <button class="saas-primary" type="submit" name="decision" value="approved">Aprovar / salvar correção</button>
              </div>
            </div>
          </form>
        </div>
      </details>
    <?php endforeach;?>
    </div>
  <?php endif;?>
  <?php if($library['pages']>1):?>
    <nav class="ai-paging" aria-label="Páginas da biblioteca">
      <?php if($library['page']>1):?><a href="<?=aiEscape(aiUrl($filter,$search,$library['page']-1))?>">← Anterior</a><?php endif;?>
      <span>Página <?=aiEscape($library['page'])?> / <?=aiEscape($library['pages'])?></span>
      <?php if($library['page']<$library['pages']):?><a href="<?=aiEscape(aiUrl($filter,$search,$library['page']+1))?>">Próxima →</a><?php endif;?>
    </nav>
  <?php endif;?>
</section>
<p class="ai-footer-note">A memória contextual utiliza exemplos revisados com texto semelhante. O envio de um print por si só não executa treinamento dos parâmetros da IA nem faz leitura automática nesta versão.</p>

</main>
</div>
</div>
<script>
(function(){
  'use strict';
  const form=document.getElementById('aiZipImportForm');
  if(!form || typeof FormData==='undefined' || typeof fetch==='undefined') return;
  form.addEventListener('submit',async function(event){
    const file=form.querySelector('input[name="dataset_zip"]').files[0];
    if(!file)return;
    event.preventDefault();
    const progress=document.getElementById('aiZipProgress');
    const button=form.querySelector('button[type="submit"]');
    if(file.size>24*1024*1024 || file.size<100){
      progress.textContent='Selecione um ZIP válido com até 24 MB.';return;
    }
    button.disabled=true;
    try{
      const parts=Math.ceil(file.size/1048576);
      for(let i=0;i<parts;i++){
        progress.textContent='Enviando ZIP: parte '+(i+1)+' de '+parts+'…';
        const request=new FormData();
        request.append('csrf',form.querySelector('input[name="csrf"]').value);
        request.append('action','import_zip_chunk');
        request.append('index',String(i));
        request.append('last',i===parts-1?'1':'0');
        request.append('chunk',file.slice(i*1048576,(i+1)*1048576),'dataset.part');
        const response=await fetch('/ai-learning.php',{
          method:'POST',body:request,credentials:'same-origin'
        });
        const data=await response.json();
        if(!response.ok||!data.ok)throw new Error(data.error||'Falha na importação.');
        if(data.result){
          const url='/ai-learning.php?status=pending&imported='+
            encodeURIComponent(String(data.result.created))+'&skipped='+
            encodeURIComponent(String(data.result.skipped))+'#biblioteca';
          window.location.assign(url);return;
        }
      }
      throw new Error('O envio do arquivo não foi concluído.');
    }catch(error){
      progress.textContent=error instanceof Error?error.message:'Não foi possível importar o ZIP.';
      button.disabled=false;
    }
  });
})();
</script>
</body>
</html>
