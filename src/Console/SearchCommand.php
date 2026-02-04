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
    name: 'search:search',
    description: 'Run a search query against an index'
)]
class SearchCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Run a search query against an index')
            ->addArgument('index', InputOption::VALUE_REQUIRED, 'Index name')
            ->addArgument('query', InputOption::VALUE_OPTIONAL, 'Search query', '')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Filter expression')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit', '20')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Raw JSON output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $index = (string) $input->getArgument('index');
        $query = (string) $input->getArgument('query');
        $filter = $input->getOption('filter');
        $limit = (int) $input->getOption('limit');
        $raw = (bool) $input->getOption('raw');

        $client = $this->getService(MeilisearchClient::class);
        $prefixedIndex = $client->prefixedIndexName($index);
        $params = ['limit' => $limit];
        if (is_string($filter) && $filter !== '') {
            $params['filter'] = [$filter];
        }

        try {
            $result = $client->index($prefixedIndex)->search($query, $params);
        } catch (\Throwable $e) {
            $this->error("Search failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        if ($raw) {
            $this->line(json_encode($result->toArray()));
            return self::SUCCESS;
        }

        $hits = $result->getHits();
        $total = $result->getEstimatedTotalHits();
        $time = $result->getProcessingTimeMs();

        $this->info("Results: " . count($hits) . " of ~{$total} (in {$time}ms)");
        $this->line('');

        if ($hits === []) {
            $this->line('No results found.');
        } else {
            $this->line(json_encode($hits, JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }
}
