<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Config\Config;
use App\Core\Config\Environment;

require __DIR__ . '/autoload.php';

$basePath = dirname(__DIR__);
Environment::load($basePath . '/.env');

return Application::build($basePath, Config::load($basePath . '/config'));
