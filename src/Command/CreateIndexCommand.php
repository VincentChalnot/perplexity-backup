<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BackupPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:conversations:create-index', description: 'Create markdown index file from conversations')]
class CreateIndexCommand extends Command
{
    public function __construct(
        private readonly BackupPaths $paths,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conversationsJson = json_decode(file_get_contents($this->paths->conversationsList()), true, 512, JSON_THROW_ON_ERROR);

        $indexContent = "# Conversations Index\n\n";

        $byDate = [];
        foreach ($conversationsJson as $conversation) {
            $date = new \DateTime($conversation['last_query_datetime'])->format('Y-m-d');
            $byDate[$date][] = $conversation;
        }

        krsort($byDate);

        foreach ($byDate as $date => $conversations) {
            $indexContent .= "## {$date}\n\n";
            foreach ($conversations as $conversation) {
                $title = $conversation['title'];
                $uuid = $conversation['uuid'];
                $collectionTitle = $conversation['collection']['title'] ?? '';
                $messageCount = $this->countMessages($uuid);

                $displayTitle = mb_strimwidth($title, 0, 250, '...');
                $displayTitle = str_replace(['[', ']', '(', ')', "\n", '`', '<', '>'], ['\[', '\]', '\(', '\)', ' ', '', '\<', '\>'], $displayTitle);

                $indexContent .= "- [{$displayTitle}](conversations/{$uuid}/conversation.md)";
                if ($collectionTitle) {
                    $indexContent .= "  _{$collectionTitle}_";
                }
                $indexContent .= "  ({$messageCount} messages)\n";
            }
            $indexContent .= "\n";
        }

        file_put_contents($this->paths->conversationsIndex(), $indexContent);

        $output->writeln('Index created successfully!');

        return Command::SUCCESS;
    }

    private function countMessages(string $uuid): int
    {
        $filePath = $this->paths->threadJson($uuid);
        if (!file_exists($filePath)) {
            return 0;
        }

        $conversation = json_decode(file_get_contents($filePath), true, 512, JSON_THROW_ON_ERROR);

        $count = 0;
        foreach ($conversation['entries'] as $entry) {
            if (isset($entry['query_str']) && $entry['query_str'] !== '') {
                $count++;
            }
            if (isset($entry['blocks'])) {
                foreach ($entry['blocks'] as $block) {
                    if (($block['intended_usage'] ?? '') === 'ask_text') {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }
}
