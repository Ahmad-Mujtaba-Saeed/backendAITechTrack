<?php

namespace Modules\Resume\Services;

class KeywordMatchAnalyzerService
{
   
    private const STOPWORDS = [
        'a','an','the','and','or','but','if','then','else','of','to','in','on','for','with',
        'at','by','from','as','is','are','was','were','be','been','being','it','its','this',
        'that','these','those','you','your','we','our','they','their','he','she','his','her',
        'will','shall','can','could','should','would','may','might','must','have','has','had',
        'do','does','did','not','no','yes','so','such','than','too','very','just','also',
        'about','into','through','during','before','after','above','below','up','down','out',
        'off','over','under','again','further','once','here','there','when','where','why','how',
        'all','any','both','each','few','more','most','other','some','only','own','same',
        'job','role','position','company','team','work','years','year','experience','required',
        'requirements','responsibilities','qualifications','preferred','ability','strong',
        'excellent','including','etc','plus','looking','candidate','candidates','apply',
    ];

   
    private const HARD_SKILL_HINTS = [
        // software / IT
        'php','laravel','javascript','typescript','python','java','c++','c#','ruby','golang',
        'react','vue','angular','node.js','nodejs','django','flask','spring','symfony',
        'mysql','postgresql','mongodb','redis','elasticsearch','sql','nosql',
        'aws','azure','gcp','docker','kubernetes','ci/cd','jenkins','terraform','ansible',
        'git','github','gitlab','rest api','graphql','microservices','linux','bash',
        'html','css','sass','tailwind','bootstrap','webpack','vite',
        'machine learning','deep learning','tensorflow','pytorch','nlp','data science',
        // business / marketing
        'seo','sem','google analytics','google ads','facebook ads','hubspot','salesforce',
        'crm','erp','sap','excel','powerpoint','power bi','tableau','looker',
        'project management','agile','scrum','kanban','pmp','six sigma',
        'financial modeling','budgeting','forecasting','bookkeeping','quickbooks',
        // design
        'figma','sketch','adobe photoshop','adobe illustrator','ui/ux','ux research',
        // healthcare / other
        'hipaa','ehr','emr','patient care','clinical research',
        // certifications / education-adjacent
        'bachelor','master','mba','phd','certified','certification','license','licensed',
    ];

    private const SOFT_SKILL_HINTS = [
        'communication','leadership','teamwork','collaboration','problem solving',
        'problem-solving','critical thinking','time management','adaptability',
        'creativity','attention to detail','organization','organizational',
        'interpersonal','negotiation','conflict resolution','decision making',
        'multitasking','flexibility','initiative','work ethic','mentoring','coaching',
        'presentation','public speaking','customer service','stakeholder management',
    ];

    /** Action verbs — presence/count is a real, checkable ATS signal. */
    private const ACTION_VERBS = [
        'achieved','managed','led','built','created','developed','designed','implemented',
        'launched','increased','decreased','reduced','improved','optimized','streamlined',
        'delivered','executed','coordinated','directed','supervised','trained','mentored',
        'negotiated','analyzed','architected','automated','deployed','migrated','scaled',
        'generated','drove','spearheaded','established','restructured','resolved',
        'authored','presented','collaborated','initiated','transformed','accelerated',
    ];

    public function match(array $resume, string $jobDescription): array
    {
        $resumeText = $this->flattenResumeText($resume);
        $resumeNormalized = $this->normalize($resumeText);
        $resumeTerms = $this->extractCandidateTerms($jobDescription); // seeded by JD vocabulary, see below

        $jdKeywords = $this->extractKeywordsWithWeight($jobDescription);

        $matched = [];
        $missing = [];
        $matchedHard = [];
        $missingHard = [];
        $matchedSoft = [];
        $missingSoft = [];

        foreach ($jdKeywords as $term => $weight) {
            $isHard = $this->isHardSkill($term);
            $found = $this->termAppearsIn($term, $resumeNormalized);

            if ($found) {
                $matched[$term] = $weight;
                $isHard ? $matchedHard[] = $term : $matchedSoft[] = $term;
            } else {
                $missing[$term] = $weight;
                $isHard ? $missingHard[] = $term : $missingSoft[] = $term;
            }
        }

        $totalWeight = array_sum($jdKeywords) ?: 1;
        $matchedWeight = array_sum($matched);
        $matchPercentage = (int) round(($matchedWeight / $totalWeight) * 100);
        $matchPercentage = max(0, min(100, $matchPercentage));

        return [
            'match_percentage' => $matchPercentage,
            'total_keywords' => count($jdKeywords),
            'matched_count' => count($matched),
            'missing_count' => count($missing),
            'matched_keywords' => array_keys($matched),
            'missing_keywords' => $this->topByWeight($missing, 20),
            'hard_skills' => [
                'matched' => array_values(array_unique($matchedHard)),
                'missing' => array_values(array_unique($missingHard)),
            ],
            'soft_skills' => [
                'matched' => array_values(array_unique($matchedSoft)),
                'missing' => array_values(array_unique($missingSoft)),
            ],
            'verdict' => $this->verdict($matchPercentage),
        ];
    }

