<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Core\Deployment\ReleaseBuilder;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class SourceReplacementUpdateE2ETest extends TestCase
{
    public function testRealTwoSourceReplacementPreservesVersionOneDatabase(): void
    {
        $root = dirname(__DIR__, 2);
        $work = sys_get_temp_dir() . '/seo-update-e2e-' . bin2hex(random_bytes(5));
        mkdir($work, 0700, true);

        try {
            $v2Archive = $work . '/v2.zip';
            (new ReleaseBuilder($root))->build($v2Archive);
            $this->extract($v2Archive, $work . '/v1');

            // Construct a reproducible predecessor release from the distribution: schema 12,
            // before the managed-settings migration shipped in application 2.2.0.
            file_put_contents($work . '/v1/config/version.php', "<?php\ndeclare(strict_types=1);\nreturn ['application' => '2.1.0', 'schema' => 12];\n");
            unlink($work . '/v1/database/migrations/2026_08_24_020000_settings_system.php');
            (new ReleaseBuilder($work . '/v1'))->build($work . '/v1-release.zip');
            $this->remove($work . '/v1');
            $this->extract($work . '/v1-release.zip', $work . '/v1');

            $database = $work . '/persistent/application.sqlite';
            mkdir(dirname($database), 0700, true);
            $before = $this->runFixture($work . '/v1', $database, 'seed');
            $this->assertSame(12, $before['schema']);
            $this->assertSame('2.1.0', $before['source']);
            $this->assertSame(2, $before['users']);
            $this->assertSame(2, $before['keywords']);
            $this->assertSame(1, $before['rank_results']);
            $this->assertSame(1, $before['search_console_data']);

            $backup = $database . '.pre-2.2.0.bak';
            $this->assertTrue(copy($database, $backup));
            $this->assertSame(hash_file('sha256', $database), hash_file('sha256', $backup));

            // Delete all predecessor source. The database and its verified backup live outside it.
            $this->remove($work . '/v1');
            $this->extract($v2Archive, $work . '/v2');
            $result = $this->runFixture($work . '/v2', $database, 'upgrade');

            $this->assertSame(302, $result['home_before']);
            $this->assertSame('/update', $result['home_location']);
            $this->assertSame(200, $result['plan_status']);
            $this->assertSame(403, $result['unauthorized_status']);
            $this->assertSame(200, $result['authorized_status']);
            $this->assertSame(14, $result['schema']);
            $this->assertSame('2.4.0', $result['source']);
            $this->assertSame(1, $result['settings_migration']);
            $this->assertSame(0, $result['migration_failures']);
            $this->assertSame(302, $result['home_after']);
            $this->assertSame('/login', $result['after_location']);
            $this->assertSame(2, $result['users']);
            $this->assertSame(1, $result['roles_preserved']);
            $this->assertSame(1, $result['permissions_preserved']);
            $this->assertSame(1, $result['website_preserved']);
            $this->assertSame(1, $result['settings_preserved']);
            $this->assertSame(2, $result['keywords']);
            $this->assertSame(1, $result['rank_preserved']);
            $this->assertSame(1, $result['module_preserved']);
            $this->assertSame(1, $result['oauth_preserved']);
            $this->assertSame(1, $result['search_console_preserved']);
            $this->assertSame(1, $result['auth_works']);
            $this->assertSame(1, $result['managed_settings_exists']);
            $this->assertSame(14, $result['noop_schema']);
            $this->assertSame(1, $result['settings_migration_after_noop']);
        } finally {
            $this->remove($work);
        }
    }

    /** @return array<string, mixed> */
    private function runFixture(string $release, string $database, string $mode): array
    {
        $script = tempnam(sys_get_temp_dir(), 'seo-update-fixture-');
        if (!is_string($script)) throw new RuntimeException('Unable to create fixture script.');
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$release = $argv[1]; $path = $argv[2]; $mode = $argv[3];
require $release . '/bootstrap/autoload.php';
use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Core\Update\UpdaterController;
$pdo = new PDO('sqlite:' . $path); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db = new Database($pdo);
$version = require $release . '/config/version.php';
$config = new Config(['database'=>['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>$path]]], 'version'=>$version, 'logging'=>['path'=>$release.'/storage/logs/update.log','level'=>'error']]);
if ($mode === 'seed') {
    (new SchemaInstaller())->install($pdo, 'Update Admin', 'admin@example.test', 'correct-horse-battery', 'Persistent Tracker');
    (new MigrationRunner($db, new MigrationDiscovery($release.'/database/migrations'), $version['application'], $version['schema'], new Logger($release.'/storage/logs/update.log')))->run();
    $now='2026-08-26 10:00:00';
    $pdo->exec("INSERT INTO users(name,email,password_hash,email_verified_at,created_at,updated_at) VALUES('Analyst','analyst@example.test','".password_hash('analyst-password-123', PASSWORD_DEFAULT)."','$now','$now','$now')");
    $pdo->exec("INSERT INTO roles(role_key,name,created_at,updated_at) VALUES('analyst','Analyst','$now','$now')");
    $pdo->exec("INSERT INTO user_roles(user_id,role_id,assigned_at) VALUES(2,2,'$now')");
    $pdo->exec("INSERT INTO websites(public_id,owner_user_id,site_name,normalized_domain,canonical_url,protocol,description,timezone,status,created_at,updated_at) VALUES('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,'Preserved Site','example.test','https://example.test','https','before upgrade','Asia/Tehran','active','$now','$now')");
    foreach ([['desktop','seo tracker'],['mobile','سئو فارسی']] as $i=>$k) { $id=str_repeat((string)($i+2),32); $q=$pdo->quote($k[1]); $device=$pdo->quote($k[0]); $pdo->exec("INSERT INTO keywords(public_id,website_id,keyword_text,normalized_keyword,target_url,search_engine,country_code,language_code,device,active,created_at,updated_at) VALUES('$id',1,$q,$q,'https://example.test/page','google','IR','fa',$device,1,'$now','$now')"); }
    $pdo->exec("INSERT INTO rank_check_requests(public_id,user_id,website_id,keyword_id,keyword_text,target_url,search_engine,country_code,language_code,requested_device,execution_source,adapter_key,status,attempt_count,available_at,created_at,started_at,completed_at) VALUES('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',1,1,1,'seo tracker','https://example.test/page','google','IR','fa','desktop','client','fixture','completed',1,'$now','$now','$now','$now')");
    $pdo->exec("INSERT INTO rank_execution_attempts(public_id,request_id,attempt_number,execution_source,adapter_key,adapter_version,requested_device,execution_device,user_agent_profile,network_context,status,leased_by,lease_token_hash,lease_expires_at,started_at,completed_at,retryable) VALUES('cccccccccccccccccccccccccccccccc',1,1,'client','fixture','1.0','desktop','desktop','fixture','local','completed','fixture','".str_repeat('a',64)."','$now','$now','$now',0)");
    $pdo->exec("INSERT INTO rank_results(public_id,request_id,attempt_id,website_id,keyword_id,result_type,position,ranking_url,checked_depth,search_engine,country_code,language_code,requested_device,execution_device,execution_source,adapter_key,adapter_version,observed_at,created_at) VALUES('dddddddddddddddddddddddddddddddd',1,1,1,1,'ranked',7,'https://example.test/page',100,'google','IR','fa','desktop','desktop','client','fixture','1.0','$now','$now')");
    $envelope='encrypted-envelope-must-survive';
    $quotedEnvelope=$pdo->quote($envelope); $pdo->exec("INSERT INTO search_console_connections(public_id,user_id,provider_subject,status,granted_scopes,credential_envelope,credential_key_version,token_expires_at,created_at,updated_at) VALUES('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',1,'provider-user','connected','scope',$quotedEnvelope,'v1','2027-01-01 00:00:00','$now','$now')");
    $pdo->exec("INSERT INTO search_console_properties(public_id,connection_id,website_id,property_uri,property_type,permission_level,selected,created_at,updated_at) VALUES('ffffffffffffffffffffffffffffffff',1,1,'sc-domain:example.test','domain','siteOwner',1,'$now','$now')");
    $pdo->exec("INSERT INTO search_console_syncs(public_id,user_id,website_id,property_id,start_date,end_date,search_type,status,phase,attempt_count,available_at,rows_fetched,rows_saved,created_at,completed_at,updated_at) VALUES('99999999999999999999999999999999',1,1,1,'2026-08-01','2026-08-25','web','completed','complete',1,'$now',1,1,'$now','$now','$now')");
    $hash=str_repeat('f',64); $pdo->exec("INSERT INTO search_console_data(dimension_hash,website_id,property_id,last_sync_id,data_date,query_text,page_url,device,country,search_type,clicks,impressions,ctr,average_position,created_at,updated_at) VALUES('$hash',1,1,1,'2026-08-25','seo tracker','https://example.test/page','desktop','IR','web',12,120,0.1,8.5,'$now','$now')");
    $pdo->exec("UPDATE modules SET enabled=1 WHERE module_key='search_console'");
    $out=['schema'=>(int)$pdo->query('SELECT schema_version FROM app_installations')->fetchColumn(),'source'=>$pdo->query('SELECT source_version FROM app_installations')->fetchColumn(),'users'=>(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),'keywords'=>(int)$pdo->query('SELECT COUNT(*) FROM keywords')->fetchColumn(),'rank_results'=>(int)$pdo->query('SELECT COUNT(*) FROM rank_results')->fetchColumn(),'search_console_data'=>(int)$pdo->query('SELECT COUNT(*) FROM search_console_data')->fetchColumn()];
} else {
    $controller=new UpdaterController($release,$config); $home=$controller->home(); $show=$controller->show();
    $bad=$controller->run(new Request('POST','/update',body:['email'=>'admin@example.test','password'=>'wrong-password']));
    $good=$controller->run(new Request('POST','/update',body:['email'=>'admin@example.test','password'=>'correct-horse-battery']));
    $after=$controller->home();
    $runner=new MigrationRunner($db,new MigrationDiscovery($release.'/database/migrations'),$version['application'],$version['schema'],new Logger($release.'/storage/logs/update.log')); $noop=$runner->run();
    $out=['home_before'=>$home->status,'home_location'=>$home->headers['Location']??'','plan_status'=>$show->status,'unauthorized_status'=>$bad->status,'authorized_status'=>$good->status,'schema'=>(int)$pdo->query('SELECT schema_version FROM app_installations')->fetchColumn(),'source'=>$pdo->query('SELECT source_version FROM app_installations')->fetchColumn(),'settings_migration'=>(int)$pdo->query("SELECT COUNT(*) FROM migrations WHERE migration='2026_08_24_020000_settings_system'")->fetchColumn(),'migration_failures'=>(int)$pdo->query('SELECT COUNT(*) FROM migration_failures')->fetchColumn(),'home_after'=>$after->status,'after_location'=>$after->headers['Location']??'','users'=>(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),'roles_preserved'=>(int)$pdo->query("SELECT COUNT(*) FROM roles WHERE role_key='analyst'")->fetchColumn(),'permissions_preserved'=>(int)$pdo->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn()>0?1:0,'website_preserved'=>(int)$pdo->query("SELECT COUNT(*) FROM websites WHERE normalized_domain='example.test' AND timezone='Asia/Tehran'")->fetchColumn(),'settings_preserved'=>(int)$pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key='application.name' AND setting_value='Persistent Tracker'")->fetchColumn(),'keywords'=>(int)$pdo->query('SELECT COUNT(*) FROM keywords')->fetchColumn(),'rank_preserved'=>(int)$pdo->query("SELECT COUNT(*) FROM rank_results WHERE position=7 AND ranking_url='https://example.test/page'")->fetchColumn(),'module_preserved'=>(int)$pdo->query("SELECT COUNT(*) FROM modules WHERE module_key='search_console' AND enabled=1")->fetchColumn(),'oauth_preserved'=>(int)$pdo->query("SELECT COUNT(*) FROM search_console_connections WHERE credential_envelope='encrypted-envelope-must-survive'")->fetchColumn(),'search_console_preserved'=>(int)$pdo->query("SELECT COUNT(*) FROM search_console_data WHERE clicks=12 AND impressions=120 AND average_position=8.5")->fetchColumn(),'auth_works'=>(int)password_verify('correct-horse-battery',(string)$pdo->query("SELECT password_hash FROM users WHERE email='admin@example.test'")->fetchColumn()),'managed_settings_exists'=>(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='managed_settings'")->fetchColumn(),'noop_schema'=>$noop->installedSchemaVersion,'settings_migration_after_noop'=>(int)$pdo->query("SELECT COUNT(*) FROM migrations WHERE migration='2026_08_24_020000_settings_system'")->fetchColumn()];
}
echo json_encode($out, JSON_THROW_ON_ERROR);
PHP;
        file_put_contents($script, $code);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($release) . ' ' . escapeshellarg($database) . ' ' . escapeshellarg($mode) . ' 2>&1', $lines, $status);
        unlink($script);
        if ($status !== 0) throw new RuntimeException("Update fixture failed:\n" . implode("\n", $lines));
        $decoded = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('Update fixture returned invalid output.');
        return $decoded;
    }

    private function extract(string $archive, string $destination): void
    {
        mkdir($destination, 0700, true); $zip = new ZipArchive();
        if ($zip->open($archive) !== true || !$zip->extractTo($destination)) throw new RuntimeException('Unable to extract release fixture.');
        $zip->close();
    }

    private function remove(string $path): void
    {
        if (!file_exists($path)) return;
        if (is_file($path) || is_link($path)) { unlink($path); return; }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) $this->remove($path . '/' . $item);
        rmdir($path);
    }
}
