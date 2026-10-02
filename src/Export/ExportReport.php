<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Export;

use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;

/**
 * The verification report of an export, as JSON and as a self-contained
 * HTML page.
 */
final class ExportReport
{
    public function write(string $directory, ExportResult $result, ExportOptions $options): void
    {
        $checks = $result->checks;
        $report = [
            'generated_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'package' => 'mueller-schmitz/laravel-model-integrity '.$this->packageVersion(),
            'filter' => [
                'from' => $options->from?->format('Y-m-d\TH:i:s\Z'),
                'to' => $options->to?->format('Y-m-d\TH:i:s\Z'),
                'type' => $options->type,
                'personal_data_revealed' => $options->reveal,
            ],
            'head_sequence' => $result->headSequence,
            'export_problems' => $result->problems,
            'checks' => array_filter([
                'all' => $this->result($checks['all']),
                'files' => $checks['files'] === null ? null : $this->result($checks['files']),
            ]),
        ];

        file_put_contents($directory.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        file_put_contents($directory.'/report.html', $this->html($report, $checks, $result->problems));
    }

    /**
     * @return array{passes: bool, checked: int, errors: list<array<string, mixed>>}
     */
    private function result(IntegrityResult $result): array
    {
        return [
            'passes' => $result->passes(),
            'checked' => $result->checkedVersions(),
            'errors' => array_values($result->errors()->map(fn (IntegrityError $error): array => [
                'type' => $error->type->value,
                'versionable_type' => $error->versionableType,
                'versionable_id' => $error->versionableId,
                'version' => $error->version,
                'sequence' => $error->sequence,
                'message' => $error->message,
            ])->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array{all: IntegrityResult, files: IntegrityResult|null}  $checks
     * @param  list<string>  $problems
     */
    private function html(array $report, array $checks, array $problems): string
    {
        $e = fn (mixed $value): string => htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sections = '';

        foreach (array_filter(['Chains, anchors and models' => $checks['all'], 'Stored file contents' => $checks['files']]) as $title => $result) {
            $status = $result->passes() ? 'passed' : 'failed';
            $rows = $result->errors()->map(fn (IntegrityError $error): string => '<tr><td>'.$e($error->type->value).'</td><td>'.$e(trim(($error->versionableType ?? '').' '.($error->versionableId ?? ''))).'</td><td>'.$e($error->version).'</td><td>'.$e($error->sequence).'</td><td>'.$e($error->message).'</td></tr>')->implode("\n");

            $sections .= '<h2>'.$e($title).'</h2><p class="'.$status.'">'.$e(ucfirst($status)).' – '.$e($result->checkedVersions()).' checked, '.$e($result->errors()->count()).' findings.</p>'
                .($rows === '' ? '' : '<table><thead><tr><th>Type</th><th>Subject</th><th>Version</th><th>Sequence</th><th>Message</th></tr></thead><tbody>'.$rows.'</tbody></table>');
        }

        if ($problems !== []) {
            $sections .= '<h2>Export problems</h2><p class="failed">Some data could not be exported as it should; it is exported as stored.</p><ul>'
                .implode('', array_map(fn (string $problem): string => '<li>'.$e($problem).'</li>', $problems)).'</ul>';
        }

        $filter = is_array($report['filter'] ?? null) ? $report['filter'] : [];

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Integrity report</title>'
            .'<style>body{font-family:system-ui,sans-serif;margin:2rem;color:#1a1a1a}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:.3rem .5rem;text-align:left;vertical-align:top}.passed{color:#176b2c}.failed{color:#a4161a}code{word-break:break-all}</style></head><body>'
            .'<h1>Integrity report</h1>'
            .'<p>Generated '.$e($report['generated_at'] ?? '').' with <code>'.$e($report['package'] ?? '').'</code>.</p>'
            .'<p>Covers the global chain up to sequence '.$e($report['head_sequence'] ?? '').'. Filter: from '.$e($filter['from'] ?? '–').', to '.$e($filter['to'] ?? '–').', type '.$e($filter['type'] ?? 'all').'.</p>'
            .$sections
            .'<p>Check the export independently with SPEC.md: recompute every hash from versions.jsonl, the Merkle inclusion proofs against the anchors, and the proof files with <code>ots verify</code> or <code>openssl ts -verify</code>. SHA256SUMS lists the checksums of all files.</p>'
            ."</body></html>\n";
    }

    private function packageVersion(): string
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('mueller-schmitz/laravel-model-integrity')
            ? (string) InstalledVersions::getPrettyVersion('mueller-schmitz/laravel-model-integrity')
            : 'unknown';
    }
}
