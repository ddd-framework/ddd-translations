<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Support;

use DDD\Infrastructure\Libs\Config;
use ReflectionProperty;

/** Sets one config entry of a test (as an application's file would) and restores the tree */
class ConfigTreeOverride
{
    protected static ?array $configTreeBefore = null;

    /**
     * @param string[] $path The config path, e.g. ['AI', 'skillPackages']
     * @param mixed $value
     * @return void
     */
    public static function apply(array $path, mixed $value): void
    {
        $configTreeProperty = new ReflectionProperty(Config::class, 'configTree');
        $configTree = $configTreeProperty->isInitialized() ? ($configTreeProperty->getValue() ?? []) : [];
        self::$configTreeBefore ??= $configTree;
        $configNode = &$configTree;
        foreach ($path as $pathPart) {
            if (!isset($configNode[$pathPart]) || !is_array($configNode[$pathPart])) {
                $configNode[$pathPart] = [];
            }
            $configNode = &$configNode[$pathPart];
        }
        $configNode = $value;
        unset($configNode);
        $configTreeProperty->setValue(null, $configTree);
    }

    public static function restore(): void
    {
        if (self::$configTreeBefore === null) {
            return;
        }
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, self::$configTreeBefore);
        self::$configTreeBefore = null;
    }
}
