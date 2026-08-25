<?php

namespace Modules\Resume\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\Resume\Models\Resume;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
// use thiagoalessio\TesseractOCR\TesseractOCR;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\FacadesLog;
// use Modules\Resume\Models\GettingStartedStep;
use Illuminate\Support\Facades\Log;

class ResumeController extends Controller
{

    public function index(Request $request)
    {
        $perPage = $request->per_page ?? 5;
        $page = $request->page ?? 1;

        $resumes = Resume::where('user_id', auth()->id())
            ->latest('updated_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $resumes,
            'total' => $resumes->total(),
            'per_page' => (int)$perPage,
            'current_page' => (int)$page,
            'last_page' => ceil($resumes->total() / $perPage)
        ]);
    }

   
    public function createEmpty(Request $request)
    {
        $request->validate([
            'newEmptyResume' => 'required',
        ]);

        $newEmptyResume = $request->newEmptyResume;

        // Create a new resume
        $resume = Resume::create([
            'user_id' => auth()->id(),
            'title' => 'My Resume',
            'cv_resumejson' => $newEmptyResume,
        ]);    

        return response()->json([
            'success' => true,
            'data' => $resume
        ]);
    }

    public function show(string $id)
    {
        
        $resume = Resume::findOrFail($id);
        if($resume->user_id == Auth::user()->id){
            return response()->json([
                'success' => true,
                'data' => $resume
            ]);
        }else{
            return response()->json([
                'success' => false,
                'message' => "Required CV Not Found!"
            ]);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {     
        $request->validate([
            'title' => 'nullable',
            'cv_resumejson' => 'nullable',
            'job_description' => 'nullable',
        ]);

        $cv_resumejson = $request->cv_resumejson;
        $job_description = $request->job_description;

        $resume = Resume::findOrFail($id);

        if ((int) $resume->user_id !== (int) Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update this resume.',
            ], 403);
        }

        if($request->cv_resumejson){
            $resume->cv_resumejson = $cv_resumejson;
        }
        if($request->title){
            $resume->title = $request->title;
        }

        if ($request->has('job_description')) {
            $resume->job_description = $job_description;
        }

        $cv_resumejson = $request->cv_resumejson ?? $resume->cv_resumejson;

        $finalTitle = $request->title ?? $resume->title;

        if ($finalTitle === "My Resume") {

            $candidateName = $cv_resumejson['candidateName'][0] ?? null;
            $headline = $cv_resumejson['headline'] ?? null;

            if ($candidateName && isset($candidateName['firstName']) && isset($candidateName['familyName']) && $headline) {

                $headlineWords = array_slice(explode(' ', $headline), 0, 10);
                $headline = implode(' ', $headlineWords);

                $resume->title = $candidateName['firstName'].' '.$candidateName['familyName'].' | '.$headline;

            } elseif ($candidateName && isset($candidateName['firstName']) && isset($candidateName['familyName'])) {

                $resume->title = $candidateName['firstName'].' '.$candidateName['familyName'];

            } elseif ($candidateName && isset($candidateName['firstName'])) {

                $resume->title = $candidateName['firstName'];
            }
        }

        $resume->save();

        return response()->json([
            'success' => true,
            'data' => $resume
        ]);
    }

    public function delete(Request $request, string $id)
    {
        $resume = Resume::findOrFail($id);
        if($resume->user_id != Auth::user()->id) {

            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this resume.',
            ], 403);
        }

        // Delete the resume
        $resume->delete();


        $perPage = $request->per_page ?? 3;
        $page = $request->page ?? 1;

        $resumes = Resume::where('user_id', auth()->id())
                ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'message' => "Resume Deleted Successfully",
            'data' => $resumes,
            'total' => $resumes->total(),
            'per_page' => (int)$perPage,
            'current_page' => (int)$page,
            'last_page' => ceil($resumes->total() / $perPage)
        ]);
    }


    private function extractTextFromElement($element)
    {
        $text = '';
        
        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractTextFromElement($child);
            }
        } elseif (method_exists($element, 'getText')) {
            $text .= $element->getText();
        }
        
        return $text;
    }

    private function languageStyleGuide(string $style): string
    {
        $guides = [
            'Professional' => <<<'TXT'
                - Register: measured, formal and understated. No slang, no exclamation, no hype.
                - Verb palette: Led, Delivered, Managed, Oversaw, Established, Coordinated, Maintained.
                - Foreground: scope of responsibility, reliability, standards upheld, stakeholders served.
                - Rhythm: even, full sentences of similar length; sober connective phrasing.
                TXT,
            'Creative' => <<<'TXT'
                - Register: vivid and energetic, concrete over abstract, confident but never gimmicky.
                - Verb palette: Shaped, Crafted, Reimagined, Devised, Launched, Brought to life, Explored.
                - Foreground: originality, design and storytelling instincts, ideas taken from concept to
                  shipped work, the human effect of what was made.
                - Rhythm: deliberately varied sentence lengths; a short punchy clause among longer ones.
                TXT,
            'Analytical' => <<<'TXT'
                - Register: precise and evidence-led; every claim traceable to something in the CV.
                - Verb palette: Analysed, Modelled, Quantified, Evaluated, Diagnosed, Benchmarked, Investigated.
                - Foreground: method before outcome - what was measured, how, and what it showed. Where a
                  stated metric exists, name the method that produced it.
                - Rhythm: cause-and-effect phrasing ("... which reduced ...", "... enabling ...").
                TXT,
            'Results Driven' => <<<'TXT'
                - Register: brisk and outcome-first. Trim qualifiers and hedging.
                - Verb palette: Increased, Reduced, Accelerated, Delivered, Achieved, Converted, Recovered.
                - Foreground: open each bullet with what changed, then how it was done. Reuse every stated
                  metric verbatim and place it early in the sentence.
                - Rhythm: short, declarative, no subordinate clauses before the outcome.
                TXT,
            'Strategic' => <<<'TXT'
                - Register: senior and forward-looking; frames work against business objectives.
                - Verb palette: Defined, Aligned, Prioritised, Steered, Scaled, Championed, Positioned.
                - Foreground: direction set, trade-offs made, roadmaps and priorities, why the work mattered
                  to the organisation rather than only what was built.
                - Rhythm: longer horizon framing; connect an action to the outcome it positioned.
                TXT,
            'Technical' => <<<'TXT'
                - Register: exact engineering prose. Prefer the specific term to the general one.
                - Verb palette: Engineered, Implemented, Architected, Refactored, Automated, Instrumented, Optimised.
                - Foreground: systems, architecture, protocols, data flows and tooling - but only those the
                  CV already names. Be concrete about the mechanism, not the sentiment.
                - Rhythm: dense and economical; no motivational padding.
                TXT,
            'Collaborative' => <<<'TXT'
                - Register: warm and team-centred without losing precision.
                - Verb palette: Partnered, Facilitated, Mentored, Co-ordinated, Supported, Aligned, Enabled.
                - Foreground: who the work was done with and for - cross-functional partners, stakeholders,
                  mentees, handovers - naming only teams and roles the CV already mentions.
                - Rhythm: pair each action with the people or team it involved.
                TXT,
            'Entrepreneurial' => <<<'TXT'
                - Register: ownership-first and resourceful; conveys initiative taken unprompted.
                - Verb palette: Founded, Launched, Bootstrapped, Pioneered, Grew, Identified, Seized.
                - Foreground: gaps spotted, things started from zero, constraints worked around, breadth of
                  responsibility carried without a large team behind it.
                - Rhythm: momentum - from problem noticed, to action taken, to what it produced.
                TXT,
        ];

        return $guides[$style] ?? <<<TXT
                - Register: write in a consistently {$style} voice throughout.
                - Choose verbs and emphasis that a reader would recognise as {$style}, and keep that
                  register identical across the summary, descriptions and bullets.
                TXT;
    }

   
    private function resumeSchemaJson(): string
    {
        return <<<'SCHEMA'
            {
            "data": {
            "candidateName": [
            {
            "firstName": "",
            "familyName": ""
            }
            ],
            "headline": "",
            "website": null,
            "preferredWorkLocation": null,
            "willingToRelocate": null,
            "objective": null,
            "association": null,
            "hobby": null,
            "patent": null,
            "publication": null,
            "referee": null,
            "dateOfBirth": null,
            "headshot": null,
            "nationality": null,
            "email": [""],
            "phoneNumber": [
            {
            "rawText": "",
            "countryCode": "",
            "nationalNumber": "",
            "formattedNumber": "",
            "internationalCountryCode": ""
            }
            ],
            "location": {
            "city": "",
            "state": "",
            "poBox": null,
            "street": null,
            "country": "",
            "latitude": null,
            "formatted": "",
            "longitude": null,
            "rawInput": "",
            "stateCode": "",
            "postalCode": null,
            "countryCode": "",
            "streetNumber": null,
            "apartmentNumber": null
            },
            "availability": null,
            "summary": {
                "paragraph": "",
                "years_experience": null,
                "confidence": "stated"
            },
            "expectedSalary": null,
            "education": [
            {
            "educationAccreditation": "",
            "educationOrganization": "",
            "educationDates": {
              "end": {
                "day": null,
                "date": "",
                "year": null,
                "month": null,
                "isCurrent": false
              },
              "start": {
                "day": null,
                "date": "",
                "year": null,
                "month": null,
                "isCurrent": false
              },
              "durationInMonths": null
            },
            "educationMajor": [],
            "educationLevel": {
              "id": null,
              "label": "",
              "value": ""
            }
            }
            ],
            "workExperience": [
            {
            "workExperienceJobTitle": "",
            "workExperienceOrganization": "",
            "workExperienceDates": {
              "end": {
                "day": null,
                "date": "",
                "year": null,
                "month": null,
                "isCurrent": true
              },
              "start": {
                "day": null,
                "date": "",
                "year": null,
                "month": null,
                "isCurrent": false
              },
              "durationInMonths": null
            },
            "workExperienceDescription": "",
            "highlights": {
            "minItems": 3,
            "maxItems": 7,
            "items":  [{
                "bullet": "",
                "impact": "",
                "keywords": "",
                "confidence": ""
              },
              ],
            },
            "workExperienceType": {
              "id": null,
              "label": "",
              "value": ""
            }
            }
            ],
            "totalYearsExperience": null,
            "project": null,
            "achievement": [],
            "rightToWork": null,
            "languages": [
            {
            "name": "",
            "level": null
            }
            ],
            "skill": [
            {
            "name": "",
            "type": "Specialized Skill"
            }
            ]
            }
            }
            SCHEMA;
    }


    private function extractResumeFacts(string $apiKey, string $model, string $rawText): array
    {
        $schema = $this->resumeSchemaJson();

        $systemPrompt = <<<PROMPT
            ROLE
            - You transcribe raw CV text into ONE valid JSON object matching the SCHEMA below.
            - You are a transcriber, not a writer. This pass captures facts; prose is written later.

            CORE RULES
            1) Output JSON only - no extra text.
            2) Preserve every stated fact exactly: names, dates, employers, job titles, metrics,
               qualifications and contact details.
            3) Never invent, expand, summarise or improve anything. If the CV does not state it,
               the field is null (or an empty array).
            4) Do NOT rewrite the candidate's wording anywhere in this pass.
            5) UK date formats (e.g., Mar 2023 - Jul 2025).

            FIELDS THIS PASS ONLY COPIES
            - "summary".paragraph: the CV's existing profile/summary verbatim, or "" if it has none.
              "years_experience": only if explicitly stated, else null. "confidence": "stated".
            - "workExperienceDescription": any prose paragraph already attached to that role,
              verbatim, or "" if the role has none.
            - "highlights".items: one entry per bullet or duty ALREADY present for that role, the
              original wording in "bullet" and "confidence":"stated". Do not split, merge, reword or
              add bullets. A role that lists no duties gets an empty items array.

            ### REQUIRED JSON FORMAT:
            {$schema}

            Rules:
            - Respond ONLY with JSON - no extra commentary.
            - Leave fields as `null` if the value is unknown or not found.
            PROMPT;

        $decoded = $this->callOpenAiJson(
            $apiKey,
            $model,
            $systemPrompt,
            "Raw Text : {$rawText}",
            0.0,     // transcription must not drift
            4000,
            'CV extraction pass'
        );

        $facts = $decoded['data'] ?? null;

        if (!is_array($facts)) {
            throw new \RuntimeException('CV extraction pass: response contained no "data" object');
        }

        return $facts;
    }

    private function writeResumeProse(
        string $apiKey,
        string $model,
        array $facts,
        string $style,
        string $jobDescription
    ): array {
        $evidence = $this->proseEvidence($facts);

        if ($evidence['workExperience'] === [] && $evidence['existingSummary'] === '' && $evidence['headline'] === '') {
            // Nothing worth a second call.
            return [];
        }

        $styleGuide = $this->languageStyleGuide($style);

        $jobDescriptionBlock = $jobDescription === '' ? '' : <<<JD

            TARGET ROLE
            - The candidate is applying for the role described between the markers below.
            - Mirror its vocabulary wherever the facts genuinely support it, and lead each
              section with the experience most relevant to it.
            - Never claim a skill, tool or responsibility the facts do not evidence.
            --- JOB DESCRIPTION START ---
            {$jobDescription}
            --- JOB DESCRIPTION END ---
            JD;

        $systemPrompt = <<<PROMPT
            ROLE
            - You are a CV copywriter. The candidate's facts have already been extracted and are FINAL.
            - You rewrite prose in one specific voice and return a small JSON patch. Nothing else.

            LANGUAGE STYLE - {$style}
            {$styleGuide}
            - This voice governs the summary paragraph, every role description and every bullet.
            - Two CVs built from the same facts in different styles MUST read noticeably differently.
              If your output would read the same in any other style, it is wrong.
            - The voice changes wording, emphasis and rhythm only. Never invent employers, teams,
              tools, numbers, dates or outcomes in order to serve it.
            {$jobDescriptionBlock}

            SUMMARY
            - ONE cohesive paragraph of 80-130 words.
            - Open clauses with strong verbs from the verb palette above.
            - Cover, where the facts support it: technical/domain scope, scale, collaboration, quality.
            - Reuse stated metrics verbatim (e.g. "improved performance by 30%"). No fabricated metrics.

            PER ROLE
            - "workExperienceDescription": 100-150 words of flowing prose about that role.
            - "highlights": 3-7 bullets. Fewer than 3 is INVALID.
            - Each bullet is ONE concise ATS-friendly sentence opening with a strong verb.
            - Where a role states few duties, DECOMPOSE what is there into distinct facets:
              (a) what was built/delivered, (b) integrations/security, (c) performance/scale,
              (d) collaboration/delivery, (e) quality/testing, (f) architecture/tooling.
              One facet per bullet - never combine facets into a single bullet.
            - "confidence": "stated" when the bullet rests on wording present in the facts,
              "inferred" for anything you generalised. When in doubt use "inferred".
            - "impact": the outcome where one is stated, otherwise "".
            - "keywords": comma-separated ATS keywords for that bullet.

            OUTPUT - return exactly this shape and nothing else:
            {
              "summary": { "paragraph": "", "years_experience": null, "confidence": "stated" },
              "workExperience": [
                {
                  "index": 0,
                  "workExperienceDescription": "",
                  "highlights": [
                    { "bullet": "", "impact": "", "keywords": "", "confidence": "" }
                  ]
                }
              ]
            }
            - Include one workExperience entry for EVERY index present in the facts, reusing the
              SAME index values. Do not reorder, add or drop roles.
            - Return no other keys. Do not echo names, dates, contact details, education or skills.
            PROMPT;

        return $this->callOpenAiJson(
            $apiKey,
            $model,
            $systemPrompt,
            json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            0.5,    
            3000,
            'CV style pass'
        );
    }

    private function proseEvidence(array $facts): array
    {
        $roles = [];

        foreach (array_values($facts['workExperience'] ?? []) as $index => $role) {
            $bullets = [];

            foreach (($role['highlights']['items'] ?? []) as $item) {
                $bullet = trim((string) ($item['bullet'] ?? ''));

                if ($bullet !== '') {
                    $bullets[] = $bullet;
                }
            }

            $roles[] = [
                'index' => $index,
                'jobTitle' => (string) ($role['workExperienceJobTitle'] ?? ''),
                'organisation' => (string) ($role['workExperienceOrganization'] ?? ''),
                'dates' => $role['workExperienceDates'] ?? null,
                'statedDescription' => (string) ($role['workExperienceDescription'] ?? ''),
                'statedBullets' => $bullets,
            ];
        }

        $skills = [];

        foreach (($facts['skill'] ?? []) as $skill) {
            $name = trim((string) ($skill['name'] ?? ''));

            if ($name !== '') {
                $skills[] = $name;
            }
        }

        return [
            'headline' => (string) ($facts['headline'] ?? ''),
            'totalYearsExperience' => $facts['totalYearsExperience'] ?? null,
            'existingSummary' => (string) ($facts['summary']['paragraph'] ?? ''),
            'skills' => $skills,
            'workExperience' => $roles,
        ];
    }

    private function mergeResumeProse(array $facts, array $prose): array
    {
        $paragraph = trim((string) ($prose['summary']['paragraph'] ?? ''));

        if ($paragraph !== '') {
            $facts['summary'] = [
                'paragraph' => $paragraph,
                'years_experience' => $prose['summary']['years_experience']
                    ?? ($facts['summary']['years_experience'] ?? null),
                'confidence' => ($prose['summary']['confidence'] ?? '') === 'stated' ? 'stated' : 'inferred',
            ];
        }

        $facts['workExperience'] = array_values($facts['workExperience'] ?? []);

        foreach (($prose['workExperience'] ?? []) as $entry) {
            $index = $entry['index'] ?? null;

            if (!is_numeric($index) || !isset($facts['workExperience'][(int) $index])) {
                continue;   // pass 2 does not get to invent roles
            }

            $index = (int) $index;
            $description = trim((string) ($entry['workExperienceDescription'] ?? ''));

            if ($description !== '') {
                $facts['workExperience'][$index]['workExperienceDescription'] = $description;
            }

            $bullets = [];

            foreach (($entry['highlights'] ?? []) as $item) {
                $bullet = trim((string) ($item['bullet'] ?? ''));

                if ($bullet === '') {
                    continue;
                }

                $bullets[] = [
                    'bullet' => $bullet,
                    'impact' => $this->flattenToString($item['impact'] ?? ''),
                    'keywords' => $this->flattenToString($item['keywords'] ?? ''),
                    'confidence' => ($item['confidence'] ?? '') === 'stated' ? 'stated' : 'inferred',
                ];
            }

            if ($bullets !== []) {
                $facts['workExperience'][$index]['highlights'] = [
                    'minItems' => 3,
                    'maxItems' => 7,
                    'items' => $bullets,
                ];
            }
        }

        return $facts;
    }

    private function flattenToString(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(static fn ($item) => trim((string) $item), $value));
        }

        return trim((string) $value);
    }


    private function callOpenAiJson(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userContent,
        float $temperature,
        int $maxTokens,
        string $label
    ): array {
        $response = Http::timeout(180)->withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'temperature' => $temperature,
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => $maxTokens,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException("{$label}: OpenAI request failed with status {$response->status()}");
        }

        $choice = $response->json()['choices'][0] ?? null;
        $content = $choice['message']['content'] ?? null;
        $finishReason = $choice['finish_reason'] ?? null;

        if ($finishReason === 'length') {
            throw new \RuntimeException("{$label}: response hit the {$maxTokens} token cap and was truncated");
        }

        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException("{$label}: OpenAI returned no content");
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            Log::error("{$label}: could not decode model JSON", [
                'json_error' => json_last_error_msg(),
                'raw' => mb_substr($content, 0, 2000),
            ]);

            throw new \RuntimeException("{$label}: model did not return valid JSON");
        }

        Log::info("{$label}: complete", [
            'model' => $model,
            'finish_reason' => $finishReason,
            'completion_tokens' => $response->json()['usage']['completion_tokens'] ?? null,
        ]);

        return $decoded;
    }

    public function parseResumeOCRPyScript(Request $request)
    {     
              $request->validate([
                  'file' => 'required|mimes:pdf,png,jpg,jpeg,docx'
              ]);
            
        // return ("new - OCR script");
        $model = $request->model ?? 'gpt-4o-mini';
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $path = storage_path('app/temp/' . $originalName);
        $file->move(storage_path('app/temp'), $originalName);

        $cleanOutput = '';

        if ($extension == 'docx') {
           
            try {
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
                $text = '';
                
                foreach ($phpWord->getSections() as $section) {
                    $elements = $section->getElements();
                    
                    foreach ($elements as $element) {
                        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                            // Handle TextRun elements
                            foreach ($element->getElements() as $textElement) {
                                if ($textElement instanceof \PhpOffice\PhpWord\Element\Text) {
                                    $text .= $textElement->getText();
                                }
                            }
                            $text .= "\n"; // Add newline after each TextRun
                        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                            // Handle direct Text elements
                            $text .= $element->getText() . "\n";
                        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                            // Handle tables
                            foreach ($element->getRows() as $row) {
                                foreach ($row->getCells() as $cell) {
                                    $text .= $this->extractTextFromElement($cell) . "\t";
                                }
                                $text .= "\n";
                            }
                        }
                    }
                }
                
                $cleanOutput = mb_convert_encoding(trim($text), 'UTF-8', 'UTF-8');
                
            } catch (\Exception $e) {
               
                return response()->json([
                    'error' => 'Failed to process DOCX file',
                    'details' => $e->getMessage()
                ], 500);
            }
        } else {
                try {
                       
                        $parser = new Parser();
                        $pdf = $parser->parseFile($path);
                        $cleanOutput = trim($pdf->getText());

                      
                        $ocrEnabled = filter_var(env('OCR_ENABLED', true), FILTER_VALIDATE_BOOLEAN);

                        if ((empty($cleanOutput) || strlen($cleanOutput) < 100)) {

                            if (!$ocrEnabled) {
                                Log::warning('OCR disabled and PDF extraction insufficient', [
                                    'text_length' => strlen($cleanOutput)
                                ]);

                                throw new \Exception('PDF text extraction failed and OCR is disabled');
                            }

                            $pythonPath = env('PYTHON_PATH', '/usr/bin/python3');
                            $scriptPath = public_path('scripts/parse_resume.py');

                            if (!file_exists($scriptPath)) {
                                Log::error('OCR script not found', [
                                    'script_path' => $scriptPath
                                ]);
                                throw new \Exception('OCR script not found');
                            }

                            if (!is_executable($pythonPath)) {
                                Log::error('Python executable not found or not executable', [
                                    'python_path' => $pythonPath
                                ]);
                                throw new \Exception('Invalid Python path');
                            }

                            $command = sprintf(
                                '%s %s %s',
                                escapeshellarg($pythonPath),
                                escapeshellarg($scriptPath),
                                escapeshellarg($path)
                            );


                            $output = shell_exec($command . ' 2>&1');

                            if ($output === null) {
                                Log::error('OCR execution failed (null output)');
                                throw new \Exception('Failed to execute OCR script');
                            }

                            $cleanOutput = mb_convert_encoding(trim($output), 'UTF-8', 'UTF-8');

                            if (empty($cleanOutput)) {
                                Log::error('OCR returned empty output');
                                throw new \Exception('OCR processing returned no text');
                            }
                        }

                       
                    } catch (\Throwable $e) {

                        return response()->json([
                            'success' => false,
                            'error' => 'Failed to process resume',
                            'details' => env('APP_DEBUG') ? $e->getMessage() : null
                        ], 400);
                    }
        }

        try {
            $apiKey = config('services.openai.api_key');
            $style_adjective = trim((string) $request->input('languageStyle')) ?: 'Professional';
            $job_description = trim((string) $request->input('additionalInfo'));

            $facts = $this->extractResumeFacts($apiKey, $model, $cleanOutput);

            try {
                $prose = $this->writeResumeProse($apiKey, $model, $facts, $style_adjective, $job_description);
                $facts = $this->mergeResumeProse($facts, $prose);
            } catch (\Throwable $e) {
               
                Log::warning('CV language-style pass failed; returning unstyled CV', [
                    'style' => $style_adjective,
                    'error' => $e->getMessage(),
                ]);
            }

            $facts['languageStyle'] = $style_adjective;

            return response()->json(['data' => $facts]);

        } catch (\Throwable $e) {

            return response()->json([
                'error' => 'Failed to get evaluation from AI model',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
     

    public function parseResumeGPT(Request $request)
    {
            $request->validate([
                'file' => 'required|file|mimes:pdf|max:204800', 
            ]);
            
            $model = $request->input('model', 'gpt-4o-mini'); 
            $file = $request->file('file');
            
            // Create training data directories
            $trainingBase = public_path('training_data');
            $pdfDir = $trainingBase . '/resume-pdfs';
            $jsonDir = $trainingBase . '/resume-jsons';
            $ocrDir = $trainingBase . '/ocr-outputs';
            
            // Ensure directories exist
            if (!file_exists($pdfDir)) mkdir($pdfDir, 0755, true);
            if (!file_exists($jsonDir)) mkdir($jsonDir, 0755, true);
            if (!file_exists($ocrDir)) mkdir($ocrDir, 0755, true);
            
            try {
                // Generate unique filename with timestamp
                $timestamp = time();
                $uniqueId = uniqid();
                $baseFilename = "resume_{$timestamp}_{$uniqueId}";
                
                // Store original PDF
                $pdfFilename = $baseFilename . '.pdf';
                $pdfPath = $pdfDir . '/' . $pdfFilename;
                $file->move($pdfDir, $pdfFilename);
                
                $text = '';
                $usedOcr = false;
                $ocrPath = null;
                
                // 1. First try direct text extraction
                $parser = new Parser();
                $pdf = $parser->parseFile($pdfPath);
                $text = trim($pdf->getText());
                
                // 2. Fallback to OCR if text extraction fails
                if (strlen($text) < 100) {
                    $usedOcr = true;
                    
                    // Use Python script for OCR
                    $pythonPath = '/var/www/html/backend_cv_finder/env/bin/python';
                    $scriptPath = public_path('scripts/parse_resume.py');
                    $command = sprintf(
                        '%s %s "%s"',
                        escapeshellarg($pythonPath),
                        escapeshellarg($scriptPath),
                        str_replace('"', '\"', $pdfPath)
                    );
                    
                    $output = shell_exec($command . ' 2>&1');
                    $cleanOutput = mb_convert_encoding(trim($output), 'UTF-8', 'UTF-8');
                    
                    // Save OCR output to file
                    $ocrFilename = $baseFilename . '_ocr.txt';
                    $ocrPath = $ocrDir . '/' . $ocrFilename;
                    file_put_contents($ocrPath, $cleanOutput);
                    
                    $text = $cleanOutput;
                }
                
                // Process with GPT regardless of OCR or direct extraction
                $apiKey = config('services.openai.api_key');
                
                $prompt = <<<PROMPT
                You are a resume parsing AI. Analyze the candidate's CV and extract structured information in the following JSON format. Fill as many fields as possible based on the text.
                
                ### RAW TEXT:
                "{$text}"
                
                ### REQUIRED JSON FORMAT:
                {
                "data": {
                    "candidateName": [
                    {
                        "firstName": "",
                        "familyName": ""
                    }
                    ],
                    "headline": "",
                    "website": null,
                    "preferredWorkLocation": null,
                    "willingToRelocate": null,
                    "objective": null,
                    "association": null,
                    "hobby": null,
                    "patent": null,
                    "publication": null,
                    "referee": null,
                    "dateOfBirth": null,
                    "headshot": null,
                    "nationality": null,
                    "email": [""],
                    "phoneNumber": [
                    {
                        "rawText": "",
                        "countryCode": "",
                        "nationalNumber": "",
                        "formattedNumber": "",
                        "internationalCountryCode": ""
                    }
                    ],
                    "location": {
                    "city": "",
                    "state": "",
                    "poBox": null,
                    "street": null,
                    "country": "",
                    "latitude": null,
                    "formatted": "",
                    "longitude": null,
                    "rawInput": "",
                    "stateCode": "",
                    "postalCode": null,
                    "countryCode": "",
                    "streetNumber": null,
                    "apartmentNumber": null
                    },
                    "availability": null,
                    "summary": "",
                    "expectedSalary": null,
                    "education": [
                    {
                        "educationAccreditation": "",
                        "educationOrganization": "",
                        "educationDates": {
                        "end": {
                            "day": null,
                            "date": "",
                            "year": null,
                            "month": null,
                            "isCurrent": false
                        },
                        "start": {
                            "day": null,
                            "date": "",
                            "year": null,
                            "month": null,
                            "isCurrent": false
                        },
                        "durationInMonths": null
                        },
                        "educationMajor": [],
                        "educationLevel": {
                        "id": null,
                        "label": "",
                        "value": ""
                        }
                    }
                    ],
                    "workExperience": [
                    {
                        "workExperienceJobTitle": "",
                        "workExperienceOrganization": "",
                        "workExperienceDates": {
                        "end": {
                            "day": null,
                            "date": "",
                            "year": null,
                            "month": null,
                            "isCurrent": true
                        },
                        "start": {
                            "day": null,
                            "date": "",
                            "year": null,
                            "month": null,
                            "isCurrent": false
                        },
                        "durationInMonths": null
                        },
                        "workExperienceDescription": "",
                        "workExperienceType": {
                        "id": null,
                        "label": "",
                        "value": ""
                        }
                    }
                    ],
                    "totalYearsExperience": null,
                    "project": null,
                    "achievement": null,
                    "rightToWork": null,
                    "languages": [
                    {
                        "name": "",
                        "level": null
                    }
                    ],
                    "skill": [
                    {
                        "name": "",
                        "type": "Specialized Skill"
                    }
                    ]
                }
                }
                
                Rules:
                - Respond ONLY with JSON — no extra commentary.
                - Leave fields as `null` if the value is unknown or not found.
                - Ensure `rawText` contains the same original content provided.
                PROMPT;
                
                $gptResponse = Http::timeout(60)->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a professional JSON resume parser. Respond ONLY with valid full JSON.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.0,
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens' => 2048,
                ]);

                $evaluation = $gptResponse->json()['choices'][0]['message']['content'] ?? null;
                $parsedData = json_decode($evaluation, true);

                if ($evaluation) {
                    $aiText = $evaluation;
                    
                    // Extract only the JSON part
                    $jsonStart = strpos($aiText, '{');
                    if ($jsonStart !== false) {
                        $jsonString = substr($aiText, $jsonStart);
                        $decoded = json_decode($jsonString, true);
                        
                        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['data'])) {
                            $parsedData = $decoded;
                        }
                    }
                }

                // Save JSON to training data
                $jsonFilename = $baseFilename . '.json';
                $jsonPath = $jsonDir . '/' . $jsonFilename;
                file_put_contents($jsonPath, json_encode($parsedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                // Save to database
                $pdfParsed = new PdfParsed();
                $pdfParsed->ip_address = $request->ip();
                $pdfParsed->user_agent = $request->userAgent();
                if (isset($parsedData['data']['candidateName'][0]['firstName'], $parsedData['data']['candidateName'][0]['familyName'])) {
                    $pdfParsed->full_name = $parsedData['data']['candidateName'][0]['firstName'] . ' ' . $parsedData['data']['candidateName'][0]['familyName'];
                }
                $pdfParsed->file_name = $file->getClientOriginalName();
                $pdfParsed->parsed_data = $parsedData;
                $pdfParsed->training_file_id = $baseFilename; // Store the unique ID for reference
                $pdfParsed->used_ocr = $usedOcr;
                $pdfParsed->save();

                return response()->json([
                    'success' => true,
                    'data' => $parsedData,
                    'training_data' => [
                        'file_id' => $baseFilename,
                        'pdf_path' => asset(str_replace(public_path(), '', $pdfPath)),
                        'json_path' => asset(str_replace(public_path(), '', $jsonPath)),
                        'ocr_used' => $usedOcr,
                        'ocr_path' => $usedOcr ? asset(str_replace(public_path(), '', $ocrPath)) : null,
                        'text_length' => strlen($text)
                    ]
                ]);

            } catch (\Exception $e) {
                Log::error('Resume parsing failed: ' . $e->getMessage(), [
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process resume: ' . $e->getMessage()
                ], 500);
            }
    }



    public function downloadDoc($id, Request $request)
    {
        try {
            // 1. Get resume data (similar to your PDF download method)
            $resume = Resume::findOrFail($id);

            if ((int) $resume->user_id !== (int) Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resume not found'
                ], 404);
            }

            // 2. Get and decode resume data
            $resumeData = $resume->cv_resumejson;
            
            if (empty($resumeData)) {
                Log::error('Resume data is empty', ['resume_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Resume data is empty'
                ], 400);
            }
            
            if (is_string($resumeData)) {
                $decoded = json_decode($resumeData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('Failed to decode resume JSON', [
                        'resume_id' => $id,
                        'error' => json_last_error_msg()
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid resume data format: ' . json_last_error_msg()
                    ], 400);
                }
                $resumeData = $decoded;
            }

            // 3. Create simple HTML content (avoid complex HTML structure)
            $html = $this->generateResumeHTML($resumeData);

            // 4. Create DOCX with proper settings
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            
            // Set document properties
            $phpWord->getDocInfo()->setCreator($resumeData['candidateName'][0]['firstName'] . ' ' . $resumeData['candidateName'][0]['familyName']);
            $phpWord->getDocInfo()->setTitle('Resume - ' . $resumeData['candidateName'][0]['firstName'] . ' ' . $resumeData['candidateName'][0]['familyName']);
            
            $section = $phpWord->addSection();
            
            // Add HTML content - use simple HTML without DOCTYPE, html, head, body tags
            \PhpOffice\PhpWord\Shared\Html::addHtml($section, $html, false, false);

            $first = $resumeData['candidateName'][0]['firstName'] ?? '';
            $last  = $resumeData['candidateName'][0]['familyName'] ?? '';

            $safeName = trim(preg_replace('/[^\p{L}\p{N}\-_ ]+/u', '', $first . ' ' . $last));
            $safeName = $safeName !== '' ? preg_replace('/\s+/', '_', $safeName) : 'resume';
            $fileName = $safeName . '.docx';

            $tempPath = tempnam(sys_get_temp_dir(), 'cvdoc_');

            // 6. Save the document
            $phpWord->save($tempPath, 'Word2007', false);

            // 7. Send the file
            return response()->download($tempPath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate DOCX: ' . $e->getMessage()
            ], 500);
        }
    }


    // Helper function to generate simple HTML for the resume
    private function generateResumeHTML($resumeData)
    {
        $html = '';
        
        // Header with name and photo area
        $html .= '<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px;">';
        
        // Name and headline section
        $html .= '<div>';
        $html .= '<div style="color: #000; font-size: 24px; font-weight: bold; margin-bottom: 5px; font-family: Inter, Times New Roman, serif;">' . 
                htmlspecialchars(($resumeData['candidateName'][0]['firstName'] ?? '') . ' ' . ($resumeData['candidateName'][0]['familyName'] ?? '')) . 
                '</div>';
        if (!empty($resumeData['headline'])) {
            $html .= '<div style="font-style: italic; margin: 0 0 15px 0; color: #555; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['headline']) . 
                    '</div>';
        }
        $html .= '</div>';
        
        // Profile photo area (space reserved but no image in Word)
        if (!empty($resumeData['profilePic'])) {
            $html .= '<div style="width: 80px; height: 80px; border-radius: 50%; border: 2px solid #ddd; background-color: #f5f5f5; display: flex; align-items: center; justify-content: center; color: #999; font-size: 12px;">';
            $html .= 'Photo';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '<br></br>';
        // Personal Details Table
        if (empty($resumeData['personalDisabled'])) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['personalTitle'] ?? 'Personal details') . 
                    '</div>';
            
            $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 25px; font-family: Inter, Times New Roman, serif;">';
            
            // Name
            $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Name</td>';
            $html .= '<td style="padding: 3px 0; vertical-align: top;">' . 
                    htmlspecialchars(($resumeData['candidateName'][0]['firstName'] ?? '') . ' ' . ($resumeData['candidateName'][0]['familyName'] ?? '')) . 
                    '</td></tr>';
            
            // Email
            if (!empty($resumeData['email'][0])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Email address</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['email'][0]) . '</td></tr>';
            }
            
            // Phone
            if (!empty($resumeData['phoneNumber'][0]['formattedNumber'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Phone number</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['phoneNumber'][0]['formattedNumber']) . '</td></tr>';
            }
            
            // Address
            if (!empty($resumeData['location']['formatted'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Address</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['location']['formatted']) . '</td></tr>';
            }
            
            // City
            if (!empty($resumeData['location']['city'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">City</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['location']['city']) . '</td></tr>';
            }
            
            // Postcode
            if (!empty($resumeData['location']['postCode'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Postcode</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['location']['postCode']) . '</td></tr>';
            }
            
            // GitHub
            if (!empty($resumeData['socialLinks']['github'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">GitHub</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['socialLinks']['github']) . '</td></tr>';
            }
            
            // LinkedIn
            if (!empty($resumeData['socialLinks']['linkedin'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">LinkedIn</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['socialLinks']['linkedin']) . '</td></tr>';
            }
            
            // Website
            if (!empty($resumeData['socialLinks']['website'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Website</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['socialLinks']['website']) . '</td></tr>';
            }
            
            $html .= '</table>';
            $html .= '</div>';
        }
            $html .= '<br></br>';
        // Profile Summary
        if (!empty($resumeData['summary']['paragraph'])) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">Profile</div>';
            $html .= '<div style="margin: 0 0 25px 0; text-align: justify; line-height: 1.5; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['summary']['paragraph']) . 
                    '</div>';
            $html .= '</div>';
        }

        $html .= '<br></br>';
        
        // Work Experience
        if (!empty($resumeData['workExperience']) && !($resumeData['employmentDisabled'] ?? false)) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['employmentTitle'] ?? 'Employment') . 
                    '</div>';
            
            foreach ($resumeData['workExperience'] as $job) {
                $html .= '<div style="margin-bottom: 20px; page-break-inside: avoid;">';
                
                // Date
                $html .= '<div style="font-weight: 500; margin: 0 0 4px 0; font-size: 14px; color: #000; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars(($job['workExperienceDates']['start']['date'] ?? '') . ' - ' . ($job['workExperienceDates']['end']['date'] ?? 'Present')) . 
                        '</div>';
                
                // Job Title
                $html .= '<div style="font-weight: 600; margin: 0 0 4px 0; color: #000; font-size: 14px; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars($job['workExperienceJobTitle'] ?? '') . 
                        '</div>';
                
                // Company
                $html .= '<div style="font-weight: 600; margin: 0 0 8px 0; color: #000; font-size: 14px; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars($job['workExperienceOrganization'] ?? '') . 
                        '</div>';
                
                // Job Description
                if (!empty($job['workExperienceDescription'])) {
                    $html .= '<div style="margin: 0 0 8px 0; font-family: Inter, Times New Roman, serif;">' . 
                            htmlspecialchars($job['workExperienceDescription']) . 
                            '</div>';
                }
                
                // Key Achievements
                if (!empty($job['highlights']['items'])) {
                    $html .= '<div style="font-weight: 600; margin: 8px 0 5px; color: #000; font-family: Inter, Times New Roman, serif;">Key Achievements</div>';
                    $html .= '<ul style="padding-left: 18px; margin: 0 0 20px 0; font-family: Inter, Times New Roman, serif;">';
                    foreach ($job['highlights']['items'] as $point) {
                        $html .= '<li style="margin-bottom: 4px;">' . htmlspecialchars($point['bullet']) . '</li>';
                    }
                    $html .= '</ul>';
                }
                
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        
        $html .= '<br></br>';

        // Education
        if (!empty($resumeData['education']) && !($resumeData['educationDisabled'] ?? false)) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['educationTitle'] ?? 'Education') . 
                    '</div>';
            
            foreach ($resumeData['education'] as $edu) {
                $html .= '<div style="margin-bottom: 20px; page-break-inside: avoid;">';
                
                // Date
                $html .= '<div style="font-weight: 500; margin: 0 0 4px 0; font-size: 14px; color: #000; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars(($edu['educationDates']['start']['date'] ?? '') . ' - ' . ($edu['educationDates']['end']['date'] ?? '')) . 
                        '</div>';
                
                // Degree
                $html .= '<div style="font-weight: 600; margin: 0 0 4px 0; color: #000; font-size: 14px; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars($edu['educationLevel']['label'] ?? '') . 
                        '</div>';
                
                // Institution
                $html .= '<div style="font-weight: 600; margin: 0 0 8px 0; color: #000; font-size: 14px; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars($edu['educationOrganization'] ?? '') . 
                        '</div>';
                
                // Subjects/Major
                if (!empty($edu['educationMajor']) && is_array($edu['educationMajor']) && count($edu['educationMajor']) > 0) {
                    $html .= '<div style="margin: 5px 0; font-family: Inter, Times New Roman, serif;"><strong>Subjects:</strong> ' . 
                            htmlspecialchars(implode(', ', $edu['educationMajor'])) . 
                            '</div>';
                }
                
                // Grade
                if (!empty($edu['achievedGrade'])) {
                    $html .= '<div style="margin: 5px 0; font-family: Inter, Times New Roman, serif;"><strong>Grade:</strong> ' . 
                            htmlspecialchars($edu['achievedGrade']) . 
                            '</div>';
                }
                
                // Education Description
                if (!empty($edu['educationDescription'])) {
                    $html .= '<div style="margin: 8px 0 0 0; font-family: Inter, Times New Roman, serif;">' . 
                            htmlspecialchars($edu['educationDescription']) . 
                            '</div>';
                }
                
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        
        $html .= '<br></br>';
        
        // Skills
        if (!empty($resumeData['skill']) && !($resumeData['skillsDisabled'] ?? false)) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['skillsTitle'] ?? 'Skills') . 
                    '</div>';
            
            $skills = array_filter($resumeData['skill'], function($skill) {
                if (is_array($skill)) {
                    return !isset($skill['selected']) || $skill['selected'];
                }
                return true;
            });
            
            $skillNames = array_map(function($skill) {
                return is_array($skill) ? ($skill['name'] ?? $skill) : $skill;
            }, $skills);
            
            $html .= '<ul style="padding-left: 18px; margin: 0 0 20px 0; font-family: Inter, Times New Roman, serif;">';
            foreach ($skillNames as $skill) {
                $html .= '<li style="margin-bottom: 4px;">' . htmlspecialchars($skill) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }
        $html .= '<br></br>';

        // Languages
        if (!empty($resumeData['languages']) && !($resumeData['languagesDisabled'] ?? false)) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['languagesTitle'] ?? 'Languages') . 
                    '</div>';
            
            $html .= '<ul style="padding-left: 18px; margin: 0 0 20px 0; font-family: Inter, Times New Roman, serif;">';
            foreach ($resumeData['languages'] as $lang) {
                $level = $lang['level'] ?? 'Fluent';
                $html .= '<li style="margin-bottom: 4px;">' . htmlspecialchars($lang['name']) . ' (' . htmlspecialchars($level) . ')</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }
        $html .= '<br></br>';

        // Hobbies
        if (!empty($resumeData['hobbies']) && !($resumeData['hobbiesDisabled'] ?? false)) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['hobbiesTitle'] ?? 'Hobbies') . 
                    '</div>';
            
            $html .= '<ul style="padding-left: 18px; margin: 0 0 20px 0; font-family: Inter, Times New Roman, serif;">';
            foreach ($resumeData['hobbies'] as $hobby) {
                $html .= '<li style="margin-bottom: 4px;">' . htmlspecialchars($hobby) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }
        $html .= '<br></br>';

        // Custom Sections
        if (!empty($resumeData['customSections'])) {
            foreach ($resumeData['customSections'] as $section) {
                if (!empty($section['title']) && !empty($section['content'])) {
                    $html .= '<div style="margin-bottom: 30px;">';
                    $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                            htmlspecialchars($section['title']) . 
                            '</div>';
                    
                    // Clean HTML content while preserving basic formatting
                    $content = strip_tags($section['content'], '<p><br><strong><em><u><ul><ol><li>');
                    $content = str_replace('<p>', '<p style="margin: 8px 0; font-family: Inter, Times New Roman, serif;">', $content);
                    $content = str_replace('<ul>', '<ul style="padding-left: 18px; margin: 8px 0; font-family: Inter, Times New Roman, serif;">', $content);
                    $content = str_replace('<li>', '<li style="margin-bottom: 4px;">', $content);
                    
                    $html .= '<div style="font-family: Inter, Times New Roman, serif;">' . $content . '</div>';
                    $html .= '</div>';
                }
            }
        }

        return $html;
    }

    public function download($id, Request $request)
    {
        try {
            // 1. Get resume record from DB
            $resume = Resume::findOrFail($id);

            if ((int) $resume->user_id !== (int) Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resume not found'
                ], 404);
            }
            
            // 2. Validate template parameter
            $template = $request->input('template');
            if (!$template) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template parameter is required'
                ], 400);
            }
            $template = strtolower($template);
            
            // Validate template exists
            $validTemplates = ['classic', 'default', 'luxe', 'modern'];
            if (!in_array($template, $validTemplates)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid template. Valid templates: ' . implode(', ', $validTemplates)
                ], 400);
            }

            // 3. Get and decode resume data
            $resumeData = $resume->cv_resumejson;
            
            // Check if cv_resumejson is null or empty
            if (empty($resumeData)) {
                Log::error('Resume data is empty', ['resume_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Resume data is empty'
                ], 400);
            }
            
            // If cv_resumejson is a JSON string, decode it
            if (is_string($resumeData)) {
                $decoded = json_decode($resumeData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('Failed to decode resume JSON', [
                        'resume_id' => $id,
                        'error' => json_last_error_msg()
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid resume data format: ' . json_last_error_msg()
                    ], 400);
                }
                $resumeData = $decoded;
            }
            
            // Validate that resumeData is an array
            if (!is_array($resumeData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resume data is not in the correct format'
                ], 400);
            }

            // 4. Pass it to Blade template
            $pdf = Pdf::loadView(
                'resume::pdfs.' . $template . '-template',
                compact('resumeData')
            )->setPaper('a4', 'portrait');

            // 5. Return as inline PDF with proper headers
            $filename = ($resume->title) . '.pdf';
            
            return response($pdf->output(), 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $filename . '"')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
            
        } catch (\Exception $e) {
            
            return response()->json([
                'success' => false,
                'message' => 'Error generating PDF: ' . $e->getMessage()
            ], 500);
        }
    }

}
