<?php

namespace Modules\Resume\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Modules\Resume\Models\Resume;
use Modules\Resume\Services\ATSAnalyzerService;
use Modules\Resume\Services\JobMatchAnalyzerService;
use Modules\Resume\Services\KeywordMatchAnalyzerService;
use Modules\Resume\Exceptions\ATSAnalysisException;
use Illuminate\Support\Facades\Log;
class ATSController extends Controller
{
    public function __construct(
        private ATSAnalyzerService $atsAnalyzer,
        private JobMatchAnalyzerService $jobMatchAnalyzer,
        private KeywordMatchAnalyzerService $keywordMatcher,
    ) {
    }

   public function check(Request $request, string $id)
{
    try {
        $resume = Resume::findOrFail($id);

        if ($resume->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to analyze this resume.',
            ], 403);
        }

        $resumeData = $resume->cv_resumejson ?? [];

        $template = $request->input('template');
        $template = is_string($template) ? strtolower($template) : null;

        Log::info('ATS: Resume loaded', [
            'resume_id' => $id,
            'data_type' => gettype($resumeData),
            'template' => $template,
        ]);

        // Deterministic structural check runs first and always succeeds —
        // no API key, no network call, no cost. This is the real,
        // reproducible ATS signal the product stands on.
        $structure = $this->keywordMatcher->analyzeStructure($resumeData);

        // The AI layer adds narrative suggestions/summary on top. If it's
        // not configured or fails, the deterministic result still stands
        // on its own — the product never breaks because an API key is
        // missing or OpenAI is down.
        $aiResult = null;

        try {
            $aiResult = $this->atsAnalyzer->analyze($resumeData, template: $template);
        } catch (\Throwable $e) {
            Log::warning('ATS: AI enrichment unavailable, falling back to deterministic result only', [
                'resume_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }

        $result = $aiResult ?? $this->deterministicOnlyResult($structure, $template);
        $result['deterministic_structure'] = $structure;

        Log::info('ATS: Analysis completed', [
            'resume_id' => $id,
            'ai_enrichment_used' => $aiResult !== null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'ATS analysis completed successfully.',
            'data' => $result,
        ]);

    } catch (\Throwable $e) {

        Log::error('ATS ERROR', [
            'resume_id' => $id,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $safeMessage = $e instanceof ATSAnalysisException
            ? $e->getSafeMessage()
            : 'CV analysis failed. Please try again shortly.';

        return response()->json([
            'success' => false,
            'message' => $safeMessage,
        ], 500);
    }
}

    /**
     * Score a resume against a job description — either the one already
     * saved on the resume, or a fresh one passed in the request body.
     */
    public function matchJob(Request $request, string $id)
    {
        try {
            $resume = Resume::findOrFail($id);

            if ($resume->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to analyze this resume.',
                ], 403);
            }

            $request->validate([
                'job_description' => ['nullable', 'string', 'min:40', 'max:8000'],
            ]);

            // A JD passed in this request takes priority over one already
            // saved on the resume, so a user can test against a different
            // posting without overwriting what's stored.
            $jobDescription = $request->input('job_description', $resume->job_description);

            if (!$jobDescription || mb_strlen(trim($jobDescription)) < 40) {
                return response()->json([
                    'success' => false,
                    'requires_job_description' => true,
                    'message' => 'Please provide a job description before running job match analysis.',
                ], 422);
            }

            $resumeData = $resume->cv_resumejson ?? [];

            Log::info('Job match: resume loaded', [
                'resume_id' => $id,
                'data_type' => gettype($resumeData),
                'job_description_source' => $request->filled('job_description') ? 'request' : 'saved',
            ]);

            // Deterministic keyword match is the authoritative score —
            // real, reproducible, works with zero AI configured.
            $keywordMatch = $this->keywordMatcher->match($resumeData, $jobDescription);

            $aiResult = null;

            try {
                $aiResult = $this->jobMatchAnalyzer->analyze($resumeData, $jobDescription);
            } catch (\Throwable $e) {
                Log::warning('Job match: AI enrichment unavailable, falling back to keyword match only', [
                    'resume_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }

            $result = $aiResult ?? [];
            $result['keyword_match'] = $keywordMatch;
            $result['match_percentage'] = $keywordMatch['match_percentage'];

            Log::info('Job match: analysis completed', [
                'resume_id' => $id,
                'ai_enrichment_used' => $aiResult !== null,
                'keyword_match_percentage' => $keywordMatch['match_percentage'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Job match analysis completed successfully.',
                'data' => $result,
            ]);

        } catch (\Throwable $e) {

            Log::error('JOB MATCH ERROR', [
                'resume_id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $safeMessage = $e instanceof ATSAnalysisException
                ? $e->getSafeMessage()
                : 'Job match analysis failed. Please try again shortly.';

            return response()->json([
                'success' => false,
                'message' => $safeMessage,
            ], 500);
        }
    }

    /**
     * Build a full ATS result shape from deterministic checks alone, for
     * when the AI enrichment layer is unavailable or unconfigured.
     *
     * @param array<string, mixed> $structure
     * @return array<string, mixed>
     */
    private function deterministicOnlyResult(array $structure, ?string $template): array
    {
        $score = 0;
        $score += $structure['contact_info']['has_email'] ? 10 : 0;
        $score += $structure['contact_info']['has_phone'] ? 5 : 0;
        $score += min(20, $structure['action_verb_count'] * 2);
        $score += min(20, $structure['quantified_achievement_count'] * 4);
        $score += $structure['word_count'] >= 150 && $structure['word_count'] <= 1200 ? 15 : 5;

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'max_score' => 100,
            'percentage' => $score,
            'grade' => match (true) {
                $score >= 90 => 'Excellent',
                $score >= 80 => 'Very Good',
                $score >= 70 => 'Good',
                $score >= 60 => 'Needs Improvement',
                $score >= 50 => 'Weak',
                default => 'Poor',
            },
            'summary' => 'Deterministic structural analysis (AI enrichment unavailable).',
            'categories' => [],
            'suggestions' => [],
            'template' => $template,
        ];
    }
}