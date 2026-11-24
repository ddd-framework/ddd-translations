<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Presentation\Mcp;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationAreas;
use DDD\Domain\Common\Entities\Languages\Language;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Modules\Translations\TranslationsModule;
use DDD\Presentation\Mcp\Admin\Common\Exceptions\AdminToolNotSavedException;
use DDD\Presentation\Mcp\Admin\Translations\TranslationsAppTextsMCPPackage;
use DDD\Tests\Translations\Support\ConfigTreeOverride;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** The skill `Translations.AppTexts` and its package `translations.appTexts`: names, right, tools, lint, writing styles */
class TranslationsAppTextsPackageTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigTreeOverride::restore();
        parent::tearDown();
    }

    public function testThePackageIdIsTheSkillNameAndTheRightIsManageTexts(): void
    {
        self::assertSame('translations.appTexts', TranslationsAppTextsMCPPackage::$name);
        self::assertFileExists(dirname(__DIR__, 3) . '/config/app/AI/Skills/Translations/AppTexts.md');
        self::assertSame([AgentAccountRights::MANAGE_TEXTS], MCPPackage::findRequiredAccountRightNamesOfClass(TranslationsAppTextsMCPPackage::class));
        self::assertSame(
            [TranslationsModule::AGENTS_PACKAGE_CLASS],
            array_values(TranslationsModule::getNamespacesRequiringClasses())
        );
        self::assertSame(AgentAccountRights::class, TranslationsModule::AGENTS_PACKAGE_CLASS);
    }

    public function testEveryToolDeclaresItsEffectAndIsNamedInTheDescription(): void
    {
        $effectsByToolName = [];
        foreach ((new ReflectionClass(TranslationsAppTextsMCPPackage::class))->getMethods() as $reflectionMethod) {
            if ($reflectionMethod->getAttributes(MCPTool::class) === []) {
                continue;
            }
            $effectAttributes = $reflectionMethod->getAttributes(MCPToolEffect::class);
            self::assertCount(1, $effectAttributes, $reflectionMethod->getName() . ' declares its effect');
            $effectsByToolName[$reflectionMethod->getName()] = $effectAttributes[0]->getArguments()[0] ?? null;
        }
        self::assertSame([
            'findAppTranslationKeys' => MCPToolEffect::READ,
            'getAppTranslationKey' => MCPToolEffect::READ,
            'getAppTranslationCompleteness' => MCPToolEffect::READ,
            'listActiveLocales' => MCPToolEffect::READ,
            'listTranslationDefaultTerms' => MCPToolEffect::READ,
            'createAppTranslationKey' => MCPToolEffect::WRITE,
            'setAppTranslationValue' => MCPToolEffect::WRITE,
        ], $effectsByToolName);
        $skillFile = (string)file_get_contents(dirname(__DIR__, 3) . '/config/app/AI/Skills/Translations/AppTexts.md');
        foreach (array_keys($effectsByToolName) as $toolName) {
            self::assertStringContainsString('`' . $toolName . '`', $skillFile, 'the skill names ' . $toolName);
        }
    }

    public function testTheSkillFileAndTheBindingPassTheLint(): void
    {
        $aISkillFilesLintService = new AISkillFilesLintService();
        $skillRootDirectory = dirname(__DIR__, 3) . '/config/app/AI/Skills';
        self::assertSame([], $aISkillFilesLintService->findSkillNamingProblems([$skillRootDirectory]));
        self::assertSame([], $aISkillFilesLintService->findSkillFileProblems([$skillRootDirectory]));
        ConfigTreeOverride::apply(['AI', 'skillPackages'], ['Translations.AppTexts' => [TranslationsAppTextsMCPPackage::class]]);
        ConfigTreeOverride::apply(['AI', 'packageIdProvider'], 'app');
        self::assertSame([], $aISkillFilesLintService->findBindingNamingProblems());
        $aISkill = (new AISkillsService())->getAISkillByName('Translations.AppTexts');
        self::assertNotNull($aISkill, 'the module config root serves the skill file');
        self::assertSame([AgentAccountRights::MANAGE_TEXTS], $aISkill->requiredAccountRights);
    }

    public function testTheToolSchemaBuilds(): void
    {
        MCPPackage::clearDocumentationCache();
        $packageDocumentation = TranslationsAppTextsMCPPackage::buildDocumentation(true);
        $documentedToolNames = [];
        foreach ($packageDocumentation->functions as $functionDocumentation) {
            $documentedToolNames[] = is_object($functionDocumentation) ? $functionDocumentation->name : $functionDocumentation['name'];
        }
        sort($documentedToolNames);
        self::assertSame([
            'createAppTranslationKey', 'findAppTranslationKeys', 'getAppTranslationCompleteness', 'getAppTranslationKey',
            'listActiveLocales', 'listTranslationDefaultTerms', 'setAppTranslationValue',
        ], $documentedToolNames);
    }

    public function testAnAreaWithOneWritingStyleWritesThatOneAndRefusesAnother(): void
    {
        $appTranslationAreas = AppTranslationAreas::fromConfig(['APP' => ['keyPrefix' => 'mobile.', 'writingStyles' => ['INFORMAL']], 'SERVER' => []]);
        $language = new Language();
        $language->languageCode = 'de';
        $language->supportedWritingStyles = Language::WRITING_STYLE_FORMAL_AND_INFORMAL;
        $resolveWritingStyles = new ReflectionMethod(TranslationsAppTextsMCPPackage::class, 'resolveWritingStyles');
        $package = new TranslationsAppTextsMCPPackage();
        $appArea = $appTranslationAreas->findByName('APP');
        $serverArea = $appTranslationAreas->findByName('SERVER');
        self::assertSame([Text::WRITING_STYLE_INFORMAL], $resolveWritingStyles->invoke($package, null, $appArea, $language, []));
        self::assertSame([Text::WRITING_STYLE_INFORMAL], $resolveWritingStyles->invoke($package, 'BOTH', $appArea, $language, []));
        self::assertSame([Text::WRITING_STYLE_FORMAL, Text::WRITING_STYLE_INFORMAL], $resolveWritingStyles->invoke($package, 'BOTH', $serverArea, $language, []));
        self::assertSame([Text::WRITING_STYLE_FORMAL], $resolveWritingStyles->invoke($package, null, $serverArea, $language, ['FORMAL' => 'Sie']));
        $this->expectException(AdminToolNotSavedException::class);
        $resolveWritingStyles->invoke($package, 'FORMAL', $appArea, $language, []);
    }

    public function testAServerKeyWithoutATextInAFormalAndInformalLanguageAsksForTheStyle(): void
    {
        $serverArea = AppTranslationAreas::fromConfig(null)->getRemainderArea();
        $language = new Language();
        $language->languageCode = 'de';
        $language->supportedWritingStyles = Language::WRITING_STYLE_FORMAL_AND_INFORMAL;
        $this->expectException(AdminToolNotSavedException::class);
        $this->expectExceptionMessage('writingStyle');
        (new ReflectionMethod(TranslationsAppTextsMCPPackage::class, 'resolveWritingStyles'))
            ->invoke(new TranslationsAppTextsMCPPackage(), null, $serverArea, $language, []);
    }
}
