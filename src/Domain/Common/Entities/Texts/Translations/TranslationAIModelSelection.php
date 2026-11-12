<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Texts\Translations;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The language model one translation purpose runs on (app UI translations or entity translations), as the application
 * configures it under `Common.TranslationModels.<purpose>` ({@see \DDD\Domain\Common\Services\Translations\TranslationAIModelsService}).
 */
class TranslationAIModelSelection extends ValueObject
{
    /** @var string The name of the model in the AI model catalog, e.g. `OPENAI.GPT5_4_MINI` */
    public string $aiModelName;

    /** @var bool Asks for the provider's fast tier; sent only when the model supports it (AIModel::$supportsFastMode) */
    public bool $fastMode = false;

    public function __construct(string $aiModelName, bool $fastMode = false)
    {
        $this->aiModelName = $aiModelName;
        $this->fastMode = $fastMode;
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->aiModelName . '_' . ($this->fastMode ? 'fast' : 'default'));
    }
}
