<?php

namespace Plugin\Komoju42\Command;

use Plugin\Komoju42\Service\LogService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PurgeLogsCommand extends Command
{
    private $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
        parent::__construct('komoju:logs:purge');
    }

    protected function configure(): void
    {
        $this->setDescription('Delete expired KOMOJU operational logs; preserve order history.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->logService->purgeExpiredLogs(new \DateTimeImmutable());
        $output->writeln(sprintf('Deleted %d expired operational logs.', $count));

        return 0;
    }
}
