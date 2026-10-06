<?php

declare(strict_types=1);

namespace Typesense\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Typesense\Bundle\ORM\TypesenseManager;

#[AsCommand(name: 'typesense:list', aliases: [], description: 'List collections from typesense database')]
class ListCommand extends Command
{
    private TypesenseManager $typesenseManager;

    public function __construct(TypesenseManager $typesenseManager)
    {
        parent::__construct();
        $this->typesenseManager = $typesenseManager;
    }

    protected function configure(): void
    {
        $this->addOption('collection', null, InputOption::VALUE_OPTIONAL, 'Collection name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $execStart = microtime(true);
        $populated = 0;

        $io->newLine();
        $collectionName = $input->getOption('collection');

        // A mapping key is also accepted: it is listed under the name it has
        // on the server, behind the configured `collection_prefix`.
        $collectionNames = [$collectionName];
        if ($collectionName && array_key_exists($collectionName, $this->typesenseManager->getCollections())) {
            $collectionNames[] = $this->typesenseManager->getCollection($collectionName)->metadata()->getCollectionName();
        }

        foreach ($this->typesenseManager->getConnections() as $connectionName => $connection) {
            $output->writeln(sprintf('<info>Connection Typesense </info> "<comment>%s</comment>": ' . ($connection->getHealth() ? 'OK' : 'BAD STATE'), $connectionName));
            foreach ($connection->getCollections()->retrieve() as $collection) {
                if ($collectionName && !in_array($collection['name'], $collectionNames)) {
                    continue;
                }

                $fields = $collection['fields'] ?? [];
                $output->writeln('- Collection <info>[' . $collection['name'] . '] </info>; ' . count($fields) . ' field(s)');

                foreach ($fields as $field) {
                    $output->writeln("\t" . 'Field <info>[' . $field['name'] . ']</info> (' . $field['type'] . ')', OutputInterface::VERBOSITY_VERBOSE);
                }

                if (count($fields) > 0) {
                    $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
                }
            }
        }

        return 0;
    }

    private function printErrors(SymfonyStyle $io, array $response): bool
    {
        $isError = false;
        foreach ($response as $item) {
            if (!$item['success']) {
                $isError = true;
                $io->error($item['error']);
            }
        }

        return $isError;
    }
}
