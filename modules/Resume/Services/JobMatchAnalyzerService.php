<?php

namespace Modules\Resume\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Modules\Resume\Exceptions\ATSAnalysisException;

/**
 * Scores a CV against a specific job description — unlike
 * ATSAnalyzerService (general ATS readiness, no job context),
 * this answers "how well does this CV match THIS job posting".
 */
class JobMatchAnalyzerService
{
    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

   
    private const MAX_CV_FIELD_LENGTH = 4000;

    private const MAX_JOB_DESCRIPTION_LENGTH = 8000;

    private const CACHE_TTL_SECONDS = 86400; // 24 hours

    public function analyze(array $cv, string $jobDescription, ?string $requestId = null): array
    {
        $requestId ??= (string) Str::uuid();

        $resume = $this->normalizeCv($cv);
        $jobDescription = $this->capText($jobDescription, self::MAX_JOB_DESCRIPTION_LENGTH);

        $this->assertHasContent($resume, $jobDescription, $requestId);

        $cacheKey = $this->cacheKey($resume, $jobDescription);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            Log::info('Job match analysis: served from cache — content unchanged since last run', [
                'request_id' => $requestId,
                'cache_key' => $cacheKey,
            ]);

            return $cached;
        }

        $apiKey = config('services.openai.api_key');

        if (empty($apiKey)) {

            throw new ATSAnalysisException(
                'OpenAI API key is not configured.',
                requestId: $requestId,
            );
        }

        $model = config('services.openai.ats_model', 'gpt-4o-mini');

        $startedAt = microtime(true);

