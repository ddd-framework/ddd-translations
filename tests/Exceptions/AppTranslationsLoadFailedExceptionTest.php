<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Exceptions;

use DDD\Domain\Common\Exceptions\AppTranslationsLoadFailedException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Which failures of an app translations batch are worth a retry of the message */
class AppTranslationsLoadFailedExceptionTest extends TestCase
{
    public function testUpstreamStatusesOfAPassingOutageAreTransient(): void
    {
        foreach (['http 503 from the upstream call', 'http 429 from the upstream call: rate limited', 'http 502 from the upstream call', 'http 408 from the upstream call'] as $loadFailureReason) {
            $this->assertTrue(AppTranslationsLoadFailedException::fromLoadFailureReason($loadFailureReason)->isTransient, $loadFailureReason);
        }
    }

    public function testATransportRejectionIsTransient(): void
    {
        $loadFailureReason = 'GuzzleHttp\Exception\ConnectException: cURL error 7: Failed to connect to app port 80: Connection refused';

        $this->assertTrue(AppTranslationsLoadFailedException::fromLoadFailureReason($loadFailureReason)->isTransient);
        $this->assertTrue(AppTranslationsLoadFailedException::isTransientLoadFailureReason('cURL error 28: Operation timed out after 300000 milliseconds'));
    }

    public function testAnUnreadableAnswerOrA4xxIsPermanent(): void
    {
        foreach (['no parseable answer', 'an answer without text', 'http 400 from the upstream call: invalid request', 'http 401 from the upstream call'] as $loadFailureReason) {
            $this->assertFalse(AppTranslationsLoadFailedException::fromLoadFailureReason($loadFailureReason)->isTransient, $loadFailureReason);
        }
    }

    public function testATransportExceptionThrownOutOfTheModelCallIsTransientAlsoWrapped(): void
    {
        $request = new Request('POST', 'http://app/argus');
        $connectException = new ConnectException('cURL error 7: Connection refused', $request);

        $this->assertTrue(AppTranslationsLoadFailedException::isTransientFailure($connectException));
        $this->assertTrue(AppTranslationsLoadFailedException::isTransientFailure(new RuntimeException('wrapped', 0, $connectException)));
        $this->assertTrue(AppTranslationsLoadFailedException::isTransientFailure(new ServerException('503', $request, new Response(503))));
        $this->assertFalse(AppTranslationsLoadFailedException::isTransientFailure(new ClientException('400', $request, new Response(400))));
    }

    public function testAnyOtherFailureIsPermanent(): void
    {
        $this->assertFalse(AppTranslationsLoadFailedException::isTransientFailure(new RuntimeException('Syntax error in the JSON of the model')));
        $this->assertFalse(AppTranslationsLoadFailedException::isTransientFailure(AppTranslationsLoadFailedException::fromLoadFailureReason('no parseable answer')));
        $this->assertTrue(AppTranslationsLoadFailedException::isTransientFailure(AppTranslationsLoadFailedException::fromLoadFailureReason('http 504 from the upstream call')));
    }
}
