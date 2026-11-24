<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Services\AppTranslations;

use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKey;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationsMaintenanceService;
use DDD\Tests\Translations\Support\ConfigTreeOverride;
use PHPUnit\Framework\TestCase;

/** A new text keeps the placeholders of its reference text; the areas come from the config */
class AppTranslationPlaceholdersTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigTreeOverride::restore();
        parent::tearDown();
    }

    public function testATextThatLeavesOutAPlaceholderIsRefused(): void
    {
        $appTranslationsMaintenanceService = new AppTranslationsMaintenanceService();
        $appTranslationKey = new AppTranslationKey();
        $appTranslationKey->key = 'Hello %name%, your {count} rides';
        self::assertSame(['%name%', '{count}'], AppTranslationsMaintenanceService::extractPlaceholders($appTranslationKey->key));
        self::assertNull($appTranslationsMaintenanceService->findPlaceholderProblem($appTranslationKey, $appTranslationKey->key, 'Hallo %name%, deine {count} Fahrten', 'de'));
        $placeholderProblem = $appTranslationsMaintenanceService->findPlaceholderProblem($appTranslationKey, $appTranslationKey->key, 'Hallo, deine Fahrten', 'de');
        self::assertStringContainsString('%name%, {count}', (string)$placeholderProblem);
    }

    public function testTheAreasAreReadFromTheConfig(): void
    {
        ConfigTreeOverride::apply(['Common', 'Translations', 'AppTexts'], ['areas' => ['APP' => ['keyPrefix' => 'mobile.', 'isSyncedFromCode' => true], 'SERVER' => []]]);
        $appTranslationsMaintenanceService = new AppTranslationsMaintenanceService();
        self::assertSame(['APP', 'SERVER'], $appTranslationsMaintenanceService->getAreas()->getNames());
        self::assertSame('APP', $appTranslationsMaintenanceService->getAreaOfKey('mobile.home')->name);
    }

    public function testTheModulesOwnConfigHasTheServerArea(): void
    {
        $appTextsConfig = require dirname(__DIR__, 3) . '/config/app/Common/Translations/AppTexts.php';
        ConfigTreeOverride::apply(['Common', 'Translations', 'AppTexts'], $appTextsConfig);
        self::assertSame(['SERVER'], (new AppTranslationsMaintenanceService())->getAreas()->getNames());
    }
}
