<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\Texts\Embeddings;

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
use DDD\Infrastructure\Exceptions\InternalErrorException;
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
}
