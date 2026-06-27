<?php
declare(strict_types=1);

namespace App\Command;

use App\Helper\ConvertCommandHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

#[AsCommand(name: 'app:conversations:convert', description: 'Convert all json conversations files to markdown')]
class ConvertConversationsCommand extends Command
{
    public function __construct(
        private readonly string $conversationsPath,
        private readonly ConvertCommandHelper $convertCommandHelper,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $indexPath = "{$this->conversationsPath}/medias/index.json";
        $existingIndex = $this->convertCommandHelper->readMediaIndex($indexPath);

        $finder = new Finder();
        $finder->in("{$this->conversationsPath}/conversations")->sortByName()->name('conversation.json')->files();
        foreach ($finder as $i) {
            $conversation = json_decode($i->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $result = $this->convertCommandHelper->convertConversation($conversation);

            $basePath = $i->getPath();
            file_put_contents("{$basePath}/conversation.md", $result['markdown']);
            $this->convertCommandHelper->writeJsonAtomic("{$basePath}/conversation_meta.json", $result['conversationMeta']);

            $existingIndex = $this->convertCommandHelper->mergeMediaIndex($existingIndex, $result['mediaIndex']);
        }

        // Write final merged index
        $this->convertCommandHelper->writeJsonAtomic($indexPath, $existingIndex);
        $output->writeln("Media index: " . count($existingIndex) . " total entries");

        return Command::SUCCESS;
    }
}
