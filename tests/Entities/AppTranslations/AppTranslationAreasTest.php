<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Entities\AppTranslations;

use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationAreas;
use DDD\Domain\Common\Entities\Languages\Language;
use DDD\Domain\Common\Entities\Texts\Text;
use PHPUnit\Framework\TestCase;

/** The areas of the UI text keys: from the config, the area of a key, the prefixes a query leaves out */
class AppTranslationAreasTest extends TestCase
{
    protected function createThreeAreas(): AppTranslationAreas
    {
        return AppTranslationAreas::fromConfig([
            'APP' => ['label' => 'the mobile app', 'keyPrefix' => 'mobile.', 'isSyncedFromCode' => true, 'writingStyles' => ['INFORMAL', 'SOMETHING']],
            'WEB' => ['label' => 'the web', 'keyPrefix' => 'web.', 'isSyncedFromCode' => true],
            'PARTNER_WEB' => ['keyPrefix' => 'web.partner.'],
            'SERVER' => ['label' => 'mails', 'liveNote' => 'within an hour'],
        ]);
    }

    public function testWithoutConfigOneAreaHoldsEveryKey(): void
    {
        $appTranslationAreas = AppTranslationAreas::fromConfig(null);
        self::assertSame([AppTranslationAreas::DEFAULT_AREA_NAME], $appTranslationAreas->getNames());
        self::assertSame(AppTranslationAreas::DEFAULT_AREA_NAME, $appTranslationAreas->getAreaOfKey('mobile.ride.start')->name);
        self::assertSame([], $appTranslationAreas->getExcludedKeyPrefixesOfArea($appTranslationAreas->getRemainderArea()));
    }

    public function testAKeyBelongsToTheAreaWithTheLongestPrefixItStartsWith(): void
    {
        $appTranslationAreas = $this->createThreeAreas();
        self::assertSame('APP', $appTranslationAreas->getAreaOfKey('mobile.ride.start')->name);
        self::assertSame('WEB', $appTranslationAreas->getAreaOfKey('web.menu.home')->name);
        self::assertSame('PARTNER_WEB', $appTranslationAreas->getAreaOfKey('web.partner.home')->name);
        self::assertSame('SERVER', $appTranslationAreas->getAreaOfKey('Your prize is on its way')->name);
        self::assertTrue($appTranslationAreas->getAreaOfKey('mobile.x')->isSyncedFromCode);
        self::assertSame('SERVER', $appTranslationAreas->findByName('server')?->name);
        self::assertNull($appTranslationAreas->findByName('NONE'));
    }

    public function testAQueryOverAnAreaLeavesOutTheKeysOfTheAreasInsideIt(): void
    {
        $appTranslationAreas = $this->createThreeAreas();
        self::assertSame(['web.partner.'], $appTranslationAreas->getExcludedKeyPrefixesOfArea($appTranslationAreas->findByName('WEB')));
        self::assertSame([], $appTranslationAreas->getExcludedKeyPrefixesOfArea($appTranslationAreas->findByName('APP')));
        self::assertSame(['mobile.', 'web.', 'web.partner.'], $appTranslationAreas->getExcludedKeyPrefixesOfArea($appTranslationAreas->getRemainderArea()));
    }

    public function testAnAreaKeepsItsOwnWritingStylesElseThoseOfTheLanguage(): void
    {
        $appTranslationAreas = $this->createThreeAreas();
        $language = new Language();
        $language->supportedWritingStyles = Language::WRITING_STYLE_FORMAL_AND_INFORMAL;
        self::assertSame([Text::WRITING_STYLE_INFORMAL], $appTranslationAreas->findByName('APP')->getWritingStylesOfLanguage($language));
        self::assertSame([Text::WRITING_STYLE_FORMAL, Text::WRITING_STYLE_INFORMAL], $appTranslationAreas->findByName('SERVER')->getWritingStylesOfLanguage($language));
        self::assertSame('within an hour', $appTranslationAreas->findByName('SERVER')->liveNote);
        self::assertSame('APP (the mobile app, keys mobile.*)', $appTranslationAreas->findByName('APP')->getDescription());
    }

    public function testAConfigWithoutARemainderAreaGetsOne(): void
    {
        $appTranslationAreas = AppTranslationAreas::fromConfig(['APP' => ['keyPrefix' => 'mobile.']]);
        self::assertSame(['APP', AppTranslationAreas::DEFAULT_AREA_NAME], $appTranslationAreas->getNames());
        self::assertSame(AppTranslationAreas::DEFAULT_AREA_NAME, $appTranslationAreas->getAreaOfKey('Hello')->name);
    }
}
