<?php

declare(strict_types=1);

namespace DDD\Modules\Translations;

use DDD\Infrastructure\Modules\DDDModule;

/**
 * Texts, entity translations, app translations and embeddings, and, beside `mgamadeus/ddd-agents` 0.3 or later, the
 * agent's tools for the UI texts (skill `Translations.AppTexts`, package `translations.appTexts`).
 */
class TranslationsModule extends DDDModule
{
    /** @var string A class of ddd-agents ≥ 0.3.0 (the right constants): the agent part of the module registers only beside it */
    public const string AGENTS_PACKAGE_CLASS = 'DDD\\Domain\\AI\\Entities\\Conversations\\Entitlements\\AgentAccountRights';

    public static function getSourcePath(): string
    {
        return __DIR__ . '/../..';
    }

    public static function getConfigPath(): ?string
    {
        return __DIR__ . '/../../../config/app';
    }

    public static function getPublicServiceNamespaces(): array
    {
        return [
            'DDD\\Domain\\Common\\Services\\AppTranslations\\',
            'DDD\\Domain\\Common\\Services\\Translations\\',
        ];
    }

    public static function getExcludePatterns(): array
    {
        return [...parent::getExcludePatterns(), 'Dtos'];
    }

    public static function getNamespacesRequiringClasses(): array
    {
        return [
            'DDD\\Presentation\\Mcp\\Admin\\Translations\\' => self::AGENTS_PACKAGE_CLASS,
        ];
    }
}
