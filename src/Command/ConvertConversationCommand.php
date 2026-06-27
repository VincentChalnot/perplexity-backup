<?php
declare(strict_types=1);

namespace App\Command;

use App\Helper\ConvertCommandHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:conversation:convert', description: 'Convert a single conversation file to markdown')]
class ConvertConversationCommand extends Command
{
    public function __construct(
        private readonly string $conversationsPath,
        private readonly ConvertCommandHelper $convertCommandHelper,
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
        $basePath = "{$this->conversationsPath}/conversations/{$uuid}";
        $filePath = "{$basePath}/conversation.json";

        if (!file_exists($filePath)) {
            $output->writeln("<error>Conversation file not found: {$filePath}</error>");
            return Command::FAILURE;
        }

        $conversation = json_decode(file_get_contents($filePath), true, 512, JSON_THROW_ON_ERROR);

        $output->writeln("Converting <info>{$uuid}</info>...");

        $result = $this->convertCommandHelper->convertConversation($conversation);

        // Write conversation.md
        $mdPath = "{$basePath}/conversation.md";
        file_put_contents($mdPath, $result['markdown']);
        $output->writeln("  ✓ Written: {$mdPath}");

        // Merge & write medias/index.json atomically
        $indexPath = "{$this->conversationsPath}/medias/index.json";
        $existingIndex = $this->convertCommandHelper->readMediaIndex($indexPath);
        $mergedIndex = $this->convertCommandHelper->mergeMediaIndex($existingIndex, $result['mediaIndex']);
        $this->convertCommandHelper->writeJsonAtomic($indexPath, $mergedIndex);
        $newCount = count($result['mediaIndex']);
        $totalCount = count($mergedIndex);
        $output->writeln("  ✓ Media index updated: {$newCount} new entries ({$totalCount} total)");

        // Write conversation_meta.json
        $metaPath = "{$basePath}/conversation_meta.json";
        $this->convertCommandHelper->writeJsonAtomic($metaPath, $result['conversationMeta']);
        $output->writeln("  ✓ Written: {$metaPath}");

        $output->writeln('<info>Done.</info>');

        return Command::SUCCESS;
    }
}
