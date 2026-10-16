<?php

declare(strict_types=1);

namespace Typesense\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Typesense\Bundle\ORM\TypesenseManager;

/**
 * Drops and recreates the collections of the configured mappings (under the
 * `collection_prefix`), and nothing else. The server is never asked what
 * else it holds: another application, another environment of this one or a
 * collection created by hand may live next to the mapped ones, and there is
 * deliberately no option to sweep them away.
 */
#[AsCommand(name: 'typesense:create', aliases: [], description: 'Drop and recreate the Typesense collections of the configured mappings (and only those)')]
class CreateCommand extends Command
{
    private TypesenseManager $typesenseManager;

    public function __construct(TypesenseManager $typesenseManager)
    {
        parent::__construct();
        $this->typesenseManager = $typesenseManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->typesenseManager->getConnections() as $connectionName => $connection) {
            $output->writeln(sprintf('<info>Connection Typesense </info> "<comment>%s</comment>": ' . ($connection->getHealth() ? 'OK' : 'BAD STATE'), $connectionName));
        }

        foreach ($this->typesenseManager->getCollections() as $name => $collection) {
            $output->writeln("\t" . sprintf('<info>Deleting</info> <comment>%s</comment> (<comment>%s</comment> in Typesense)', $name, $collection->metadata()->getCollectionName()));
            $collection->delete();

            $output->writeln("\t" . sprintf('<info>Creating</info> <comment>%s</comment>', $name));
            $collection->create();

            $this->typesenseManager->getFinder($name)->cache()->clear();
        }

        return 0;
    }
}
