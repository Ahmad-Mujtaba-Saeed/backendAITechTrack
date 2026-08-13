<?php

namespace Modules\Resume\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use modules\Resume\Exceptions\ATSAnalysisException;

class ATSAnalyzerService
{
    /**
     * HTTP status codes worth retrying — transient provider-side issues.
     * 4xx client errors (bad request, auth) are deliberately excluded:
     * retrying those just burns quota for a guaranteed repeat failure.
     */
    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    /**
     * Hard cap on any single free-text field sent to the model.
     * Protects against context blow-up / runaway token cost from a
     * pathological parser output (e.g. a mis-parsed multi-page CV
     * dumped into one field).
     */
    private const MAX_FIELD_LENGTH = 4000;

    /**
     * How long a cached result for an unchanged CV stays valid.
     * Long-lived on purpose: the point of this cache is "same resume,
     * same score", not freshness.
     */
    private const RESULT_CACHE_TTL_SECONDS = 60 * 60 * 24 * 30;

    /**
     * Known structural properties of each resume template, sourced from the
     * actual Blade files (not guessed) — this is the "evidence" the system
     * prompt requires before the model may say anything about layout/format.
     * Keep this in sync with modules/Resume/resources/views/pdfs/*.blade.php.
     */
    private const TEMPLATE_FORMATTING_PROFILES = [
        'classic' => ['tables' => 0, 'columns' => 1, 'graphics_or_icons' => false],
        'luxe' => ['tables' => 0, 'columns' => 1, 'graphics_or_icons' => false],
        'default' => ['tables' => 1, 'columns' => 1, 'graphics_or_icons' => true],
        'modern' => ['tables' => 3, 'columns' => 2, 'graphics_or_icons' => true],
    ];

    /**
     * Analyze a CV for ATS readiness.
     *
     * @param array<string, mixed> $cv
     * @param string|null $requestId Correlation id for logs; caller-supplied
     *                               (e.g. a queue job id) so a support ticket
     *                               can be traced end to end.
     * @return array<string, mixed>
     *
     * @throws ATSAnalysisException on any failure. getMessage() has full
     *         internal detail for logs; getSafeMessage() is safe to expose.
     */
    public function analyze(array $cv, ?string $requestId = null, ?string $template = null): array
    {
        $requestId ??= (string) \Illuminate\Support\Str::uuid();

        $resume = $this->normalizeCv($cv);

        $this->assertHasContent($resume, $requestId);

        $templateFacts = $this->templateFormattingFacts($template);
        $resume['template_formatting'] = $templateFacts;

        $cacheKey = $this->resultCacheKey($resume);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            Log::info('ATS analysis: served from cache (unchanged CV)', [
                'request_id' => $requestId,
                'cache_key' => $cacheKey,
            ]);

            return $cached;
        }

        $apiKey = config('services.openai.api_key');

        if (empty($apiKey)) {
            Log::error('ATS analysis: OpenAI API key not configured', [
                'request_id' => $requestId,
            ]);

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
                    'task' => 'Analyze this CV for ATS readiness.',
                    'cv' => $resume,
                ],
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            Log::error('ATS analysis: failed to encode outbound CV payload', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);

