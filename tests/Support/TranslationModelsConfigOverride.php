<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Support;

use DDD\Infrastructure\Libs\Config;
use ReflectionProperty;

/** Sets the `Common.TranslationModels` config of a test (as an application's file would) and restores the tree */
class TranslationModelsConfigOverride
{
    protected static ?array $configTreeBefore = null;

    public static function apply(array $translationModelsConfig): void
    {
        $configTreeProperty = new ReflectionProperty(Config::class, 'configTree');
        $configTree = $configTreeProperty->isInitialized() ? ($configTreeProperty->getValue() ?? []) : [];
        self::$configTreeBefore ??= $configTree;
        $configTree['Common']['TranslationModels'] = $translationModelsConfig;
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