        try {
            $payload = json_encode(
                [
                    'task' => 'Score this CV against the supplied job description.',
                    'cv' => $resume,
                    'job_description' => $jobDescription,
                ],
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {

            throw new ATSAnalysisException(
                'Failed to encode payload: ' . $e->getMessage(),
                previous: $e,
                requestId: $requestId,
            );
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.openai.ats_timeout', 60))
                ->connectTimeout(10)
                ->retry(
                    2,
                    function (int $attempt, \Throwable $exception): int {
                        return match ($attempt) {
                            1 => 1000,
                            2 => 3000,
                            default => 3000,
                        };
                    },
                    function (\Throwable $exception) {
                        if ($exception instanceof ConnectionException) {
                            return true;
                        }

                        if (
                            $exception instanceof RequestException
                            && $exception->response !== null
                        ) {
                            return in_array(
                                $exception->response->status(),
                                self::RETRYABLE_STATUS_CODES,
                                true
                            );
                        }

                        return false;
                    },
                    false
                )
                ->post(
                    'https://api.openai.com/v1/responses',
                    [
                        'model' => $model,
                        'store' => false,

                        'temperature' => 0.2,

                        'input' => [
                            [
                                'role' => 'system',
                                'content' => [
                                    [
                                        'type' => 'input_text',
                                        'text' => $this->systemPrompt(),
                                    ],
                                ],
                            ],
                            [
                                'role' => 'user',
                                'content' => [
                                    [
                                        'type' => 'input_text',
                                        'text' => $payload,
                                    ],
                                ],
                            ],
                        ],

                        'text' => [
                            'format' => [
                                'type' => 'json_schema',
                                'name' => 'job_match_analysis',
                                'strict' => true,
                                'schema' => $this->schema(),
                            ],
                        ],
                    ]
                );
        } catch (ConnectionException $e) {
            Log::error('Job match analysis: connection failure to OpenAI', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw new ATSAnalysisException(
                'Connection to AI provider failed: ' . $e->getMessage(),
                previous: $e,
                requestId: $requestId,
            );
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            Log::error('Job match analysis: OpenAI request failed', [
                'request_id' => $requestId,
                'status' => $response->status(),
                'body' => $this->truncateForLog($response->body()),
                'duration_ms' => $durationMs,
            ]);

            $safeMessage = $response->status() === 429
                ? 'Job match analysis is receiving high demand right now. Please try again in a moment.'
                : 'Job match analysis is temporarily unavailable. Please try again shortly.';

            throw new ATSAnalysisException(
                "Job match analysis failed: {$response->status()} - {$response->body()}",
                safeMessage: $safeMessage,
                requestId: $requestId,
            );
        }

        $result = $response->json();

        if (!is_array($result)) {
            Log::error('Job match analysis: non-array API response', [
                'request_id' => $requestId,
                'duration_ms' => $durationMs,
            ]);

            throw new ATSAnalysisException(
                'Job match analysis returned an invalid API response.',
                requestId: $requestId,
            );
        }

        $output = $this->extractOutputText($result);

        if ($output === '') {
            Log::error('Job match analysis: empty output_text from OpenAI', [
                'request_id' => $requestId,
                'duration_ms' => $durationMs,
            ]);

            throw new ATSAnalysisException(
                'Job match analysis returned an empty response.',
                requestId: $requestId,
            );
        }

        try {
            $analysis = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::error('Job match analysis: model output was not valid JSON', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            throw new ATSAnalysisException(
                'Job match analysis returned invalid JSON: ' . $e->getMessage(),
                previous: $e,
                requestId: $requestId,
            );
        }

        if (!is_array($analysis)) {
            Log::error('Job match analysis: decoded output was not a JSON object', [
                'request_id' => $requestId,
                'duration_ms' => $durationMs,
            ]);

            throw new ATSAnalysisException(
                'Job match analysis returned an invalid JSON structure.',
                requestId: $requestId,
            );
        }

        Log::info('Job match analysis completed', [
            'request_id' => $requestId,
            'duration_ms' => $durationMs,
            'model' => $model,
        ]);

        $normalized = $this->normalizeResult($analysis, $requestId);

        Cache::put($cacheKey, $normalized, self::CACHE_TTL_SECONDS);

        return $normalized;
    }

    /**
     * Content-addressed cache key — a hash of the exact normalized CV plus
     * the exact job description text. Any edit to either produces a
     * different key, so a changed CV always triggers a fresh analysis and
     * an unchanged CV always returns the exact same cached score.
     *
     * @param array<string, mixed> $resume
     */
    private function cacheKey(array $resume, string $jobDescription): string
    {
        $fingerprint = hash('sha256', json_encode($resume) . '|' . $jobDescription);

        return "job_match_analysis:{$fingerprint}";
    }

    /**
     * @param array<string, mixed> $resume
     */
    private function assertHasContent(array $resume, string $jobDescription, string $requestId): void
    {
        $cvHasContent =
            $resume['summary'] !== ''
            || $resume['candidate']['first_name'] !== ''
            || !empty($resume['experience'])
            || !empty($resume['skills'])
            || !empty($resume['education']);

        if (!$cvHasContent) {
            Log::warning('Job match analysis: rejected empty/content-free CV', [
                'request_id' => $requestId,
            ]);

            throw new ATSAnalysisException(
                'CV contains no analyzable content.',
                safeMessage: 'This CV doesn\'t have enough content to analyze yet. Add some details and try again.',
                requestId: $requestId,
            );
        }

        if (trim($jobDescription) === '' || mb_strlen(trim($jobDescription)) < 40) {
            Log::warning('Job match analysis: rejected missing/too-short job description', [
                'request_id' => $requestId,
                'length' => mb_strlen(trim($jobDescription)),
            ]);

            throw new ATSAnalysisException(
                'Job description is missing or too short to analyze against.',
                safeMessage: 'Please paste the full job description — a title alone isn\'t enough to score against.',
                requestId: $requestId,
            );
        }
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a production-grade ATS job-match analysis engine.

You are given a candidate's CV and a specific job description. Score
how well the CV matches THIS job, as an ATS and recruiter screening
specialist would.

Your analysis must be evidence-based and must only use information
present in the supplied CV and job description. Do not invent
experience, skills, education, achievements, technologies, dates,
responsibilities, or job requirements that are not actually present
in the source text.

IMPORTANT RULES:

1. Extract actual requirements from the job description — required
   skills, qualifications, experience level, responsibilities,
   domain terms. Do not use a fixed hardcoded requirements list.
2. Distinguish requirements the job description marks as required
   / must-have from those that read as preferred / nice-to-have.
3. Compare the CV against those extracted requirements, not against
   a generic industry checklist.
4. Every matched item and every gap must be traceable to specific
   text in the CV and/or the job description.
5. Do not penalize the CV for lacking something the job description
   itself never asked for.
6. Do not assume years of experience, seniority, or specific tools
   that are not stated in either document.
7. Evaluate ATS/keyword match readiness, not general employability
   or the candidate's overall career quality.
8. Do not claim a specific ATS vendor uses this exact scoring method.
9. Be honest about weak matches. A low score with clear, evidenced
   reasoning is more useful than an inflated one.

EVALUATE AGAINST THE JOB DESCRIPTION:

- Required skills and technologies mentioned in the CV vs the JD
- Preferred/nice-to-have skills mentioned in the CV vs the JD
- Years of experience and seniority level alignment
- Required qualifications (degree, certification, license) if the
  JD specifies them
- Job title / role alignment
- Domain and industry terminology overlap
- Core responsibilities in the JD that the CV's experience actually
  demonstrates evidence of handling
- ATS structural readiness (same criteria as a standalone ATS scan:
  parseable structure, no evidence of missing critical sections)
- Overall content quality and clarity relative to what the JD asks for

KEYWORD ANALYSIS:

From the job description, extract the meaningful requirement terms
(skills, tools, methodologies, qualifications, domain terms).

For each extracted term, determine whether the CV provides evidence
of it. Sort into:

- matched: terms found with real supporting evidence in the CV
- missing_critical: JD terms described as required/must-have that
  the CV shows no evidence of
- missing_nice_to_have: JD terms described as preferred/bonus that
  the CV shows no evidence of

Do not pad these lists with terms not actually present in the job
description.

SCORING:

The total score must be exactly 100. Use these maximum category
scores:

- Keyword & skill match: 30
- Experience relevance: 25
- Qualifications match: 20
- Title & seniority alignment: 10
- ATS structure/parsing readiness: 10
- Content quality: 5

These category scores MUST add up to exactly 100.

For each category:

- score must be between 0 and its maximum
- assessment must be concise and specific to this job description,
  not generic
- strengths must contain only supported strengths
- issues must contain only supported gaps
- matched_items must contain items actually present in both documents
- missing_items must contain only genuinely missing or weak items,
  referenced against what the job description actually asks for

SUGGESTIONS:

Return concise, actionable suggestions for improving this CV's match
to THIS specific job. Every suggestion must cite evidence from the
job description and/or the CV. Prioritize suggestions that close
missing_critical gaps first.

RECOMMENDATION:

Based on the final score, set recommendation to one of:
- "strong_match" for 80-100
- "possible_match" for 55-79
- "weak_match" for 0-54

STATS:

Calculate as accurately as possible from the supplied documents:

- jd_requirement_count (meaningful requirement terms extracted from JD)
- matched_keyword_count
- missing_critical_count
- missing_nice_to_have_count
- experience_entries (from the CV)
- quantified_achievements (from the CV)
- word_count (of the CV)

FINAL SCORE:

Return a score from 0 through 100, equal to the sum of all category
scores.

Grade mapping:

90-100 = Excellent
80-89  = Very Good
70-79  = Good
60-69  = Needs Improvement
50-59  = Weak
0-49   = Poor
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,

            'properties' => [
                'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],

                'grade' => [
                    'type' => 'string',
                    'enum' => ['Excellent', 'Very Good', 'Good', 'Needs Improvement', 'Weak', 'Poor'],
                ],

                'recommendation' => [
                    'type' => 'string',
                    'enum' => ['strong_match', 'possible_match', 'weak_match'],
                ],

                'summary' => ['type' => 'string'],

                'categories' => [
                    'type' => 'object',
                    'additionalProperties' => false,

                    'properties' => [
                        'keyword_skill_match' => $this->categorySchema(),
                        'experience_relevance' => $this->categorySchema(),
                        'qualifications_match' => $this->categorySchema(),
                        'title_seniority_alignment' => $this->categorySchema(),
                        'ats_formatting' => $this->categorySchema(),
                        'content_quality' => $this->categorySchema(),
                    ],

                    'required' => [
                        'keyword_skill_match',
                        'experience_relevance',
                        'qualifications_match',
                        'title_seniority_alignment',
                        'ats_formatting',
                        'content_quality',
                    ],
                ],

                'keyword_analysis' => [
                    'type' => 'object',
                    'additionalProperties' => false,

                    'properties' => [
                        'matched' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'missing_critical' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'missing_nice_to_have' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],

                    'required' => ['matched', 'missing_critical', 'missing_nice_to_have'],
                ],

                'suggestions' => [
                    'type' => 'array',

                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,

                        'properties' => [
                            'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'category' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'evidence' => ['type' => 'string'],
                        ],

                        'required' => ['priority', 'category', 'title', 'description', 'evidence'],
                    ],
                ],

                'stats' => [
                    'type' => 'object',
                    'additionalProperties' => false,

                    'properties' => [
                        'jd_requirement_count' => ['type' => 'integer', 'minimum' => 0],
                        'matched_keyword_count' => ['type' => 'integer', 'minimum' => 0],
                        'missing_critical_count' => ['type' => 'integer', 'minimum' => 0],
                        'missing_nice_to_have_count' => ['type' => 'integer', 'minimum' => 0],
                        'experience_entries' => ['type' => 'integer', 'minimum' => 0],
                        'quantified_achievements' => ['type' => 'integer', 'minimum' => 0],
                        'word_count' => ['type' => 'integer', 'minimum' => 0],
                    ],

                    'required' => [
                        'jd_requirement_count',
                        'matched_keyword_count',
                        'missing_critical_count',
                        'missing_nice_to_have_count',
                        'experience_entries',
                        'quantified_achievements',
                        'word_count',
                    ],
                ],
            ],

            'required' => [
                'score',
                'grade',
                'recommendation',
                'summary',
                'categories',
                'keyword_analysis',
                'suggestions',
                'stats',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categorySchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,

            'properties' => [
                'label' => ['type' => 'string'],
                'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'max_score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'assessment' => ['type' => 'string'],
                'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                'issues' => ['type' => 'array', 'items' => ['type' => 'string']],
                'matched_items' => ['type' => 'array', 'items' => ['type' => 'string']],
                'missing_items' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],

            'required' => [
                'label',
                'score',
                'max_score',
                'assessment',
                'strengths',
                'issues',
                'matched_items',
                'missing_items',
            ],
        ];
    }

    /**
     * Reuses the same normalization contract as ATSAnalyzerService so both
     * services accept identical parser output.
     *
     * @param array<string, mixed> $cv
     * @return array<string, mixed>
     */
    private function normalizeCv(array $cv): array
    {
        $candidate = $cv['candidateName'][0] ?? [];

        if (!is_array($candidate)) {
            $candidate = [];
        }

        $experience = $cv['workExperience'] ?? $cv['experience'] ?? $cv['employment'] ?? [];
        $skills = $cv['skills'] ?? $cv['skill'] ?? [];
        $education = $cv['education'] ?? [];
        $projects = $cv['projects'] ?? [];
        $certifications = $cv['certifications'] ?? [];
        $languages = $cv['languages'] ?? [];

        $phone = '';

        if (
            isset($cv['phoneNumber'])
            && is_array($cv['phoneNumber'])
            && isset($cv['phoneNumber'][0])
            && is_array($cv['phoneNumber'][0])
        ) {
            $phone = $cv['phoneNumber'][0]['formattedNumber']
                ?? $cv['phoneNumber'][0]['number']
                ?? '';
        }

        if ($phone === '') {
            $phone = $cv['phone'] ?? '';
        }

        $socialLinks = $cv['socialLinks'] ?? [];

        if (!is_array($socialLinks)) {
            $socialLinks = [];
        }

        $linkedin = $cv['linkedin']
            ?? $cv['linkedIn']
            ?? $cv['linkedinUrl']
            ?? $socialLinks['linkedin']
            ?? $socialLinks['linkedIn']
            ?? '';

        return [
            'candidate' => [
                'first_name' => $this->text($candidate['firstName'] ?? ''),
                'last_name' => $this->text($candidate['familyName'] ?? $candidate['lastName'] ?? ''),
            ],

            'contact' => [
                'email' => $this->text(
                    is_array($cv['email'] ?? null) ? ($cv['email'][0] ?? '') : ($cv['email'] ?? '')
                ),
                'phone' => $this->text($phone),
                'linkedin' => $this->text($linkedin),
                'location' => $this->text($cv['location'] ?? ''),
            ],

            'summary' => $this->text(
                $cv['summary'] ?? $cv['profile'] ?? $cv['professionalSummary'] ?? ''
            ),

            'experience' => $this->capList(is_array($experience) ? $experience : []),
            'skills' => $this->capList(is_array($skills) ? $skills : []),
            'education' => $this->capList(is_array($education) ? $education : []),
            'projects' => $this->capList(is_array($projects) ? $projects : []),
            'certifications' => $this->capList(is_array($certifications) ? $certifications : []),
            'languages' => $this->capList(is_array($languages) ? $languages : []),
        ];
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int|string, mixed>
     */
    private function capList(array $items): array
    {
        array_walk_recursive($items, function (&$value): void {
            if (is_string($value)) {
                $value = $this->capText($value, self::MAX_CV_FIELD_LENGTH);
            }
        });

        return $items;
    }

    private function capText(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit
            ? mb_substr($value, 0, $limit) . '…'
            : $value;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalizeResult(array $result, string $requestId): array
    {
        $categories = $result['categories'] ?? [];

        if (!is_array($categories)) {
            $categories = [];
        }

        $categoryMaxScores = [
            'keyword_skill_match' => 30,
            'experience_relevance' => 25,
            'qualifications_match' => 20,
            'title_seniority_alignment' => 10,
            'ats_formatting' => 10,
            'content_quality' => 5,
        ];

        $totalScore = 0;
        $rawModelScore = (int) ($result['score'] ?? -1);

        foreach ($categoryMaxScores as $key => $maxScore) {
            if (!isset($categories[$key]) || !is_array($categories[$key])) {
                $categories[$key] = $this->emptyCategory($key, $maxScore);
            }

            $categoryScore = (int) ($categories[$key]['score'] ?? 0);
            $categoryScore = max(0, min($maxScore, $categoryScore));

            $categories[$key]['score'] = $categoryScore;
            $categories[$key]['max_score'] = $maxScore;
            $categories[$key]['label'] = $categories[$key]['label'] ?? $this->categoryLabel($key);
            $categories[$key]['assessment'] = $categories[$key]['assessment'] ?? '';
            $categories[$key]['strengths'] = is_array($categories[$key]['strengths'] ?? null) ? $categories[$key]['strengths'] : [];
            $categories[$key]['issues'] = is_array($categories[$key]['issues'] ?? null) ? $categories[$key]['issues'] : [];
            $categories[$key]['matched_items'] = is_array($categories[$key]['matched_items'] ?? null) ? $categories[$key]['matched_items'] : [];
            $categories[$key]['missing_items'] = is_array($categories[$key]['missing_items'] ?? null) ? $categories[$key]['missing_items'] : [];

            $totalScore += $categoryScore;
        }

        $score = max(0, min(100, $totalScore));

        if ($rawModelScore >= 0 && abs($rawModelScore - $score) > 5) {
            Log::warning('Job match analysis: model score/category total mismatch', [
                'request_id' => $requestId,
                'model_score' => $rawModelScore,
                'category_total' => $score,
            ]);
        }

        $keywordAnalysis = $result['keyword_analysis'] ?? [];

        if (!is_array($keywordAnalysis)) {
            $keywordAnalysis = [];
        }

        $suggestions = $result['suggestions'] ?? [];

        if (!is_array($suggestions)) {
            $suggestions = [];
        }

        $stats = $result['stats'] ?? [];

        if (!is_array($stats)) {
            $stats = [];
        }

        $recommendation = $result['recommendation'] ?? null;

        if (!in_array($recommendation, ['strong_match', 'possible_match', 'weak_match'], true)) {
            // Derive from score if the model omitted/mangled it, rather
            // than trusting an unvalidated enum straight through.
            $recommendation = match (true) {
                $score >= 80 => 'strong_match',
                $score >= 55 => 'possible_match',
                default => 'weak_match',
            };
        }

        return [
            'score' => $score,
            'max_score' => 100,
            'percentage' => $score,
            'grade' => $this->grade($score),
            'recommendation' => $recommendation,
            'summary' => (string) ($result['summary'] ?? ''),
            'categories' => $categories,

            'keyword_analysis' => [
                'matched' => is_array($keywordAnalysis['matched'] ?? null) ? $keywordAnalysis['matched'] : [],
                'missing_critical' => is_array($keywordAnalysis['missing_critical'] ?? null) ? $keywordAnalysis['missing_critical'] : [],
                'missing_nice_to_have' => is_array($keywordAnalysis['missing_nice_to_have'] ?? null) ? $keywordAnalysis['missing_nice_to_have'] : [],
            ],

            'suggestions' => $suggestions,

            'stats' => [
                'jd_requirement_count' => max(0, (int) ($stats['jd_requirement_count'] ?? 0)),
                'matched_keyword_count' => max(0, (int) ($stats['matched_keyword_count'] ?? 0)),
                'missing_critical_count' => max(0, (int) ($stats['missing_critical_count'] ?? 0)),
                'missing_nice_to_have_count' => max(0, (int) ($stats['missing_nice_to_have_count'] ?? 0)),
                'experience_entries' => max(0, (int) ($stats['experience_entries'] ?? 0)),
                'quantified_achievements' => max(0, (int) ($stats['quantified_achievements'] ?? 0)),
                'word_count' => max(0, (int) ($stats['word_count'] ?? 0)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractOutputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return trim($response['output_text']);
        }

        foreach ($response['output'] ?? [] as $output) {
            if (!is_array($output)) {
                continue;
            }

            foreach ($output['content'] ?? [] as $content) {
                if (!is_array($content)) {
                    continue;
                }

                if (isset($content['text']) && is_string($content['text'])) {
                    return trim($content['text']);
                }
            }
        }

        return '';
    }

    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                $text = $this->text($item);

                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return implode(' ', $parts);
        }

        if (is_object($value)) {
            return $this->text(get_object_vars($value));
        }

        return '';
    }

    private function emptyCategory(string $key, int $maxScore): array
    {
        return [
            'label' => $this->categoryLabel($key),
            'score' => 0,
            'max_score' => $maxScore,
            'assessment' => '',
            'strengths' => [],
            'issues' => [],
            'matched_items' => [],
            'missing_items' => [],
        ];
    }

    private function categoryLabel(string $key): string
    {
        return match ($key) {
            'keyword_skill_match' => 'Keyword & Skill Match',
            'experience_relevance' => 'Experience Relevance',
            'qualifications_match' => 'Qualifications Match',
            'title_seniority_alignment' => 'Title & Seniority Alignment',
            'ats_formatting' => 'ATS Formatting',
            'content_quality' => 'Content Quality',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'Excellent',
            $score >= 80 => 'Very Good',
            $score >= 70 => 'Good',
            $score >= 60 => 'Needs Improvement',
            $score >= 50 => 'Weak',
            default => 'Poor',
        };
    }

    private function truncateForLog(string $body, int $limit = 2000): string
    {
        return mb_strlen($body) > $limit
            ? mb_substr($body, 0, $limit) . '… [truncated]'
            : $body;
    }
}