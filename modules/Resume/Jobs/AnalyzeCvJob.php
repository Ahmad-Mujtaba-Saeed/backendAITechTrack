<?php

namespace Modules\Resume\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use modules\Resume\Exceptions\ATSAnalysisException;
use modules\Resume\Services\ATSAnalyzerService;
use Throwable;

class AnalyzeCvJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Job-level retries — separate from the HTTP-level retries inside the service. */
    public int $tries = 3;

    /** Give up retrying after this many seconds even if $tries hasn't been hit. */
    public int $timeout = 150;

    /** Backoff between job attempts, in seconds. */
    public array $backoff = [10, 30, 60];

    /** How long a result (or error) stays readable by the client after completion. */
    private const RESULT_TTL_SECONDS = 3600;

    public function __construct(
        private readonly array $cv,
        private readonly string $analysisId,
        private readonly int|string|null $userId = null,
    ) {
    }

    public function handle(ATSAnalyzerService $service): void
    {
        Cache::put(
            $this->cacheKey(),
            ['status' => 'processing'],
            self::RESULT_TTL_SECONDS
        );

        try {
            $result = $service->analyze($this->cv, requestId: $this->analysisId);

            Cache::put(
                $this->cacheKey(),
                ['status' => 'complete', 'data' => $result],
                self::RESULT_TTL_SECONDS
            );
        } catch (ATSAnalysisException $e) {
            // Service already logged full internal detail — just record the
            // safe message for the client and let the job retry naturally.
            Log::warning('AnalyzeCvJob: analysis attempt failed', [
                'analysis_id' => $this->analysisId,
                'user_id' => $this->userId,
                'attempt' => $this->attempts(),
                'safe_message' => $e->getSafeMessage(),
            ]);

            if ($this->attempts() >= $this->tries) {
                Cache::put(
                    $this->cacheKey(),
                    ['status' => 'failed', 'message' => $e->getSafeMessage()],
                    self::RESULT_TTL_SECONDS
                );
            }

            throw $e;
        }
    }

    /**
     * Called by the queue after all retries are exhausted.
     */
    public function failed(Throwable $exception): void
    {
        $safeMessage = $exception instanceof ATSAnalysisException
            ? $exception->getSafeMessage()
            : 'CV analysis failed. Please try again shortly.';

        Log::error('AnalyzeCvJob: permanently failed after all retries', [
            'analysis_id' => $this->analysisId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);

        Cache::put(
            $this->cacheKey(),
            ['status' => 'failed', 'message' => $safeMessage],
            self::RESULT_TTL_SECONDS
        );
    }

    private function cacheKey(): string
    {
        return "ats_analysis:{$this->analysisId}";
    }
}