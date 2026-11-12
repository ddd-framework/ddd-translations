<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\Translations;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\Common\Entities\Texts\Translations\TranslationAIModelSelection;
use DDD\Domain\Common\Entities\Texts\Translations\Translations;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The language model of each translation purpose. An application chooses model and fast mode per purpose in
 * `config/app/Common/TranslationModels.php`:
 *
 * ```php
 * return [
 *     'appTranslations' => ['model' => AIModel::MODEL_OPENAI_GPT6_1_SOL, 'fastMode' => false],
 *     'entityTranslations' => ['model' => AIModel::MODEL_OPENAI_GPT6_LUNA, 'fastMode' => true],
 * ];
 * ```
 *
 * A purpose without an entry runs on the module's default ({@see self::getDefaultAIModelSelectionForPurpose()}): app
 * UI translations on GPT-6.1 Sol, entity translations on GPT-6 Luna in fast mode.
 */
class TranslationAIModelsService extends Service
{
    public const string CONFIG_PATH = 'Common.TranslationModels';

    /**
     * Model name and fast mode the application configured for a translation purpose, or the default.
     *
     * @param string $translationPurpose one of the Translations::TRANSLATION_PURPOSE_* constants
     * @return TranslationAIModelSelection
     */
    public function getAIModelSelectionForPurpose(string $translationPurpose): TranslationAIModelSelection
    {
        $defaultAIModelSelection = $this->getDefaultAIModelSelectionForPurpose($translationPurpose);
        $purposeConfig = Config::get(self::CONFIG_PATH . '.' . $translationPurpose);
        if (!is_array($purposeConfig)) {
            return $defaultAIModelSelection;
        }
        $aiModelName = $purposeConfig['model'] ?? null;
        return new TranslationAIModelSelection(
            is_string($aiModelName) && $aiModelName !== '' ? $aiModelName : $defaultAIModelSelection->aiModelName,
            (bool)($purposeConfig['fastMode'] ?? $defaultAIModelSelection->fastMode)
        );
    }

    /**
     * The module's model of a translation purpose when the application configures none.
     *
     * @param string $translationPurpose one of the Translations::TRANSLATION_PURPOSE_* constants
     * @return TranslationAIModelSelection
     */
    public function getDefaultAIModelSelectionForPurpose(string $translationPurpose): TranslationAIModelSelection
    {
        return match ($translationPurpose) {
            Translations::TRANSLATION_PURPOSE_ENTITY_TRANSLATIONS => new TranslationAIModelSelection(
                Translations::AI_MODEL_FOR_ENTITY_TRANSLATIONS,
                true
            ),
            default => new TranslationAIModelSelection(Translations::AI_MODEL_FOR_TRANSLATIONS),
        };
    }

    /**
     * The catalog model of a translation purpose.
     *
     * @param string $translationPurpose one of the Translations::TRANSLATION_PURPOSE_* constants
     * @return AIModel
     * @throws InternalErrorException when the configured model is not in the catalog
     */
    public function getAIModelForPurpose(string $translationPurpose): AIModel
    {
        $aiModelSelection = $this->getAIModelSelectionForPurpose($translationPurpose);
        /** @var AIModelsService $aiModelsService */
        $aiModelsService = DDDService::instance()->getService(AIModelsService::class);
        $aiModel = $aiModelsService->getAIModelByName($aiModelSelection->aiModelName);
        if (!$aiModel) {
            throw new InternalErrorException(
                "Translation model '{$aiModelSelection->aiModelName}' of purpose '$translationPurpose' ("
                . self::CONFIG_PATH . ") is not in the AI model catalog"
            );
        }
        return $aiModel;
    }
}
