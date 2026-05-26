<?php
declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: "app:export-conversations-list", description: "Export all conversations to JSON files")]
class ExportConversationsListCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $kernelProjectDir,
        private readonly string $cookie,
        ?string $name = null,
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $responseData = $this->fetchConversations();
        file_put_contents("{$this->kernelProjectDir}/var/data/conversations.json", json_encode($responseData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }

    private function fetchConversations(): array
    {
        $url = 'https://www.perplexity.ai/rest/thread/list_ask_threads?version=2.18&source=default';
        $headers = [
            'accept' => '*/*',
            'accept-language' => 'en-US,en-GB;q=0.9,en;q=0.8,fr-FR;q=0.7,fr;q=0.6,es;q=0.5',
            'content-type' => 'application/json',
            'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
            'x-app-apiclient' => 'default',
            'x-app-apiversion' => '2.18',
            'x-perplexity-request-endpoint' => $url,
            'x-perplexity-request-reason' => 'threads-body',
            'x-perplexity-request-try-number' => '1',
            'Cookie' => $this->cookie,
        ];
        $body = [
            'limit' => 10000,
            'ascending' => false,
            'offset' => 0,
            'search_term' => '',
        ];
        $options = [
            'headers' => $headers,
            'json' => $body,
        ];

        return $this->httpClient->request('POST', $url, $options)->toArray();
    }
}
