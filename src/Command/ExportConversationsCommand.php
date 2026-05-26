<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:export-individual-conversations', description: 'Hello PhpStorm')]
class ExportConversationsCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $kernelProjectDir,
        private readonly string $cookie,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conversationList = json_decode(
            file_get_contents("{$this->kernelProjectDir}/var/data/conversations.json"),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($conversationList as $item) {
            $slug = $item['slug'];
            $this->exportConversation($slug);
        }

        return Command::SUCCESS;
    }

    private function exportConversation(string $slug): void
    {
        $filePath = "{$this->kernelProjectDir}/var/data/conversations/{$slug}.json";
        if (file_exists($filePath)) {
            return;
        }
        $url = "https://www.perplexity.ai/rest/thread/{$slug}?with_parent_info=true&with_schematized_response=true&version=2.18&source=default&limit=1000&offset=0&from_first=true&supported_block_use_cases=answer_modes&supported_block_use_cases=media_items&supported_block_use_cases=knowledge_cards&supported_block_use_cases=inline_entity_cards&supported_block_use_cases=place_widgets&supported_block_use_cases=finance_widgets&supported_block_use_cases=sports_widgets&supported_block_use_cases=shopping_widgets&supported_block_use_cases=jobs_widgets&supported_block_use_cases=search_result_widgets&supported_block_use_cases=clarification_responses&supported_block_use_cases=inline_images&supported_block_use_cases=inline_assets&supported_block_use_cases=inline_finance_widgets&supported_block_use_cases=placeholder_cards&supported_block_use_cases=diff_blocks&supported_block_use_cases=inline_knowledge_cards&supported_block_use_cases=entity_group_v2&supported_block_use_cases=refinement_filters&supported_block_use_cases=canvas_mode&supported_block_use_cases=maps_preview";
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
        $options = [
            'headers' => $headers,
        ];
        $response = $this->httpClient->request('GET', $url, $options);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(
                "Failed to fetch conversation {$slug}: HTTP {$response->getStatusCode()}\n{$response->getContent()}"
            );
        }
        $responseData = $response->toArray();
        file_put_contents($filePath, json_encode($responseData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        usleep(random_int(500, 5000));
    }
}
