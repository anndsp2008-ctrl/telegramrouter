<?php declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use App\Auth;

Auth::requireLogin();

const RESET_PHRASE_FILE = __DIR__.'/storage/reset-security.json';
const RESET_AUDIT_FILE = __DIR__.'/storage/reset-audit.log';

function rh(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function resetStoragePath(string $relative = ''): string {
    $base = __DIR__.'/storage';
    return $relative === '' ? $base : $base.'/'.ltrim($relative, '/');
}

function resetEnsureStorage(): void {
    $dir = resetStoragePath();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível preparar o armazenamento seguro do módulo.');
    }
}

function resetPhraseConfig(): ?array {
    if (!is_file(RESET_PHRASE_FILE)) return null;
    $raw = @file_get_contents(RESET_PHRASE_FILE);
    if (!is_string($raw) || $raw === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) && !empty($data['hash']) ? $data : null;
}

function resetSavePhrase(string $phrase): void {
    resetEnsureStorage();
    $payload = json_encode([
        'hash' => password_hash($phrase, PASSWORD_DEFAULT),
        'updated_at' => gmdate('c'),
        'version' => 1,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) throw new RuntimeException('Não foi possível preparar a frase de segurança.');
    $tmp = RESET_PHRASE_FILE.'.tmp.'.bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível salvar a frase de segurança.');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, RESET_PHRASE_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível ativar a frase de segurança.');
    }
    @chmod(RESET_PHRASE_FILE, 0600);
}

function resetValidateNewPhrase(string $phrase): void {
    if (mb_strlen($phrase) < 12) throw new InvalidArgumentException('A frase de segurança deve ter pelo menos 12 caracteres.');
    if (mb_strlen($phrase) > 180) throw new InvalidArgumentException('A frase de segurança é longa demais.');
    if (!preg_match('/[A-Za-zÀ-ÿ]/u', $phrase) || !preg_match('/[0-9]/', $phrase)) {
        throw new InvalidArgumentException('Use uma frase com letras e pelo menos um número.');
    }
}

function resetVerifyPhrase(string $phrase): bool {
    $config = resetPhraseConfig();
    if (!$config || !isset($config['hash'])) return false;

    $lockedUntil = (int)($_SESSION['reset_phrase_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        throw new RuntimeException('Muitas tentativas incorretas. Aguarde '.($lockedUntil - time()).' segundos e tente novamente.');
    }

    if (password_verify($phrase, (string)$config['hash'])) {
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

function resetPdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $host = trim((string)getenv('DB_HOST'));
    $port = (int)(getenv('DB_PORT') ?: 3306);
    $name = trim((string)getenv('DB_NAME'));
    $user = (string)getenv('DB_USER');
    $pass = (string)getenv('DB_PASS');
    if ($host === '' || $name === '' || $user === '') throw new RuntimeException('A conexão com o banco não está configurada.');
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function resetQuoteIdentifier(string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) throw new RuntimeException('Nome de tabela inválido detectado.');
    return '`'.$identifier.'`';
}

function resetAllTables(PDO $pdo): array {
    $stmt = $pdo->query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME');
    $tables = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) if (is_string($name) && $name !== '') $tables[] = $name;
    return $tables;
}

function resetIsProtectedTable(string $table): bool {
    $name = strtolower($table);
    if (str_contains($name, 'telegram') || str_contains($name, 'credential')) return false;
    return (bool)preg_match('/(^|_)(admin|admins|user|users|migration|migrations|schema|schemas|auth|password|passwords)($|_)/i', $name);
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

function resetStorageItemCount(): int {
    $count = 0;
    $log = resetStoragePath('worker.log');
    if (is_file($log) && filesize($log) > 0) $count++;
    foreach (['auth-jobs', 'cache'] as $dir) {
        $path = resetStoragePath($dir);
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $item) if ($item->isFile()) $count++;
    }
    return $count;
}

function resetInventory(PDO $pdo): array {
    $categories = [
        'activity' => ['label'=>'Histórico de atividades','description'=>'Eventos, mensagens processadas, entregas e histórico operacional.','tables'=>[],'rows'=>0],
        'rules' => ['label'=>'Regras de roteamento','description'=>'Regras, gatilhos, origem, destino e configurações de encaminhamento.','tables'=>[],'rows'=>0],
        'translation' => ['label'=>'Traduções e telemetria','description'=>'Tentativas de tradução, métricas e registros técnicos de tradução.','tables'=>[],'rows'=>0],
        'integrations' => ['label'=>'Integrações salvas','description'=>'Configurações e chaves armazenadas pelo painel. Variáveis do Railway não são apagadas.','tables'=>[],'rows'=>0],
        'telegram' => ['label'=>'Conexão Telegram','description'=>'Credenciais armazenadas, vínculo da conta e sessão Telegram gerenciada pela aplicação.','tables'=>[],'rows'=>0],
        'storage' => ['label'=>'Logs e arquivos operacionais','description'=>'Worker log, cache e arquivos temporários/autenticação pendente no volume persistente.','tables'=>[],'rows'=>0],
        'other' => ['label'=>'Outros dados da aplicação','description'=>'Demais tabelas de dados que não fazem parte da instalação ou autenticação administrativa.','tables'=>[],'rows'=>0],
    ];
    foreach (resetAllTables($pdo) as $table) {
        if (resetIsProtectedTable($table)) continue;
        $category = resetCategoryForTable($table);
        $categories[$category]['tables'][] = $table;
        try { $categories[$category]['rows'] += (int)$pdo->query('SELECT COUNT(*) FROM '.resetQuoteIdentifier($table))->fetchColumn(); } catch (Throwable) {}
    }
    $categories['storage']['rows'] = resetStorageItemCount();
    return $categories;
}

function resetDeleteDirectoryContents(string $directory): int {
    if (!is_dir($directory)) return 0;
    $deleted = 0;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) @rmdir($path); elseif (@unlink($path)) $deleted++;
    }
    return $deleted;
}

