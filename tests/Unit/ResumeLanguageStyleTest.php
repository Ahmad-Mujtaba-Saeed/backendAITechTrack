<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Modules\Resume\Http\Controllers\ResumeController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Covers the two-pass CV enrichment: extraction at temperature 0, then a separate
 * voice pass that may only patch prose fields.
 *
 * Wires the Http and Log facades onto a bare container rather than booting the
 * framework - a Feature test cannot run here because the app's providers touch the
 * database on boot and this PHP CLI has no pdo_sqlite.
 */
class ResumeLanguageStyleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('http', new Factory());
        $container->instance('log', new NullLogger());

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private function invokePrivate(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(ResumeController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(new ResumeController(), $args);
    }

    private function fakeCompletion(array $payload, string $finishReason = 'stop'): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode($payload)],
                    'finish_reason' => $finishReason,
                ]],
                'usage' => ['completion_tokens' => 100],
            ]),
        ]);
    }

    private function facts(): array
    {
        return [
            'headline' => 'Backend Engineer',
            'candidateName' => [['firstName' => 'Ada', 'familyName' => 'Lovelace']],
            'email' => ['ada@example.com'],
            'summary' => ['paragraph' => 'Existing summary.', 'years_experience' => 6, 'confidence' => 'stated'],
            'skill' => [['name' => 'Go', 'type' => 'Specialized Skill'], ['name' => '', 'type' => '']],
            'workExperience' => [
                [
                    'workExperienceJobTitle' => 'Engineer',
                    'workExperienceOrganization' => 'Acme',
                    'workExperienceDescription' => '',
                    'highlights' => ['items' => [['bullet' => 'Built the API.', 'confidence' => 'stated']]],
                ],
                [
                    'workExperienceJobTitle' => 'Junior Engineer',
                    'workExperienceOrganization' => 'Globex',
                    'workExperienceDescription' => 'Did things.',
                    'highlights' => ['items' => []],
                ],
            ],
        ];
    }

    public function test_extraction_pass_runs_at_temperature_zero_and_forbids_rewriting(): void
    {
        $this->fakeCompletion(['data' => ['headline' => 'Backend Engineer']]);

        $facts = $this->invokePrivate('extractResumeFacts', ['key', 'gpt-4o-mini', 'Ada Lovelace, Engineer at Acme']);

        $this->assertSame('Backend Engineer', $facts['headline']);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $system = $body['messages'][0]['content'];

            return $body['temperature'] === 0.0
                && str_contains($system, 'You are a transcriber, not a writer')
                && str_contains($system, 'Do NOT rewrite')
                // The style must not leak into the pass that captures facts.
                && !str_contains($system, 'LANGUAGE STYLE');
        });
    }

    public function test_style_pass_carries_the_selected_voice_and_the_job_description(): void
    {
        $this->fakeCompletion(['summary' => ['paragraph' => 'x'], 'workExperience' => []]);

        $this->invokePrivate('writeResumeProse', ['key', 'gpt-4o-mini', $this->facts(), 'Creative', 'Senior Platform Engineer, Go.']);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $system = $body['messages'][0]['content'];
            $user = $body['messages'][1]['content'];

            return $body['temperature'] === 0.5
                && str_contains($system, 'LANGUAGE STYLE - Creative')
                // The Creative brief specifically, not merely the word "Creative".
                && str_contains($system, 'Shaped, Crafted, Reimagined')
                && str_contains($system, 'Senior Platform Engineer, Go.')
                // Pass 2 works from extracted facts, and only the prose-relevant slice.
                && str_contains($user, 'statedBullets')
                && !str_contains($user, 'ada@example.com');
        });
    }

    public function test_every_dropdown_style_has_its_own_brief(): void
    {
        $styles = ['Professional', 'Creative', 'Analytical', 'Results Driven',
                   'Strategic', 'Technical', 'Collaborative', 'Entrepreneurial'];

        $briefs = [];

        foreach ($styles as $style) {
            $briefs[$style] = $this->invokePrivate('languageStyleGuide', [$style]);
        }

        $this->assertCount(8, array_unique($briefs), 'every dropdown option needs its own voice brief');
        $this->assertStringContainsString('Increased, Reduced, Accelerated', $briefs['Results Driven']);
        $this->assertStringContainsString('Engineered, Implemented, Architected', $briefs['Technical']);
    }

    public function test_merge_applies_prose_without_disturbing_extracted_facts(): void
    {
        $merged = $this->invokePrivate('mergeResumeProse', [$this->facts(), [
            'summary' => ['paragraph' => 'Crafted summary.', 'confidence' => 'inferred'],
            'workExperience' => [
                [
                    'index' => 0,
                    'workExperienceDescription' => 'A rewritten description.',
                    'highlights' => [
                        ['bullet' => 'Shaped the API.', 'impact' => '', 'keywords' => ['Go', 'REST'], 'confidence' => 'stated'],
                        ['bullet' => '', 'confidence' => 'stated'],
                    ],
                ],
            ],
        ]]);

        // Prose updated.
        $this->assertSame('Crafted summary.', $merged['summary']['paragraph']);
        $this->assertSame('A rewritten description.', $merged['workExperience'][0]['workExperienceDescription']);
        $this->assertSame('Shaped the API.', $merged['workExperience'][0]['highlights']['items'][0]['bullet']);

        // Array-valued keywords flattened for the frontend.
        $this->assertSame('Go, REST', $merged['workExperience'][0]['highlights']['items'][0]['keywords']);

        // Empty bullets dropped.
        $this->assertCount(1, $merged['workExperience'][0]['highlights']['items']);

        // Facts untouched.
        $this->assertSame('Ada', $merged['candidateName'][0]['firstName']);
        $this->assertSame('Acme', $merged['workExperience'][0]['workExperienceOrganization']);
        $this->assertSame(6, $merged['summary']['years_experience']);

        // A role pass 2 said nothing about keeps what pass 1 extracted.
        $this->assertSame('Did things.', $merged['workExperience'][1]['workExperienceDescription']);
    }

    public function test_merge_ignores_roles_the_style_pass_invented(): void
    {
        $merged = $this->invokePrivate('mergeResumeProse', [$this->facts(), [
            'workExperience' => [
                ['index' => 99, 'workExperienceDescription' => 'A role that does not exist.'],
                ['index' => null, 'workExperienceDescription' => 'No index at all.'],
            ],
        ]]);

        $this->assertCount(2, $merged['workExperience']);
        $this->assertSame('', $merged['workExperience'][0]['workExperienceDescription']);
    }

    public function test_empty_prose_never_blanks_a_populated_field(): void
    {
        $merged = $this->invokePrivate('mergeResumeProse', [$this->facts(), [
            'summary' => ['paragraph' => '   '],
            'workExperience' => [['index' => 1, 'workExperienceDescription' => '', 'highlights' => []]],
        ]]);

        $this->assertSame('Existing summary.', $merged['summary']['paragraph']);
        $this->assertSame('Did things.', $merged['workExperience'][1]['workExperienceDescription']);
    }

    public function test_truncated_response_is_reported_as_truncation_not_bad_json(): void
    {
        $this->fakeCompletion(['data' => ['headline' => 'x']], 'length');

        $this->expectExceptionMessageMatches('/truncated/');

        $this->invokePrivate('extractResumeFacts', ['key', 'gpt-4o-mini', 'some cv text']);
    }

    public function test_style_pass_is_skipped_when_there_is_nothing_to_write_about(): void
    {
        Http::fake();

        $result = $this->invokePrivate('writeResumeProse', ['key', 'gpt-4o-mini', [], 'Creative', '']);

        $this->assertSame([], $result);
        Http::assertNothingSent();
    }
}
