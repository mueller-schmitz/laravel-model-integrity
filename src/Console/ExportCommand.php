<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Console\Concerns\ResolvesModelClasses;
use MuellerSchmitz\ModelIntegrity\Export\AuditorExport;
use MuellerSchmitz\ModelIntegrity\Export\ExportOptions;
use Throwable;

class ExportCommand extends Command
{
    use ResolvesModelClasses;

    protected $signature = 'model-integrity:export
        {directory : Empty directory to write the export to}
        {--from= : Only versions recorded from this date on (UTC)}
        {--to= : Only versions recorded up to this date (UTC, inclusive)}
        {--model= : Only versions of this model class or morph alias}
        {--files : Also hash the content of every stored file}
        {--no-reveal : Leave out the snapshots with decrypted personal data}
        {--fetch-dtd : Download the GDPdU DTD from its publisher if no path is configured}
        {--without-dtd : Write the export without the GDPdU DTD}';

    protected $description = 'Write an auditor export (GDPdU tables, proofs, inclusion proofs, verification report)';

    public function handle(AuditorExport $export): int
    {
        try {
            $from = $this->date('from', endOfDay: false);
            $to = $this->date('to', endOfDay: true);

            if ($from !== null && $to !== null && $from->greaterThan($to)) {
                throw new InvalidArgumentException('--from must not be after --to.');
            }

            $options = new ExportOptions(
                from: $from,
                to: $to,
                type: $this->type(),
                reveal: $this->option('no-reveal') !== true,
                files: $this->option('files') === true,
                fetchDtd: $this->option('fetch-dtd') === true,
                withoutDtd: $this->option('without-dtd') === true,
            );
            $directory = $this->argument('directory');

            if (! is_string($directory) || $directory === '') {
                throw new InvalidArgumentException('Name the directory to write the export to.');
            }

            $supplier = config('model-integrity.export.supplier.name');

            if (! is_string($supplier) || $supplier === '') {
                $this->warn('No supplier configured (model-integrity.export.supplier); the index.xml names none.');
            }

            $result = $export->export($directory, $options);
        } catch (Throwable $e) {
            // Nothing or nothing complete was written: never exit like a written export.
            $this->error($e->getMessage());

            return self::INVALID;
        }

        if ($options->withoutDtd) {
            $this->warn('The export has no GDPdU DTD; add gdpdu-01-03-2019.dtd next to index.xml before handing it over.');
        }

        foreach ($result->problems as $problem) {
            $this->warn($problem);
        }

        if (! $result->passes()) {
            $this->error("Written to [{$directory}], but the verification found violations; see report.html.");

            return self::FAILURE;
        }

        $this->info("Written to [{$directory}]; the verification found no violations.");

        return self::SUCCESS;
    }

    /**
     * A date covers the whole day in UTC; a date with time is taken as given
     * and converted to UTC.
     */
    private function date(string $option, bool $endOfDay): ?CarbonImmutable
    {
        $value = $this->stringOption($option);

        if ($value === null) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException("--{$option} must be a date, [{$value}] given.");
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        }

        return $date;
    }

    private function type(): ?string
    {
        $model = $this->stringOption('model');

        if ($model === null) {
            return null;
        }

        $class = $this->resolveModelClass($model);

        if ($class === null) {
            throw new InvalidArgumentException("[{$model}] is no model class or morph alias using the HasIntegrity trait.");
        }

        return (new $class)->getMorphClass();
    }
}