function resetClearStorage(bool $includeTelegram): array {
    resetEnsureStorage();
    $result = ['files'=>0,'telegram_disconnected'=>false];
    $workerLog = resetStoragePath('worker.log');
    if (is_file($workerLog)) { @file_put_contents($workerLog, '', LOCK_EX); @chmod($workerLog, 0600); $result['files']++; }
    $result['files'] += resetDeleteDirectoryContents(resetStoragePath('auth-jobs'));
    $result['files'] += resetDeleteDirectoryContents(resetStoragePath('cache'));
    if ($includeTelegram) {
        $telegramAuth = __DIR__.'/app/TelegramAuth.php';
        if (is_file($telegramAuth)) {
            require_once $telegramAuth;
            if (class_exists('App\\TelegramAuth') && method_exists('App\\TelegramAuth', 'disconnect')) {
                App\TelegramAuth::disconnect();
                $result['telegram_disconnected'] = true;
            }
        }
    }
    return $result;
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
        foreach ($tables as $table) { $stmt=$pdo->prepare('DELETE FROM '.resetQuoteIdentifier($table)); $stmt->execute(); $rows += $stmt->rowCount(); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
    foreach ($tables as $table) { try { $pdo->exec('ALTER TABLE '.resetQuoteIdentifier($table).' AUTO_INCREMENT = 1'); } catch (Throwable) {} }
    return ['tables'=>count($tables),'rows'=>$rows];
}

function resetAudit(string $mode, array $categories, array $result): void {
    resetEnsureStorage();
    $entry = json_encode(['at'=>gmdate('c'),'mode'=>$mode,'categories'=>array_values($categories),'tables_cleared'=>(int)($result['tables']??0),'rows_deleted'=>(int)($result['rows']??0),'files_cleared'=>(int)($result['files']??0),'telegram_disconnected'=>(bool)($result['telegram_disconnected']??false)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (is_string($entry)) { @file_put_contents(RESET_AUDIT_FILE, $entry.PHP_EOL, FILE_APPEND|LOCK_EX); @chmod(RESET_AUDIT_FILE, 0600); }
}

$notice=null; $error=null;
try { $pdo=resetPdo(); $inventory=resetInventory($pdo); } catch(Throwable $e) { $pdo=null; $inventory=[]; $error='Não foi possível carregar o inventário de dados: '.$e->getMessage(); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        Auth::verifyCsrf($_POST['csrf']??null);
        $action=(string)($_POST['action']??'');
        if ($action==='save_phrase') {
            $current=(string)($_POST['current_phrase']??''); $new=trim((string)($_POST['new_phrase']??'')); $confirm=trim((string)($_POST['confirm_phrase']??''));
            $configured=resetPhraseConfig();
            if ($configured && !resetVerifyPhrase($current)) throw new RuntimeException('A frase de segurança atual não confere.');
            resetValidateNewPhrase($new);
            if (!hash_equals($new,$confirm)) throw new InvalidArgumentException('A confirmação da nova frase não confere.');
            resetSavePhrase($new);
            $notice=$configured?'Frase de segurança atualizada com sucesso.':'Frase de segurança configurada. Os resets agora estão protegidos.';
        }
        if ($action==='execute_reset') {
            if (!$pdo instanceof PDO) throw new RuntimeException('O banco não está disponível para executar o reset.');
            if (!resetPhraseConfig()) throw new RuntimeException('Configure a frase de segurança antes de executar qualquer reset.');
            if (empty($_POST['acknowledge'])) throw new RuntimeException('Confirme que leu e compreendeu os riscos desta ação.');
            if (!resetVerifyPhrase((string)($_POST['security_phrase']??''))) throw new RuntimeException('Frase de segurança incorreta.');
            $mode=(string)($_POST['reset_mode']??'');
            if (!in_array($mode,['custom','full'],true)) throw new RuntimeException('Modo de reset inválido.');
            $inventory=resetInventory($pdo); $allowed=array_keys($inventory);
            $selected=$mode==='full'?$allowed:array_values(array_intersect($allowed,array_map('strval',(array)($_POST['categories']??[]))));
            if (!$selected) throw new RuntimeException('Selecione pelo menos uma categoria para o reset personalizado.');
            $tables=[]; foreach($selected as $category) foreach(($inventory[$category]['tables']??[]) as $table) $tables[]=$table;
            $result=resetDeleteTables($pdo,$tables); $storageResult=['files'=>0,'telegram_disconnected'=>false];
            if (in_array('storage',$selected,true)||in_array('telegram',$selected,true)) $storageResult=resetClearStorage(in_array('telegram',$selected,true));
            $result=array_merge($result,$storageResult); resetAudit($mode,$selected,$result);
            $_SESSION['reset_flash']=['message'=>sprintf('Reset concluído: %d registro(s) removido(s) de %d tabela(s)%s.',$result['rows'],$result['tables'],$result['files']>0?' e '.$result['files'].' arquivo(s) operacional(is) limpo(s)':'')];
            header('Location: /reset.php'); exit;
        }
    } catch(Throwable $e) { $error=$e->getMessage(); }
    try { if($pdo instanceof PDO) $inventory=resetInventory($pdo); } catch(Throwable) {}
}

if(isset($_SESSION['reset_flash'])&&is_array($_SESSION['reset_flash'])) { $notice=(string)($_SESSION['reset_flash']['message']??$notice); unset($_SESSION['reset_flash']); }
$phraseConfigured=resetPhraseConfig()!==null; $categoryOrder=['activity','rules','translation','integrations','telegram','storage','other']; $totalRows=0; foreach($inventory as $item)$totalRows+=(int)($item['rows']??0);
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset de dados · Telegram Router</title><link rel="stylesheet" href="/assets/saas.css"><link rel="stylesheet" href="/assets/reset.css?v=1"></head><body><div class="saas-shell"><aside class="saas-sidebar"><div class="saas-brand"><span class="saas-logo">↯</span><div><b>Telegram Router</b><small>Automação inteligente</small></div></div><div class="saas-nav-label">PAINEL</div><nav class="saas-nav"><a href="/?page=dashboard"><span class="nav-icon">⌂</span>Visão geral</a><a href="/?page=rules"><span class="nav-icon">≋</span>Regras de roteamento</a><a href="/?page=events"><span class="nav-icon">◷</span>Atividade</a><a href="/?page=integrations"><span class="nav-icon">◇</span>Integrações</a><a href="/connect.php"><span class="nav-icon">♢</span>Telegram</a><a class="active" href="/reset.php"><span class="nav-icon">↺</span>Reset de dados</a></nav><div class="saas-bottom"><div class="saas-status"><i></i>Área protegida</div></div></aside><div class="saas-main"><header class="saas-topbar"><div class="saas-user"><span class="saas-avatar">AD</span><span><?=rh(function_exists('config')?config('panel_username'):'Administrador')?></span></div></header><main class="saas-content reset-page"><div class="saas-heading reset-heading"><div><span class="saas-kicker">MANUTENÇÃO E SEGURANÇA</span><h1>Reset de dados</h1><p>Exclua dados armazenados de forma controlada, com confirmação em duas etapas e proteção contra ações acidentais.</p></div><div class="reset-shield <?=$phraseConfigured?'is-ready':'is-blocked'?>"><span><?=$phraseConfigured?'✓':'!'?></span><div><b><?=$phraseConfigured?'Proteção ativa':'Reset bloqueado'?></b><small><?=$phraseConfigured?'Frase de segurança configurada':'Configure a frase de segurança para liberar os resets'?></small></div></div></div><?php if($error):?><div class="saas-flash error"><?=rh($error)?></div><?php endif;?><?php if($notice):?><div class="saas-flash success"><?=rh($notice)?></div><?php endif;?><section class="reset-warning-banner"><div class="reset-warning-icon">!</div><div><b>Operação destrutiva e irreversível</b><p>Um reset remove registros permanentemente. A instalação, o login administrativo, a estrutura das tabelas, o arquivo <code>.env</code>, a frase de segurança e o histórico de auditoria dos resets são preservados.</p></div></section><section class="reset-grid"><article class="saas-card reset-card reset-custom"><div class="reset-card-head"><span class="reset-mode-icon">◫</span><div><span class="saas-kicker">MODO 01</span><h2>Reset personalizado</h2><p>Escolha exatamente quais grupos de dados deseja excluir.</p></div></div><div class="reset-category-list"><?php foreach($categoryOrder as $key):if(!isset($inventory[$key]))continue;$item=$inventory[$key];?><label class="reset-category"><input type="checkbox" class="reset-category-check" value="<?=rh($key)?>" <?=$phraseConfigured?'':'disabled'?>><span class="reset-check-ui"></span><span class="reset-category-copy"><b><?=rh($item['label'])?></b><small><?=rh($item['description'])?></small></span><span class="reset-count"><?=rh((int)$item['rows'])?></span></label><?php endforeach;?></div><button type="button" class="saas-primary reset-action" id="openCustom" <?=$phraseConfigured?'':'disabled'?>>Revisar reset personalizado →</button></article><article class="saas-card reset-card reset-full"><div class="reset-card-head"><span class="reset-mode-icon danger">↺</span><div><span class="saas-kicker">MODO 02</span><h2>Reset completo</h2><p>Zera todos os dados resetáveis da aplicação e desconecta a conta Telegram.</p></div></div><div class="reset-full-summary"><div><span>Registros atualmente identificados</span><strong><?=rh($totalRows)?></strong></div><div><span>Categorias incluídas</span><strong><?=rh(count($inventory))?></strong></div><div><span>Proteções preservadas</span><strong>Instalação + login</strong></div></div><div class="reset-danger-note"><b>O Reset Completo inclui:</b><p>histórico, regras, traduções/telemetria, integrações salvas, conexão Telegram, logs, cache e demais dados operacionais identificados.</p></div><button type="button" class="reset-danger-button" id="openFull" <?=$phraseConfigured?'':'disabled'?>>Iniciar reset completo</button></article></section><section class="saas-card reset-security-card"><div class="saas-card-head"><div><span class="saas-kicker">SEGUNDA CONFIRMAÇÃO</span><h2>Frase de segurança</h2><p>Funciona como uma autenticação adicional exclusivamente para ações destrutivas.</p></div><span class="reset-security-status <?=$phraseConfigured?'ready':'pending'?>"><?=$phraseConfigured?'Configurada':'Não configurada'?></span></div><form method="post" class="reset-phrase-form" autocomplete="off"><input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>"><input type="hidden" name="action" value="save_phrase"><?php if($phraseConfigured):?><label>Frase atual<input type="password" name="current_phrase" autocomplete="current-password" required placeholder="Informe a frase atual"></label><?php endif;?><label>Nova frase de segurança<input type="password" name="new_phrase" autocomplete="new-password" required minlength="12" placeholder="Mínimo de 12 caracteres, com letras e número"></label><label>Confirmar nova frase<input type="password" name="confirm_phrase" autocomplete="new-password" required minlength="12" placeholder="Repita a nova frase"></label><div class="reset-phrase-footer"><small>A frase não é exibida nem armazenada em texto puro. Após 5 tentativas incorretas, o módulo bloqueia novas tentativas por 5 minutos.</small><button class="saas-primary"><?=$phraseConfigured?'Atualizar frase':'Ativar proteção'?></button></div></form></section></main></div></div><dialog id="resetDialog" class="reset-dialog"><form method="post" id="resetExecuteForm" autocomplete="off"><input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>"><input type="hidden" name="action" value="execute_reset"><input type="hidden" name="reset_mode" id="resetMode" value=""><div id="categoryHiddenFields"></div><div class="reset-dialog-icon">!</div><span class="saas-kicker">CONFIRMAÇÃO DE SEGURANÇA</span><h2 id="resetDialogTitle">Confirmar reset</h2><p id="resetDialogLead"></p><div class="reset-dialog-warning"><b>Leia antes de continuar</b><p>Os dados selecionados serão removidos permanentemente e não poderão ser recuperados pelo painel.</p></div><div class="reset-dialog-selection" id="resetDialogSelection"></div><label class="reset-secret-label">Frase de segurança<input type="password" name="security_phrase" required autocomplete="off" placeholder="Digite sua frase secreta"><small>Esta é a segunda autenticação. A frase não é mostrada nesta tela.</small></label><label class="reset-ack"><input type="checkbox" name="acknowledge" value="1" required><span>Li o aviso, revisei o escopo e compreendo que esta ação é irreversível.</span></label><div class="reset-dialog-actions"><button type="button" class="saas-secondary" id="cancelReset">Cancelar</button><button class="reset-danger-button">Confirmar exclusão</button></div></form></dialog><script>(()=>{const dialog=document.getElementById('resetDialog'),mode=document.getElementById('resetMode'),hidden=document.getElementById('categoryHiddenFields'),title=document.getElementById('resetDialogTitle'),lead=document.getElementById('resetDialogLead'),selection=document.getElementById('resetDialogSelection'),labels=<?=json_encode(array_map(static fn(array $i):string=>(string)$i['label'],$inventory),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;function esc(s){return s.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}function open(m,cats){hidden.innerHTML='';mode.value=m;if(m==='custom'){if(!cats.length){alert('Selecione pelo menos uma categoria para continuar.');return}title.textContent='Confirmar reset personalizado';lead.textContent='Somente as categorias selecionadas abaixo serão excluídas.';cats.forEach(c=>{const f=document.createElement('input');f.type='hidden';f.name='categories[]';f.value=c;hidden.appendChild(f)})}else{title.textContent='Confirmar RESET COMPLETO';lead.textContent='Todos os dados resetáveis serão excluídos e a conexão Telegram será encerrada.'}const list=m==='full'?Object.values(labels):cats.map(c=>labels[c]).filter(Boolean);selection.innerHTML='<b>Escopo desta ação</b><ul>'+list.map(x=>'<li>'+esc(x)+'</li>').join('')+'</ul>';dialog.showModal()}document.getElementById('openCustom')?.addEventListener('click',()=>open('custom',[...document.querySelectorAll('.reset-category-check:checked')].map(e=>e.value)));document.getElementById('openFull')?.addEventListener('click',()=>open('full',[]));document.getElementById('cancelReset')?.addEventListener('click',()=>dialog.close());dialog?.addEventListener('click',e=>{if(e.target===dialog)dialog.close()})})();</script></body></html>