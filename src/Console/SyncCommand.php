<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'search:sync',
    description: 'Sync index settings from models'
)]
class SyncCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Sync index settings from models')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model class')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show settings without applying');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modelClass = $input->getOption('model');
        $dry = (bool) $input->getOption('dry-run');
        $manager = $this->getService(IndexManager::class);

        if (!is_string($modelClass) || $modelClass === '') {
            $this->error('Provide --model to sync settings');
            return self::FAILURE;
        }

        if (!class_exists($modelClass)) {
            $this->error('Model class not found: ' . $modelClass);
            return self::FAILURE;
        }

        $model = new $modelClass([], $this->getContext());
        if ($dry) {
            $this->info('Dry run only; no changes applied.');
            $settings = $manager->buildSettingsForModel($model);
            if ($settings === []) {
                $this->info('No settings to apply for this model.');
                return self::SUCCESS;
            }
            $this->line(json_encode($settings, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $manager->syncSettingsForModel($model);
        $this->success('Index settings synced.');
        return self::SUCCESS;
    }
}
