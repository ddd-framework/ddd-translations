<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Support;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;

/** A usage recorder that keeps every booked call and its usage */
class RecordingAIModelCallUsageRecorderService extends AIModelCallUsageRecorderService
{
    /** @var AIModelCall[] */
    public array $recordedCalls = [];

    /** @var AIModelCallUsage[] */
    public array $recordedUsages = [];

    public function recordModelCallUsage(AIModelCall $aiModelCall, AIModelCallUsage $aiModelCallUsage): void
    {
        $this->recordedCalls[] = $aiModelCall;
        $this->recordedUsages[] = $aiModelCallUsage;
    }
}
