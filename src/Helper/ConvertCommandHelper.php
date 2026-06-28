<?php
declare(strict_types=1);

namespace App\Helper;

use App\Processor\BlockProcessorInterface;
use App\Processor\ConvertContext;
use Symfony\Component\Yaml\Yaml;

class ConvertCommandHelper
{
    private const array KEEP_DOMAINS = [
        'ppl-ai-file-upload.s3.amazonaws.com',
        'ppl-ai-code-interpreter-files.s3.amazonaws.com',
        'user-gen-media-assets.s3.amazonaws.com',
    ];

    /**
     * @param iterable<BlockProcessorInterface> $processors
     */
    public function __construct(
        private readonly iterable $processors,
    ) {
    }

    /**
     * Convert a full conversation JSON (with entries[]) to markdown + media index updates.
     *
     * @return array{markdown: string, mediaIndex: array, conversationMeta: array}
     */
    public function convertConversation(array $conversation): array
    {
        $entries = $conversation['entries'] ?? [$conversation];
        if (empty($entries)) {
            return ['markdown' => '', 'mediaIndex' => [], 'conversationMeta' => []];
        }

        $context = new ConvertContext($conversation);

        foreach ($entries as $i => $entry) {
            $context->setCurrentEntryIndex($i);
            foreach ($this->processors as $processor) {
                $processor->process($entry, $context);
            }
        }

        $mediaIndex = $this->buildMediaIndex(
            $context->generatedImages,
            $context->attachments,
            $context->threadUuid,
            $context->createdAt
        );

        $md = $this->buildMarkdown($context, $mediaIndex);

        $conversationMeta = [
            'thread_uuid' => $context->threadUuid,
            'title' => $context->title,
            'generated_images' => array_map(fn($img) => [
                's3_path' => $img['s3_path'] ?? '',
                'uuid' => $img['uuid'] ?? '',
                'name' => $img['name'] ?? '',
                'prompt' => $img['prompt'] ?? '',
                'model' => $img['model'] ?? '',
            ], $context->generatedImages),
            'attachments' => array_map(fn($att) => [
                's3_key' => $att['s3_key'] ?? '',
                'name' => $att['name'] ?? '',
                'num_characters' => $att['num_characters'] ?? null,
            ], $context->attachments),
        ];

        return [
            'markdown' => $md,
            'mediaIndex' => $mediaIndex,
            'conversationMeta' => $conversationMeta,
        ];
    }

    // ─── S3 path extraction ───────────────────────────────────────

    private function s3KeyFromUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parsed = parse_url($url);

