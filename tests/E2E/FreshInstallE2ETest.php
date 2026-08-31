<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Core\Deployment\ReleaseBuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;
use ZipArchive;

final class FreshInstallE2ETest extends TestCase
{
    public function testExtractedReleaseCanCreateACompleteFreshApplicationState(): void
    {
        $workspace = sys_get_temp_dir() . '/seo-fresh-e2e-' . bin2hex(random_bytes(6));
        $release = $workspace . '/seo-tracker.zip';
        $extracted = $workspace . '/release';
        mkdir($workspace, 0750, true);
        try {
            $built = (new ReleaseBuilder(dirname(__DIR__, 2)))->build($release);
            $this->assertTrue($built['files'] > 100);
            $this->assertSame($built['checksum'], hash_file('sha256', $release));
            $zip = new ZipArchive();
            $this->assertSame(true, $zip->open($release));
            $this->assertTrue($zip->getExternalAttributesName('bin/console', $opsys, $attributes));
            $this->assertTrue(((int) $attributes >> 16 & 0111) !== 0);
            mkdir($extracted, 0750);
            $this->assertTrue($zip->extractTo($extracted));
            $zip->close();

            foreach (['.htaccess', 'public/.htaccess', 'public/index.php', 'bootstrap/app.php', 'database/migrations', 'storage/logs/.gitignore', 'storage/framework/sessions/.gitignore', 'bin/console'] as $required) {
                $this->assertTrue(file_exists($extracted . '/' . $required), 'Missing release path: ' . $required);
            }
            foreach (['.env', '.git', '.github', 'dist', 'tests', 'storage/installed.lock', 'storage/logs/application.log'] as $forbidden) {
                $this->assertTrue(!file_exists($extracted . '/' . $forbidden), 'Release leaked generated/private path: ' . $forbidden);
            }

            $this->withServer($extracted, function (string $origin) use ($extracted): void {
                $cookie = '';
                $home = $this->http($origin . '/');
                $this->assertSame(302, $home['status']);
                $this->assertSame('/install', $home['location']);
                $frontController = $this->http($origin . '/index.php');
                $this->assertSame(302, $frontController['status']);
                $this->assertSame('/install', $frontController['location']);
                $installer = $this->http($origin . '/install', cookie: $cookie);
                $this->assertSame(200, $installer['status']);
                $this->assertTrue(str_contains($this->decoded($installer['body']), 'بررسی محیط میزبانی'));
                $this->assertTrue(str_contains($this->decoded($installer['body']), 'ادامه به تنظیم پایگاه داده'));
                $databaseForm = $this->http($origin . '/install?step=database', cookie: $cookie);
                $this->assertSame(200, $databaseForm['status']);
                $failedDatabase = $this->http($origin . '/install', ['_token' => $this->csrf($databaseForm['body']), 'action' => 'database', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'fresh_database', 'username' => 'fresh_user', 'password' => 'unavailable'], $cookie);
                $this->assertSame(422, $failedDatabase['status']);
                $this->assertTrue(str_contains($this->decoded($failedDatabase['body']), 'اتصال به پایگاه داده برقرار نشد'), $this->decoded($failedDatabase['body']));
                $this->assertTrue(!file_exists($extracted . '/.env'));
            });

            $probe = $workspace . '/probe.php';
            file_put_contents($probe, $this->probeScript());
            $command = [PHP_BINARY, $probe, $extracted];
            $pipes = [];
            $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $workspace, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $workspace]);
            $this->assertTrue(is_resource($process));
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $exit = proc_close($process);
            $this->assertSame(0, $exit, 'Fresh probe failed: ' . trim((string) $stderr));
            $result = json_decode((string) $stdout, true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame(14, $result['schema']);
            $this->assertSame(1, $result['users']);
            $this->assertSame(true, $result['login']);
            $this->assertSame(1, $result['websites']);
            $this->assertSame(1, $result['keywords']);
            $this->assertSame('blocked_no_adapter', $result['rank_tracking']);
            $this->assertSame('disabled', $result['search_console']);
            $this->assertSame(404, $result['installer_status']);
            $this->assertTrue(in_array('SearchConsole', $result['modules'], true));
            $this->assertSame(false, $result['secret_logged']);
            $this->withServer($extracted, fn (string $origin) => $this->exerciseInstalledHttpFlows($origin));
        } finally {
            $this->remove($workspace);
        }
    }

    private function exerciseInstalledHttpFlows(string $origin): void
    {
        $cookie = '';
        $login = $this->http($origin . '/login', cookie: $cookie);
        $this->assertSame(200, $login['status']);
        $this->assertPersianPage($login['body'], 'ورود به حساب');
        $token = $this->csrf($login['body']);
        $signedIn = $this->http($origin . '/login', ['_token' => $token, 'email' => 'fresh-admin@example.test', 'password' => 'fresh-install-password'], $cookie);
        $this->assertSame(303, $signedIn['status'], 'Login response: ' . strip_tags($signedIn['body']));
        $this->assertSame('/account', $signedIn['location']);
        $account = $this->http($origin . '/account', cookie: $cookie);
        $this->assertSame(200, $account['status']);
        $this->assertPersianPage($account['body'], 'حساب کاربری');
        $this->assertTrue(str_contains($account['body'], 'Fresh Administrator'));

        $websiteForm = $this->http($origin . '/websites/create', cookie: $cookie);
        $this->assertSame(200, $websiteForm['status']);
        $this->assertPersianPage($websiteForm['body'], 'افزودن وب‌سایت');
        $created = $this->http($origin . '/websites/create', ['_token' => $this->csrf($websiteForm['body']), 'name' => 'HTTP Fresh Website', 'url' => 'https://http-fresh.example.test', 'description' => 'created over HTTP'], $cookie);
        $this->assertSame(303, $created['status'], 'Website response: ' . strip_tags($created['body']));
        $this->assertTrue(preg_match('#^/websites/dashboard\?id=([a-f0-9]{32})$#', $created['location'], $websiteMatch) === 1);
        $website = $websiteMatch[1];
        $dashboard = $this->http($origin . $created['location'], cookie: $cookie);
        $this->assertSame(200, $dashboard['status']);
        $this->assertPersianPage($dashboard['body'], 'نمای کلی ردیابی');
        $this->assertTrue(str_contains($dashboard['body'], 'HTTP Fresh Website'));

        $keywordForm = $this->http($origin . '/keywords/create?website=' . $website, cookie: $cookie);
        $this->assertSame(200, $keywordForm['status']);
        $this->assertPersianPage($keywordForm['body'], 'افزودن کلیدواژه');
        $keywordCreated = $this->http($origin . '/keywords/create', ['_token' => $this->csrf($keywordForm['body']), 'website' => $website, 'keyword' => 'http fresh keyword', 'target_url' => 'https://http-fresh.example.test', 'search_engine' => 'google', 'country' => 'US', 'language' => 'en', 'device' => 'mobile', 'active' => '1'], $cookie);
        $this->assertSame(303, $keywordCreated['status'], 'Keyword response: ' . strip_tags($keywordCreated['body']));
        $keywords = $this->http($origin . '/keywords?website=' . $website, cookie: $cookie);
        $this->assertSame(200, $keywords['status']);
        $this->assertPersianPage($keywords['body'], 'کلیدواژه‌ها');
        $this->assertTrue(str_contains($keywords['body'], 'http fresh keyword'));
        $this->assertTrue(preg_match('#/keywords/edit\?website=[a-f0-9]{32}&amp;id=([a-f0-9]{32})#', $keywords['body'], $keywordMatch) === 1);
        $keyword = $keywordMatch[1];

        $edit = $this->http($origin . '/keywords/edit?website=' . $website . '&id=' . $keyword, cookie: $cookie);
        $this->assertPersianPage($edit['body'], 'ویرایش کلیدواژه');
        $rank = $this->http($origin . '/rank-checks', ['_token' => $this->csrf($edit['body']), 'website' => $website, 'keyword' => $keyword], $cookie);
        $this->assertSame(303, $rank['status']);
        $rankDashboard = $this->http($origin . '/rank-dashboard?website=' . $website, cookie: $cookie);
        $this->assertSame(200, $rankDashboard['status']);
        $this->assertPersianPage($rankDashboard['body'], 'داشبورد رتبه‌بندی');
        $this->assertTrue(str_contains($rankDashboard['body'], 'manual-rank-start'));
        $searchConsole = $this->http($origin . '/websites/search-console?website=' . $website, cookie: $cookie);
        $this->assertTrue(in_array($searchConsole['status'], [200, 503], true));
        $this->assertPersianPage($searchConsole['body'], 'سرچ کنسول');
        foreach ([['/admin/users','کاربران'],['/admin/roles','نقش‌ها'],['/admin/roles/permissions?id=1','تخصیص مجوزهای نقش'],['/reports','گزارش‌ها'],['/settings','تنظیمات من'],['/admin/settings','مدیریت ماژول‌ها و تنظیمات']] as [$path,$copy]) {
            $screen=$this->http($origin.$path,cookie:$cookie); $this->assertSame(200,$screen['status']); $this->assertPersianPage($screen['body'],$copy);
        }
        $installer = $this->http($origin . '/install', cookie: $cookie);
        $this->assertSame(404, $installer['status']);
    }

    /** @return array{status:int,body:string,location:string} */
    private function http(string $url, ?array $post = null, string &$cookie = ''): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10]);
        if ($cookie !== '') curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post, '', '&', PHP_QUERY_RFC3986)]);
        $response = curl_exec($curl);
        if (!is_string($response)) throw new \RuntimeException('HTTP probe failed: ' . curl_error($curl));
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        curl_close($curl);
        if (preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $headers, $match)) $cookie = $match[1];
        $location = preg_match('/^Location:\s*([^\r\n]+)/mi', $headers, $match) ? trim($match[1]) : '';
        return compact('status', 'body', 'location');
    }

    private function csrf(string $html): string
    {
        if (!preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $match)) throw new \RuntimeException('CSRF token missing from form.');
        return $match[1];
    }

    private function withServer(string $base, callable $callback): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        if (!is_resource($socket)) throw new \RuntimeException('Unable to reserve test port: ' . $message);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $base . '/public', $base . '/public/index.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $base, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => dirname($base)]);
        if (!is_resource($process)) throw new \RuntimeException('Unable to start test host.');
        fclose($pipes[0]);
        try {
            $origin = 'http://127.0.0.1:' . $port;
            $ready = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $probe = @fsockopen('127.0.0.1', $port, $code, $error, 0.1);
                if (is_resource($probe)) { fclose($probe); $ready = true; break; }
                usleep(20000);
            }
            if (!$ready) throw new \RuntimeException('Test host did not start.');
            $callback($origin);
        } finally {
            proc_terminate($process);
            stream_get_contents($pipes[1]); fclose($pipes[1]);
            stream_get_contents($pipes[2]); fclose($pipes[2]);
            proc_close($process);
        }
    }

    private function probeScript(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
$base = $argv[1];
require $base . '/bootstrap/autoload.php';
$databasePath = $base . '/storage/fresh-install.sqlite';
$pdo = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new App\Core\Installer\SchemaInstaller())->install($pdo, 'Fresh Administrator', 'fresh-admin@example.test', 'fresh-install-password', 'Fresh SEO Tracker');
$database = new App\Core\Database\Database($pdo);
$version = require $base . '/config/version.php';
(new App\Core\Update\MigrationRunner($database, new App\Core\Update\MigrationDiscovery($base . '/database/migrations'), $version['application'], $version['schema'], new App\Core\Logging\Logger($base . '/storage/logs/migration.log')))->run();