            throw new ATSAnalysisException(
                'Failed to encode CV payload: ' . $e->getMessage(),
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
                    1000,
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

                        // Deterministic-as-possible sampling. This alone does not
                        // guarantee identical output on every call (OpenAI does not
                        // promise bit-for-bit determinism even at temperature 0),
                        // which is why the result is also cached by CV content hash
                        // below — that's what actually guarantees "same resume, same
                        // score" from the client's point of view.
                        'temperature' => 0,

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
                                'name' => 'ats_analysis',
                                'strict' => true,
                                'schema' => $this->schema(),
                            ],
                        ],
                    ]
                );
        } catch (ConnectionException $e) {
            Log::error('ATS analysis: connection failure to OpenAI', [
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
            // Full provider body goes to logs only — never to the caller.
            Log::error('ATS analysis: OpenAI request failed', [
                'request_id' => $requestId,
                'status' => $response->status(),
                'body' => $this->truncateForLog($response->body()),
                'duration_ms' => $durationMs,
            ]);

            $safeMessage = $response->status() === 429
                ? 'CV analysis is receiving high demand right now. Please try again in a moment.'
                : 'CV analysis is temporarily unavailable. Please try again shortly.';

            throw new ATSAnalysisException(
                "AI ATS analysis failed: {$response->status()} - {$response->body()}",
                safeMessage: $safeMessage,
                requestId: $requestId,
            );
        }

        $result = $response->json();

        if (!is_array($result)) {
            Log::error('ATS analysis: non-array API response', [
                'request_id' => $requestId,
                'duration_ms' => $durationMs,
            ]);

            throw new ATSAnalysisException(
                'AI ATS analysis returned an invalid API response.',
                requestId: $requestId,
            );
        }

        $output = $this->extractOutputText($result);

        if ($output === '') {
            Log::error('ATS analysis: empty output_text from OpenAI', [
                'request_id' => $requestId,
                'duration_ms' => $durationMs,
            ]);

            throw new ATSAnalysisException(
                'AI ATS analysis returned an empty response.',
                requestId: $requestId,
            );
        }

        try {
            $analysis = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::error('ATS analysis: model output was not valid JSON', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            throw new ATSAnalysisException(
                'AI ATS analysis returned invalid JSON: ' . $e->getMessage(),
                previous: $e,
                requestId: $requestId,
            );
        }

        if (!is_array($analysis)) {
            Log::error('ATS analysis: decoded output was not a JSON object', [
                'request_id' => $requestId,
                'duration_ms' => $durationMs,
            ]);

            throw new ATSAnalysisException(
                'AI ATS analysis returned an invalid JSON structure.',
                requestId: $requestId,
            );
        }

        Log::info('ATS analysis completed', [
            'request_id' => $requestId,
            'duration_ms' => $durationMs,
            'model' => $model,
        ]);

        $normalized = $this->normalizeResult($analysis, $requestId);

        Cache::put($cacheKey, $normalized, self::RESULT_CACHE_TTL_SECONDS);

        return $normalized;
    }

    /**
     * Deterministic cache key derived from the CV content actually sent to
     * the model — not the resume's database id and not a per-request id.
     * Same normalized content -> same key -> same cached score, regardless
     * of how many times the user re-runs the check.
     */
    private function resultCacheKey(array $resume): string
    {
        $canonical = json_encode($resume, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return 'ats_analysis:' . hash('sha256', $canonical);
    }

    /**
     * Look up known structural facts for a template. Unknown/missing
     * templates get an explicit "unknown" marker rather than being silently
     * treated as single-column/no-tables — the model is instructed not to
     * guess, so we don't want to hand it a false "clean" default either.
     */
    private function templateFormattingFacts(?string $template): array
    {
        if ($template === null || !isset(self::TEMPLATE_FORMATTING_PROFILES[$template])) {
            return [
                'template_id' => $template ?? 'unknown',
                'known' => false,
            ];
        }

        return [
            'template_id' => $template,
            'known' => true,
            ...self::TEMPLATE_FORMATTING_PROFILES[$template],
        ];
    }

    /**
     * Reject empty or content-free CVs before spending a paid API call.
     *
     * @param array<string, mixed> $resume
     */
    private function assertHasContent(array $resume, string $requestId): void
    {
        $hasContent =
            $resume['summary'] !== ''
            || $resume['candidate']['first_name'] !== ''
            || !empty($resume['experience'])
            || !empty($resume['skills'])
            || !empty($resume['education']);

        if (!$hasContent) {
            Log::warning('ATS analysis: rejected empty/content-free CV', [
                'request_id' => $requestId,
            ]);

            throw new ATSAnalysisException(
                'CV contains no analyzable content.',
                safeMessage: 'This CV doesn\'t have enough content to analyze yet. Add some details and try again.',
                requestId: $requestId,
            );
        }
    }

    /**
     * System instructions for the ATS model.
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a production-grade ATS resume analysis engine.

Analyze the supplied CV as an ATS and resume screening specialist.

Your analysis must be evidence-based and must only use information
present in the supplied CV.

IMPORTANT RULES:

1. Do not use a fixed hardcoded keyword list.
2. Do not assume every profession requires the same skills.
3. Infer the candidate's likely professional domain from the CV.
4. Evaluate the actual content present in the CV.
5. Do not invent experience, skills, education, achievements,
   certifications, technologies, dates or responsibilities.
6. Every weakness or suggestion must be supported by evidence from
   the supplied CV.
7. Do not penalize information that is not reasonably expected for
   the candidate's professional domain.
8. Evaluate ATS readiness, not the candidate's overall employability.
9. Do not claim that a particular ATS vendor uses this exact score.
10. Do not calculate a job-match percentage because no job description
    is supplied.

EVALUATE:

- Contact information
- Candidate name
- Professional summary
- Work experience
- Job titles
- Employment dates
- Experience descriptions
- Skills
- Education
- Certifications when present
- Projects when present
- Languages when present
- Domain-specific terminology
- Keywords and professional concepts
- Action verbs
- Quantified achievements
- Repetition
- Generic or vague statements
- Missing information
- ATS structure/parsing readiness
- Overall content quality

KEYWORD ANALYSIS:

Extract important terms from the actual CV.

Identify:

- professional terminology
- technologies
- tools
- methodologies
- responsibilities
- industry terminology
- repeated concepts
- domain-specific skills

Do not compare the CV against a generic predefined keyword database.

If no job description is supplied, do not pretend to calculate
keyword match against a job.

ATS FORMATTING:

Only evaluate formatting information that can reasonably be inferred
from the supplied structured CV data.

Do NOT claim that fonts, columns, icons, tables, graphics, colors,
headers or visual design are problematic unless the supplied data
actually provides evidence about them.

The CV JSON includes a `template_formatting` object. When
`known` is true, treat its `tables`, `columns` and
`graphics_or_icons` fields as ground-truth evidence about the
resume's actual layout, and factor them into the ats_formatting
score (e.g. multiple tables or more than one column are common
causes of ATS parsing errors). When `known` is false, you have no
formatting evidence — do not guess.

SCORING:

The total score must be exactly 100.

Use these maximum category scores:

- Contact information: 10
- Professional summary: 10
- Work experience: 25
- Skills: 15
- Education: 10
- Keyword/topic coverage: 10
- Achievements/action verbs: 5
- ATS structure/parsing readiness: 10
- Content quality: 5

These category scores MUST add up to exactly 100.

For each category:

- score must be between 0 and its maximum
- assessment must be concise
- strengths must contain only supported strengths
- issues must contain only supported issues
- matched_items must contain items actually present
- missing_items must contain only genuinely missing or weak items

SUGGESTIONS:

Return concise, actionable suggestions.

Every suggestion must include evidence from the supplied CV.

Do not recommend adding a skill merely because it is popular.

Only recommend adding a skill, keyword, achievement or section when
the CV provides a reasonable basis for the recommendation.

STATS:

Calculate statistics from the supplied CV as accurately as possible:

- experience_entries
- skills_count
- education_entries
- keyword_matches
- action_verbs
- quantified_achievements
- word_count

keyword_matches means the number of meaningful professional terms
identified from the candidate's own CV, not matches against a
predefined keyword database.

FINAL SCORE:

Return a score from 0 through 100.

The score must equal the sum of all category scores.

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
     * Structured output JSON schema.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,

            'properties' => [
                'score' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                ],

                'grade' => [
                    'type' => 'string',
                    'enum' => [
                        'Excellent',
                        'Very Good',
                        'Good',
                        'Needs Improvement',
                        'Weak',
                        'Poor',
                    ],
                ],

                'summary' => [
                    'type' => 'string',
                ],

                'categories' => [
                    'type' => 'object',
                    'additionalProperties' => false,

                    'properties' => [
                        'contact_information' => $this->categorySchema(),
                        'professional_summary' => $this->categorySchema(),
                        'work_experience' => $this->categorySchema(),
                        'skills' => $this->categorySchema(),
                        'education' => $this->categorySchema(),
                        'keywords' => $this->categorySchema(),
                        'achievements' => $this->categorySchema(),
                        'ats_formatting' => $this->categorySchema(),
                        'content_quality' => $this->categorySchema(),
                    ],

                    'required' => [
                        'contact_information',
                        'professional_summary',
                        'work_experience',
                        'skills',
                        'education',
                        'keywords',
                        'achievements',
                        'ats_formatting',
                        'content_quality',
                    ],
                ],

                'suggestions' => [
                    'type' => 'array',

                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,

                        'properties' => [
                            'priority' => [
                                'type' => 'string',
                                'enum' => ['high', 'medium', 'low'],
                            ],
                            'category' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'evidence' => ['type' => 'string'],
                        ],

                        'required' => [
                            'priority',
                            'category',
                            'title',
                            'description',
                            'evidence',
                        ],
                    ],
                ],

                'stats' => [
                    'type' => 'object',
                    'additionalProperties' => false,

                    'properties' => [
                        'experience_entries' => ['type' => 'integer', 'minimum' => 0],
                        'skills_count' => ['type' => 'integer', 'minimum' => 0],
                        'education_entries' => ['type' => 'integer', 'minimum' => 0],
                        'keyword_matches' => ['type' => 'integer', 'minimum' => 0],
                        'action_verbs' => ['type' => 'integer', 'minimum' => 0],
                        'quantified_achievements' => ['type' => 'integer', 'minimum' => 0],
                        'word_count' => ['type' => 'integer', 'minimum' => 0],
                    ],

                    'required' => [
                        'experience_entries',
                        'skills_count',
                        'education_entries',
                        'keyword_matches',
                        'action_verbs',
                        'quantified_achievements',
                        'word_count',
                    ],
                ],
            ],

            'required' => [
                'score',
                'grade',
                'summary',
                'categories',
                'suggestions',
                'stats',
            ],
        ];
    }

    /**
     * Schema for an individual scoring category.
     *
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
     * Normalize incoming CV data into a predictable structure.
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
     * Recursively cap any string value in a list to MAX_FIELD_LENGTH so a
     * single malformed field can't blow up token cost or context size.
     *
     * @param array<int|string, mixed> $items
     * @return array<int|string, mixed>
     */
    private function capList(array $items): array
    {
        array_walk_recursive($items, function (&$value): void {
            if (is_string($value) && mb_strlen($value) > self::MAX_FIELD_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_FIELD_LENGTH) . '…';
            }
        });

        return $items;
    }

    /**
     * Normalize and validate AI result.
     *
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
            'contact_information' => 10,
            'professional_summary' => 10,
            'work_experience' => 25,
            'skills' => 15,
            'education' => 10,
            'keywords' => 10,
            'achievements' => 5,
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

        /*
         * Use the category total as the authoritative score. This prevents
         * the model from returning score=92 while categories total 74.
         */
        $score = max(0, min(100, $totalScore));

        if ($rawModelScore >= 0 && abs($rawModelScore - $score) > 5) {
            // Model's own arithmetic drifted from its category breakdown by
            // more than a rounding margin — not fatal, but worth knowing
            // about if it happens often (signals prompt drift).
            Log::warning('ATS analysis: model score/category total mismatch', [
                'request_id' => $requestId,
                'model_score' => $rawModelScore,
                'category_total' => $score,
            ]);
        }

        $suggestions = $result['suggestions'] ?? [];

        if (!is_array($suggestions)) {
            $suggestions = [];
        }

        $stats = $result['stats'] ?? [];

        if (!is_array($stats)) {
            $stats = [];
        }

        return [
            'score' => $score,
            'max_score' => 100,
            'percentage' => $score,
            'grade' => $this->grade($score),
            'summary' => (string) ($result['summary'] ?? ''),
            'categories' => $categories,
            'suggestions' => $suggestions,

            'stats' => [
                'experience_entries' => max(0, (int) ($stats['experience_entries'] ?? 0)),
                'skills_count' => max(0, (int) ($stats['skills_count'] ?? 0)),
                'education_entries' => max(0, (int) ($stats['education_entries'] ?? 0)),
                'keyword_matches' => max(0, (int) ($stats['keyword_matches'] ?? 0)),
                'action_verbs' => max(0, (int) ($stats['action_verbs'] ?? 0)),
                'quantified_achievements' => max(0, (int) ($stats['quantified_achievements'] ?? 0)),
                'word_count' => max(0, (int) ($stats['word_count'] ?? 0)),
            ],
        ];
    }

    /**
     * Safely extract structured JSON text from a Responses API result.
     *
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

    /**
     * Convert arbitrary CV values into text.
     */
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

    /**
     * Create a safe empty category.
     */
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

    /**
     * Human-readable category label.
     */
    private function categoryLabel(string $key): string
    {
        return match ($key) {
            'contact_information' => 'Contact Information',
            'professional_summary' => 'Professional Summary',
            'work_experience' => 'Work Experience',
            'skills' => 'Skills',
            'education' => 'Education',
            'keywords' => 'Keywords',
            'achievements' => 'Achievements & Action Verbs',
            'ats_formatting' => 'ATS Formatting',
            'content_quality' => 'Content Quality',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }

    /**
     * Convert score into grade.
     */
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

    /**
     * Keep provider error bodies out of logs beyond a sane size —
     * some error responses embed large HTML/debug payloads.
     */
    private function truncateForLog(string $body, int $limit = 2000): string
    {
        return mb_strlen($body) > $limit
            ? mb_substr($body, 0, $limit) . '… [truncated]'
            : $body;
    }
}