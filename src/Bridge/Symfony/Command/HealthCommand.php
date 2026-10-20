<?php

declare(strict_types=1);

namespace Typesense\Bundle\Bridge\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Typesense\Bundle\ORM\TypesenseManager;

#[AsCommand(name: 'typesense:health', aliases: [], description: 'Typesense health check')]
class HealthCommand extends Command
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

        foreach ($this->typesenseManager->getConnections() as $connectionName => $connection) {
            $output->writeln(sprintf('<info>Connection Typesense </info> "<comment>%s</comment>": ' . ($connection->getHealth() ? 'OK' : 'BAD STATE'), $connectionName));
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
