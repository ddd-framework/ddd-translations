<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Repo\Argus\Texts\Translations;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\Common\Repo\Argus\Texts\ArgusDetectedLanguage;
use DDD\Domain\Common\Repo\Argus\Texts\Translations\ArgusTranslations;
use DDD\Domain\Common\Entities\Texts\Translations\Translations;
use DDD\Tests\Translations\Support\TranslationModelsConfigOverride;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** The translations repository runs on the model and service tier of its purpose */
class ArgusTranslationsModelPerPurposeTest extends TestCase
{
    protected function tearDown(): void
    {
        TranslationModelsConfigOverride::restore();
    }

    protected function createTranslations(?string $translationPurpose): ArgusTranslationsServiceTierProbe
    {
        $argusTranslations = new ArgusTranslationsServiceTierProbe();
        $argusTranslations->translationPurpose = $translationPurpose;
        return $argusTranslations;
    }

    public function testEntityTranslationsRunOnGpt6LunaInThePriorityTier(): void
    {
        $argusTranslations = $this->createTranslations(Translations::TRANSLATION_PURPOSE_ENTITY_TRANSLATIONS);

        $this->assertSame(AIModel::MODEL_OPENAI_GPT6_LUNA, $argusTranslations->getAIModel()->name);
        $this->assertSame(ArgusTranslationsServiceTierProbe::SERVICE_TIER_PRIORITY, $argusTranslations->getServiceTierForRequest());
    }

    public function testAppTranslationsRunOnGpt61SolInTheDefaultTier(): void
    {
        $argusTranslations = $this->createTranslations(Translations::TRANSLATION_PURPOSE_APP_TRANSLATIONS);

        $this->assertSame(AIModel::MODEL_OPENAI_GPT6_1_SOL, $argusTranslations->getAIModel()->name);
        $this->assertNull($argusTranslations->getServiceTierForRequest());
    }

    public function testFastModeOnAModelWithoutAFastTierSendsNoTier(): void
    {
        TranslationModelsConfigOverride::apply([
            Translations::TRANSLATION_PURPOSE_APP_TRANSLATIONS => ['model' => AIModel::MODEL_OPENAI_GPT6_1_SOL, 'fastMode' => true],
        ]);
        $argusTranslations = $this->createTranslations(Translations::TRANSLATION_PURPOSE_APP_TRANSLATIONS);

        $this->assertSame(AIModel::MODEL_OPENAI_GPT6_1_SOL, $argusTranslations->getAIModel()->name);
        $this->assertNull($argusTranslations->getServiceTierForRequest());
    }

    public function testAnApplicationTurnsFastModeOffForEntityTranslations(): void
    {
        TranslationModelsConfigOverride::apply([
            Translations::TRANSLATION_PURPOSE_ENTITY_TRANSLATIONS => ['fastMode' => false],
        ]);
        $argusTranslations = $this->createTranslations(Translations::TRANSLATION_PURPOSE_ENTITY_TRANSLATIONS);

        $this->assertNull($argusTranslations->getServiceTierForRequest());
    }

    /**
     * Without a purpose the class declaration applies (the probe subclass carries no attribute of its own, so the
     * declarations are read from the repositories themselves).
     */
    public function testWithoutPurposeTheRepositoriesDeclareGpt6LunaInFastMode(): void
    {
        foreach ([ArgusTranslations::class, ArgusDetectedLanguage::class] as $repositoryClass) {
            $argusLanguageModel = (new ReflectionClass($repositoryClass))
                ->getAttributes(ArgusLanguageModel::class)[0]->newInstance();

            $this->assertSame(AIModel::MODEL_OPENAI_GPT6_LUNA, $argusLanguageModel->defaultAIModelName, $repositoryClass);
            $this->assertTrue($argusLanguageModel->fastMode, $repositoryClass);
        }
    }
}
