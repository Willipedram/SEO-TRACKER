<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Logging\Logger;

final class AuthFactory
{
    public function __construct(private readonly string $basePath, private readonly Config $config) {}

    public function make(?SessionStore $session = null): Authenticator
    {
        $database = (new ConnectionFactory($this->config))->connect();
        $logPath = (string) $this->config->get('logging.path', 'storage/logs/application.log');
        if (!str_starts_with($logPath, '/')) {
            $logPath = $this->basePath . '/' . $logPath;
        }
        $key = (string) $this->config->get('app.key');
        return new Authenticator(
            $database,
            new PasswordHasher(),
            $session ?? new NativeSessionStore(),
            new LoginRateLimiter($database, $key, (int) $this->config->get('auth.max_attempts', 5), (int) $this->config->get('auth.attempt_window', 900)),
            new Logger($logPath, (string) $this->config->get('logging.level', 'info')),
            (int) $this->config->get('auth.idle_timeout', 1800),
            (int) $this->config->get('auth.absolute_timeout', 43200),
            $key,
        );
    }

    public function dashboard(int $userId): array
    {
        $database = (new ConnectionFactory($this->config))->connect();
        $websites = (int)($database->fetchOne('SELECT COUNT(*) AS total FROM websites WHERE owner_user_id=:user AND archived_at IS NULL', ['user'=>$userId])['total'] ?? 0);
        $keywords = (int)($database->fetchOne('SELECT COUNT(*) AS total FROM keywords JOIN websites ON websites.id=keywords.website_id WHERE websites.owner_user_id=:user AND websites.archived_at IS NULL', ['user'=>$userId])['total'] ?? 0);
        $history = $database->fetchAll('SELECT rank_results.keyword_id,rank_results.position,rank_results.observed_at FROM rank_results JOIN websites ON websites.id=rank_results.website_id WHERE websites.owner_user_id=:user ORDER BY rank_results.keyword_id,rank_results.observed_at DESC,rank_results.id DESC', ['user'=>$userId]);
        $positions=[]; $improved=0; $dropped=0; $last=null;
        foreach($history as $row){ $key=(int)$row['keyword_id']; if(count($positions[$key]??[])<2)$positions[$key][]=$row['position']===null?null:(int)$row['position']; $observed=(string)$row['observed_at']; if($last===null||$observed>$last)$last=$observed; }
        foreach($positions as $values){ if(count($values)<2||$values[0]===null||$values[1]===null)continue; if($values[0]<$values[1])$improved++; elseif($values[0]>$values[1])$dropped++; }
        $current=array_map(static fn(array $values):?int=>$values[0]??null,$positions);
        return ['websites'=>$websites,'keywords'=>$keywords,'improved'=>$improved,'dropped'=>$dropped,'top10'=>count(array_filter($current,static fn(?int $p):bool=>$p!==null&&$p<=10)),'top3'=>count(array_filter($current,static fn(?int $p):bool=>$p!==null&&$p<=3)),'first'=>count(array_filter($current,static fn(?int $p):bool=>$p===1)),'last_check'=>$last];
    }
}
