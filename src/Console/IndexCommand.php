<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Meilisearch\Contracts\SearchEngineInterface;
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'search:index',
    description: 'Index searchable models'
)]
class IndexCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Index searchable models')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model class to index')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Comma-separated IDs to index')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Flush index before indexing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modelClass = $input->getOption('model');
        $ids = $input->getOption('id');
        $fresh = (bool) $input->getOption('fresh');

        if (!is_string($modelClass) || $modelClass === '') {
            $this->error('You must provide --model');
            return self::FAILURE;
        }

        if (!class_exists($modelClass)) {
            $this->error('Model class not found: ' . $modelClass);
            return self::FAILURE;
        }

        $engine = $this->getService(SearchEngineInterface::class);
        $indexManager = $this->getService(IndexManager::class);

        $indexName = (new $modelClass([], $this->getContext()))->searchableAs();
        if ($fresh) {
            $indexManager->getOrCreateIndex($indexName)->deleteAllDocuments();
        }

        if (is_string($ids) && $ids !== '') {
            $idList = array_filter(array_map('trim', explode(',', $ids)));
            foreach ($idList as $id) {
                $model = $modelClass::find($this->getContext(), $id);
                if ($model !== null) {
                    $engine->index($model);
                }
            }
            $this->success('Indexed selected IDs.');
            return self::SUCCESS;
        }

        // Index all
        $models = $modelClass::all($this->getContext());
        foreach ($models as $model) {
            $engine->index($model);
        }

        $this->success('Indexing complete.');
        return self::SUCCESS;
    }
}
