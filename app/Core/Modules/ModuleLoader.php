<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Http\Router;
use RuntimeException;

final class ModuleLoader
{
    private array $loaded = [];

    public function __construct(private readonly array $paths, private readonly array $enabled) {}

    public function load(Router $router, ?ModuleContext $context = null): void
    {
        $manifests = [];
        foreach ($this->enabled as $name) {
            if (!is_string($name) || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
                throw new RuntimeException('Invalid module name.');
            }
            $directory = $this->find($name);
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
            $manifests[$name] = $manifest;
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
            $provider = $manifest['provider'];
            $module = new $provider();
            if (!$module instanceof Module) {
                throw new RuntimeException(sprintf('Provider for %s must implement Module.', $name));
            }
            $module->register($router, $context);
            $this->loaded[] = ['name' => $name, 'version' => $manifest['version']];
        }
    }

    public function loaded(): array
    {
        return $this->loaded;
    }

    private function find(string $name): string
    {
        foreach ($this->paths as $path) {
            $candidate = rtrim((string) $path, '/') . '/' . $name;
            if (is_file($candidate . '/module.json')) {
                return $candidate;
            }
        }
        throw new RuntimeException(sprintf('Enabled module %s was not found.', $name));
    }
}