$generated = (new App\Core\Installer\EnvironmentWriter($base . '/.env.generated'))->prepare(new App\Core\Installer\DatabaseConfiguration('localhost', 3306, 'fresh_database', 'fresh_user', 'fresh-database-password'), 'https://fresh.example.test');
if (fileperms($generated) % 01000 !== 0600 || !str_contains((string) file_get_contents($generated), 'APP_KEY=')) throw new RuntimeException('Protected environment generation failed.');
unlink($generated);

$environment = [
    'APP_ENV="production"', 'APP_DEBUG="false"', 'APP_URL="http://localhost"',
    'APP_KEY="base64:' . base64_encode(random_bytes(32)) . '"', 'APP_TIMEZONE="UTC"',
    'APP_LOCALE="fa"', 'APP_TRUSTED_HOSTS="localhost,127.0.0.1"', 'LOG_LEVEL="debug"',
    'LOG_PATH="storage/logs/application.log"', 'DB_CONNECTION="sqlite"',
    'DB_DATABASE="' . addcslashes($databasePath, "\\\"") . '"', 'SESSION_SECURE="false"',
    'SESSION_SAME_SITE="Lax"', 'SESSION_LIFETIME="43200"', 'RANK_ADAPTER=""',
];
file_put_contents($base . '/.env', implode(PHP_EOL, $environment) . PHP_EOL, LOCK_EX);
chmod($base . '/.env', 0600);
file_put_contents($base . '/storage/installed.lock', App\Core\Installer\SchemaInstaller::APPLICATION_ID . PHP_EOL, LOCK_EX);
chmod($base . '/storage/installed.lock', 0600);

