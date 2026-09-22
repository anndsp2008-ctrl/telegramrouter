<?php declare(strict_types=1);
require __DIR__.'/bootstrap.php';
\App\Auth::requireLogin();
$memory=new \App\AiLearningMemory(\App\Database::pdo());
$memory->migrate(); // Idempotent: this isolated admin page is the sole installer.
$directory=__DIR__.'/storage/ai-learning';
$fields=['sport'=>'Esporte','status'=>'Status da partida','match'=>'Confronto',
    'league'=>'Campeonato','market'=>'Mercado','selection'=>'Seleção','odd'=>'Odd',
    'time'=>'Horário','day'=>'Data ou dia','bookmaker'=>'Casa de apostas','analysis'=>'Análise original'];
$notice='';$error='';
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
if($_SERVER['REQUEST_METHOD']==='POST'){
    try {
        \App\Auth::verifyCsrf($_POST['csrf']??null);
        $action=(string)($_POST['action']??'');
        if($action==='save'){
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
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Aprendizado da IA · TelegramRouter</title>
<link rel="stylesheet" href="/assets/saas.css">
<style>
:root{color-scheme:dark;--al-bg:#0b171c;--al-panel:#11242a;--al-panel2:#152e33;--al-edge:#2c494b;--al-ink:#eaf5f0;--al-muted:#a5b9b6;--al-accent:#88bc47;--al-accent-ink:#13241d;--al-warning:#f2bd69;--al-danger:#ff9e9e;--al-ring:#b8e86c}
@media(prefers-color-scheme:light){:root{color-scheme:light;--al-bg:#f1f6f2;--al-panel:#fff;--al-panel2:#eaf3ef;--al-edge:#cddfd5;--al-ink:#172c27;--al-muted:#4b6761;--al-accent:#487d25;--al-accent-ink:#fff;--al-warning:#976017;--al-danger:#ab3535;--al-ring:#487d25}}
html[data-theme="dark"],body[data-theme="dark"],body.dark{color-scheme:dark;--al-bg:#0b171c;--al-panel:#11242a;--al-panel2:#152e33;--al-edge:#2c494b;--al-ink:#eaf5f0;--al-muted:#a5b9b6;--al-accent:#88bc47;--al-accent-ink:#13241d;--al-warning:#f2bd69;--al-danger:#ff9e9e;--al-ring:#b8e86c}
html[data-theme="light"],body[data-theme="light"],body.light{color-scheme:light;--al-bg:#f1f6f2;--al-panel:#fff;--al-panel2:#eaf3ef;--al-edge:#cddfd5;--al-ink:#172c27;--al-muted:#4b6761;--al-accent:#487d25;--al-accent-ink:#fff;--al-warning:#976017;--al-danger:#ab3535;--al-ring:#487d25}
*{box-sizing:border-box}body{margin:0;background:var(--al-bg);color:var(--al-ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
a{color:inherit}button,input,textarea,select{font:inherit}button,a,input,textarea,summary{touch-action:manipulation}
:focus-visible{outline:3px solid var(--al-ring);outline-offset:3px}
.al-layout{width:min(1280px,100%);margin:0 auto;padding:24px clamp(14px,3vw,32px) 88px}
.al-top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.al-brand{display:flex;align-items:center;gap:12px;min-width:0}.al-mark{width:44px;height:44px;display:grid;place-items:center;background:var(--al-panel2);border:1px solid var(--al-edge);color:var(--al-accent);border-radius:13px;font-size:21px;font-weight:900;flex:none}
.al-eyebrow{font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase;color:var(--al-accent)}
.al-top h1{font-size:clamp(22px,3vw,31px);line-height:1.2;margin:2px 0 0;letter-spacing:-.035em}
.al-link{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:1px solid var(--al-edge);border-radius:11px;padding:10px 15px;text-decoration:none;font-weight:650;white-space:nowrap}
.al-card{min-width:0;background:var(--al-panel);border:1px solid var(--al-edge);border-radius:17px;box-shadow:0 8px 35px #00000009}
.al-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:16px;padding:22px 24px;margin-bottom:16px;background:linear-gradient(120deg,var(--al-panel),var(--al-panel2))}
.al-hero h2{font-size:clamp(19px,2vw,25px);letter-spacing:-.02em;line-height:1.2;margin:5px 0 8px}
.al-hero p{color:var(--al-muted);margin:0;max-width:73ch}
.al-pill{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--al-edge);border-radius:999px;padding:6px 11px;font-size:12px;font-weight:750;white-space:nowrap}
.al-pill:before{content:"";width:7px;height:7px;border-radius:100%;background:currentColor}
.al-pill--on,.al-pill--approved{color:var(--al-accent)}.al-pill--off,.al-pill--pending{color:var(--al-warning)}.al-pill--rejected{color:var(--al-danger)}
.al-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.al-stat{padding:17px 18px}.al-stat span{font-size:12px;color:var(--al-muted);display:block}.al-stat strong{font-size:28px;line-height:1.25;display:block;font-variant-numeric:tabular-nums;margin-top:5px}
.al-process{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:14px 18px;margin-bottom:16px}.al-process span{font-size:13px;color:var(--al-muted)}.al-process b{color:var(--al-ink)}.al-arrow{opacity:.45}
.al-section{padding:clamp(17px,2.4vw,26px);margin-bottom:18px}.al-heading{display:flex;justify-content:space-between;gap:16px;align-items:start;flex-wrap:wrap;margin-bottom:20px}
.al-heading h2{font-size:20px;line-height:1.3;letter-spacing:-.02em;margin:0 0 5px}.al-heading p,.al-hint{font-size:13px;color:var(--al-muted);margin:0;line-height:1.55}
.al-field{min-width:0;display:flex;flex-direction:column;gap:6px}.al-field>span{font-size:13px;font-weight:700}.al-field small{color:var(--al-muted);font-size:12px}
.al-field input,.al-field textarea,.al-field select,.al-search input{background:var(--al-bg);color:var(--al-ink);border:1px solid var(--al-edge);border-radius:10px;padding:11px 13px;min-height:44px;width:100%;min-width:0;max-width:100%;font-weight:450;line-height:1.4}
.al-field textarea{resize:vertical;min-height:110px}.al-field input[type=file]{font-size:13px;padding:8px;line-height:1.6}.al-field input::file-selector-button{border:1px solid var(--al-edge);border-radius:8px;background:var(--al-panel2);color:var(--al-ink);padding:7px 11px;margin-right:10px;font:inherit;font-weight:700;cursor:pointer}
.al-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.al-full{grid-column:1/-1}
.al-optional{border:1px dashed var(--al-edge);border-radius:12px;padding:13px 15px}.al-optional summary{cursor:pointer;font-weight:750;list-style:none}.al-optional summary::-webkit-details-marker{display:none}.al-optional summary:after{content:"＋";float:right;font-size:18px;color:var(--al-accent)}.al-optional[open] summary:after{content:"−"}.al-optional .al-grid{padding-top:15px}
.al-action{display:inline-flex;justify-content:center;align-items:center;gap:8px;min-height:44px;border-radius:10px;background:var(--al-accent);color:var(--al-accent-ink);font-weight:800;border:1px solid var(--al-accent);padding:10px 17px;cursor:pointer;text-decoration:none}
.al-action--secondary{background:transparent;color:var(--al-ink);border-color:var(--al-edge)}.al-action--danger{background:transparent;color:var(--al-danger);border-color:var(--al-edge)}
.al-footer-actions{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-top:4px}
.al-flash{padding:13px 16px;border:1px solid var(--al-edge);border-radius:12px;margin-bottom:16px;background:var(--al-panel2)}
.al-flash--error{border-color:var(--al-danger);color:var(--al-danger)}.al-flash--success{border-color:var(--al-accent)}
.al-toolbar{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.al-filters{display:flex;flex-wrap:wrap;gap:7px}.al-filter{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:7px 11px;font-size:12px;font-weight:750;border:1px solid var(--al-edge);border-radius:9px;text-decoration:none}
.al-filter[aria-current=page]{background:var(--al-accent);color:var(--al-accent-ink);border-color:var(--al-accent)}
.al-search{display:flex;align-items:center;gap:7px;min-width:min(350px,100%);max-width:100%}.al-search input{min-width:0;min-height:39px;padding:7px 12px}.al-search button{min-height:39px;padding:7px 12px;background:var(--al-panel2);color:var(--al-ink);border:1px solid var(--al-edge);border-radius:9px;cursor:pointer;font-weight:700}
.al-results{font-size:12px;color:var(--al-muted);margin:0 0 12px}
.al-records{display:grid;gap:10px}.al-record{border:1px solid var(--al-edge);border-radius:13px;overflow:hidden;background:var(--al-panel2);min-width:0}
.al-record summary{list-style:none;cursor:pointer;padding:14px;display:grid;grid-template-columns:60px minmax(0,1fr) auto;gap:14px;align-items:center;min-width:0}.al-record summary::-webkit-details-marker{display:none}
.al-thumb{width:60px;height:60px;border:1px solid var(--al-edge);background:var(--al-panel);border-radius:10px;display:grid;place-items:center;overflow:hidden;color:var(--al-muted);font-size:22px}.al-thumb img{width:100%;height:100%;object-fit:cover}
.al-rec-main{min-width:0}.al-rec-main strong{display:block;font-size:14px;white-space:nowrap;text-overflow:ellipsis;overflow:hidden}.al-rec-main small{display:block;color:var(--al-muted);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:4px}
.al-rec-meta{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.al-chevron{color:var(--al-muted);font-weight:800}.al-record[open] .al-chevron{transform:rotate(180deg)}.al-rec-detail{border-top:1px solid var(--al-edge);padding:18px}
.al-preview{max-width:100%;max-height:350px;object-fit:contain;display:block;margin:0 0 16px;border-radius:12px;border:1px solid var(--al-edge);background:var(--al-bg)}
.al-warning{padding:10px 13px;border:1px solid var(--al-edge);border-radius:10px;color:var(--al-warning);font-size:13px;margin:10px 0}
.al-paging{display:flex;gap:7px;justify-content:flex-end;align-items:center;flex-wrap:wrap;margin-top:16px;font-size:12px;color:var(--al-muted)}.al-paging a{border:1px solid var(--al-edge);padding:7px 12px;border-radius:8px;text-decoration:none;font-weight:750}
.al-empty{text-align:center;padding:35px 12px;color:var(--al-muted)}.al-empty b{color:var(--al-ink);display:block;margin-bottom:7px}
@media(max-width:880px){.al-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.al-hero{grid-template-columns:1fr}.al-top{align-items:flex-start}}
@media(max-width:620px){.al-layout{padding:16px 12px 72px}.al-stats{gap:8px}.al-stat{padding:14px}.al-stat strong{font-size:24px}.al-section{padding:15px}.al-grid{grid-template-columns:1fr;gap:13px}.al-full{grid-column:1}.al-record summary{grid-template-columns:46px minmax(0,1fr);gap:10px}.al-thumb{width:46px;height:46px}.al-rec-meta{grid-column:1/-1;justify-content:space-between}.al-toolbar{align-items:stretch}.al-search{width:100%}.al-footer-actions .al-action{width:100%}.al-paging{justify-content:center}.al-hero{padding:18px}}
@media(prefers-reduced-motion:no-preference){.al-action,.al-link,.al-filter{transition:filter .15s ease}.al-action:hover,.al-link:hover,.al-filter:hover{filter:brightness(1.07)}}
</style>
</head>
<body>
<main class="al-layout">
<header class="al-top">
  <div class="al-brand"><div class="al-mark" aria-hidden="true">✦</div><div>
    <div class="al-eyebrow">TelegramRouter · Inteligência artificial</div>
    <h1>Aprendizado da IA</h1>
  </div></div>
  <a class="al-link" href="/?page=dashboard">← Voltar ao painel</a>
</header>

<section class="al-card al-hero" aria-label="Como funciona a memória">
  <div><div class="al-eyebrow">Memória contextual · Exemplos revisados</div>
    <h2>Ensine com exemplos, melhore as próximas interpretações.</h2>
    <p>Cadastre prints ou tips, revise os dados e aprove os exemplos corretos. A IA consulta exemplos semelhantes nas próximas mensagens. Este recurso não treina os pesos do Llama e não altera o padrão visual dos cards.</p>
  </div>
  <span class="al-pill <?=\App\AiLearningMemory::enabled()?'al-pill--on':'al-pill--off'?>"
        aria-label="Estado da memória"><?=\App\AiLearningMemory::enabled()?'Memória ativada':'Memória desativada'?></span>
</section>

<section class="al-stats" aria-label="Resumo de exemplos">
  <div class="al-card al-stat"><span>Total de exemplos</span><strong><?=aiEscape($counts['all'])?></strong></div>
  <div class="al-card al-stat"><span>Aguardando revisão</span><strong><?=aiEscape($counts['pending'])?></strong></div>
  <div class="al-card al-stat"><span>Aprovados para memória</span><strong><?=aiEscape($counts['approved'])?></strong></div>
  <div class="al-card al-stat"><span>Rejeitados</span><strong><?=aiEscape($counts['rejected'])?></strong></div>
</section>

<div class="al-card al-process" aria-label="Etapas do aprendizado">
  <span><b>01</b> Envie o print ou a tip</span><span class="al-arrow" aria-hidden="true">→</span>
  <span><b>02</b> Confira a interpretação</span><span class="al-arrow" aria-hidden="true">→</span>
  <span><b>03</b> Aprove o exemplo</span>
</div>

<?php if($notice!==''):?><div class="al-flash al-flash--success" role="status"><?=aiEscape($notice)?></div><?php endif;?>
<?php if($error!==''):?><div class="al-flash al-flash--error" role="alert"><?=aiEscape($error)?></div><?php endif;?>

<section class="al-card al-section" id="novo-exemplo">
  <div class="al-heading"><div><div class="al-eyebrow">01 · Cadastro</div><h2>Novo exemplo de aposta</h2>
    <p>Comece com um print ou uma mensagem de texto. Você pode completar os campos na revisão.</p></div>
    <span class="al-pill al-pill--pending">Novo · Pendente</span>
  </div>
  <form method="post" enctype="multipart/form-data" class="al-grid">
    <input type="hidden" name="csrf" value="<?=aiEscape(\App\Auth::csrf())?>">
    <input type="hidden" name="action" value="save">
    <label class="al-field al-full"><span>Print da aposta</span>
      <input type="file" name="image" accept="image/jpeg,image/png,image/webp" aria-describedby="al-file-hint">
      <small id="al-file-hint">JPG, PNG ou WebP, até 4 MB. Imagem armazenada de forma criptografada.</small>
    </label>
    <label class="al-field al-full"><span>Texto original / transcrição</span>
      <textarea name="source_text" maxlength="12000" placeholder="Ex.: Liverpool x Aston Villa · Más de 10.5 córners · odd 1.72"></textarea>
      <small>Se o print for a única fonte, transcreva o mercado quando puder: a memória busca semelhanças pelo texto, não pela imagem.</small>
    </label>
    <label class="al-field al-full"><span>ID da regra (opcional)</span>
      <input type="number" name="rule_id" min="1" placeholder="Vazio = exemplo disponível para todas as regras">
    </label>
    <details class="al-optional al-full">
      <summary>Já sabe a interpretação correta? Preencha aqui (opcional)</summary>
      <div class="al-grid">
      <?php foreach($fields as $key=>$title):?>
        <label class="al-field <?=in_array($key,['match','market','selection','analysis'],true)?'al-full':''?>">
          <span><?=aiEscape($title)?></span>
          <?php if($key==='analysis'):?><textarea name="label[<?=aiEscape($key)?>]" maxlength="3000" placeholder="Somente a análise presente na mensagem original"></textarea>
          <?php else:?><input name="label[<?=aiEscape($key)?>]" maxlength="220" placeholder="<?=aiEscape($title)?>">
          <?php endif;?>
        </label>
      <?php endforeach;?>
      </div>
    </details>
    <div class="al-footer-actions al-full">
      <p class="al-hint">Salvar não aprova automaticamente. Confira o conteúdo antes de disponibilizá-lo à IA.</p>
      <button class="al-action" type="submit">Salvar para revisão →</button>
    </div>
  </form>
</section>

<section class="al-card al-section" id="biblioteca">
  <div class="al-heading"><div><div class="al-eyebrow">02 · Biblioteca</div><h2>Revisar e gerenciar exemplos</h2>
    <p>Abra um registro para conferir a imagem, corrigir os dados, aprovar ou rejeitar.</p></div></div>
  <div class="al-toolbar">
    <nav class="al-filters" aria-label="Filtrar exemplos por situação">
      <?php foreach(['all'=>'Todos','pending'=>'Pendentes','approved'=>'Aprovados','rejected'=>'Rejeitados'] as $status=>$text):?>
      <a class="al-filter" href="<?=aiEscape(aiUrl($status,$search))?>" <?=$filter===$status?'aria-current="page"':''?>>
        <?=aiEscape($text)?> <span><?=aiEscape($counts[$status])?></span>
      </a>
      <?php endforeach;?>
    </nav>
    <form class="al-search" method="get" role="search">
      <input type="hidden" name="status" value="<?=aiEscape($filter)?>">
      <label for="al-q" class="al-hint">Buscar</label>
      <input id="al-q" name="q" type="search" value="<?=aiEscape($search)?>" maxlength="120" placeholder="Equipe, mercado ou mensagem">
      <button type="submit">Buscar</button>
    </form>
  </div>
  <p class="al-results"><?=aiEscape($library['total'])?> exemplo(s) encontrados · Página <?=aiEscape($library['page'])?> de <?=aiEscape($library['pages'])?></p>
  <?php if(!$examples):?>
    <div class="al-empty"><b>Nenhum exemplo encontrado</b>
      <p>Cadastre uma tip acima ou ajuste os filtros da biblioteca.</p>
      <a class="al-link" href="/ai-learning.php#novo-exemplo">Adicionar exemplo</a>
    </div>
  <?php else:?>
    <div class="al-records">
    <?php foreach($examples as $example):
      $label=json_decode((string)$example['expected_json'],true)?:[];
      $state=(string)$example['status'];
      $isBlank=trim((string)$example['source_text'])==='';
      $cardTitle=trim((string)($label['match']??''))?:($example['image_name']?'Print aguardando interpretação':'Mensagem aguardando interpretação');
      $subtitle=trim((string)($label['market']??''));
      if($subtitle==='')$subtitle=mb_substr(trim((string)$example['source_text']),0,125,'UTF-8');
      if($subtitle==='')$subtitle='Sem transcrição — adicione o texto para usar na memória';
    ?>
      <details class="al-record" id="exemplo-<?=aiEscape($example['id'])?>">
        <summary>
          <div class="al-thumb" aria-hidden="true">
            <?php if($example['image_name']):?><img loading="lazy" src="/ai-learning.php?image=<?=aiEscape($example['id'])?>" alt="">
            <?php else:?>✎<?php endif;?>
          </div>
          <div class="al-rec-main">
            <strong>#<?=aiEscape($example['id'])?> · <?=aiEscape($cardTitle)?></strong>
            <small><?=aiEscape($subtitle)?> · Regra: <?=aiEscape($example['rule_id']??'Todas')?> · <?=aiEscape($example['created_at'])?></small>
          </div>
          <div class="al-rec-meta">
            <span class="al-pill al-pill--<?=aiEscape(in_array($state,['approved','rejected'],true)?$state:'pending')?>"><?=aiEscape(aiStatusLabel($state))?></span>
            <span class="al-chevron" aria-hidden="true">⌄</span>
          </div>
        </summary>
        <div class="al-rec-detail">
          <?php if($example['image_name']):?>
            <img class="al-preview" loading="lazy" src="/ai-learning.php?image=<?=aiEscape($example['id'])?>" alt="Print da aposta cadastrada no exemplo <?=aiEscape($example['id'])?>">
          <?php endif;?>
          <?php if($isBlank):?><p class="al-warning">Este exemplo ainda não possui texto original. Complete a transcrição abaixo para que ele possa ser encontrado em apostas semelhantes.</p><?php endif;?>
          <form method="post" class="al-grid">
            <input type="hidden" name="csrf" value="<?=aiEscape(\App\Auth::csrf())?>">
            <input type="hidden" name="action" value="review">
            <input type="hidden" name="id" value="<?=aiEscape($example['id'])?>">
            <label class="al-field al-full"><span>Texto original / transcrição para memória</span>
              <textarea maxlength="12000" name="source_text" placeholder="Transcreva as equipes, o mercado e a linha exibidos no print"><?=aiEscape($example['source_text'])?></textarea>
            </label>
            <?php foreach($fields as $key=>$title):?>
              <label class="al-field <?=in_array($key,['match','market','selection','analysis'],true)?'al-full':''?>">
                <span><?=aiEscape($title)?><?=in_array($key,['match','market','selection'],true)?' *':''?></span>
                <?php if($key==='analysis'):?><textarea maxlength="3000" name="label[<?=aiEscape($key)?>]"><?=aiEscape($label[$key]??'')?></textarea>
                <?php else:?><input maxlength="220" name="label[<?=aiEscape($key)?>]" value="<?=aiEscape($label[$key]??'')?>" <?=in_array($key,['match','market','selection'],true)?'required':''?>>
                <?php endif;?>
              </label>
            <?php endforeach;?>
            <div class="al-footer-actions al-full">
              <p class="al-hint">* Preenchimento obrigatório para aprovar. Só os exemplos aprovados podem orientar a IA.</p>
              <div class="al-footer-actions">
                <button class="al-action al-action--danger" type="submit" name="decision" value="rejected" formnovalidate>Rejeitar</button>
                <button class="al-action" type="submit" name="decision" value="approved">Aprovar / salvar correção</button>
              </div>
            </div>
          </form>
        </div>
      </details>
    <?php endforeach;?>
    </div>
  <?php endif;?>
  <?php if($library['pages']>1):?>
    <nav class="al-paging" aria-label="Páginas da biblioteca">
      <?php if($library['page']>1):?><a href="<?=aiEscape(aiUrl($filter,$search,$library['page']-1))?>">← Anterior</a><?php endif;?>
      <span>Página <?=aiEscape($library['page'])?> / <?=aiEscape($library['pages'])?></span>
      <?php if($library['page']<$library['pages']):?><a href="<?=aiEscape(aiUrl($filter,$search,$library['page']+1))?>">Próxima →</a><?php endif;?>
    </nav>
  <?php endif;?>
</section>
<p class="al-hint">A memória contextual utiliza exemplos revisados com texto semelhante. O envio de um print por si só não executa treinamento dos parâmetros da IA nem faz leitura automática nesta versão.</p>
</main>
</body>
</html>
