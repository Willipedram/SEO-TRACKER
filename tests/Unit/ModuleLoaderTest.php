<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Router;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleLoader;
use App\Core\Modules\ModuleContext;
use RuntimeException;
use Tests\TestCase;

final class DependencyModule implements Module
{
    public function register(Router $router, ?ModuleContext $context = null): void {}
}

final class DependantModule implements Module
{
    public function register(Router $router, ?ModuleContext $context = null): void {}
}

final class ModuleLoaderTest extends TestCase
{
    public function testDependenciesAreLoadedBeforeDependants(): void
    {
        $path = $this->modules([
            'Dependant' => ['provider' => DependantModule::class, 'dependencies' => ['Dependency']],
            'Dependency' => ['provider' => DependencyModule::class, 'dependencies' => []],
        ]);
        $loader = new ModuleLoader([$path], ['Dependant', 'Dependency']);
        $loader->load(new Router());
        $this->assertSame(['Dependency', 'Dependant'], array_column($loader->loaded(), 'name'));
        $this->remove($path);
    }

    public function testCircularDependenciesAreRejected(): void
    {
        $path = $this->modules([
            'Dependant' => ['provider' => DependantModule::class, 'dependencies' => ['Dependency']],
            'Dependency' => ['provider' => DependencyModule::class, 'dependencies' => ['Dependant']],
        ]);
        $rejected = false;
        try {
            (new ModuleLoader([$path], ['Dependant', 'Dependency']))->load(new Router());
        } catch (RuntimeException) {
            $rejected = true;
        }
        $this->remove($path);
        $this->assertTrue($rejected);
    }

    private function modules(array $modules): string
    {
        $path = sys_get_temp_dir() . '/seo-modules-' . bin2hex(random_bytes(4));
        foreach ($modules as $name => $manifest) {
            mkdir($path . '/' . $name, 0700, true);
            file_put_contents($path . '/' . $name . '/module.json', json_encode(['name' => $name, 'version' => '1.0.0'] + $manifest, JSON_THROW_ON_ERROR));
        }
        return $path;
    }

    private function remove(string $path): void
    {
        foreach (glob($path . '/*/module.json') ?: [] as $file) {
            unlink($file);
            rmdir(dirname($file));
        }
        rmdir($path);
    }
}
