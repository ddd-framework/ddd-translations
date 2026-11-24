<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations\Maintenance;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Common\Entities\Texts\Text;

/**
 * The areas UI text keys are shown in, read from the config `Common.Translations.AppTexts` (key `areas`, by name):
 *
 *   'areas' => [
 *       'APP' => ['label' => 'the mobile app', 'keyPrefix' => 'mobile.', 'isSyncedFromCode' => true,
 *                 'writingStyles' => ['INFORMAL'], 'liveNote' => 'The app shows the new text after its next start.'],
 *       'SERVER' => ['label' => 'mails, push and error texts', 'liveNote' => '…'],
 *   ]
 *
 * Exactly one area has no key prefix and holds every key no other area claims; a configuration without one gets
 * {@see self::DEFAULT_AREA_NAME}. A key belongs to the area with the longest prefix it starts with.
 *
 * @property AppTranslationArea[] $elements
 * @method AppTranslationArea|null getByUniqueKey(string $uniqueKey)
 * @method AppTranslationArea[] getElements()
 * @method AppTranslationArea|null first()
 */
class AppTranslationAreas extends ObjectSet
{
    /** @var string The name of the area that holds every key when the configuration names none without a prefix */
    public const string DEFAULT_AREA_NAME = 'SERVER';

    /**
     * @param mixed $areasConfig The `areas` entry of the config, by area name
     * @return static
     */
    public static function fromConfig(mixed $areasConfig): static
    {
        $appTranslationAreas = new static();
        foreach (is_array($areasConfig) ? $areasConfig : [] as $areaName => $areaConfig) {
            if (!is_string($areaName) || trim($areaName) === '' || !is_array($areaConfig)) {
                continue;
            }
            $appTranslationArea = new AppTranslationArea(trim($areaName));
            $appTranslationArea->label = is_string($areaConfig['label'] ?? null) ? $areaConfig['label'] : '';
            $keyPrefix = $areaConfig['keyPrefix'] ?? null;
            $appTranslationArea->keyPrefix = is_string($keyPrefix) && $keyPrefix !== '' ? $keyPrefix : null;
            $appTranslationArea->isSyncedFromCode = (bool)($areaConfig['isSyncedFromCode'] ?? false);
            $writingStyles = [];
            foreach ((array)($areaConfig['writingStyles'] ?? []) as $writingStyle) {
                if (in_array($writingStyle, [Text::WRITING_STYLE_FORMAL, Text::WRITING_STYLE_INFORMAL], true)) {
                    $writingStyles[] = $writingStyle;
                }
            }
            $appTranslationArea->writingStyles = array_values(array_unique($writingStyles));
            $appTranslationArea->liveNote = is_string($areaConfig['liveNote'] ?? null) ? $areaConfig['liveNote'] : null;
            $appTranslationAreas->add($appTranslationArea);
        }
        if ($appTranslationAreas->getRemainderArea() === null) {
            $defaultArea = new AppTranslationArea(static::DEFAULT_AREA_NAME);
            $defaultArea->label = $appTranslationAreas->count() > 0 ? 'every other key' : 'every key';
            $appTranslationAreas->add($defaultArea);
        }
        return $appTranslationAreas;
    }

    /**
     * @param string $areaName
     * @return AppTranslationArea|null
     */
    public function findByName(string $areaName): ?AppTranslationArea
    {
        foreach ($this->getElements() as $appTranslationArea) {
            if (strcasecmp($appTranslationArea->name, trim($areaName)) === 0) {
                return $appTranslationArea;
            }
        }
        return null;
    }

    /**
     * @return AppTranslationArea|null The area without a key prefix (the first one, should the config name several)
     */
    public function getRemainderArea(): ?AppTranslationArea
    {
        foreach ($this->getElements() as $appTranslationArea) {
            if ($appTranslationArea->keyPrefix === null) {
                return $appTranslationArea;
            }
        }
        return null;
    }

    /**
     * @param string $key
     * @return AppTranslationArea The area with the longest prefix the key starts with, else the remainder area
     */
    public function getAreaOfKey(string $key): AppTranslationArea
    {
        $matchingArea = null;
        foreach ($this->getElements() as $appTranslationArea) {
            if ($appTranslationArea->hasPrefixOfKey($key)
                && ($matchingArea === null || strlen((string)$appTranslationArea->keyPrefix) > strlen((string)$matchingArea->keyPrefix))) {
                $matchingArea = $appTranslationArea;
            }
        }
        return $matchingArea ?? $this->getRemainderArea() ?? new AppTranslationArea(static::DEFAULT_AREA_NAME);
    }

    /**
     * @return string[] The names of the areas, in config order
     */
    public function getNames(): array
    {
        $areaNames = [];
        foreach ($this->getElements() as $appTranslationArea) {
            $areaNames[] = $appTranslationArea->name;
        }
        return $areaNames;
    }

    /**
     * @param AppTranslationArea $appTranslationArea
     * @return string[] The prefixes of the other areas whose keys a query over the given area has to leave out: for
     *     an area with a prefix the longer prefixes that start with it, for the remainder area every prefix
     */
    public function getExcludedKeyPrefixesOfArea(AppTranslationArea $appTranslationArea): array
    {
        $excludedKeyPrefixes = [];
        foreach ($this->getElements() as $otherArea) {
            if ($otherArea->name === $appTranslationArea->name || $otherArea->keyPrefix === null) {
                continue;
            }
            if ($appTranslationArea->keyPrefix === null
                || (strlen($otherArea->keyPrefix) > strlen($appTranslationArea->keyPrefix) && str_starts_with($otherArea->keyPrefix, $appTranslationArea->keyPrefix))) {
                $excludedKeyPrefixes[] = $otherArea->keyPrefix;
            }
        }
        return $excludedKeyPrefixes;
    }
}
