<?php

declare(strict_types=1);

namespace App\Command;

use App\Helper\ExportCommandHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:conversations:export-all', description: 'For each conversation in var/data/conversations.json, export the full json conversation')]
class ExportConversationsCommand extends Command
{
    public function __construct(
        private readonly ExportCommandHelper $exportCommandHelper,
        private readonly string $conversationsPath,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = "{$this->conversationsPath}/conversations.json";
        if (!file_exists($path)) {
            $output->writeln("Conversations list not found at {$path}. Please run 'app:conversations:export-list' first.");
            return Command::FAILURE;
        }

        $conversationList = json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($conversationList as $item) {
            $uuid = $item['uuid'];
            $title = strtok(mb_substr($item['title'] ?? 'Untitled', 0, 100), "\n");
            $output->writeln("Exporting conversation {$uuid}: {$title}");
            $this->exportCommandHelper->exportConversation($uuid, $output);
            usleep(random_int(500, 5000));
        }

        return Command::SUCCESS;
    }
}
