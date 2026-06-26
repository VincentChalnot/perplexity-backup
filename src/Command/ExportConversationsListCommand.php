<?php
declare(strict_types=1);

namespace App\Command;

use App\Client\PerplexityClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: "app:export-conversations-list", description: "Export all conversations to JSON files")]
class ExportConversationsListCommand extends Command
{
    public function __construct(
        private readonly PerplexityClient $perplexityClient,
        private readonly string $conversationsPath,
        ?string $name = null,
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $responseData = $this->perplexityClient->getConversationList();
        file_put_contents("{$this->conversationsPath}/conversations.json", json_encode($responseData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}
