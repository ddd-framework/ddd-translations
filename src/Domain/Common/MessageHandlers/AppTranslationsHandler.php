<?php

declare (strict_types=1);

namespace DDD\Domain\Common\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Domain\Common\Exceptions\AppTranslationsLoadFailedException;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Throwable;

#[AsMessageHandler(fromTransport: 'app_translations')]
class AppTranslationsHandler extends AppMessageHandler
{
    public function __invoke(AppTranslationsMessage $appMessage)
    {
        $this->setAuthAccountFromMessage($appMessage);
        if ($appMessage->processOnWorkspaceIfNecessary()) {
            return;
        }
        /** @var AppTranslationsService $appTranslationsService */
        $appTranslationsService = DDDService::instance()->getService(AppTranslationsService::class);
        try {
            $this->getLogger()->info(
                "Performing AppTranslations for {$appMessage->texts->count()} texts."
            );
            $appTranslationsService->translateAppTranslationsTexts($appMessage->texts, async: false);
            //$this->getLogger()->notice("Regenerating the name for ServiceArea {$serviceAreaRegenerateNameMessage->serviceArea->id} finished.");
        } catch (Throwable $t) {
            $this->logIssue($t, $appMessage, 'FAIL: Performing AppTranslations');
            if (AppTranslationsLoadFailedException::isTransientFailure($t)) {
                // nothing was translated (connection refused, timeout, 408/429/5xx): the transport's retry strategy
                // retries the message (Messenger default: 3 retries, 1 s doubling)
                throw $t;
            }
            // any other failure gives the message up: a retry could bill the AI translation of the same texts again
            // (the failed transport keeps it for a manual retry)
            throw new UnrecoverableMessageHandlingException('AppTranslations failed: ' . $t->getMessage(), 0, $t);
        }
    }
}
