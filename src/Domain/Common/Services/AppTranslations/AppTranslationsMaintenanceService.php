<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\AppTranslations;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKey;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationValue;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationArea;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationAreaCompleteness;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationAreaCompletenesses;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationAreas;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationKeyCoverage;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationKeyCoverages;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationKeyListCriteria;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationStoredText;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationStoredTexts;
use DDD\Domain\Common\Entities\Languages\Language;
use DDD\Domain\Common\Entities\Locales\Locales;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationKey;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationValue;
use DDD\Domain\Common\Services\Languages\LanguagesService;
use DDD\Domain\Common\Services\Languages\LocalesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Reads and single writes over the UI text keys as a person maintains them: which languages a key has a text in,
 * which keys lack one, how complete each area is, and the text of one key in one language. The areas (an app, a web,
 * the server's own texts) share the two tables and are told apart by the start of the key string
 * ({@see AppTranslationAreas}, config `Common.Translations.AppTexts`). Lists read projections, never whole key or
 * value objects.
 *
 * An application overrides {@see self::findPlaceholderProblem()} (same-path subclass) when an area writes its
 * placeholders in another syntax than `%name%` and `{name}` (ICU plural blocks, markup).
 */
class AppTranslationsMaintenanceService extends Service
{
    /** @var string The config of the areas */
    public const string CONFIG_APP_TEXTS = 'Common.Translations.AppTexts';

    /** @var string Placeholders of the form `%name%` */
    public const string PERCENT_PLACEHOLDER_PATTERN = '/%([A-Za-z_]\w*)%/';

    /** @var string Placeholders of the form `{name}` */
    public const string BRACE_PLACEHOLDER_PATTERN = '/\{([A-Za-z_]\w*)\}/';

    /** @var string The language of the reference texts of keys that come from the code */
    public const string REFERENCE_LANGUAGE_CODE = 'en';

    /** @var int The most locales findActiveLocales() reads */
    public const int MAX_LOCALES = 500;

    /**
     * @return AppTranslationAreas The configured areas (at least the remainder area)
     */
    public function getAreas(): AppTranslationAreas
    {
        $appTextsConfig = Config::get(static::CONFIG_APP_TEXTS);
        return AppTranslationAreas::fromConfig(is_array($appTextsConfig) ? ($appTextsConfig['areas'] ?? null) : null);
    }

    /**
     * @param string $key
     * @return AppTranslationArea The area of a key string
     */
    public function getAreaOfKey(string $key): AppTranslationArea
    {
        return $this->getAreas()->getAreaOfKey($key);
    }

    /**
     * The languages the texts are kept in: every active language, by id.
     *
     * @return string[] Language code by language id, ordered by code
     */
    public function findActiveLanguageCodesById(): array
    {
        $languageCodesById = [];
        foreach ($this->getLanguagesService()->findActiveLanguages()->getElements() as $language) {
            $languageCodesById[(int)$language->id] = (string)$language->languageCode;
        }
        asort($languageCodesById, SORT_STRING);
        return $languageCodesById;
    }

    /**
     * @return string[] Language code by language id, of every language (a text may be stored in an inactive one)
     */
    public function findLanguageCodesById(): array
    {
        $languagesRepoClass = $this->getLanguagesService()->getEntitySetRepoClassInstance();
        $queryBuilder = $languagesRepoClass::createQueryBuilder(true);
        $languageAlias = $languagesRepoClass::getBaseModelAlias();
        $queryBuilder->select("$languageAlias.id", "$languageAlias.languageCode");
        $languageCodesById = [];
        foreach ($queryBuilder->getQuery()->getArrayResult() as $languageRow) {
            $languageCodesById[(int)$languageRow['id']] = (string)$languageRow['languageCode'];
        }
        return $languageCodesById;
    }

    /**
     * Every active locale (the set's default page size does not apply), ordered by language and country.
     *
     * @return Locales
     */
    public function findActiveLocales(): Locales
    {
        /** @var LocalesService $localesService */
        $localesService = DDDService::instance()->getService(LocalesService::class);
        Locales::setDefaultQueryOptionsSnapshot();
        try {
            $localeQueryOptions = Locales::getDefaultQueryOptions();
            $localeQueryOptions->setTop(static::MAX_LOCALES);
            Locales::setDefaultQueryOptions($localeQueryOptions);
            return $localesService->findActiveLocales();
        } finally {
            Locales::restoreDefaultQueryOptionsSnapshot();
        }
    }

    /**
     * One page of keys, ordered by key string, each with the languages that have a text and the active languages
     * that have none.
     *
     * @param AppTranslationKeyListCriteria $appTranslationKeyListCriteria
     * @param int $top
     * @param int $skip
     * @return AppTranslationKeyCoverages
     * @throws BadRequestException When the criteria name an unknown area
     */
    public function findKeyCoverages(AppTranslationKeyListCriteria $appTranslationKeyListCriteria, int $top, int $skip = 0): AppTranslationKeyCoverages
    {
        $appTranslationKeyCoverages = new AppTranslationKeyCoverages();
        $keysRepoClass = $this->getAppTranslationKeysService()->getEntitySetRepoClassInstance();
        $queryBuilder = $keysRepoClass::createQueryBuilder(true);
        $keyAlias = $keysRepoClass::getBaseModelAlias();
        $this->applyKeyListCriteria($queryBuilder, $keyAlias, $appTranslationKeyListCriteria);
        $queryBuilder->select("$keyAlias.id", "$keyAlias.key")
            ->orderBy("$keyAlias.key", 'ASC')->addOrderBy("$keyAlias.id", 'ASC')
            ->setFirstResult(max(0, $skip))->setMaxResults(max(1, $top));
        $keyRows = $queryBuilder->getQuery()->getArrayResult();
        if ($keyRows === []) {
            return $appTranslationKeyCoverages;
        }
        $keyIds = [];
        foreach ($keyRows as $keyRow) {
            $keyIds[] = (int)$keyRow['id'];
        }
        $storedTexts = $this->findStoredTextsOfKeys($keyIds);
        $languageCodesById = $this->findLanguageCodesById();
        $activeLanguageCodesById = $this->findActiveLanguageCodesById();
        $appTranslationAreas = $this->getAreas();
        $searchText = mb_strtolower(trim((string)($appTranslationKeyListCriteria->searchText ?? '')));

        foreach ($keyRows as $keyRow) {
            $appTranslationKeyCoverage = new AppTranslationKeyCoverage();
            $appTranslationKeyCoverage->appTranslationKeyId = (int)$keyRow['id'];
            $appTranslationKeyCoverage->key = (string)$keyRow['key'];
            $appTranslationKeyCoverage->areaName = $appTranslationAreas->getAreaOfKey($appTranslationKeyCoverage->key)->name;
            $languageIdsWithText = [];
            $sampleText = null;
            $sampleRank = 0;
            foreach ($storedTexts->filterByKeyId($appTranslationKeyCoverage->appTranslationKeyId)->getElements() as $appTranslationStoredText) {
                $languageId = $appTranslationStoredText->languageId;
                $languageIdsWithText[$languageId] = true;
                // the text that matched the search before the reference language's before any other
                $rank = ($languageCodesById[$languageId] ?? null) === static::REFERENCE_LANGUAGE_CODE ? 2 : 1;
                if ($searchText !== '' && str_contains(mb_strtolower($appTranslationStoredText->translation), $searchText)
                    && ($appTranslationKeyListCriteria->searchLanguageId === null || $appTranslationKeyListCriteria->searchLanguageId === $languageId)) {
                    $rank = 3;
                }
                if ($rank > $sampleRank) {
                    $sampleRank = $rank;
                    $sampleText = $appTranslationStoredText;
                }
            }
            $languageCodesWithText = [];
            foreach (array_keys($languageIdsWithText) as $languageId) {
                $languageCodesWithText[] = $languageCodesById[$languageId] ?? ('language #' . $languageId);
            }
            sort($languageCodesWithText, SORT_STRING);
            $appTranslationKeyCoverage->languageCodesWithText = $languageCodesWithText;
            $appTranslationKeyCoverage->missingLanguageCodes = array_values(array_diff_key($activeLanguageCodesById, $languageIdsWithText));
            if ($sampleText !== null) {
                $appTranslationKeyCoverage->sampleText = $sampleText->translation;
                $appTranslationKeyCoverage->sampleLanguageCode = $languageCodesById[$sampleText->languageId] ?? null;
            }
            $appTranslationKeyCoverages->add($appTranslationKeyCoverage);
        }
        return $appTranslationKeyCoverages;
    }

    /**
     * How many keys findKeyCoverages() pages over.
     *
     * @param AppTranslationKeyListCriteria $appTranslationKeyListCriteria
     * @return int
     * @throws BadRequestException When the criteria name an unknown area
     */
    public function countKeys(AppTranslationKeyListCriteria $appTranslationKeyListCriteria): int
    {
        $keysRepoClass = $this->getAppTranslationKeysService()->getEntitySetRepoClassInstance();
        $queryBuilder = $keysRepoClass::createQueryBuilder(true);
        $keyAlias = $keysRepoClass::getBaseModelAlias();
        $this->applyKeyListCriteria($queryBuilder, $keyAlias, $appTranslationKeyListCriteria);
        $queryBuilder->select("COUNT($keyAlias.id)");
        return (int)$queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Every stored text of the given keys, empty texts left out, ordered by key, language, style.
     *
     * @param int[] $appTranslationKeyIds
     * @return AppTranslationStoredTexts
     */
    public function findStoredTextsOfKeys(array $appTranslationKeyIds): AppTranslationStoredTexts
    {
        $appTranslationStoredTexts = new AppTranslationStoredTexts();
        if ($appTranslationKeyIds === []) {
            return $appTranslationStoredTexts;
        }
        $valuesRepoClass = $this->getAppTranslationValuesService()->getEntitySetRepoClassInstance();
        $queryBuilder = $valuesRepoClass::createQueryBuilder(true);
        $valueAlias = $valuesRepoClass::getBaseModelAlias();
        $queryBuilder->select(
            "$valueAlias.id",
            "$valueAlias.appTranslationKeyId",
            "$valueAlias.languageId",
            "$valueAlias.countryId",
            "$valueAlias.writingStyle",
            "$valueAlias.context",
            "$valueAlias.translation"
        )
            ->andWhere("$valueAlias.appTranslationKeyId IN (:appTranslationKeyIds)")
            ->andWhere("$valueAlias.translation IS NOT NULL")
            ->andWhere("$valueAlias.translation <> ''")
            ->setParameter('appTranslationKeyIds', array_values($appTranslationKeyIds))
            ->orderBy("$valueAlias.appTranslationKeyId", 'ASC')
            ->addOrderBy("$valueAlias.languageId", 'ASC')
            ->addOrderBy("$valueAlias.writingStyle", 'ASC')
            ->addOrderBy("$valueAlias.id", 'ASC');
        foreach ($queryBuilder->getQuery()->getArrayResult() as $valueRow) {
            $appTranslationStoredText = new AppTranslationStoredText();
            $appTranslationStoredText->appTranslationValueId = (int)$valueRow['id'];
            $appTranslationStoredText->appTranslationKeyId = (int)$valueRow['appTranslationKeyId'];
            $appTranslationStoredText->languageId = (int)$valueRow['languageId'];
            $appTranslationStoredText->countryId = $valueRow['countryId'] ? (int)$valueRow['countryId'] : null;
            $appTranslationStoredText->writingStyle = (string)$valueRow['writingStyle'];
            $appTranslationStoredText->context = (string)($valueRow['context'] ?? Text::CONTEXT_ONE);
            $appTranslationStoredText->translation = (string)$valueRow['translation'];
            $appTranslationStoredTexts->add($appTranslationStoredText);
        }
        return $appTranslationStoredTexts;
    }

    /**
     * Per area, active language and writing style the area keeps for it: how many keys have a text.
     *
     * @return AppTranslationAreaCompletenesses ordered by area (config order), language code, style
     */
    public function getCompleteness(): AppTranslationAreaCompletenesses
    {
        $activeLanguages = $this->getLanguagesService()->findActiveLanguages()->getElements();
        usort($activeLanguages, [static::class, 'compareLanguagesByCode']);

        $appTranslationAreaCompletenesses = new AppTranslationAreaCompletenesses();
        foreach ($this->getAreas()->getElements() as $appTranslationArea) {
            $areaCriteria = new AppTranslationKeyListCriteria();
            $areaCriteria->areaName = $appTranslationArea->name;
            $keyCount = $this->countKeys($areaCriteria);
            $translatedKeyCounts = $this->countTranslatedKeysOfArea($appTranslationArea);
            foreach ($activeLanguages as $language) {
                foreach ($appTranslationArea->getWritingStylesOfLanguage($language) as $writingStyle) {
                    $appTranslationAreaCompleteness = new AppTranslationAreaCompleteness();
                    $appTranslationAreaCompleteness->areaName = $appTranslationArea->name;
                    $appTranslationAreaCompleteness->languageCode = (string)$language->languageCode;
                    $appTranslationAreaCompleteness->writingStyle = $writingStyle;
                    $appTranslationAreaCompleteness->keyCount = $keyCount;
                    $appTranslationAreaCompleteness->translatedKeyCount = $translatedKeyCounts[(int)$language->id][$writingStyle] ?? 0;
                    $appTranslationAreaCompletenesses->add($appTranslationAreaCompleteness);
                }
            }
        }
        return $appTranslationAreaCompletenesses;
    }

    /**
     * The country-neutral value of a key in a language and style; null when none is stored.
     *
     * @param AppTranslationKey $appTranslationKey
     * @param Language $language
     * @param string $writingStyle Text::WRITING_STYLE_*
     * @return AppTranslationValue|null
     */
    public function findValue(AppTranslationKey $appTranslationKey, Language $language, string $writingStyle): ?AppTranslationValue
    {
        return $this->getAppTranslationValuesService()->findValue((int)$appTranslationKey->id, (int)$language->id, $writingStyle);
    }

    /**
     * Stores the country-neutral text of a key in one language and style (creates the value or replaces its text)
     * and returns it as stored.
     *
     * @param AppTranslationKey $appTranslationKey
     * @param Language $language
     * @param string $writingStyle Text::WRITING_STYLE_*
     * @param string $text
     * @return AppTranslationValue
     * @throws BadRequestException When the text is empty
     * @throws InternalErrorException When the write returned no row
     */
    public function setValue(AppTranslationKey $appTranslationKey, Language $language, string $writingStyle, string $text): AppTranslationValue
    {
        if (trim($text) === '') {
            throw new BadRequestException('An empty text is not stored.');
        }
        $appTranslationValue = $this->findValue($appTranslationKey, $language, $writingStyle);
        if ($appTranslationValue === null) {
            /** @var AppTranslationValue $appTranslationValue */
            $appTranslationValue = AppTranslationValue::newInstance();
            $appTranslationValue->appTranslationKeyId = $appTranslationKey->id;
            $appTranslationValue->languageId = $language->id;
            $appTranslationValue->writingStyle = $writingStyle;
            $appTranslationValue->context = Text::CONTEXT_ONE;
        }
        $appTranslationValue->translation = $text;
        $appTranslationValue->update();
        if (!isset($appTranslationValue->id) || !$appTranslationValue->id) {
            throw new InternalErrorException('The text was not stored: the write returned no row.');
        }
        return $appTranslationValue;
    }

    /**
     * The country-neutral text of a key in a language and style as the database holds it now (a projection read,
     * never an object of the entity registry): what a write reads back.
     *
     * @param AppTranslationKey $appTranslationKey
     * @param Language $language
     * @param string $writingStyle Text::WRITING_STYLE_*
     * @return string|null null when no text is stored
     */
    public function findStoredText(AppTranslationKey $appTranslationKey, Language $language, string $writingStyle): ?string
    {
        foreach ($this->findStoredTextsOfKeys([(int)$appTranslationKey->id])->getElements() as $appTranslationStoredText) {
            if ($appTranslationStoredText->languageId === (int)$language->id
                && $appTranslationStoredText->writingStyle === $writingStyle
                && $appTranslationStoredText->isCountryNeutral()) {
                return $appTranslationStoredText->translation;
            }
        }
        return null;
    }

    /**
     * Creates a key of an area whose keys are not synced from the code (for the server's texts the key string is the
     * English sentence the code passes to `__()`).
     *
     * @param string $key
     * @param string|null $translationHint What the translators need to know about the text
     * @return AppTranslationKey
     * @throws BadRequestException When the key belongs to an area synced from the code
     * @throws InternalErrorException When the write returned no row
     */
    public function createKey(string $key, ?string $translationHint = null): AppTranslationKey
    {
        $appTranslationArea = $this->getAreaOfKey($key);
        if ($appTranslationArea->isSyncedFromCode) {
            throw new BadRequestException('Keys of the area ' . $appTranslationArea->name . ' are created by their sync from the code, not by hand.');
        }
        /** @var AppTranslationKey $appTranslationKey */
        $appTranslationKey = AppTranslationKey::newInstance();
        $appTranslationKey->key = $key;
        if ($translationHint !== null && trim($translationHint) !== '') {
            $appTranslationKey->translationHint = trim($translationHint);
        }
        $appTranslationKey->update();
        if (!isset($appTranslationKey->id) || !$appTranslationKey->id) {
            throw new InternalErrorException('The key was not stored: the write returned no row.');
        }
        return $appTranslationKey;
    }

    /**
     * The text a new text has to keep the placeholders of: the reference text for translators, else (for a key
     * synced from the code) the English text, else the key string itself.
     *
     * @param AppTranslationKey $appTranslationKey
     * @return string|null
     */
    public function findReferenceText(AppTranslationKey $appTranslationKey): ?string
    {
        $key = (string)$appTranslationKey->key;
        $translationTemplate = trim((string)($appTranslationKey->translationTemplate ?? '')) !== '' ? (string)$appTranslationKey->translationTemplate : null;
        if (!$this->getAreaOfKey($key)->isSyncedFromCode) {
            return $translationTemplate ?? $key;
        }
        $referenceLanguage = $this->getLanguagesService()->findByLanguageCode(static::REFERENCE_LANGUAGE_CODE);
        foreach ($referenceLanguage !== null ? [Text::WRITING_STYLE_INFORMAL, Text::WRITING_STYLE_FORMAL] : [] as $writingStyle) {
            $referenceText = $this->findStoredText($appTranslationKey, $referenceLanguage, $writingStyle);
            if ($referenceText !== null) {
                return $referenceText;
            }
        }
        return $translationTemplate;
    }

    /**
     * Why $text may not become a text of the key, or null when it may: it has to keep the placeholders (`%name%`,
     * `{name}`) of the reference text. An application whose area writes placeholders otherwise overrides this.
     *
     * @param AppTranslationKey $appTranslationKey
     * @param string $referenceText
     * @param string $text
     * @param string|null $languageCode
     * @return string|null
     */
    public function findPlaceholderProblem(AppTranslationKey $appTranslationKey, string $referenceText, string $text, ?string $languageCode = null): ?string
    {
        $missingPlaceholders = array_values(array_diff(static::extractPlaceholders($referenceText), static::extractPlaceholders($text)));
        if ($missingPlaceholders === []) {
            return null;
        }
        return 'the text leaves out the placeholder' . (count($missingPlaceholders) > 1 ? 's ' : ' ') . implode(', ', $missingPlaceholders)
            . ' of the reference text (they are replaced at runtime and have to stay exactly as written)';
    }

    /**
     * @param string $text
     * @return string[] The `%name%` and `{name}` placeholders of a text, each once, as written
     */
    public static function extractPlaceholders(string $text): array
    {
        $placeholders = [];
        foreach ([static::PERCENT_PLACEHOLDER_PATTERN, static::BRACE_PLACEHOLDER_PATTERN] as $placeholderPattern) {
            if (preg_match_all($placeholderPattern, $text, $matches)) {
                array_push($placeholders, ...$matches[0]);
            }
        }
        return array_values(array_unique($placeholders));
    }

    /**
     * @param Language $firstLanguage
     * @param Language $secondLanguage
     * @return int
     */
    public static function compareLanguagesByCode(Language $firstLanguage, Language $secondLanguage): int
    {
        return strcmp((string)$firstLanguage->languageCode, (string)$secondLanguage->languageCode);
    }

    /**
     * @param AppTranslationArea $appTranslationArea
     * @return int[][] Keys of the area with a text, by language id and writing style
     */
    protected function countTranslatedKeysOfArea(AppTranslationArea $appTranslationArea): array
    {
        $valuesRepoClass = $this->getAppTranslationValuesService()->getEntitySetRepoClassInstance();
        $queryBuilder = $valuesRepoClass::createQueryBuilder(true);
        $valueAlias = $valuesRepoClass::getBaseModelAlias();
        $keyModel = DBAppTranslationKey::BASE_ORM_MODEL;
        $queryBuilder->select("$valueAlias.languageId", "$valueAlias.writingStyle", "COUNT(DISTINCT $valueAlias.appTranslationKeyId) AS translatedKeyCount")
            ->andWhere("$valueAlias.translation IS NOT NULL")
            ->andWhere("$valueAlias.translation <> ''")
            ->andWhere("$valueAlias.appTranslationKeyId IN (SELECT areaKey.id FROM $keyModel areaKey WHERE "
                . $this->applyAreaCondition($queryBuilder, 'areaKey', $appTranslationArea) . ')')
            ->groupBy("$valueAlias.languageId")->addGroupBy("$valueAlias.writingStyle");
        $translatedKeyCounts = [];
        foreach ($queryBuilder->getQuery()->getArrayResult() as $countRow) {
            $translatedKeyCounts[(int)$countRow['languageId']][(string)$countRow['writingStyle']] = (int)$countRow['translatedKeyCount'];
        }
        return $translatedKeyCounts;
    }

    /**
     * Adds the conditions of the criteria to a query over the keys.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $keyAlias
     * @param AppTranslationKeyListCriteria $appTranslationKeyListCriteria
     * @return void
     * @throws BadRequestException When the criteria name an unknown area
     */
    protected function applyKeyListCriteria(
        DoctrineQueryBuilder $queryBuilder,
        string $keyAlias,
        AppTranslationKeyListCriteria $appTranslationKeyListCriteria
    ): void {
        $valueModel = DBAppTranslationValue::BASE_ORM_MODEL;
        if ($appTranslationKeyListCriteria->areaName !== null) {
            $appTranslationAreas = $this->getAreas();
            $appTranslationArea = $appTranslationAreas->findByName($appTranslationKeyListCriteria->areaName);
            if ($appTranslationArea === null) {
                throw new BadRequestException(
                    'No area ' . $appTranslationKeyListCriteria->areaName . '. Areas: ' . implode(', ', $appTranslationAreas->getNames()) . '.'
                );
            }
            $areaCondition = $this->applyAreaCondition($queryBuilder, $keyAlias, $appTranslationArea);
            if ($areaCondition !== '1 = 1') {
                $queryBuilder->andWhere($areaCondition);
            }
        }
        $searchText = trim((string)($appTranslationKeyListCriteria->searchText ?? ''));
        if ($searchText !== '') {
            $searchLanguageCondition = '';
            if ($appTranslationKeyListCriteria->searchLanguageId !== null) {
                $searchLanguageCondition = ' AND searchedValue.languageId = :searchLanguageId';
                $queryBuilder->setParameter('searchLanguageId', $appTranslationKeyListCriteria->searchLanguageId);
            }
            $queryBuilder->andWhere(
                "(LOWER($keyAlias.key) LIKE :searchPattern OR EXISTS ("
                . "SELECT searchedValue.id FROM $valueModel searchedValue WHERE searchedValue.appTranslationKeyId = $keyAlias.id"
                . " AND LOWER(searchedValue.translation) LIKE :searchPattern$searchLanguageCondition))"
            )->setParameter('searchPattern', '%' . addcslashes(mb_strtolower($searchText), '%_\\') . '%');
        }
        if ($appTranslationKeyListCriteria->missingLanguageId !== null) {
            $queryBuilder->andWhere(
                "NOT EXISTS (SELECT missingValue.id FROM $valueModel missingValue WHERE missingValue.appTranslationKeyId = $keyAlias.id"
                . " AND missingValue.languageId = :missingLanguageId AND missingValue.translation IS NOT NULL AND missingValue.translation <> '')"
            )->setParameter('missingLanguageId', $appTranslationKeyListCriteria->missingLanguageId);
        }
    }

    /**
     * The DQL condition that holds for the keys of an area; binds its prefix parameters on the query builder.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $keyAlias
     * @param AppTranslationArea $appTranslationArea
     * @return string `1 = 1` when the area holds every key
     */
    protected function applyAreaCondition(DoctrineQueryBuilder $queryBuilder, string $keyAlias, AppTranslationArea $appTranslationArea): string
    {
        $conditions = [];
        $parameterPrefix = $keyAlias . 'AreaPrefix';
        if ($appTranslationArea->keyPrefix !== null) {
            $conditions[] = "$keyAlias.key LIKE :{$parameterPrefix}Included";
            $queryBuilder->setParameter($parameterPrefix . 'Included', addcslashes($appTranslationArea->keyPrefix, '%_\\') . '%');
        }
        foreach ($this->getAreas()->getExcludedKeyPrefixesOfArea($appTranslationArea) as $prefixIndex => $excludedKeyPrefix) {
            $conditions[] = "$keyAlias.key NOT LIKE :{$parameterPrefix}Excluded$prefixIndex";
            $queryBuilder->setParameter($parameterPrefix . 'Excluded' . $prefixIndex, addcslashes($excludedKeyPrefix, '%_\\') . '%');
        }
        return $conditions !== [] ? implode(' AND ', $conditions) : '1 = 1';
    }

    /**
     * @return LanguagesService
     */
    protected function getLanguagesService(): LanguagesService
    {
        /** @var LanguagesService $languagesService */
        $languagesService = DDDService::instance()->getService(LanguagesService::class);
        return $languagesService;
    }

    /**
     * @return AppTranslationKeysService
     */
    protected function getAppTranslationKeysService(): AppTranslationKeysService
    {
        /** @var AppTranslationKeysService $appTranslationKeysService */
        $appTranslationKeysService = AppTranslationKey::getService();
        return $appTranslationKeysService;
    }

    /**
     * @return AppTranslationValuesService
     */
    protected function getAppTranslationValuesService(): AppTranslationValuesService
    {
        /** @var AppTranslationValuesService $appTranslationValuesService */
        $appTranslationValuesService = AppTranslationValue::getService();
        return $appTranslationValuesService;
    }
}
