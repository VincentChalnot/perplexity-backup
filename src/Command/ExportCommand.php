<?php

declare(strict_types=1);

namespace App\Command;

use App\Helper\ExportCommandHelper;
use App\Service\BackupPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:conversations:export', description: 'Export conversation JSON and media. Without UUID: exports all. With UUID: exports one.')]
class ExportCommand extends Command
{
    public function __construct(
        private readonly ExportCommandHelper $exportCommandHelper,
        private readonly BackupPaths $paths,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::OPTIONAL, 'UUID of a single conversation to export');
        $this->addOption('full', null, InputOption::VALUE_NONE, 'Force re-export of all conversations, ignoring local timestamps');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = $input->getArgument('uuid');

        if ($uuid !== null) {
            return $this->exportSingle($uuid, $output);
        }

        return $this->exportAll($input, $output);
    }

    private function exportSingle(string $uuid, OutputInterface $output): int
    {
        $output->writeln("Exporting conversation {$uuid}:");
        $this->exportCommandHelper->exportConversation($uuid, $output);

        return Command::SUCCESS;
    }

    private function exportAll(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->paths->conversationsList();
        if (!file_exists($path)) {
            $output->writeln("Conversations list not found at {$path}. Please run 'app:conversations:export-list' first.");
            return Command::FAILURE;
        }

        $conversationList = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $full = $input->getOption('full');

        if ($full) {
            $output->writeln('Full export: re-fetching all conversations.');
        }

        $exported = 0;
        $skipped = 0;
        $newCount = 0;
        foreach ($conversationList as $item) {
            $itemUuid = $item['uuid'];
            $title = strtok(mb_substr($item['title'] ?? 'Untitled', 0, 100), "\n");
            $remoteDate = $item['last_query_datetime'] ?? null;

            if (!$full) {
                $localJsonPath = $this->paths->threadJson($itemUuid);
                if (file_exists($localJsonPath)) {
                    $localData = json_decode(file_get_contents($localJsonPath), true, 512, JSON_THROW_ON_ERROR);
                    $localDate = $localData['thread_metadata']['updated_at'] ?? null;

                    if ($localDate !== null && $remoteDate !== null && $localDate === $remoteDate) {
                        $skipped++;
                        continue;
                    }
                } else {
                    $newCount++;
                }
            }

            $output->writeln("Exporting conversation {$itemUuid}: {$title}");
            $this->exportCommandHelper->exportConversation($itemUuid, $output);
            $exported++;
            usleep(random_int(500, 5000));
        }

        $output->writeln("Done. Exported: {$exported}, skipped (up-to-date): {$skipped}, new: {$newCount}");

        return Command::SUCCESS;
    }
}
