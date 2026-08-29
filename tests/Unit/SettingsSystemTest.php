<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Rbac\AuthorizationException;
use App\Core\Settings\ModuleManager;
use App\Core\Settings\SettingsManager;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use PDO;
use Tests\TestCase;

final class SettingsSystemTest extends TestCase
{
    public function testModuleMetadataToggleCoreSafetyAndPersistence(): void
    {
        [$database,, $modules] = $this->services(); $rows = $modules->all(1); $this->assertTrue(in_array('core', array_column($rows, 'key'), true)); $this->assertTrue(in_array('search_console', array_column($rows, 'key'), true));
        $database->execute("INSERT INTO search_console_connections (public_id,user_id,status,granted_scopes,created_at,updated_at) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,'connected','scope',:now,:now)", ['now'=>gmdate('Y-m-d H:i:s')]); $modules->setEnabled(1,'search_console',false); $this->assertSame(0,(int)$database->fetchOne("SELECT enabled FROM modules WHERE module_key='search_console'")['enabled']); $this->assertSame(1,(int)$database->fetchOne('SELECT COUNT(*) AS total FROM search_console_connections')['total']); $modules->setEnabled(1,'search_console',true);
        $locked=false; try{$modules->setEnabled(1,'core',false);}catch(\InvalidArgumentException){$locked=true;} $this->assertTrue($locked); $this->assertSame(1,(int)$database->fetchOne("SELECT enabled FROM modules WHERE module_key='core'")['enabled']);
    }

    public function testTypedSystemUserModuleSettingsAndCacheInvalidation(): void
    {
        [$database,$settings] = $this->services(); $this->assertSame('SEO Tracker',$settings->get('system.application_name')); $settings->set(1,'system.application_name','Production Tracker'); $this->assertSame('Production Tracker',$settings->get('system.application_name'));
        $settings->set(1,'user.locale','fa','1'); $settings->set(1,'user.items_per_page','25','1'); $this->assertSame('fa',$settings->get('user.locale','1')); $this->assertSame(25,$settings->get('user.items_per_page','1'));
        $settings->set(1,'module.reports.default_page_size','75','reports'); $this->assertSame(75,$settings->get('module.reports.default_page_size','reports'));
        $mismatch=false; try{$settings->set(1,'module.search_console.default_range_days',28,'reports');}catch(\InvalidArgumentException){$mismatch=true;} $this->assertTrue($mismatch);
        $fresh = new SettingsManager($database,new Authorization($database),new AuditRecorder($database)); $this->assertSame('Production Tracker',$fresh->get('system.application_name'));
    }

    public function testValidationPermissionsScopeIsolationAndAudit(): void
    {
        [$database,$settings,$modules]=$this->services(); $database->execute("INSERT INTO users (name,email,password_hash,created_at,updated_at) VALUES ('Member','member@example.test','x',:now,:now)",['now'=>gmdate('Y-m-d H:i:s')]);
        $settings->set(2,'user.locale','fa','2'); $this->assertSame('fa',$settings->get('user.locale','2')); $cross=false; try{$settings->set(2,'user.locale','en','1');}catch(\InvalidArgumentException){$cross=true;} $this->assertTrue($cross);
        $denied=false; try{$settings->set(2,'system.locale','fa');}catch(AuthorizationException){$denied=true;} $this->assertTrue($denied); $moduleDenied=false; try{$modules->setEnabled(2,'reports',false);}catch(AuthorizationException){$moduleDenied=true;} $this->assertTrue($moduleDenied);
        foreach ([['user.items_per_page',1000,'1'],['system.locale','xx',null],['system.timezone','Mars/Olympus',null],['unknown.key','x',null]] as [$key,$value,$id]) { $invalid=false; try{$settings->set(1,$key,$value,$id);}catch(\InvalidArgumentException){$invalid=true;} $this->assertTrue($invalid); }
        $this->assertTrue((int)$database->fetchOne("SELECT COUNT(*) AS total FROM audit_logs WHERE action IN ('setting.changed','module.disabled','module.enabled')")['total']>0);
    }

    public function testFeatureFlagsAreTypedOperationalSwitches(): void
    {
        [, $settings]=$this->services(); $this->assertTrue($settings->featureEnabled('feature.rank_manual_checks')); $settings->set(1,'feature.rank_manual_checks','false'); $this->assertSame(false,$settings->featureEnabled('feature.rank_manual_checks')); $settings->set(1,'feature.search_console_sync','0'); $this->assertSame(false,$settings->featureEnabled('feature.search_console_sync'));
        $invalid=false; try{$settings->featureEnabled('system.locale');}catch(\InvalidArgumentException){$invalid=true;} $this->assertTrue($invalid);
    }

    private function services(): array
    {
        $pdo=new PDO('sqlite::memory:',options:[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); (new SchemaInstaller())->install($pdo,'Administrator','admin@example.test','correct-horse-battery','Tracker'); $database=new Database($pdo); (new MigrationRunner($database,new MigrationDiscovery(dirname(__DIR__,2).'/database/migrations'),'1.7.0',13,new Logger(sys_get_temp_dir().'/settings-migration.log')))->run(); $authorization=new Authorization($database); $audit=new AuditRecorder($database); return [$database,new SettingsManager($database,$authorization,$audit),new ModuleManager($database,$authorization,$audit,dirname(__DIR__,2).'/app/Modules')];
    }
}
