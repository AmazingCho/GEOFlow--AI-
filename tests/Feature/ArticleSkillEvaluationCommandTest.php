<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ArticleSkillEvaluationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake();
    }

    public function test_command_runs_offline_and_writes_a_private_no_go_report(): void
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('geoflow:article-skills:evaluate', ['--json' => true], $output);
        $report = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('offline_fixture', $report['evaluation_mode']);
        $this->assertSame('no_go', $report['release_decision']);
        $this->assertFalse($report['model']['is_real_model']);
        $this->assertArrayNotHasKey('name', $report['model']);
        $this->assertArrayHasKey('name_sha256', $report['model']);
        $this->assertStringStartsWith('article-skill-evaluations/', $report['report_path']);
        Storage::disk('local')->assertExists($report['report_path']);
        $this->assertSame(0600, fileperms(Storage::disk('local')->path($report['report_path'])) & 0777);
        Http::assertNothingSent();
    }

    public function test_default_command_has_no_paid_model_or_network_option(): void
    {
        $command = Artisan::all()['geoflow:article-skills:evaluate'];
        $definition = $command->getDefinition();

        $this->assertFalse($definition->hasOption('model'));
        $this->assertFalse($definition->hasOption('generate'));
        $this->assertTrue($definition->hasOption('input'));
        $this->assertTrue($definition->hasOption('json'));
    }

    public function test_external_real_model_input_requires_pinned_reproducibility_metadata(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'geoflow-skill-eval-');
        file_put_contents($path, json_encode([
            'model' => ['name' => 'unversioned-model', 'provider' => 'test-provider', 'is_real_model' => true],
            'outputs' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            $exitCode = Artisan::call('geoflow:article-skills:evaluate', ['--input' => $path]);
            $this->assertNotSame(0, $exitCode);
            $this->assertStringContainsString('model_version', Artisan::output());
            Http::assertNothingSent();
        } finally {
            @unlink($path);
        }
    }

    public function test_external_input_rejects_string_false_and_untrusted_metadata_values(): void
    {
        foreach ([
            [
                'model' => [
                    'name' => 'pinned-model',
                    'provider' => 'test-provider',
                    'model_version' => '2026-07-20',
                    'temperature' => 0,
                    'max_output_tokens' => 1800,
                    'code_commit' => str_repeat('a', 40),
                    'is_real_model' => 'false',
                ],
                'expected' => 'is_real_model=true',
            ],
            [
                'model' => [
                    'name' => 'sk-PRIVATE_PROVIDER_SECRET',
                    'provider' => 'test-provider',
                    'model_version' => '2026-07-20',
                    'temperature' => 0,
                    'max_output_tokens' => 1800,
                    'code_commit' => str_repeat('a', 40),
                    'is_real_model' => true,
                ],
                'expected' => 'invalid name',
            ],
        ] as $scenario) {
            $path = tempnam(sys_get_temp_dir(), 'geoflow-skill-eval-');
            file_put_contents($path, json_encode([
                'model' => $scenario['model'],
                'outputs' => [],
            ], JSON_THROW_ON_ERROR));

            try {
                $exitCode = Artisan::call('geoflow:article-skills:evaluate', ['--input' => $path]);
                $this->assertNotSame(0, $exitCode);
                $this->assertStringContainsString($scenario['expected'], Artisan::output());
            } finally {
                @unlink($path);
            }
        }

        Http::assertNothingSent();
    }
}
