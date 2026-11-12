<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Repo\Argus\Texts\Translations;

use DDD\Domain\Common\Repo\Argus\Texts\Translations\ArgusTranslations;

/** ArgusTranslations with the service tier of a request readable from a test */
class ArgusTranslationsServiceTierProbe extends ArgusTranslations
{
    public function getServiceTierForRequest(): ?string
    {
        return $this->resolveServiceTierForRequest($this->getAIModel());
    }
}
