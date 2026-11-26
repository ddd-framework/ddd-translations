<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Repo\Argus\Texts;

use DDD\DDDBundle;
use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;
use DDD\Domain\Common\Repo\Argus\Texts\Embeddings\ArgusTextEmbedding;
use DDD\Tests\Translations\Support\RecordingAIModelCallUsageRecorderService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** An embedding answer is booked through the usage recorder under its own usage scope; an answer without tokens is not */
class ArgusTextEmbeddingUsageBookingTest extends TestCase
{
    protected RecordingAIModelCallUsageRecorderService $recordingAIModelCallUsageRecorderService;

    protected ?object $previousRecorder = null;

    protected function setUp(): void
    {
        $this->recordingAIModelCallUsageRecorderService = new RecordingAIModelCallUsageRecorderService();
        $testInstances = $this->readTestInstances();
        $this->previousRecorder = $testInstances[AIModelCallUsageRecorderService::class] ?? null;
        $testInstances[AIModelCallUsageRecorderService::class] = $this->recordingAIModelCallUsageRecorderService;
        $this->writeTestInstances($testInstances);
    }

    protected function tearDown(): void
    {
        $testInstances = $this->readTestInstances();
        unset($testInstances[AIModelCallUsageRecorderService::class]);
        if ($this->previousRecorder !== null) {
            $testInstances[AIModelCallUsageRecorderService::class] = $this->previousRecorder;
        }
        $this->writeTestInstances($testInstances);
    }

    public function testTheProvidersReportedCostIsBookedUnderTheEmbeddingScope(): void
    {
        $this->bookEmbeddingUsage((object)['usage' => (object)['prompt_tokens' => 12, 'cost' => 0.0002]]);

        self::assertCount(1, $this->recordingAIModelCallUsageRecorderService->recordedCalls);
        self::assertSame(ArgusTextEmbedding::USAGE_SCOPE_TEXT_EMBEDDING, $this->recordingAIModelCallUsageRecorderService->recordedCalls[0]->aiUsageScope);
        self::assertSame(ArgusTextEmbedding::class, $this->recordingAIModelCallUsageRecorderService->recordedCalls[0]->argusClass);
        self::assertSame(12, $this->recordingAIModelCallUsageRecorderService->recordedUsages[0]->inputTokens);
        self::assertEqualsWithDelta(0.0002, $this->recordingAIModelCallUsageRecorderService->recordedUsages[0]->costs->amount, 1e-12);
    }

    public function testAnAnswerWithoutATokenCountBooksNothing(): void
    {
        $this->bookEmbeddingUsage((object)['usage' => (object)['cost' => 0.0002]]);
        $this->bookEmbeddingUsage((object)[]);

        self::assertSame([], $this->recordingAIModelCallUsageRecorderService->recordedCalls);
    }

    protected function bookEmbeddingUsage(object $responseData): void
    {
        $argusTextEmbedding = (new ReflectionClass(ArgusTextEmbedding::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(ArgusTextEmbedding::class, 'bookEmbeddingUsage'))->invoke($argusTextEmbedding, $responseData);
    }

    /** @return array<string, object> */
    protected function readTestInstances(): array
    {
        $container = (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
        return (new ReflectionProperty($container, 'testInstances'))->getValue($container);
    }

    /** @param array<string, object> $testInstances */
    protected function writeTestInstances(array $testInstances): void
    {
        $container = (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
        (new ReflectionProperty($container, 'testInstances'))->setValue($container, $testInstances);
    }
}
