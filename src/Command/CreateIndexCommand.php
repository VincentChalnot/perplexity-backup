<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:create-index', description: 'Create index file from conversations')]
class CreateIndexCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $kernelProjectDir,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conversationsPath = "{$this->kernelProjectDir}/var/data/conversations.json";
        $conversationsDir = "{$this->kernelProjectDir}/var/data/conversations";

        $conversationsJson = json_decode(file_get_contents($conversationsPath), true, 512, JSON_THROW_ON_ERROR);

        $indexContent = "# Conversations Index\n\n";
        $indexContent .= "| Date | Title | Collection | Messages |\n";
        $indexContent .= "|------|-------|------------|----------|\n";

        foreach ($conversationsJson as $conversation) {
            $date = (new \DateTime($conversation['last_query_datetime']))->format('Y-m-d');
            $title = $conversation['title'];
            $uuid = $conversation['uuid'];
            $collectionTitle = $conversation['collection']['title'] ?? '-';

            $messageCount = $this->countMessages($conversationsDir, $uuid);

            $escapedTitle = str_replace('|', '\\|', $title);
            $escapedCollection = str_replace('|', '\\|', $collectionTitle);

            $indexContent .= "| {$date} | [{$escapedTitle}](conversations/{$uuid}.md) | {$escapedCollection} | {$messageCount} |\n";
        }

        file_put_contents("{$this->kernelProjectDir}/var/data/00-INDEX.md", $indexContent);

        $output->writeln('Index created successfully!');

        return Command::SUCCESS;
    }

    private function countMessages(string $conversationsDir, string $uuid): int
    {
        $filePath = "{$conversationsDir}/{$uuid}.json";
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