<?php

namespace Modules\Resume\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Resume\Exceptions\ATSAnalysisException;
use Modules\Resume\Services\JobMatchAnalyzerService;
use Throwable;

class AnalyzeCvAgainstJobJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 150;
    public array $backoff = [10, 30, 60];

    private const RESULT_TTL_SECONDS = 3600;

    public function __construct(
        private readonly array $cv,
        private readonly string $jobDescription,
        private readonly string $analysisId,
        private readonly int|string|null $userId = null,
    ) {
    }

    public function handle(JobMatchAnalyzerService $service): void
    {
        Cache::put(
            $this->cacheKey(),
            ['status' => 'processing'],
            self::RESULT_TTL_SECONDS
        );

        try {
            $result = $service->analyze($this->cv, $this->jobDescription, requestId: $this->analysisId);

            Cache::put(
                $this->cacheKey(),
                ['status' => 'complete', 'data' => $result],
                self::RESULT_TTL_SECONDS
            );
        } catch (ATSAnalysisException $e) {
            Log::warning('AnalyzeCvAgainstJobJob: analysis attempt failed', [
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

    public function failed(Throwable $exception): void
    {
        $safeMessage = $exception instanceof ATSAnalysisException
            ? $exception->getSafeMessage()
            : 'Job match analysis failed. Please try again shortly.';

        Log::error('AnalyzeCvAgainstJobJob: permanently failed after all retries', [
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
        return "job_match_analysis:{$this->analysisId}";
    }
}