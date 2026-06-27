<?php
declare(strict_types=1);

namespace App\Helper;

use App\Client\PerplexityClient;
use Symfony\Component\Console\Output\OutputInterface;

readonly class ExportCommandHelper
{
    private const array PERPLEXITY_DOMAINS = [
        'ppl-ai-file-upload.s3.amazonaws.com',
        'ppl-ai-code-interpreter-files.s3.amazonaws.com',
        'user-gen-media-assets.s3.amazonaws.com',
    ];

    public function __construct(
        private PerplexityClient $perplexityClient,
        private string $conversationsPath,
    ) {
    }

    public function exportConversation(string $uuid, OutputInterface $output): void
    {
        $basePath = "{$this->conversationsPath}/conversations/{$uuid}";
        if (!is_dir($basePath) && !mkdir($basePath, 0777, true) && !is_dir($basePath)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $basePath));
        }
        $filePath = "{$basePath}/conversation.json";
        if (file_exists($filePath)) {
            return;
        }
        $responseData = $this->perplexityClient->getConversation($uuid);
        $this->fetchMedias($responseData, $output);

        file_put_contents($filePath, json_encode($responseData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function fetchMedias(array $responseData, OutputInterface $output): void
    {
        $medias = [];
        // Browse the entire object and find all URLs:
        array_walk_recursive($responseData, function ($value) use (&$medias) {
            if (!is_string($value)) {
                return;
            }
            if (!str_contains($value, 'AWSAccessKeyId')) {
                return;
            }
            $finalPath = $this->parsePerplexityUrlPath($value);
            if (null === $finalPath) {
                return;
            }

            $medias[$finalPath] = $value;
        });

        foreach ($medias as $finalPath => $fullUrl) {
            $output->writeln(" - Fetching media: {$fullUrl}");

            $filePath = "{$this->conversationsPath}/medias/{$finalPath}";
            if (file_exists($filePath)) {
                continue;
            }
            $dir = dirname($filePath);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }
            try {
                $source = fopen($fullUrl, 'rb');
            } catch (\Exception $e) {
                $output->writeln("<error>Unable to open URL: {$fullUrl}</error>");
                $output->writeln("<error>{$e}</error>");
                continue;
            }
            $dest = fopen($filePath, 'wb');

            stream_copy_to_stream($source, $dest);

            fclose($source);
            fclose($dest);
        }
    }

    private function parsePerplexityUrlPath(string $url): ?string
    {
        $urlData = parse_url($url);
        $host = $urlData['host'] ?? null;
        if (!in_array($host, self::PERPLEXITY_DOMAINS, true)) {
            return null;
        }

        return $urlData['path'] ?? null;
    }
}
