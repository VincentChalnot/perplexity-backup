<?php

declare(strict_types=1);

namespace App\Command;

use App\Helper\ExportCommandHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:conversations:export-single', description: 'Export the full json of a single conversation and it\'s related media')]
class ExportConversationCommand extends Command
{
    public function __construct(
        private readonly ExportCommandHelper $exportCommandHelper,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, 'The UUID of the conversation to export');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = $input->getArgument('uuid');
        $output->writeln("Exporting conversation {$uuid}:");
        $this->exportCommandHelper->exportConversation($uuid, $output);

        return Command::SUCCESS;
    }
}
