<?php
declare(strict_types=1);

namespace App\Command;

use App\Helper\ConvertCommandHelper;
use App\Service\BackupPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

#[AsCommand(name: 'app:conversations:convert', description: 'Convert conversation JSON to markdown. Without UUID: converts all. With UUID: converts one.')]
class ConvertCommand extends Command
{
    public function __construct(
        private readonly BackupPaths $paths,
        private readonly ConvertCommandHelper $convertCommandHelper,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::OPTIONAL, 'UUID of a single conversation to convert');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = $input->getArgument('uuid');

        if ($uuid !== null) {
            return $this->convertSingle($uuid, $output);
        }

        return $this->convertAll($output);
    }

    private function convertSingle(string $uuid, OutputInterface $output): int
    {
        $filePath = $this->paths->threadJson($uuid);

        if (!file_exists($filePath)) {
            $output->writeln("<error>Conversation file not found: {$filePath}</error>");
            return Command::FAILURE;
        }

        $conversation = json_decode(file_get_contents($filePath), true, 512, JSON_THROW_ON_ERROR);

        $output->writeln("Converting <info>{$uuid}</info>...");

        $result = $this->convertCommandHelper->convertConversation($conversation);

        // Write conversation.md
        file_put_contents($this->paths->threadMarkdown($uuid), $result['markdown']);
        $output->writeln("  Written: " . $this->paths->threadMarkdown($uuid));

        // Merge & write medias/index.json atomically
        $existingIndex = $this->convertCommandHelper->readMediaIndex($this->paths->mediaIndex());
        $mergedIndex = $this->convertCommandHelper->mergeMediaIndex($existingIndex, $result['mediaIndex']);
        $this->convertCommandHelper->writeJsonAtomic($this->paths->mediaIndex(), $mergedIndex);
        $newCount = count($result['mediaIndex']);
        $totalCount = count($mergedIndex);
        $output->writeln("  Media index updated: {$newCount} new entries ({$totalCount} total)");

        // Write conversation_meta.json
        $this->convertCommandHelper->writeJsonAtomic($this->paths->threadMeta($uuid), $result['conversationMeta']);
        $output->writeln("  Written: " . $this->paths->threadMeta($uuid));

        $output->writeln('<info>Done.</info>');

        return Command::SUCCESS;
    }

    private function convertAll(OutputInterface $output): int
    {
        $existingIndex = $this->convertCommandHelper->readMediaIndex($this->paths->mediaIndex());

        $finder = new Finder();
        $finder->in($this->paths->conversationsDir())->sortByName()->name('conversation.json')->files();
        foreach ($finder as $i) {
            $conversation = json_decode($i->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $result = $this->convertCommandHelper->convertConversation($conversation);

            $basePath = $i->getPath();
            file_put_contents("{$basePath}/conversation.md", $result['markdown']);
            $this->convertCommandHelper->writeJsonAtomic("{$basePath}/conversation_meta.json", $result['conversationMeta']);

            $existingIndex = $this->convertCommandHelper->mergeMediaIndex($existingIndex, $result['mediaIndex']);
        }

        // Write final merged index
        $this->convertCommandHelper->writeJsonAtomic($this->paths->mediaIndex(), $existingIndex);
        $output->writeln("Media index: " . count($existingIndex) . " total entries");

        return Command::SUCCESS;
    }
}
