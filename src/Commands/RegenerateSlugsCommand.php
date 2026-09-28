<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Sluggable\Actions\QueueSlugRegenerationAction;
use RoundlyConsulting\Sluggable\Actions\RegenerateSlugsAction;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\DataTransferObjects\RegeneratedSlug;
use RoundlyConsulting\Sluggable\DataTransferObjects\RegenerateSlugsData;
use RoundlyConsulting\Sluggable\Enums\RegenerationMode;
use RoundlyConsulting\Sluggable\Exceptions\InvalidLocaleException;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;
use RoundlyConsulting\Sluggable\Support\ModelArgument;

final class RegenerateSlugsCommand extends Command
{
    protected $signature = 'sluggable:regenerate
        {model : Model class or morph alias}
        {--column=* : Only these slug columns}
        {--locale=* : Only these locales (locale-map slugs)}
        {--mode=missing : missing | stale | all}
        {--chunk=500 : Rows per chunk (1-10000)}
        {--dry-run : Show what would change, write nothing}
        {--history : Record retired slugs in the history table}
        {--without-events : Do not dispatch SlugChanged}
        {--queue : Dispatch one job per chunk}
        {--force : Bypass locks, and skip the confirmation for --mode=all}';

    protected $description = 'Backfill missing slugs or regenerate existing ones';

    public function handle(RegenerateSlugsAction $action, QueueSlugRegenerationAction $queue): int
    {
        $model = ModelArgument::resolve($this->argument('model'));

        if ($model === null) {
            $this->error('The model must be an Eloquent model implementing '.Sluggable::class.'.');

            return self::FAILURE;
        }

        $modeOption = $this->option('mode');
        $mode = is_string($modeOption) ? RegenerationMode::tryFrom($modeOption) : null;
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);

        if ($mode === null || $chunk === false) {
            $this->error('Use --mode=missing|stale|all and a --chunk between 1 and 10000.');

            return self::FAILURE;
        }

        try {
            $locales = array_map(IdentifierGuard::locale(...), $this->strings('locale'));
        } catch (InvalidLocaleException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($mode === RegenerationMode::All && ! $dryRun && ! $force
            && ! $this->confirm('--mode=all rewrites existing slugs (and therefore URLs). Continue?')) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $data = new RegenerateSlugsData(
            modelClass: $model,
            columns: $this->strings('column'),
            locales: $locales,
            mode: $mode,
            chunk: $chunk,
            dryRun: $dryRun,
            withHistory: (bool) $this->option('history'),
            withoutEvents: (bool) $this->option('without-events'),
            force: $force,
        );

        if ($this->option('queue') && ! $dryRun) {
            $this->info("Dispatched {$queue->execute($data)} job(s).");

            return self::SUCCESS;
        }

        $report = $action->execute($data);

        if ($dryRun && $report->samples !== []) {
            $this->table(['Key', 'Column', 'Locale', 'Old', 'New'], array_map(
                static fn (RegeneratedSlug $slug): array => [
                    (string) $slug->key,
                    $slug->change->column,
                    $slug->change->locale ?? '',
                    $slug->change->previous ?? '',
                    $slug->change->current,
                ],
                $report->samples,
            ));
        }

        $this->info(sprintf('%s %d of %d row(s).', $dryRun ? 'Would change' : 'Changed', $report->changed, $report->scanned));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function strings(string $option): array
    {
        $values = $this->option($option);

        return is_array($values) ? array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '')) : [];
    }
}
