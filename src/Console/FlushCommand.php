<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'search:flush',
    description: 'Flush search indexes'
)]
class FlushCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Flush search indexes')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Flush all indexes')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation')
            ->addArgument('index', InputArgument::OPTIONAL, 'Index name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = (bool) $input->getOption('all');
        $force = (bool) $input->getOption('force');
        $index = $input->getArgument('index');

        $manager = $this->getService(IndexManager::class);

        if ($all) {
            if (!$force && !$this->confirm('Flush all indexes?')) {
                return self::SUCCESS;
            }
            $indexes = $manager->getAllIndexes();
            if ($indexes === []) {
                $this->info('No indexes found.');
                return self::SUCCESS;
            }
            $client = $manager->getClient();
            foreach ($indexes as $idx) {
                $uid = $idx->getUid();
                // Index already has prefix from getAllIndexes, use client directly
                $task = $client->index($uid)->deleteAllDocuments();
                $manager->waitForTask($task['taskUid']);
                $this->line("Flushed: {$uid}");
            }
            $this->success('All indexes flushed.');
            return self::SUCCESS;
        }

        if (!is_string($index) || $index === '') {
            $this->error('Specify index name or use --all');
            return self::FAILURE;
        }

        if (!$force && !$this->confirm("Flush index {$index}?")) {
            return self::SUCCESS;
        }

        try {
            $manager->flush($index);
            $this->success("Index '{$index}' flushed.");
        } catch (\Throwable $e) {
            $this->error("Failed to flush index: {$e->getMessage()}");
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
