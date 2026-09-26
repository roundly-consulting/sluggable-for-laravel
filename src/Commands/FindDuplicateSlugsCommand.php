<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Sluggable\Actions\FindDuplicateSlugsAction;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\DataTransferObjects\DuplicateScan;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugDuplicate;
use RoundlyConsulting\Sluggable\Exceptions\SluggableException;
use RoundlyConsulting\Sluggable\Support\ModelArgument;

final class FindDuplicateSlugsCommand extends Command
{
    protected $signature = 'sluggable:duplicates
        {model : Model class or morph alias}
        {--column= : Only this slug column}
        {--locale= : Only this locale}';

    protected $description = 'List slugs a unique index would reject (run before adding one to existing data)';

    public function handle(FindDuplicateSlugsAction $action): int
    {
        $model = ModelArgument::resolve($this->argument('model'));

        if ($model === null) {
            $this->error('The model must be an Eloquent model implementing '.Sluggable::class.'.');

            return self::FAILURE;
        }

        $column = $this->option('column');
        $locale = $this->option('locale');

        try {
            $findings = $action->execute(new DuplicateScan(
                $model,
                is_string($column) && $column !== '' ? $column : null,
                is_string($locale) && $locale !== '' ? $locale : null,
            ));
        } catch (SluggableException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($findings === []) {
            $this->info('No duplicate or over-length slugs found.');

            return self::SUCCESS;
        }

        $this->table(['Problem', 'Column', 'Locale', 'Slug', 'Scope', 'Keys'], array_map(
            static fn (SlugDuplicate $finding): array => [
                $finding->overLength ? 'too long' : 'duplicate',
                $finding->column,
                $finding->locale ?? '',
                $finding->slug,
                (string) json_encode($finding->scope),
                implode(', ', array_map(strval(...), $finding->keys)),
            ],
            $findings,
        ));

        $this->warn(count($findings).' problem(s) found.');

        return self::SUCCESS;
    }
}
