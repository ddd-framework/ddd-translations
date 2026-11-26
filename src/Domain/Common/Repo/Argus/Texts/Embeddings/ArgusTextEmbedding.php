<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\Texts\Embeddings;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Domain\Common\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Repo\Argus\Texts\ArgusText;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use ReflectionException;

/**
 * @method ArgusText getParent()
 * @property ArgusText $parent
 * @method TextEmbedding toEntity(array $callPath = [], EntitySet|Entity|ValueObject &$entityInstance = null)
 */
#[ArgusLoad(loadEndpoint: 'POST:/ai/openRouter/embeddings', cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB, cacheTtl: ArgusCache::CACHE_TTL_ONE_DAY)]
class ArgusTextEmbedding extends TextEmbedding
{
    use ArgusTrait;

    /** @var string The usage scope an embedding call is booked under ({@see AIModelCallUsageRecorderService}) */
    public const string USAGE_SCOPE_TEXT_EMBEDDING = 'COMMON.TEXT.EMBEDDING';

    /** @var array<int, string> The catalog model of each dimension, for the price of the booked usage */
    protected const array DIMENSION_TO_CATALOG_MODEL_NAME = [
        Vector::DIMENSION_OPENAI_EMBEDDING_SMALL => AIModel::MODEL_OPENAI_TEXT_EMBEDDING_3_SMALL,
        Vector::DIMENSION_OPENAI_EMBEDDING_LARGE => AIModel::MODEL_OPENAI_TEXT_EMBEDDING_3_LARGE,
    ];

    protected const array DIMENSION_TO_MODEL = [
        Vector::DIMENSION_OPENAI_EMBEDDING_SMALL => 'text-embedding-3-small',
        Vector::DIMENSION_OPENAI_EMBEDDING_LARGE => 'text-embedding-3-large',
    ];

    /** @var int Vector dimensions for the next embedding generation (use Vector::DIMENSION_* constants) */
    public static int $dimensions = Vector::DIMENSION_OPENAI_EMBEDDING_SMALL;

    /**
     * @param Text $text
     * @param LazyLoad $lazyloadAttributeInstance
     * @return TextEmbedding|null
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function lazyload(
        Text &$text,
        LazyLoad &$lazyloadAttributeInstance
    ): TextEmbedding|null {
        $this->setParent($text);
        $this->argusLoad(
            useArgusEntityCache: $lazyloadAttributeInstance->useCache,
            useApiACallCache: $lazyloadAttributeInstance->useCache
        );
        return $this->toEntity();
    }

    /**
     * Only an answer carrying a vector is a successful load. A non-2xx answer or a rejected call is not cached at all
     * (the next load asks again); any other answer without a vector is cached as empty for at most
     * ArgusCache::FAILED_LOAD_CACHE_TTL_MAX.
     */
    public function handleLoadResponse(mixed &$callResponseData = null, ?ArgusApiOperation &$apiOperation = null): void
    {
        $rejectionReason = $callResponseData === null
            ? ($apiOperation?->rejectionReason ?? 'no answer')
            : static::rejectionReasonForNon2xxAnswer($callResponseData, $apiOperation);
        if ($rejectionReason === null
            && isset($callResponseData->status)
            && ($callResponseData->status == 200 || $callResponseData->status == 'OK')
            && isset($callResponseData->data->data[0]->embedding)
            && is_array($callResponseData->data->data[0]->embedding)
        ) {
            $this->vectorValues = $callResponseData->data->data[0]->embedding;
            $this->bookEmbeddingUsage($callResponseData->data);
            $this->postProcessLoadResponse($callResponseData);
            return;
        }
        $this->postProcessLoadResponse($callResponseData, false, cacheFailure: $rejectionReason === null);
    }

    /**
     * @return array|null
     */
    protected function getLoadPayload(): ?array
    {
        return [
            'body' => [
                'model' => static::DIMENSION_TO_MODEL[static::$dimensions] ?? static::DIMENSION_TO_MODEL[Vector::DIMENSION_OPENAI_EMBEDDING_SMALL],
                'input' => $this->getParent()->content,
            ]
        ];
    }

    /**
     * Books the embedding call through the application's usage recorder, like every language model call of ddd-ai:
     * the tokens the provider reports (`usage.prompt_tokens`), priced by the provider's reported cost (`usage.cost`)
     * or else by the catalog model of the dimension. An answer without a token count books nothing.
     */
    protected function bookEmbeddingUsage(object $responseData): void
    {
        $promptTokens = $responseData->usage->prompt_tokens ?? null;
        if (!is_numeric($promptTokens)) {
            return;
        }
        /** @var AIModelsService $aIModelsService */
        $aIModelsService = DDDService::instance()->getService(AIModelsService::class);
        $aiModel = $aIModelsService->getAIModelByName(
            static::DIMENSION_TO_CATALOG_MODEL_NAME[static::$dimensions] ?? AIModel::MODEL_OPENAI_TEXT_EMBEDDING_3_SMALL
        );
        $providerReportedCost = $responseData->usage->cost ?? null;
        $costs = is_numeric($providerReportedCost)
            ? new MoneyAmount((float)$providerReportedCost, 'USD')
            : $aiModel?->getEstimatedCostsForTokens((int)$promptTokens, 0);
        if ($costs === null) {
            return;
        }
        /** @var AIModelCallUsageRecorderService $aIModelCallUsageRecorderService */
        $aIModelCallUsageRecorderService = DDDService::instance()->getService(AIModelCallUsageRecorderService::class);
        $aIModelCallUsageRecorderService->recordModelCallUsage(
            new AIModelCall(argusClass: static::class, aiUsageScope: static::USAGE_SCOPE_TEXT_EMBEDDING, aiModel: $aiModel),
            new AIModelCallUsage(inputTokens: (int)$promptTokens, outputTokens: 0, costs: $costs),
        );
    }
}