        return ltrim($parsed['path'] ?? '', '/');
    }

    private function isKeepDomain(string $url): bool
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        return in_array($host, self::KEEP_DOMAINS, true);
    }

    private function hasAwsAccessKey(string $url): bool
    {
        $parsed = parse_url($url);
        $query = $parsed['query'] ?? '';

        return str_contains($query, 'AWSAccessKeyId');
    }

    private function getExpiresFromUrl(string $url): ?int
    {
        $parsed = parse_url($url);
        $query = $parsed['query'] ?? '';
        parse_str($query, $params);

        return isset($params['Expires']) ? (int) $params['Expires'] : null;
    }

    // ─── Media index ──────────────────────────────────────────────

    private function buildMediaIndex(array $images, array $attachments, string $threadUuid, string $createdAt): array
    {
        $index = [];

        foreach ($images as $img) {
            $s3Path = $img['s3_path'];
            if ($s3Path === '') {
                continue;
            }

            $url = $img['signed_url'] ?? $img['thumbnail_url'] ?? '';
            $shouldDownload = $this->isKeepDomain($url) && $this->hasAwsAccessKey($url);

            $index[$s3Path] = [
                'local_path' => "medias/{$s3Path}",
                'first_seen' => $createdAt,
                'last_seen' => $createdAt,
                'source_threads' => [$threadUuid],
                'url_signed' => $shouldDownload ? $url : null,
                'expires_at' => $shouldDownload ? $this->getExpiresFromUrl($url) : null,
                'size_bytes' => null,
                'sha256' => null,
                'type' => 'generated_image',
                'name' => $img['name'] ?? '',
            ];
        }

        foreach ($attachments as $att) {
            $s3Key = $att['s3_key'];
            if ($s3Key === '') {
                continue;
            }

            $url = $att['url'] ?? '';
            $shouldDownload = $this->isKeepDomain($url) && $this->hasAwsAccessKey($url);

            $index[$s3Key] = [
                'local_path' => "medias/{$s3Key}",
                'first_seen' => $createdAt,
                'last_seen' => $createdAt,
                'source_threads' => [$threadUuid],
                'url_signed' => $shouldDownload ? $url : null,
                'expires_at' => $shouldDownload ? $this->getExpiresFromUrl($url) : null,
                'size_bytes' => null,
                'sha256' => null,
                'type' => 'attachment',
                'name' => $att['name'] ?? '',
            ];
        }

        return $index;
    }

    /**
     * Merge new media index into existing one (atomic update).
     *
     * @param array<string, array> $existing
     * @param array<string, array> $new
     * @return array<string, array>
     */
    public function mergeMediaIndex(array $existing, array $new): array
    {
        foreach ($new as $key => $entry) {
            if (isset($existing[$key])) {
                $existingThreads = $existing[$key]['source_threads'] ?? [];
                $newThreads = $entry['source_threads'] ?? [];
                foreach ($newThreads as $t) {
                    if (!in_array($t, $existingThreads, true)) {
                        $existingThreads[] = $t;
                    }
                }
                $existing[$key]['source_threads'] = $existingThreads;
                $existing[$key]['last_seen'] = $entry['last_seen'] ?? $existing[$key]['last_seen'] ?? '';
                if (!empty($entry['url_signed'])) {
                    $existing[$key]['url_signed'] = $entry['url_signed'];
                }
                if ($entry['expires_at'] !== null) {
                    $existing[$key]['expires_at'] = $entry['expires_at'];
                }
            } else {
                $existing[$key] = $entry;
            }
        }

        return $existing;
    }

    // ─── Markdown builder ─────────────────────────────────────────

    private function buildMarkdown(ConvertContext $context, array $mediaIndex): string
    {
        $md = '';

        // ── Frontmatter ───────────────────────────────────────────
        $allSourceTypes = $context->getAllSourceTypes();
        $frontmatter = [
            'thread_uuid' => $context->threadUuid,
            'model' => $context->model,
            'created' => $context->createdAt,
        ];
        if (!empty($allSourceTypes)) {
            $frontmatter['source_types'] = $allSourceTypes;
        }
        $md .= "---\n";
        $md .= Yaml::dump($frontmatter, 4, 2);
        $md .= "---\n\n";

        // ── Per-entry blocks ──────────────────────────────────────
        foreach ($context->entries as $i => $entry) {
            if ($i > 0) {
                $md .= "\n---\n\n";
            }

            // Entry YAML metadata block 1: query_language + updated_datetime
            $meta1 = [];
            if ($entry->queryLanguage !== '') {
                $meta1['query_language'] = $entry->queryLanguage;
            }
            if ($entry->updatedDatetime !== '') {
                $meta1['updated_datetime'] = $entry->updatedDatetime;
            }
            if (!empty($meta1)) {
                $md .= "```yaml\n";
                $md .= Yaml::dump($meta1, 4, 2);
                $md .= "```\n\n";
            }

            // Query string
            foreach ($entry->questions as $q) {
                $md .= "{$q}\n\n";
            }

            // Entry YAML metadata block 2: display_model + sources + search_mode + steps (with sources)
            $meta2 = [];
            if ($entry->displayModel !== '') {
                $meta2['display_model'] = $entry->displayModel;
            }
            if (!empty($entry->sourceTypes)) {
                $meta2['sources'] = $entry->sourceTypes;
            }
            if ($entry->searchMode !== '') {
                $meta2['search_mode'] = $entry->searchMode;
            }
            if (!empty($entry->steps) || !empty($entry->sources)) {
                $stepsOut = $entry->steps;
                if (!empty($entry->sources)) {
                    if (empty($stepsOut)) {
                        $stepsOut[] = ['title' => '', 'queries' => []];
                    }
                    $lastIdx = count($stepsOut) - 1;
                    foreach ($entry->sources as $s) {
                        $num = $s['number'] ?? null;
                        if ($num === null) {
                            continue;
                        }
                        $stepsOut[$lastIdx]['sources'][$num] = $this->formatSource($s);
                    }
                }
                $meta2['steps'] = $stepsOut;
            }
            if (!empty($meta2)) {
                $md .= "```yaml\n";
                $md .= Yaml::dump($meta2, 4, 2);
                $md .= "```\n\n";
            }

            // Answer
            foreach ($entry->answers as $answer) {
                $md .= "{$answer}\n\n";
            }

            // Widgets (per-entry)
            if (!empty($entry->widgets)) {
                $md .= $this->renderWidgets($entry->widgets);
            }
        }

        // ── Global sections ───────────────────────────────────────

        // Generated images
        if (!empty($context->generatedImages)) {
            $md .= "## Generated images\n\n";
            foreach ($context->generatedImages as $img) {
                $name = $img['name'] ?: 'Untitled';
                $uuid = $img['uuid'] ?? '';
                $prompt = $img['prompt'] ?? '';
                $modelStr = $img['model'] ?? '';
                $s3Path = $img['s3_path'] ?? '';

                $md .= "- **{$name}**\n";
                if ($uuid !== '') {
                    $md .= "  - uuid: {$uuid}\n";
                }
                if ($prompt !== '') {
                    $md .= "  - prompt: {$prompt}\n";
                }
                if ($modelStr !== '') {
                    $md .= "  - model: {$modelStr}\n";
                }
                if ($s3Path !== '') {
                    $localPath = "../../medias/{$s3Path}";
                    $md .= "  - thumbnail: ![{$name}]({$localPath})\n";
                    if (isset($mediaIndex[$s3Path]) && !empty($mediaIndex[$s3Path]['url_signed'])) {
                        $expires = $mediaIndex[$s3Path]['expires_at'] ?? null;
                        $expiresNote = $expires !== null ? " (Expires: " . date('Y-m-d H:i', $expires) . ")" : '';
                        $md .= "  - signed: medias/index.json → `{$s3Path}`{$expiresNote}\n";
                    }
                }
            }
            $md .= "\n";
        }

        // Attachments
        if (!empty($context->attachments)) {
            $md .= "## Attachments\n\n";
            foreach ($context->attachments as $att) {
                $name = $att['name'] ?? 'Untitled';
                $chars = $att['num_characters'] ?? null;
                $s3Key = $att['s3_key'] ?? '';
                $snippet = $att['snippet'] ?? '';
                $snippetShort = mb_strlen($snippet) > 240 ? mb_substr($snippet, 0, 240) . '...' : $snippet;



                if ($s3Key !== '') {
                    $line = "- **[{$name}](../../medias/{$s3Key})**";
                } else {
                    $line = "- **{$name}**";
                }
                if ($chars !== null) {
                    $line .= " ({$chars} chars)";
                }
                $md .= "{$line}\n";
                if ($snippetShort !== '') {
                    $md .= "  snippet: \"{$snippetShort}\"\n";
                }
            }
            $md .= "\n";
        }

        // Local media manifest reference
        $hasMedia = !empty($context->generatedImages) || !empty($context->attachments);
        if ($hasMedia) {
            $md .= "## Local media manifest\n\n";
            $md .= "See [medias/index.json](../../medias/index.json)\n";
        }

        return $md;
    }

    // ─── Source formatting ───────────────────────────────────────

    /**
     * Format a single source for YAML output.
     * Transforms S3 attachment URLs to local paths.
     */
    private function formatSource(array $s): array
    {
        $out = [];
        $name = $s['name'] ?? '';
        if ($name !== '') {
            $out['name'] = $name;
        }
        $snippet = $s['snippet'] ?? '';
        if ($snippet !== '') {
            $out['snippet'] = $snippet;
        }
        $url = $s['url'] ?? '';
        if ($url !== '') {
            if (!empty($s['is_attachment']) && $this->isKeepDomain($url)) {
                $out['url'] = '../../medias/' . $this->s3KeyFromUrl($url);
                // Skip domain for local files — S3 hostname is not useful
            } else {
                $out['url'] = $url;
                $domain = $s['domain'] ?? '';
                if ($domain !== '') {
                    $out['domain'] = $domain;
                }
            }
        }
        $description = $s['description'] ?? '';
        if ($description !== '') {
            $out['description'] = $description;
        }
        $publishedDate = $s['published_date'] ?? '';
        if ($publishedDate !== '') {
            $out['published_date'] = $publishedDate;
        }
        $images = $s['images'] ?? [];
        if (!empty($images)) {
            $out['images'] = $images;
        }
        $numChars = $s['num_characters'] ?? null;
        if ($numChars !== null) {
            $out['num_characters'] = $numChars;
        }
        return $out;
    }

    // ─── Widget rendering ─────────────────────────────────────────

    private function renderWidgets(array $widgets): string
    {
        $md = '';
        $byType = [];
        foreach ($widgets as $w) {
            $byType[$w['type'] ?? 'unknown'][] = $w;
        }

        foreach ($byType as $type => $items) {
            if ($type === 'finance') {
                $md .= "## Finance\n\n";
                $md .= "| Symbol | Name | Price | Change | Change % | Market Cap | Exchange |\n";
                $md .= "|--------|------|-------|--------|----------|------------|----------|\n";
                foreach ($items as $item) {
                    $symbol = $item['symbol'] ?? '';
                    $name = $item['name'] ?? '';
                    $price = $item['price'] !== null ? number_format($item['price'], 2) : '-';
                    $change = $item['change'] !== null ? number_format($item['change'], 2) : '-';
                    $changePct = $item['changePercent'] !== null ? number_format(
                        $item['changePercent'],
                        2
                    ) . '%' : '-';
                    $mktCap = $item['marketCap'] !== null ? $this->formatLargeNumber($item['marketCap']) : '-';
                    $exchange = $item['exchange'] ?? '';
                    $md .= "| {$symbol} | {$name} | {$price} | {$change} | {$changePct} | {$mktCap} | {$exchange} |\n";
                }
                $md .= "\n";
            } else {
                $md .= "## {$type}\n";
                foreach ($items as $item) {
                    $md .= "- " . json_encode($item, JSON_UNESCAPED_UNICODE) . "\n";
                }
                $md .= "\n";
            }
        }

        return $md;
    }

    // ─── Number formatting ──────────────────────────────────────

    private function formatLargeNumber(float|int $num): string
    {
        if ($num >= 1_000_000_000) {
            return round($num / 1_000_000_000, 2) . 'B';
        }
        if ($num >= 1_000_000) {
            return round($num / 1_000_000, 2) . 'M';
        }
        if ($num >= 1_000) {
            return round($num / 1_000, 2) . 'K';
        }

        return number_format($num, 2);
    }

    // ─── Atomic file write ────────────────────────────────────────

    /**
     * Write JSON to file atomically (write to tmp then rename).
     */
    public function writeJsonAtomic(string $filePath, array $data): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

        $tmpFile = $filePath . '.tmp.' . getmypid();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($tmpFile, $json);
        rename($tmpFile, $filePath);
    }

    /**
     * Read existing media index from disk.
     *
     * @return array<string, array>
     */
    public function readMediaIndex(string $indexPath): array
    {
        if (!file_exists($indexPath)) {
            return [];
        }
        $data = json_decode(file_get_contents($indexPath), true);

        return is_array($data) ? $data : [];
    }
}
