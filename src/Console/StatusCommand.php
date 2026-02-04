<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Meilisearch\Client\MeilisearchClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'search:status',
    description: 'Show Meilisearch index status'
)]
class StatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Show Meilisearch index status')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addArgument('index', InputOption::VALUE_OPTIONAL, 'Index name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->getService(MeilisearchClient::class);
        $index = $input->getArgument('index');
        $json = (bool) $input->getOption('json');

        if (is_string($index) && $index !== '') {
            $prefixedIndex = $client->prefixedIndexName($index);
            try {
                $indexObj = $client->index($prefixedIndex);
                $stats = $indexObj->stats();
            } catch (\Throwable $e) {
                $this->error("Index not found: {$prefixedIndex}");
                return self::FAILURE;
            }
            if ($json) {
                $this->line(json_encode($stats));
            } else {
                $this->info("Index: {$prefixedIndex}");
                $this->line('');
                $rows = [];
                foreach ($stats as $key => $value) {
                    $rows[] = [$key, is_array($value) ? json_encode($value) : (string) $value];
                }
                $this->table(['Stat', 'Value'], $rows);
            }
            return self::SUCCESS;
        }

        $result = $client->getIndexes();
        $indexes = $result->getResults();
        if ($json) {
            $this->line(json_encode($indexes));
        } else {
            if ($indexes === []) {
                $this->info('No indexes found.');
                return self::SUCCESS;
            }
            $rows = [];
            foreach ($indexes as $idx) {
                $rows[] = [$idx->getUid(), $idx->getPrimaryKey() ?? ''];
            }
            $this->table(['Index', 'Primary Key'], $rows);
        }

        return self::SUCCESS;
    }
}
