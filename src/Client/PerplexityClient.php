<?php
declare(strict_types=1);

namespace App\Client;

use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class PerplexityClient
{
    private const string CONVERSATION_PARAMETERS = 'with_parent_info=true&with_schematized_response=true&version=2.18&source=default&limit=1000&offset=0&from_first=true&supported_block_use_cases=answer_modes&supported_block_use_cases=media_items&supported_block_use_cases=knowledge_cards&supported_block_use_cases=inline_entity_cards&supported_block_use_cases=place_widgets&supported_block_use_cases=finance_widgets&supported_block_use_cases=sports_widgets&supported_block_use_cases=news_widgets&supported_block_use_cases=shopping_widgets&supported_block_use_cases=jobs_widgets&supported_block_use_cases=search_result_widgets&supported_block_use_cases=inline_images&supported_block_use_cases=inline_assets&supported_block_use_cases=placeholder_cards&supported_block_use_cases=diff_blocks&supported_block_use_cases=inline_knowledge_cards&supported_block_use_cases=entity_group_v2&supported_block_use_cases=refinement_filters&supported_block_use_cases=canvas_mode&supported_block_use_cases=maps_preview&supported_block_use_cases=answer_tabs&supported_block_use_cases=price_comparison_widgets&supported_block_use_cases=preserve_latex&supported_block_use_cases=generic_onboarding_widgets&supported_block_use_cases=in_context_suggestions&supported_block_use_cases=pending_followups&supported_block_use_cases=inline_claims&supported_block_use_cases=unified_assets&supported_block_use_cases=workflow_steps&supported_block_use_cases=workflow_widgets&supported_block_use_cases=navigation_results&supported_block_use_cases=background_agents&supported_block_use_cases=thinking_blocks&supported_block_use_cases=code_interpreter_blocks&supported_block_use_cases=image_generation_blocks';
    private const string BASE_URL = 'https://www.perplexity.ai/rest/thread';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $cookie,
    ) {
    }

    public function getConversationList(): array
    {
        $url = self::BASE_URL.'/list_ask_threads?version=2.18&source=default';
        $body = [
            'limit' => 10000,
            'ascending' => false,
            'offset' => 0,
            'search_term' => '',
        ];
        $options = [
            'headers' => $this->getHeaders($url),
            'json' => $body,
        ];

        $response = $this->httpClient->request('POST', $url, $options);
        $this->assertAuthenticated($response, 'conversations list');

        return $response->toArray();
    }

    public function getConversation(string $uuid): array
    {
        $url = self::BASE_URL."/{$uuid}?".self::CONVERSATION_PARAMETERS;
        $options = [
            'headers' => $this->getHeaders($url),
        ];
        $response = $this->httpClient->request('GET', $url, $options);
        $this->assertAuthenticated($response, "conversation {$uuid}");

        return $response->toArray();
    }

    /**
     * @throws \RuntimeException on 401, 403, or redirect (session expired / invalid cookie)
     */
    private function assertAuthenticated($response, string $context): void
    {
        $code = $response->getStatusCode();
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException(
                "Session expired or invalid cookie (HTTP {$code}) while fetching {$context}. "
                . "Update cookie.txt with a fresh ppl_session cookie from your browser."
            );
        }
        // Symfony HttpClient follows redirects by default; a 3xx here means
        // the server redirected to a login page, which also signals a stale cookie.
        if ($code >= 300 && $code < 400) {
            throw new \RuntimeException(
                "Unexpected redirect (HTTP {$code}) while fetching {$context}. "
                . "This usually means your session cookie has expired. Update cookie.txt."
            );
        }
        if ($code !== 200) {
            throw new \RuntimeException(
                "Failed to fetch {$context}: HTTP {$code}\n{$response->getContent()}"
            );
        }
    }

    private function getHeaders(string $url): array
    {
        return [
            'accept' => '*/*',
            'accept-language' => 'en-US,en-GB;q=0.9,en;q=0.8,fr-FR;q=0.7,fr;q=0.6,es;q=0.5',
            'content-type' => 'application/json',
            'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
            'x-app-apiclient' => 'default',
            'x-app-apiversion' => '2.18',
            'x-perplexity-request-endpoint' => $url,
            'x-perplexity-request-reason' => 'threads-body',
            'x-perplexity-request-try-number' => '1',
            'Cookie' => $this->cookie,
        ];
    }
}
