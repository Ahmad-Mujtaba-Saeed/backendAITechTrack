<?php

namespace Modules\Resume\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Modules\Resume\Exceptions\ATSAnalysisException;

class ATSAnalyzerService
{

    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    private const MAX_FIELD_LENGTH = 4000;

    private const RESULT_CACHE_TTL_SECONDS = 60 * 60 * 24 * 30;

    private const TEMPLATE_FORMATTING_PROFILES = [
        'classic' => ['tables' => 0, 'columns' => 1, 'graphics_or_icons' => false],
        'luxe' => ['tables' => 0, 'columns' => 1, 'graphics_or_icons' => false],
        'default' => ['tables' => 1, 'columns' => 1, 'graphics_or_icons' => true],
        'modern' => ['tables' => 3, 'columns' => 2, 'graphics_or_icons' => true],
    ];

    public function analyze(array $cv, ?string $requestId = null, ?string $template = null): array
    {
        $requestId ??= (string) \Illuminate\Support\Str::uuid();

        $resume = $this->normalizeCv($cv);

        $this->assertHasContent($resume, $requestId);

        $templateFacts = $this->templateFormattingFacts($template);

        $contentResult = $this->analyzeContent($resume, $requestId);
        $formattingCategory = $this->formattingCategory($templateFacts);

        return $this->applyFormatting($contentResult, $formattingCategory, $templateFacts);
    }

    private function analyzeContent(array $resume, string $requestId): array
    {
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

            throw new ATSAnalysisException(
                'Connection to AI provider failed: ' . $e->getMessage(),
                previous: $e,
                requestId: $requestId,
            );
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            // Full provider body goes to logs only — never to the caller.
            
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

    private function resultCacheKey(array $resume): string
    {
        $canonical = json_encode($resume, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return 'ats_analysis:' . hash('sha256', $canonical);
    }

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

    private function capList(array $items): array
    {
        array_walk_recursive($items, function (&$value): void {
            if (is_string($value) && mb_strlen($value) > self::MAX_FIELD_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_FIELD_LENGTH) . '…';
            }
        });

        return $items;
    }

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

        $score = max(0, min(100, $totalScore));

        if ($rawModelScore >= 0 && abs($rawModelScore - $score) > 5) {
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

    private function formattingCategory(array $facts): array
{
    $score = 10;

    $issues = [];
    $strengths = [];

    if ((int) ($facts['columns'] ?? 1) > 1) {
        $score -= 3;
        $issues[] = 'Multiple columns may reduce ATS parsing reliability.';
    } else {
        $strengths[] = 'Single-column structure is ATS-friendly.';
    }

    if ((int) ($facts['tables'] ?? 0) > 0) {
        $score -= 3;
        $issues[] = 'Tables may interfere with ATS parsing.';
    }

    if (($facts['graphics_or_icons'] ?? false) === true) {
        $score -= 2;
        $issues[] = 'Icons may not be reliably parsed by some ATS systems.';

        $score -= 2;
        $issues[] = 'Graphics may reduce ATS parsing reliability.';
    }

    $score = max(0, min(10, $score));

    return [
        'label' => 'ATS Formatting',
        'score' => $score,
        'max_score' => 10,
        'assessment' => $score >= 8
            ? 'The template has an ATS-friendly structure.'
            : 'The template may have some ATS parsing risks.',
        'strengths' => $strengths,
        'issues' => $issues,
        'matched_items' => [],
        'missing_items' => [],
    ];
}

private function applyFormatting(
    array $contentResult,
    array $formattingCategory,
    array $templateFacts
): array {
    $contentResult['categories']['ats_formatting'] = $formattingCategory;

    $totalScore = 0;

    foreach ($contentResult['categories'] as $category) {
        $totalScore += (int) ($category['score'] ?? 0);
    }

    $totalScore = max(0, min(100, $totalScore));

    $contentResult['score'] = $totalScore;
    $contentResult['max_score'] = 100;
    $contentResult['percentage'] = $totalScore;
    $contentResult['grade'] = $this->grade($totalScore);

    $contentResult['template'] = $templateFacts;

    return $contentResult;
}
}