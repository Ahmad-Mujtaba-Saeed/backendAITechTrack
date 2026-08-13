<?php

namespace Modules\Resume\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown for any failure inside ATSAnalyzerService.
 *
 * Carries two messages on purpose:
 *  - getMessage()      -> full internal detail, safe to Log::error() only.
 *  - getSafeMessage()  -> generic, safe to return to the end user / API response.
 *
 * This stops provider error bodies (status codes, raw OpenAI error JSON,
 * stack detail) from ever reaching a client response.
 */
class ATSAnalysisException extends RuntimeException
{
    public function __construct(
        string $internalMessage,
        private readonly string $safeMessage = 'CV analysis is temporarily unavailable. Please try again shortly.',
        private readonly ?string $requestId = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($internalMessage, $code, $previous);
    }

    public function getSafeMessage(): string
    {
        return $this->safeMessage;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}