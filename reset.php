<?php declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use App\Auth;

Auth::requireLogin();

function rh(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function resetPdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = trim((string)getenv('DB_HOST'));
    $port = (int)(getenv('DB_PORT') ?: 3306);
    $name = trim((string)getenv('DB_NAME'));
    $user = (string)getenv('DB_USER');
    $pass = (string)getenv('DB_PASS');

    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('A conexão com o banco não está configurada.');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}

function resetEnsureSecuritySchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reset_security (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        phrase_hash VARCHAR(255) NOT NULL,
        updated_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reset_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        mode VARCHAR(24) NOT NULL,
        categories TEXT NOT NULL,
        tables_cleared INT UNSIGNED NOT NULL DEFAULT 0,
        rows_deleted BIGINT UNSIGNED NOT NULL DEFAULT 0,
        files_cleared INT UNSIGNED NOT NULL DEFAULT 0,
        telegram_disconnected TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(24) NOT NULL DEFAULT 'success',
        INDEX idx_reset_audit_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function resetPhraseConfig(PDO $pdo): ?array {
    resetEnsureSecuritySchema($pdo);
    $stmt = $pdo->query('SELECT phrase_hash, updated_at FROM reset_security WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();
    return is_array($row) && !empty($row['phrase_hash']) ? $row : null;
}

function resetSavePhrase(PDO $pdo, string $phrase): void {
    resetEnsureSecuritySchema($pdo);
    $hash = password_hash($phrase, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') throw new RuntimeException('Não foi possível proteger a frase de segurança.');

    $stmt = $pdo->prepare("INSERT INTO reset_security (id, phrase_hash, updated_at, created_at)
        VALUES (1, :hash, NOW(), NOW())
        ON DUPLICATE KEY UPDATE phrase_hash = VALUES(phrase_hash), updated_at = NOW()");
    $stmt->execute(['hash' => $hash]);
}

function resetValidateNewPhrase(string $phrase): void {
    $length = mb_strlen($phrase);
    if ($length < 12) throw new InvalidArgumentException('A frase de segurança deve ter pelo menos 12 caracteres.');
    if ($length > 180) throw new InvalidArgumentException('A frase de segurança é longa demais.');
    if (!preg_match('/[A-Za-zÀ-ÿ]/u', $phrase) || !preg_match('/[0-9]/', $phrase)) {
        throw new InvalidArgumentException('Use uma frase com letras e pelo menos um número.');
    }
}

function resetVerifyPhrase(PDO $pdo, string $phrase): bool {
    $config = resetPhraseConfig($pdo);
    if (!$config || !isset($config['phrase_hash'])) return false;

    $lockedUntil = (int)($_SESSION['reset_phrase_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        throw new RuntimeException('Muitas tentativas incorretas. Aguarde '.($lockedUntil - time()).' segundos e tente novamente.');
    }

    if (password_verify($phrase, (string)$config['phrase_hash'])) {
        unset($_SESSION['reset_phrase_failures'], $_SESSION['reset_phrase_locked_until']);
        return true;
    }

    $failures = (int)($_SESSION['reset_phrase_failures'] ?? 0) + 1;
    $_SESSION['reset_phrase_failures'] = $failures;
    if ($failures >= 5) {
        $_SESSION['reset_phrase_failures'] = 0;
        $_SESSION['reset_phrase_locked_until'] = time() + 300;
    }
    return false;
}

function resetQuoteIdentifier(string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new RuntimeException('Foi detectado um identificador de banco inválido.');
    }
    return '`'.$identifier.'`';
}

function resetAllTables(PDO $pdo): array {
    $stmt = $pdo->query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME');
    $tables = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (is_string($name) && $name !== '') $tables[] = $name;
    }
    return $tables;
}

function resetIsProtectedTable(string $table): bool {
    $name = strtolower($table);
    if (in_array($name, ['reset_security', 'reset_audit'], true)) return true;
    if (str_contains($name, 'telegram') || str_contains($name, 'credential')) return false;
    return (bool)preg_match('/(^|_)(admin|admins|user|users|migration|migrations|schema|schemas|password|passwords)($|_)/i', $name);
}

function resetCategoryForTable(string $table): string {
    $name = strtolower($table);
    if (str_contains($name, 'telegram') || str_contains($name, 'credential')) return 'telegram';
    if (str_contains($name, 'integration')) return 'integrations';
    if (str_contains($name, 'rule')) return 'rules';
    if (preg_match('/translation|telemetr|metric/i', $name)) return 'translation';
    if (preg_match('/event|activit|histor|processed|deliver|message|log/i', $name)) return 'activity';
    return 'other';
}

function resetStoragePath(string $relative = ''): string {
    $base = __DIR__.'/storage';
    return $relative === '' ? $base : $base.'/'.ltrim($relative, '/');
}

function resetStorageItemCount(): int {
    $count = 0;
    $log = resetStoragePath('worker.log');
    if (is_file($log) && (int)@filesize($log) > 0) $count++;

    foreach (['auth-jobs', 'cache'] as $dir) {
        $path = resetStoragePath($dir);
        if (!is_dir($path)) continue;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) if ($item->isFile()) $count++;
    }
    return $count;
}

function resetInventory(PDO $pdo): array {
    resetEnsureSecuritySchema($pdo);
    $categories = [
        'activity' => ['label'=>'Histórico de atividades','description'=>'Eventos, mensagens processadas, entregas e histórico operacional.','tables'=>[],'rows'=>0],
        'rules' => ['label'=>'Regras de roteamento','description'=>'Regras, gatilhos, origem, destino e configurações de encaminhamento.','tables'=>[],'rows'=>0],
        'translation' => ['label'=>'Traduções e telemetria','description'=>'Tentativas de tradução, métricas e registros técnicos de tradução.','tables'=>[],'rows'=>0],
        'integrations' => ['label'=>'Integrações salvas','description'=>'Configurações e chaves gravadas pelo painel. Variáveis do Railway são preservadas.','tables'=>[],'rows'=>0],
        'telegram' => ['label'=>'Conexão Telegram','description'=>'Credenciais armazenadas, vínculo da conta e sessão Telegram gerenciada pela aplicação.','tables'=>[],'rows'=>0],
        'storage' => ['label'=>'Logs e arquivos operacionais','description'=>'Worker log, cache e arquivos temporários de autenticação no volume persistente.','tables'=>[],'rows'=>0],
        'other' => ['label'=>'Outros dados da aplicação','description'=>'Demais dados não pertencentes à instalação, login administrativo ou proteção do reset.','tables'=>[],'rows'=>0],
    ];

    foreach (resetAllTables($pdo) as $table) {
        if (resetIsProtectedTable($table)) continue;
        $category = resetCategoryForTable($table);
        $categories[$category]['tables'][] = $table;
        try {
            $categories[$category]['rows'] += (int)$pdo->query('SELECT COUNT(*) FROM '.resetQuoteIdentifier($table))->fetchColumn();
        } catch (Throwable) {}
    }

    $categories['storage']['rows'] = resetStorageItemCount();
    return $categories;
}

function resetDisconnectTelegram(): bool {
    $telegramAuth = __DIR__.'/app/TelegramAuth.php';
    if (!is_file($telegramAuth)) return false;
    require_once $telegramAuth;
    if (!class_exists('App\\TelegramAuth') || !method_exists('App\\TelegramAuth', 'disconnect')) return false;
    App\TelegramAuth::disconnect();
    return true;
}

function resetDeleteDirectoryContents(string $directory): int {
    if (!is_dir($directory)) return 0;
    $deleted = 0;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) @rmdir($path);
        elseif (@unlink($path)) $deleted++;
    }
    return $deleted;
}

