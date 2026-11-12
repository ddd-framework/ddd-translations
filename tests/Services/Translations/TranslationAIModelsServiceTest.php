<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Services\Translations;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\Common\Entities\Texts\Translations\Translations;
use DDD\Domain\Common\Services\Translations\TranslationAIModelsService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Translations\Support\TranslationModelsConfigOverride;
use PHPUnit\Framework\TestCase;

/** Model and fast mode per translation purpose: the module's defaults and an application's Common.TranslationModels */
class TranslationAIModelsServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        TranslationModelsConfigOverride::restore();
    }

    public function testWithoutConfigAppTranslationsRunOnGpt61SolWithoutFastMode(): void
    {
        $aiModelSelection = (new TranslationAIModelsService())
            ->getAIModelSelectionForPurpose(Translations::TRANSLATION_PURPOSE_APP_TRANSLATIONS);

        $this->assertSame(AIModel::MODEL_OPENAI_GPT6_1_SOL, $aiModelSelection->aiModelName);
        $this->assertFalse($aiModelSelection->fastMode);
    }

    public function testWithoutConfigEntityTranslationsRunOnGpt6LunaInFastMode(): void
    {
        $aiModelSelection = (new TranslationAIModelsService())
            ->getAIModelSelectionForPurpose(Translations::TRANSLATION_PURPOSE_ENTITY_TRANSLATIONS);

        $this->assertSame(AIModel::MODEL_OPENAI_GPT6_LUNA, $aiModelSelection->aiModelName);
        $this->assertTrue($aiModelSelection->fastMode);
    }

    public function testTheApplicationConfigChoosesModelAndFastModePerPurpose(): void
    {
        TranslationModelsConfigOverride::apply([
            Translations::TRANSLATION_PURPOSE_APP_TRANSLATIONS => ['model' => AIModel::MODEL_OPENAI_GPT6_LUNA, 'fastMode' => true],
            Translations::TRANSLATION_PURPOSE_ENTITY_TRANSLATIONS => ['model' => AIModel::MODEL_OPENAI_GPT6_SOL, 'fastMode' => false],
        ]);
        $translationAIModelsService = new TranslationAIModelsService();

        $appSelection = $translationAIModelsService->getAIModelSelectionForPurpose(Translations::TRANSLATION_PURPOSE_APP_TRANSLATIONS);
        $entitySelection = $translationAIModelsService->getAIModelSelectionForPurpose(Translations::TRANSLATION_PURPOSE_ENTITY_TRANSLATIONS);

        $this->assertSame(AIModel::MODEL_OPENAI_GPT6_LUNA, $appSelection->aiModelName);
        $this->assertTrue($appSelection->fastMode);
        $this->assertSame(AIModel::MODEL_OPENAI_GPT6_SOL, $entitySelection->aiModelName);
        $this->assertFalse($entitySelection->fastMode);
    }

    public function testAnEntryWithoutModelOrFastModeKeepsTheDefaultOfTheMissingKey(): void
    {
        TranslationModelsConfigOverride::apply([
            Translations::TRANSLATION_PURPOSE_ENTITY_TRANSLATIONS => ['fastMode' => false],
            Translations::TRANSLATION_PURPOSE_APP_TRANSLATIONS => ['model' => AIModel::MODEL_OPENAI_GPT6_SOL],
        ]);
        $translationAIModelsService = new TranslationAIModelsService();

        $entitySelection = $translationAIModelsService->getAIModelSelectionForPurpose(Translations::TRANSLATION_PURPOSE_ENTITY_TRANSLATIONS);
        $appSelection = $translationAIModelsService->getAIModelSelectionForPurpose(Translations::TRANSLATION_PURPOSE_APP_TRANSLATIONS);

        $this->assertSame(AIModel::MODEL_OPENAI_GPT6_LUNA, $entitySelection->aiModelName);
        $this->assertFalse($entitySelection->fastMode);
        $this->assertSame(AIModel::MODEL_OPENAI_GPT6_SOL, $appSelection->aiModelName);
        $this->assertFalse($appSelection->fastMode);
    }

    public function testTheCatalogModelOfAPurposeIsResolved(): void
    {
        $aiModel = (new TranslationAIModelsService())->getAIModelForPurpose(Translations::TRANSLATION_PURPOSE_APP_TRANSLATIONS);

        $this->assertSame(AIModel::MODEL_OPENAI_GPT6_1_SOL, $aiModel->name);
    }

    public function testAConfiguredModelMissingFromTheCatalogFailsLoudly(): void
    {
        TranslationModelsConfigOverride::apply([
            Translations::TRANSLATION_PURPOSE_APP_TRANSLATIONS => ['model' => 'OPENAI.NO_SUCH_MODEL'],
        ]);

        $this->expectException(InternalErrorException::class);
        (new TranslationAIModelsService())->getAIModelForPurpose(Translations::TRANSLATION_PURPOSE_APP_TRANSLATIONS);
    }
}
