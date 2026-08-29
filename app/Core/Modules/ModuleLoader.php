<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Http\Router;
use RuntimeException;
use Throwable;

final class ModuleLoader
{
    private array $loaded = [];

    public function __construct(private readonly array $paths, private readonly array $enabled, private readonly array $optional = []) {}

    public function load(Router $router, ?ModuleContext $context = null): void
    {
        $manifests = [];
        foreach ($this->enabled as $name) {
            if (!is_string($name) || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
                throw new RuntimeException('Invalid module name.');
            }
            $isOptional = in_array($name, $this->optional, true);
            $directory = $this->find($name, $isOptional);
            if ($directory === null) {
                $this->loaded[] = ['name' => $name, 'version' => null, 'status' => 'unavailable'];
                continue;
            }
            try {
                $manifestPath = $directory . '/module.json';
                $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                if (($manifest['name'] ?? null) !== $name || !is_string($manifest['provider'] ?? null)) {
                    throw new RuntimeException(sprintf('Invalid manifest for module %s.', $name));
                }
                if (!isset($manifest['version']) || !is_string($manifest['version']) || !preg_match('/^\d+\.\d+\.\d+$/', $manifest['version'])) {
                    throw new RuntimeException(sprintf('Invalid version for module %s.', $name));
                }
                $dependencies = $manifest['dependencies'] ?? [];
                if (!is_array($dependencies) || array_filter($dependencies, static fn (mixed $dependency): bool => !is_string($dependency)) !== []) {
                    throw new RuntimeException(sprintf('Invalid dependencies for module %s.', $name));
                }
            } catch (Throwable $exception) {
                if (!$isOptional) throw $exception;
                $this->loaded[] = ['name' => $name, 'version' => null, 'status' => 'failed'];
                continue;
            }
            $manifests[$name] = $manifest + ['optional' => $isOptional];
        }

        $order = [];
        $visiting = [];
        $visit = function (string $name) use (&$visit, &$order, &$visiting, $manifests): void {
            if (in_array($name, $order, true)) {
                return;
            }
            if (isset($visiting[$name])) {
                throw new RuntimeException(sprintf('Circular module dependency involving %s.', $name));
            }
            if (!isset($manifests[$name])) {
                throw new RuntimeException(sprintf('Module dependency %s is not enabled.', $name));
            }
            $visiting[$name] = true;
            foreach ($manifests[$name]['dependencies'] ?? [] as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$name]);
            $order[] = $name;
        };
        foreach (array_keys($manifests) as $name) {
            $visit($name);
        }

        foreach ($order as $name) {
            $manifest = $manifests[$name];
            try {
                $provider = $manifest['provider'];
                $module = new $provider();
                if (!$module instanceof Module) {
                    throw new RuntimeException(sprintf('Provider for %s must implement Module.', $name));
                }
                $module->register($router, $context);
                $this->loaded[] = ['name' => $name, 'version' => $manifest['version'], 'status' => 'loaded'];
            } catch (Throwable $exception) {
                if (!($manifest['optional'] ?? false)) throw $exception;
                $this->loaded[] = ['name' => $name, 'version' => $manifest['version'], 'status' => 'failed'];
            }
        }
    }

    public function loaded(): array
    {
        return $this->loaded;
    }

    private function find(string $name, bool $optional): ?string
    {
        foreach ($this->paths as $path) {
            $candidate = rtrim((string) $path, '/') . '/' . $name;
            if (is_file($candidate . '/module.json')) {
                return $candidate;
            }
        }
        if ($optional) return null;
        throw new RuntimeException(sprintf('Enabled module %s was not found.', $name));
    }
}
