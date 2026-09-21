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
    if(!preg_match('/^[a-f0-9]{32}\.(?:png|jpg|webp)$/D',$name)){http_response_code(404);exit;}
    $path=$directory.'/'.$name;
    if(!is_file($path)){http_response_code(404);exit;}
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){http_response_code(404);exit;}
    header('Content-Type: '.$mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
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
                $filename=bin2hex(random_bytes(16)).'.'.$ext;
                if(!move_uploaded_file($tmp,$directory.'/'.$filename))
                    throw new RuntimeException('Não foi possível salvar a imagem.');
                chmod($directory.'/'.$filename,0600);
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
            $memory->review((int)($_POST['id']??0),$decision,(array)($_POST['label']??[]));
            $notice=$decision==='approved'?'Exemplo aprovado e disponível para a memória.':'Exemplo rejeitado.';
        }else throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){
        $error=$e instanceof RuntimeException? $e->getMessage():'Não foi possível concluir esta operação.';
    }
}
$examples=$memory->all(30);
$approved=count(array_filter($examples,static fn($r)=>$r['status']==='approved'));
$pending=count(array_filter($examples,static fn($r)=>$r['status']==='pending'));
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Central de aprendizado · TelegramRouter</title>
<link rel="stylesheet" href="/assets/saas.css">
<style>
.ai-root{max-width:1120px;margin:0 auto;padding:24px 16px 70px;color:inherit}
.ai-root *{box-sizing:border-box}.ai-head{display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:24px}
.ai-card{border:1px solid #52646c55;border-radius:16px;padding:20px;margin:16px 0;background:var(--surface,#19252e)}
.ai-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.ai-field{display:flex;flex-direction:column;gap:6px;min-width:0}
.ai-field.full{grid-column:1/-1}.ai-field input,.ai-field textarea{width:100%;min-width:0;max-width:100%;padding:11px 13px;box-sizing:border-box;border:1px solid #75838b75;border-radius:9px;background:var(--input-bg,#17232b);color:inherit;font:inherit}
.ai-field textarea{min-height:72px}.ai-root button,.ai-back{display:inline-block;padding:10px 15px;border-radius:9px;border:1px solid #86bc47;background:#86bc47;color:#14201a;font-weight:700;cursor:pointer;text-decoration:none}
.ai-back{background:transparent;color:inherit}.ai-root .ai-reject{background:transparent;color:inherit;border-color:#9b6666}
.ai-status{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid #75838b75}
.ai-record img{max-width:100%;max-height:340px;border-radius:9px;object-fit:contain}.ai-note{opacity:.78;font-size:.91rem}
.ai-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}.ai-flash{padding:12px;border-radius:9px;margin:10px 0;border:1px solid #75838b75}
.ai-root pre{white-space:pre-wrap;overflow-wrap:anywhere}
@media(max-width:680px){.ai-grid{grid-template-columns:1fr}.ai-head{align-items:flex-start}}
</style></head><body>
<main class="ai-root">
<header class="ai-head"><div><h1>Central de aprendizado da IA</h1>
<p class="ai-note">Exemplos aprovados: <?=aiEscape($approved)?> · Aguardando revisão: <?=aiEscape($pending)?> (últimos 30)</p></div>
<a class="ai-back" href="/?page=dashboard">← Voltar ao painel</a></header>
<p class="ai-note">A memória contextual está <strong><?=\App\AiLearningMemory::enabled()?'ativada':'desativada'?></strong>.
As correções ficam em revisão até sua aprovação. Esta tela não altera o modelo Llama nem encaminha apostas.</p>
<?php if($notice!==''):?><div class="ai-flash" role="status"><?=aiEscape($notice)?></div><?php endif;?>
<?php if($error!==''):?><div class="ai-flash" role="alert"><?=aiEscape($error)?></div><?php endif;?>
<section class="ai-card"><h2>Adicionar exemplo</h2>
<p class="ai-note">Envie um print e, quando possível, transcreva a mensagem/mercado no campo original para facilitar a busca por exemplos semelhantes. Corrija todos os campos antes de aprovar.</p>
<form method="post" enctype="multipart/form-data" class="ai-grid">
<input type="hidden" name="csrf" value="<?=aiEscape(\App\Auth::csrf())?>">
<input type="hidden" name="action" value="save">
<label class="ai-field full">Texto original / transcrição do print<textarea name="source_text" maxlength="12000" placeholder="Ex.: Liverpool x Aston Villa · Más de 10.5 córners · 1.72"></textarea></label>
<label class="ai-field">Print (JPG, PNG, WebP; até 4 MB)<input name="image" type="file" accept="image/jpeg,image/png,image/webp"></label>
<label class="ai-field">ID da regra (opcional; vazio = global)<input name="rule_id" type="number" min="1" placeholder="Ex.: 12"></label>
<?php foreach($fields as $key=>$title):?>
<label class="ai-field <?=in_array($key,['match','market','selection','analysis'],true)?'full':''?>"><?=aiEscape($title)?>
<?php if($key==='analysis'):?><textarea name="label[<?=aiEscape($key)?>]" maxlength="3000"></textarea>
<?php else:?><input name="label[<?=aiEscape($key)?>]" maxlength="220" <?=in_array($key,['match','market','selection'],true)?'required':''?>>
<?php endif;?></label>
<?php endforeach;?>
<div class="ai-field full"><button type="submit">Salvar para revisão</button></div>
</form></section>
<section><h2>Exemplos recentes</h2>
<?php foreach($examples as $example):
$label=json_decode((string)$example['expected_json'],true)?:[];
?><article class="ai-card ai-record">
<div class="ai-head"><strong>#<?=aiEscape($example['id'])?> · <?=aiEscape($example['created_at'])?></strong><span class="ai-status"><?=aiEscape($example['status'])?></span></div>
<p class="ai-note">Regra: <?=aiEscape($example['rule_id']??'Todas')?></p>
<?php if($example['image_name']):?><img loading="lazy" src="/ai-learning.php?image=<?=aiEscape($example['id'])?>" alt="Print enviado para revisão"><?php endif;?>
<pre><?=aiEscape($example['source_text'])?></pre>
<form method="post" class="ai-grid">
<input type="hidden" name="csrf" value="<?=aiEscape(\App\Auth::csrf())?>">
<input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?=aiEscape($example['id'])?>">
<?php foreach($fields as $key=>$title):?>
<label class="ai-field <?=in_array($key,['match','market','selection','analysis'],true)?'full':''?>"><?=aiEscape($title)?>
<?php if($key==='analysis'):?><textarea maxlength="3000" name="label[<?=aiEscape($key)?>]"><?=aiEscape($label[$key]??'')?></textarea>
<?php else:?><input maxlength="220" name="label[<?=aiEscape($key)?>]" value="<?=aiEscape($label[$key]??'')?>" <?=in_array($key,['match','market','selection'],true)?'required':''?>>
<?php endif;?></label>
<?php endforeach;?>
<div class="ai-actions ai-field full"><button type="submit" name="decision" value="approved">Aprovar / salvar correção</button>
<button type="submit" class="ai-reject" name="decision" value="rejected" formnovalidate>Rejeitar</button></div>
</form></article>
<?php endforeach;?>
</section></main></body></html>
