<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Exceptions\SluggableException;
use RoundlyConsulting\Sluggable\Schema\SlugIndexes;
use RoundlyConsulting\Sluggable\Support\ModelArgument;

final class SlugIndexesCommand extends Command
{
    protected $signature = 'sluggable:indexes
        {model : Model class or morph alias}
        {--column=* : Only these slug columns}
        {--dry-run : Print the DDL, execute nothing}';

    protected $description = 'Create the engine-native unique indexes a model\'s slug definitions need';

    public function handle(): int
    {
        $model = ModelArgument::resolve($this->argument('model'));

        if ($model === null) {
            $this->error('The model must be an Eloquent model implementing '.Sluggable::class.'.');

            return self::FAILURE;
        }

        $columns = $this->option('column');
        $columns = is_array($columns) && $columns !== [] ? array_values(array_filter($columns, is_string(...))) : null;

        try {
            $report = SlugIndexes::forModel($model, $columns, (bool) $this->option('dry-run'));
        } catch (SluggableException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($report->statements as $statement) {
            $this->line($statement.';');
        }

        foreach ($report->skipped as $name) {
            $this->line("Exists: {$name}");
        }

        $this->info(sprintf(
            '%s %d index(es), %d already present.',
            $this->option('dry-run') ? 'Would create' : 'Created',
            count($report->created),
            count($report->skipped),
        ));

        return self::SUCCESS;
    }
}
