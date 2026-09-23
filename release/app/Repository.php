<?php declare(strict_types=1);
namespace App;

final class Repository
{
    public static function rules(int $page=1, int $perPage=20): array
    {
        $page=max(1,$page); $perPage=max(1,min(100,$perPage));
        $s=Database::pdo()->prepare('SELECT * FROM router_rules ORDER BY id DESC LIMIT :lim OFFSET :off');
        $s->bindValue(':lim',$perPage,\PDO::PARAM_INT);
        $s->bindValue(':off',($page-1)*$perPage,\PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function allRules(): array
    {
        $sql='SELECT * FROM router_rules ORDER BY id DESC';
        try {
            return Database::pdo()->query($sql)->fetchAll();
        } catch (\PDOException $e) {
            $driverCode=(int)($e->errorInfo[1]??0);
            $description=strtolower($e->getMessage());
            $stale=in_array($driverCode,[2006,2013],true)
                || str_contains($description,'server has gone away')
                || str_contains($description,'lost connection to mysql server');
            if(!$stale) throw $e;
            Database::forgetConnection();
            error_log('ROUTER_DB_RECONNECT stale_connection retry=1');
            // This SELECT is read-only: a single retry cannot duplicate a forwarded message.
            return Database::pdo()->query($sql)->fetchAll();
        }
    }
    public static function rulesTotal(): int { return (int)Database::pdo()->query('SELECT COUNT(*) FROM router_rules')->fetchColumn(); }
    public static function rule(int $id): ?array { $s=Database::pdo()->prepare('SELECT * FROM router_rules WHERE id=?'); $s->execute([$id]); return $s->fetch() ?: null; }

    private static function normalizeProvider(array $in): string
    {
        $provider=(string)($in['translation_provider']??'');
        if(!in_array($provider,['azure','gemini','google_cloud','workers_ai'],true)) throw new \InvalidArgumentException('Selecione Azure, Gemini, Google Cloud ou Workers AI.');
        return $provider;
    }

    public static function saveRule(array $in): void
    {
        $provider=self::normalizeProvider($in);
        $sql='INSERT INTO router_rules (source_chat,trigger_text,destination_chat,media_mode,remove_links,remove_emojis,custom_removals,translation_enabled,translation_provider,translation_source_language,translation_target_language,translation_fallback_original,enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';
        Database::pdo()->prepare($sql)->execute([
            (string)$in['source_chat'],(string)($in['trigger_text']??''),(string)$in['destination_chat'],(string)$in['media_mode'],
            (int)!empty($in['remove_links']),(int)!empty($in['remove_emojis']),trim((string)($in['custom_removals']??'')," \t\r\n"),
            (int)!empty($in['translation_enabled']),$provider,(string)($in['translation_source_language']??'auto'),
            (string)($in['translation_target_language']??'pt-BR'),(int)!empty($in['translation_fallback_original']),(int)!empty($in['enabled'])
        ]);
    }

    public static function updateRule(int $id, array $in): void
    {
        $provider=self::normalizeProvider($in);
        $sql='UPDATE router_rules SET source_chat=?,trigger_text=?,destination_chat=?,media_mode=?,remove_links=?,remove_emojis=?,custom_removals=?,translation_enabled=?,translation_provider=?,translation_source_language=?,translation_target_language=?,translation_fallback_original=?,enabled=? WHERE id=?';
        Database::pdo()->prepare($sql)->execute([
            (string)$in['source_chat'],(string)($in['trigger_text']??''),(string)$in['destination_chat'],(string)$in['media_mode'],
            (int)!empty($in['remove_links']),(int)!empty($in['remove_emojis']),trim((string)($in['custom_removals']??'')," \t\r\n"),
            (int)!empty($in['translation_enabled']),$provider,(string)($in['translation_source_language']??'auto'),
            (string)($in['translation_target_language']??'pt-BR'),(int)!empty($in['translation_fallback_original']),(int)!empty($in['enabled']),$id
        ]);
    }

    public static function deleteRule(int $id): void { Database::pdo()->prepare('DELETE FROM router_rules WHERE id=?')->execute([$id]); }

    public static function events(int $page=1, int $perPage=20): array
    {
        $page=max(1,$page); $perPage=20;
        $s=Database::pdo()->prepare('SELECT * FROM router_events ORDER BY id DESC LIMIT :lim OFFSET :off');
        $s->bindValue(':lim',$perPage,\PDO::PARAM_INT);
        $s->bindValue(':off',($page-1)*$perPage,\PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }
    public static function eventsTotal(): int { return (int)Database::pdo()->query('SELECT COUNT(*) FROM router_events')->fetchColumn(); }
    public static function stats(): array
    {
        $r=Database::pdo()->query("SELECT COUNT(*) total, SUM(enabled=1) active FROM router_rules")->fetch();
        $e=Database::pdo()->query("SELECT COUNT(*) forwarded FROM router_events WHERE status='forwarded' AND created_at >= CURDATE()")->fetch();
        return ['total'=>(int)($r['total']??0),'active'=>(int)($r['active']??0),'forwarded'=>(int)($e['forwarded']??0)];
    }
    public static function overview(): array
    {
        $s=self::stats();
        $counts=Database::pdo()->query("SELECT status,COUNT(*) total FROM router_events GROUP BY status")->fetchAll();
        foreach($counts as $row) $s[(string)$row['status']]=(int)$row['total'];
        $s['last_event']=Database::pdo()->query('SELECT * FROM router_events ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        return $s;
    }

    public static function credentials(): array
    {
        $rows=Database::pdo()->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'telegram_%'")->fetchAll();
        $out=[];
        foreach($rows as $row) $out[$row['setting_key']]=Crypto::decrypt($row['setting_value']);
        return $out;
    }

    public static function saveCredential(string $key,string $value): void
    {
        $s=Database::pdo()->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        $s->execute([$key,Crypto::encrypt($value)]);
    }

    public static function integration(string $key): string
    {
        $s=Database::pdo()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
        $s->execute([$key]);
        return Crypto::decrypt($s->fetchColumn() ?: null);
    }

    public static function saveIntegration(string $key,string $value): void { self::saveCredential($key,$value); }

    public static function integrationExists(string $key): bool
    {
        $s=Database::pdo()->prepare('SELECT COUNT(*) FROM app_settings WHERE setting_key=? AND setting_value IS NOT NULL AND setting_value<>\'\'');
        $s->execute([$key]);
        return (int)$s->fetchColumn()>0;
    }

    public static function deleteIntegration(string $key): void
    {
        $s=Database::pdo()->prepare('DELETE FROM app_settings WHERE setting_key=?');
        $s->execute([$key]);
    }

    public static function translationPrimaryProvider(): string
    {
        $value=self::integration('translation_primary_provider');
        return in_array($value,['azure','gemini','google_cloud','workers_ai'],true)?$value:'azure';
    }

    public static function translationFallbackProvider(): string
    {
        $value=self::integration('translation_fallback_provider');
        return in_array($value,['azure','gemini','google_cloud','workers_ai','none'],true)?$value:'none';
    }

    public static function saveTranslationRouting(string $primary,string $fallback): void
    {
        if(!in_array($primary,['azure','gemini','google_cloud','workers_ai'],true)) throw new \InvalidArgumentException('Provedor principal inválido.');
        if(!in_array($fallback,['azure','gemini','google_cloud','workers_ai','none'],true)) throw new \InvalidArgumentException('Provedor de fallback inválido.');
        if($fallback!== 'none' && $fallback===$primary) throw new \InvalidArgumentException('O fallback deve ser diferente do provedor principal.');
        self::saveIntegration('translation_primary_provider',$primary);
        self::saveIntegration('translation_fallback_provider',$fallback);
    }

    public static function workerStatus(): ?array { return Database::pdo()->query("SELECT * FROM worker_status WHERE worker_key='telegram-global'")->fetch() ?: null; }

    /** @param array{source_chat?:string,message_id?:int,rule_id?:int,provider:string,success:bool,fallback_used?:bool,http_code?:int|null,source_language?:string|null,target_language:string,latency_ms:int,text_chars?:int,text_bytes?:int,error_text?:string|null,context?:string} $data */
    public static function recordTranslationAttempt(array $data): void
    {
        $sql='INSERT INTO translation_attempts(source_chat,message_id,rule_id,provider,success,fallback_used,http_code,source_language,target_language,latency_ms,text_chars,text_bytes,error_text,context,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())';
        $s=Database::pdo()->prepare($sql);
        $s->execute([
            (string)($data['source_chat']??''),(int)($data['message_id']??0),($data['rule_id']??null),
            (string)$data['provider'],(int)!empty($data['success']),(int)!empty($data['fallback_used']),
            $data['http_code']??null,$data['source_language']??null,(string)$data['target_language'],
            max(0,(int)$data['latency_ms']),max(0,(int)($data['text_chars']??0)),max(0,(int)($data['text_bytes']??0)),$data['error_text']??null,(string)($data['context']??'message')
        ]);
    }

    public static function saveProviderTest(string $provider,bool $ok,int $latencyMs,?int $httpCode,?string $error): void
    {
        if(!in_array($provider,['azure','gemini','google_cloud','workers_ai'],true)) return;
        $sql='INSERT INTO translation_provider_status(provider,last_test_ok,last_test_at,last_test_latency_ms,last_test_http_code,last_test_error) VALUES(?, ?, NOW(), ?, ?, ?) ON DUPLICATE KEY UPDATE last_test_ok=VALUES(last_test_ok),last_test_at=VALUES(last_test_at),last_test_latency_ms=VALUES(last_test_latency_ms),last_test_http_code=VALUES(last_test_http_code),last_test_error=VALUES(last_test_error)';
        Database::pdo()->prepare($sql)->execute([$provider,(int)$ok,max(0,$latencyMs),$httpCode,$error]);
    }

    public static function providerTestStatus(string $provider): ?array
    {
        $s=Database::pdo()->prepare('SELECT * FROM translation_provider_status WHERE provider=?');
        $s->execute([$provider]);
        return $s->fetch() ?: null;
    }

    public static function translationProviderStats(string $provider): array
    {
        $s=Database::pdo()->prepare("SELECT COUNT(*) total, SUM(success=1) successful, SUM(success=0) failures, ROUND(AVG(latency_ms)) avg_latency, MAX(created_at) last_use FROM translation_attempts WHERE provider=? AND context='message'");
        $s->execute([$provider]);
        $row=$s->fetch() ?: [];
        $last=Database::pdo()->prepare("SELECT latency_ms FROM translation_attempts WHERE provider=? AND context='message' ORDER BY id DESC LIMIT 1");
        $last->execute([$provider]);
        return [
            'total'=>(int)($row['total']??0),
            'successful'=>(int)($row['successful']??0),
            'failures'=>(int)($row['failures']??0),
            'avg_latency'=>(int)($row['avg_latency']??0),
            'last_latency'=>(int)($last->fetchColumn() ?: 0),
            'last_use'=>$row['last_use']??null,
        ];
    }
}