    public function analyzeStructure(array $resume): array
    {
        $text = $this->flattenResumeText($resume);
        $normalized = $this->normalize($text);
        $wordCount = str_word_count($text);

        $hasEmail = (bool) preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text);
        $hasPhone = (bool) preg_match('/(\+?\d[\d\s().\-]{7,}\d)/', $text);
        $hasLinkedIn = (bool) preg_match('/linkedin\.com\/in\//i', $text);

        $actionVerbCount = 0;
        foreach (self::ACTION_VERBS as $verb) {
            $actionVerbCount += (int) preg_match_all('/\b' . preg_quote($verb, '/') . '\b/i', $normalized);
        }

       
        preg_match_all('/\b\d{1,3}(?:,\d{3})*(?:\.\d+)?\s?%|\$\s?\d[\d,.]*|\b\d+\+?\s?(?:x|times)\b/i', $text, $quantMatches);
        $quantifiedCount = count($quantMatches[0]);

        $bulletCount = substr_count($text, "\n-") + substr_count($text, "\n•") + substr_count($text, "\n*");

        return [
            'word_count' => $wordCount,
            'contact_info' => [
                'has_email' => $hasEmail,
                'has_phone' => $hasPhone,
                'has_linkedin' => $hasLinkedIn,
            ],
            'action_verb_count' => $actionVerbCount,
            'quantified_achievement_count' => $quantifiedCount,
            'bullet_count' => $bulletCount,
            'length_assessment' => match (true) {
                $wordCount < 150 => 'Too short — likely missing detail ATS and recruiters expect.',
                $wordCount > 1200 => 'Very long — consider trimming to the most relevant content.',
                default => 'Reasonable length.',
            },
        ];
    }

    private function extractKeywordsWithWeight(string $jobDescription): array
    {
        $normalized = $this->normalize($jobDescription);
        $weights = [];

        // 1) Direct hits against curated vocabularies (highest confidence).
        foreach (self::HARD_SKILL_HINTS as $term) {
            if ($this->termAppearsIn($term, $normalized)) {
                $weights[$term] = 3 + $this->repetitionBoost($term, $normalized);
            }
        }

        foreach (self::SOFT_SKILL_HINTS as $term) {
            if ($this->termAppearsIn($term, $normalized)) {
                $weights[$term] = 1 + $this->repetitionBoost($term, $normalized);
            }
        }

      
        foreach ($this->extractCandidateTerms($jobDescription) as $term => $count) {
            if (isset($weights[$term]) || $count < 2) {
                continue; // require repetition for uncurated terms to reduce noise
            }

            $weights[$term] = 2 + min(2, $count - 2);
        }

        arsort($weights);

        return $weights;
    }

    private function extractCandidateTerms(string $text): array
    {
        $normalized = $this->normalize($text);
        $words = preg_split('/[^a-z0-9+.#]+/i', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_map('strtolower', $words);

        $counts = [];

        foreach ($words as $i => $word) {
            if (in_array($word, self::STOPWORDS, true) || mb_strlen($word) < 3) {
                continue;
            }

            $counts[$word] = ($counts[$word] ?? 0) + 1;

            // bigram
            if (isset($words[$i + 1]) && !in_array($words[$i + 1], self::STOPWORDS, true)) {
                $bigram = $word . ' ' . $words[$i + 1];
                $counts[$bigram] = ($counts[$bigram] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function repetitionBoost(string $term, string $normalizedText): int
    {
        $occurrences = preg_match_all('/\b' . preg_quote($term, '/') . '\b/i', $normalizedText);

        return min(2, max(0, $occurrences - 1));
    }

    private function isHardSkill(string $term): bool
    {
        return in_array($term, self::HARD_SKILL_HINTS, true);
    }

    private function termAppearsIn(string $term, string $normalizedHaystack): bool
    {
        return (bool) preg_match('/\b' . preg_quote($term, '/') . '\b/i', $normalizedHaystack);
    }

   
    private function topByWeight(array $weighted, int $limit): array
    {
        arsort($weighted);

        return array_slice(array_keys($weighted), 0, $limit);
    }

    private function verdict(int $matchPercentage): string
    {
        return match (true) {
            $matchPercentage >= 80 => 'Strong match — this resume is well-aligned with the job description.',
            $matchPercentage >= 60 => 'Good match — a few missing keywords are worth adding.',
            $matchPercentage >= 40 => 'Partial match — consider tailoring the resume more closely to this role.',
            default => 'Weak match — this resume is missing many of the terms this job description emphasizes.',
        };
    }

    private function normalize(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES);

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

  
    private function flattenResumeText(array $resume): string
    {
        $parts = [];
        array_walk_recursive($resume, function ($value) use (&$parts): void {
            if (is_string($value) && trim($value) !== '') {
                $parts[] = $value;
            }
        });

        return implode("\n", $parts);
    }
}