$application = require $base . '/bootstrap/app.php';
$_SESSION = [];
session_save_path($base . '/storage/framework/sessions');
session_start();
$auth = (new App\Core\Auth\AuthFactory($base, $application->config()))->make();
$login = $auth->login('fresh-admin@example.test', 'fresh-install-password', '127.0.0.1');
$authorization = new App\Core\Rbac\Authorization($database);
$audit = new App\Core\Rbac\AuditRecorder($database);
$websites = new App\Modules\Websites\Application\WebsiteManager($database, $authorization, $audit);
$website = $websites->create(1, App\Modules\Websites\Domain\WebsiteInput::from('Fresh Website', 'https://fresh-site.example.test', 'fresh install'));
$keywords = new App\Modules\Keywords\Application\KeywordManager($database, $authorization, $audit);
$keyword = $keywords->create(1, $website, App\Modules\Keywords\Domain\KeywordInput::from(['keyword' => 'fresh install keyword', 'target_url' => 'https://fresh-site.example.test', 'search_engine' => 'google', 'country' => 'US', 'language' => 'en', 'device' => 'desktop', 'active' => true], ['google'], ['desktop', 'mobile'], ['US']));

$rank = 'unexpected';
try {
    (new App\Modules\RankTracking\Application\RankCheckManager($database, $authorization, $audit, new App\Modules\RankTracking\Infrastructure\RankAdapterRegistry(), ''))->submit(1, $website, $keyword);
} catch (InvalidArgumentException $exception) {
    $rank = $exception->getMessage() === 'No approved Rank Tracking adapter is configured.' ? 'blocked_no_adapter' : 'wrong_error';
}
$search = new App\Modules\SearchConsole\Application\SearchConsoleManager($database, $authorization, $audit, ['client_id' => '', 'client_secret' => '', 'redirect_uri' => '', 'encryption_key' => '', 'scopes' => []]);
$logger = new App\Core\Logging\Logger($base . '/storage/logs/fresh.log', 'debug');
$logger->error('fresh probe', ['refresh_token' => 'must-not-appear']);
$log = (string) file_get_contents($base . '/storage/logs/fresh.log');
$installer = new App\Core\Installer\InstallerController($base, $application->config());
$installerResponse = $installer->show(new App\Core\Http\Request('GET', '/install', headers: ['host' => 'localhost']));
$dashboard = (new App\Modules\RankTracking\Application\RankDashboardService($database, $authorization))->dashboard(1, $website);
echo json_encode([
    'schema' => (int) $database->fetchOne('SELECT schema_version FROM app_installations')['schema_version'],
    'users' => (int) $database->fetchOne('SELECT COUNT(*) AS total FROM users')['total'],
    'login' => $login->success,
    'websites' => count($websites->list(1)), 'keywords' => count($keywords->list(1, $website)),
    'dashboard_rows' => count($dashboard['rows']), 'rank_tracking' => $rank,
    'search_console' => $search->status(1)['status'], 'installer_status' => $installerResponse->status,
    'modules' => array_column($application->modules()->loaded(), 'name'),
    'secret_logged' => str_contains($log, 'must-not-appear'),
], JSON_THROW_ON_ERROR);
PHP;
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) return;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($path);
    }

    private function decoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function assertPersianPage(string $html, string $copy): void
    {
        $decoded=$this->decoded($html);
        $this->assertTrue(str_contains($decoded,'lang="fa"'),$decoded);
        $this->assertTrue(str_contains($decoded,'dir="rtl"'),$decoded);
        $this->assertTrue(str_contains($decoded,$copy),$decoded);
    }
}