function resetClearOperationalStorage(): int {
    $files = 0;
    $workerLog = resetStoragePath('worker.log');
    if (is_file($workerLog)) {
        @file_put_contents($workerLog, '', LOCK_EX);
        @chmod($workerLog, 0600);
        $files++;
    }
    $files += resetDeleteDirectoryContents(resetStoragePath('auth-jobs'));
    $files += resetDeleteDirectoryContents(resetStoragePath('cache'));
    return $files;
}

function resetDeleteTables(PDO $pdo, array $tables): array {
    $tables = array_values(array_unique(array_filter($tables, 'is_string')));
    if (!$tables) return ['tables'=>0,'rows'=>0];
    $known = array_flip(resetAllTables($pdo));
    $tables = array_values(array_filter($tables, static fn(string $table): bool => isset($known[$table]) && !resetIsProtectedTable($table)));
    $rows = 0;
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $pdo->beginTransaction();
        foreach ($tables as $table) {
            $stmt = $pdo->prepare('DELETE FROM '.resetQuoteIdentifier($table));
            $stmt->execute();
            $rows += $stmt->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    foreach ($tables as $table) {
        try { $pdo->exec('ALTER TABLE '.resetQuoteIdentifier($table).' AUTO_INCREMENT = 1'); } catch (Throwable) {}
    }
    return ['tables'=>count($tables),'rows'=>$rows];
}

function resetAudit(PDO $pdo, string $mode, array $categories, array $result, string $status='success'): void {
    resetEnsureSecuritySchema($pdo);
    $stmt=$pdo->prepare('INSERT INTO reset_audit (created_at,mode,categories,tables_cleared,rows_deleted,files_cleared,telegram_disconnected,status) VALUES (NOW(),:mode,:categories,:tables,:rows,:files,:telegram,:status)');
    $stmt->execute([
        'mode'=>$mode,
        'categories'=>json_encode(array_values($categories),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '[]',
        'tables'=>(int)($result['tables']??0),
        'rows'=>(int)($result['rows']??0),
        'files'=>(int)($result['files']??0),
        'telegram'=>!empty($result['telegram_disconnected'])?1:0,
        'status'=>$status,
    ]);
}

$notice=null; $error=null;
try { $pdo=resetPdo(); resetEnsureSecuritySchema($pdo); $inventory=resetInventory($pdo); }
catch(Throwable $e) { $pdo=null; $inventory=[]; $error='Não foi possível carregar o inventário de dados: '.$e->getMessage(); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        Auth::verifyCsrf($_POST['csrf']??null);
        $action=(string)($_POST['action']??'');
        if ($action==='save_phrase') {
            if (!$pdo instanceof PDO) throw new RuntimeException('O banco não está disponível.');
            $current=(string)($_POST['current_phrase']??'');
            $new=trim((string)($_POST['new_phrase']??''));
            $confirm=trim((string)($_POST['confirm_phrase']??''));
            $configured=resetPhraseConfig($pdo);
            if ($configured && !resetVerifyPhrase($pdo,$current)) throw new RuntimeException('A frase de segurança atual não confere.');
            resetValidateNewPhrase($new);
            if (!hash_equals($new,$confirm)) throw new InvalidArgumentException('A confirmação da nova frase não confere.');
            resetSavePhrase($pdo,$new);
            $notice=$configured?'Frase de segurança atualizada com sucesso.':'Frase de segurança configurada. Os resets agora estão protegidos.';
        }
        if ($action==='execute_reset') {
            if (!$pdo instanceof PDO) throw new RuntimeException('O banco não está disponível para executar o reset.');
            if (!resetPhraseConfig($pdo)) throw new RuntimeException('Configure a frase de segurança antes de executar qualquer reset.');
            if (empty($_POST['acknowledge'])) throw new RuntimeException('Confirme que leu e compreendeu os riscos desta ação.');
            if (!resetVerifyPhrase($pdo,(string)($_POST['security_phrase']??''))) throw new RuntimeException('Frase de segurança incorreta.');

            $mode=(string)($_POST['reset_mode']??'');
            if (!in_array($mode,['custom','full'],true)) throw new RuntimeException('Modo de reset inválido.');
            $inventory=resetInventory($pdo);
            $allowed=array_keys($inventory);
            $selected=$mode==='full'?$allowed:array_values(array_intersect($allowed,array_map('strval',(array)($_POST['categories']??[]))));
            if (!$selected) throw new RuntimeException('Selecione pelo menos uma categoria para o reset personalizado.');

            $telegramDisconnected=false;
            if (in_array('telegram',$selected,true)) $telegramDisconnected=resetDisconnectTelegram();
            $tables=[];
            foreach($selected as $category) foreach(($inventory[$category]['tables']??[]) as $table) $tables[]=$table;
            $result=resetDeleteTables($pdo,$tables);
            $result['files']=in_array('storage',$selected,true)?resetClearOperationalStorage():0;
            $result['telegram_disconnected']=$telegramDisconnected;
            resetAudit($pdo,$mode,$selected,$result,'success');

            $_SESSION['reset_flash']=['message'=>sprintf('Reset concluído: %d registro(s) removido(s) de %d tabela(s)%s%s.',$result['rows'],$result['tables'],$result['files']>0?', '.$result['files'].' arquivo(s) operacional(is) limpo(s)':'',$telegramDisconnected?', conta Telegram desconectada':'')];
            header('Location: /reset.php'); exit;
        }
    } catch(InvalidArgumentException|RuntimeException $e) { $error=$e->getMessage(); }
    catch(Throwable $e) {
        if ($pdo instanceof PDO) { try { resetAudit($pdo,'error',[],[],'failed'); } catch(Throwable) {} }
        $error='A operação não pôde ser concluída com segurança. Nenhum novo reset será tentado automaticamente.';
    }
    try { if($pdo instanceof PDO) $inventory=resetInventory($pdo); } catch(Throwable) {}
}

if(isset($_SESSION['reset_flash'])&&is_array($_SESSION['reset_flash'])) { $notice=(string)($_SESSION['reset_flash']['message']??$notice); unset($_SESSION['reset_flash']); }
$phraseConfigured=$pdo instanceof PDO && resetPhraseConfig($pdo)!==null;
$categoryOrder=['activity','rules','translation','integrations','telegram','storage','other'];
$totalItems=0; foreach($inventory as $item)$totalItems+=(int)($item['rows']??0);
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset de dados · Telegram Router</title><link rel="stylesheet" href="/assets/saas.css"><link rel="stylesheet" href="/assets/reset.css?v=4"></head><body>
<div class="saas-shell"><aside class="saas-sidebar"><div class="saas-brand"><span class="saas-logo">↯</span><div><b>Telegram Router</b><small>Automação inteligente</small></div></div><div class="saas-nav-label">PAINEL</div><nav class="saas-nav"><a href="/?page=dashboard"><span class="nav-icon">⌂</span>Visão geral</a><a href="/?page=rules"><span class="nav-icon">≋</span>Regras de roteamento</a><a href="/?page=events"><span class="nav-icon">◷</span>Atividade</a><a href="/?page=integrations"><span class="nav-icon">◇</span>Integrações</a><a href="/connect.php"><span class="nav-icon">♢</span>Telegram</a><a class="active" href="/reset.php"><span class="nav-icon">↺</span>Reset de dados</a></nav><div class="saas-bottom"><div class="saas-status"><i></i>Área protegida</div></div></aside><div class="saas-main"><header class="saas-topbar"><div class="saas-user"><span class="saas-avatar">AD</span><span><?=rh(function_exists('config')?config('panel_username'):'Administrador')?></span></div></header><main class="saas-content reset-page">
<div class="saas-heading reset-heading"><div><span class="saas-kicker">MANUTENÇÃO E SEGURANÇA</span><h1>Reset de dados</h1><p>Exclua dados armazenados de forma controlada, com confirmação em duas etapas e proteção contra ações acidentais.</p></div><div class="reset-shield <?=$phraseConfigured?'is-ready':'is-blocked'?>"><span><?=$phraseConfigured?'✓':'!'?></span><div><b><?=$phraseConfigured?'Proteção ativa':'Reset bloqueado'?></b><small><?=$phraseConfigured?'Frase de segurança configurada':'Configure a frase de segurança para liberar os resets'?></small></div></div></div>
<?php if($error):?><div class="saas-flash error"><?=rh($error)?></div><?php endif;?><?php if($notice):?><div class="saas-flash success"><?=rh($notice)?></div><?php endif;?>
<section class="reset-warning-banner"><div class="reset-warning-icon">!</div><div><b>Operação destrutiva e irreversível</b><p>Um reset remove registros permanentemente. A instalação, o login administrativo, o arquivo <code>.env</code>, a estrutura das tabelas, a frase de segurança e a auditoria dos resets são preservados.</p></div></section>
<section class="reset-grid">
<article class="saas-card reset-card reset-custom"><div class="reset-card-head"><span class="reset-mode-icon">◫</span><div><span class="saas-kicker">MODO 01</span><h2>Reset personalizado</h2><p>Escolha exatamente quais grupos de dados deseja excluir.</p></div></div><div class="reset-category-list">
<?php foreach($categoryOrder as $key):if(!isset($inventory[$key]))continue;$item=$inventory[$key];?><label class="reset-category"><input type="checkbox" class="reset-category-check" value="<?=rh($key)?>" <?=$phraseConfigured?'':'disabled'?>> <span class="reset-check-ui"></span><span class="reset-category-copy"><b><?=rh($item['label'])?></b><small><?=rh($item['description'])?></small></span><span class="reset-count"><?=rh((int)$item['rows'])?></span></label><?php endforeach;?></div><button type="button" class="saas-primary reset-action" id="openCustom" <?=$phraseConfigured?'':'disabled'?>>Revisar reset personalizado →</button></article>
<article class="saas-card reset-card reset-full"><div class="reset-card-head"><span class="reset-mode-icon danger">↺</span><div><span class="saas-kicker">MODO 02</span><h2>Reset completo</h2><p>Zera todos os dados resetáveis da aplicação e desconecta a conta Telegram.</p></div></div><div class="reset-full-summary"><div><span>Itens atualmente identificados</span><strong><?=rh($totalItems)?></strong></div><div><span>Categorias incluídas</span><strong><?=rh(count($inventory))?></strong></div><div><span>Proteções preservadas</span><strong>Instalação + login + auditoria</strong></div></div><div class="reset-danger-note"><b>O Reset Completo inclui:</b><p>histórico, regras, traduções/telemetria, integrações salvas, conexão Telegram, logs, cache e demais dados operacionais identificados.</p></div><button type="button" class="reset-danger-button" id="openFull" <?=$phraseConfigured?'':'disabled'?>>Iniciar reset completo</button></article></section>
<section class="saas-card reset-security-card"><div class="saas-card-head"><div><span class="saas-kicker">SEGUNDA CONFIRMAÇÃO</span><h2>Frase de segurança</h2><p>Funciona como uma autenticação adicional exclusivamente para ações destrutivas.</p></div><span class="reset-security-status <?=$phraseConfigured?'ready':'pending'?>"><?=$phraseConfigured?'Configurada':'Não configurada'?></span></div><form method="post" class="reset-phrase-form" autocomplete="off"><input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>"><input type="hidden" name="action" value="save_phrase"><?php if($phraseConfigured):?><label>Frase atual<input type="password" name="current_phrase" autocomplete="current-password" required placeholder="Informe a frase atual"></label><?php endif;?><label>Nova frase de segurança<input type="password" name="new_phrase" autocomplete="new-password" required minlength="12" placeholder="Mínimo de 12 caracteres, com letras e número"></label><label>Confirmar nova frase<input type="password" name="confirm_phrase" autocomplete="new-password" required minlength="12" placeholder="Repita a nova frase"></label><div class="reset-phrase-footer"><small>A frase é armazenada somente como hash. Após 5 tentativas incorretas, novas tentativas ficam bloqueadas por 5 minutos.</small><button class="saas-primary"><?=$phraseConfigured?'Atualizar frase':'Ativar proteção'?></button></div></form></section></main></div></div>
<dialog id="resetDialog" class="reset-dialog"><form method="post" id="resetExecuteForm" autocomplete="off"><input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>"><input type="hidden" name="action" value="execute_reset"><input type="hidden" name="reset_mode" id="resetMode" value=""><div id="categoryHiddenFields"></div><div class="reset-dialog-icon">!</div><span class="saas-kicker">CONFIRMAÇÃO DE SEGURANÇA</span><h2 id="resetDialogTitle">Confirmar reset</h2><p id="resetDialogLead"></p><div class="reset-dialog-warning"><b>Leia antes de continuar</b><p>Os dados selecionados serão removidos permanentemente. Não existe restauração pelo painel após a confirmação.</p></div><div class="reset-dialog-selection" id="resetDialogSelection"></div><label class="reset-secret-label">Frase de segurança<input type="password" name="security_phrase" required autocomplete="off" placeholder="Digite sua frase secreta"><small>Esta é a segunda autenticação. A frase não é exibida na tela nem gravada nos logs.</small></label><label class="reset-ack"><input type="checkbox" name="acknowledge" value="1" required><span>Li o aviso, revisei o escopo e compreendo que esta ação é irreversível.</span></label><div class="reset-dialog-actions"><button type="button" class="saas-secondary" id="cancelReset">Cancelar</button><button class="reset-danger-button">Confirmar exclusão</button></div></form></dialog>
<script>(()=>{const dialog=document.getElementById('resetDialog'),mode=document.getElementById('resetMode'),hidden=document.getElementById('categoryHiddenFields'),title=document.getElementById('resetDialogTitle'),lead=document.getElementById('resetDialogLead'),selection=document.getElementById('resetDialogSelection'),labels=<?=json_encode(array_map(static fn(array $i):string=>(string)$i['label'],$inventory),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));function openReset(resetMode,categories){hidden.innerHTML='';mode.value=resetMode;if(resetMode==='custom'){if(!categories.length){alert('Selecione pelo menos uma categoria para continuar.');return;}title.textContent='Confirmar reset personalizado';lead.textContent='Somente as categorias selecionadas abaixo serão excluídas.';categories.forEach(category=>{const field=document.createElement('input');field.type='hidden';field.name='categories[]';field.value=category;hidden.appendChild(field);});}else{title.textContent='Confirmar RESET COMPLETO';lead.textContent='Todos os dados resetáveis serão excluídos e a conexão Telegram será encerrada antes da limpeza das credenciais.';}const list=resetMode==='full'?Object.values(labels):categories.map(c=>labels[c]).filter(Boolean);selection.innerHTML='<b>Escopo desta ação</b><ul>'+list.map(item=>'<li>'+esc(item)+'</li>').join('')+'</ul>';dialog.showModal();}document.getElementById('openCustom')?.addEventListener('click',()=>openReset('custom',[...document.querySelectorAll('.reset-category-check:checked')].map(el=>el.value)));document.getElementById('openFull')?.addEventListener('click',()=>openReset('full',[]));document.getElementById('cancelReset')?.addEventListener('click',()=>dialog.close());dialog?.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});})();</script></body></html>