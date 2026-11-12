<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Exceptions;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use Throwable;

/**
 * The model call of an app translations batch brought no translations back. Transient when the transport failed or
 * the upstream answered 408 / 429 / 5xx (worth a retry: nothing was translated and nothing billed); permanent for any
 * other answer (an unreadable answer from the model, a 4xx).
 */
class AppTranslationsLoadFailedException extends InternalErrorException
{
    /** HTTP statuses of an upstream answer that a later attempt may not get again */
    public const array TRANSIENT_HTTP_STATUS_CODES = [408, 425, 429, 500, 502, 503, 504];

    /** cURL errors of a failed transport: resolve, connect, timeout, SSL connect, empty reply, send, receive */
    public const array TRANSIENT_CURL_ERROR_NUMBERS = [6, 7, 28, 35, 52, 55, 56];

    /** @var bool True when a later attempt of the same batch may succeed */
    public bool $isTransient = false;

    /**
     * The exception for a load that failed for the given reason (the language model repository's
     * getLastLoadFailureReason(): "http <status> from the upstream call…", a transport rejection, or no answer).
     *
     * @param string $loadFailureReason
     * @return static
     */
    public static function fromLoadFailureReason(string $loadFailureReason): static
    {
        $appTranslationsLoadFailedException = new static('App translations batch failed: ' . $loadFailureReason);
        $appTranslationsLoadFailedException->isTransient = static::isTransientLoadFailureReason($loadFailureReason);
        return $appTranslationsLoadFailedException;
    }

    /**
     * True when the described load failure is a transport failure or an upstream status a retry may not get again.
     *
     * @param string $loadFailureReason
     * @return bool
     */
    public static function isTransientLoadFailureReason(string $loadFailureReason): bool
    {
        if (preg_match('/^http (\d{3}) /', $loadFailureReason, $statusMatch)) {
            return in_array((int)$statusMatch[1], static::TRANSIENT_HTTP_STATUS_CODES, true);
        }
        if (preg_match('/cURL error (\d+)/', $loadFailureReason, $curlErrorMatch)) {
            return in_array((int)$curlErrorMatch[1], static::TRANSIENT_CURL_ERROR_NUMBERS, true);
        }
        return str_contains($loadFailureReason, 'ConnectException');
    }

    /**
     * True when a failure of an app translations batch — this exception or a transport exception thrown out of the
     * model call, also wrapped — may not happen on a later attempt.
     *
     * @param Throwable $throwable
     * @return bool
     */
    public static function isTransientFailure(Throwable $throwable): bool
    {
        for ($currentThrowable = $throwable; $currentThrowable !== null; $currentThrowable = $currentThrowable->getPrevious()) {
            if ($currentThrowable instanceof self) {
                return $currentThrowable->isTransient;
            }
            if ($currentThrowable instanceof BadResponseException) {
                return in_array($currentThrowable->getResponse()->getStatusCode(), static::TRANSIENT_HTTP_STATUS_CODES, true);
            }
            if ($currentThrowable instanceof TransferException) {
                // no answer at all: connection refused or reset, DNS, timeout
                return true;
            }
        }
        return false;
    }
}
