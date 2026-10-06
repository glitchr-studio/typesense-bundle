<?php

declare(strict_types=1);

namespace Typesense\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Typesense\Bundle\ORM\TypesenseManager;
use Typesense\Exceptions\ObjectNotFound;

#[AsCommand(name: 'typesense:create', aliases: [], description: 'Create Typesenses indexes')]
class CreateCommand extends Command
{
    private TypesenseManager $typesenseManager;

    public function __construct(TypesenseManager $typesenseManager)
    {
        parent::__construct();
        $this->typesenseManager = $typesenseManager;
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'DESTRUCTIVE: first delete every collection found on each connection, mapped by this application or not');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = $input->getOption('all');

        foreach ($this->typesenseManager->getConnections() as $connectionName => $connection) {
            $output->writeln(sprintf('<info>Connection Typesense </info> "<comment>%s</comment>": ' . ($connection->getHealth() ? 'OK' : 'BAD STATE'), $connectionName));

            // Only on request: a server is not always this application's
            // alone — another application, another environment of this one
            // (see `collection_prefix`) or a collection created by hand may
            // live next to the mapped ones.
            if (!$all) {
                continue;
            }

            foreach ($connection->getCollections()->retrieve() as $collection) {
                $name = $collection['name'];
                try {
                    $output->writeln("\t" . sprintf('<info>Deleting</info> <comment>%s</comment> (<comment>%s</comment> in Typesense)', $name, $name));
                    $connection->getCollection($name)->delete();
                } catch (ObjectNotFound $exception) {
                    $output->writeln("\t" . sprintf('Collection <comment>%s</comment> <info>does not exists</info> ', $name));
                }
            }
        }

        foreach ($this->typesenseManager->getCollections() as $name => $collection) {
            if (!$all) {
                $output->writeln("\t" . sprintf('<info>Deleting</info> <comment>%s</comment> (<comment>%s</comment> in Typesense)', $name, $collection->metadata()->getCollectionName()));
                $collection->delete();
            }

            $output->writeln("\t" . sprintf('<info>Creating</info> <comment>%s</comment>', $name));
            $collection->create();

            $this->typesenseManager->getFinder($name)->cache()->clear();
        }

        return 0;
    }
}
