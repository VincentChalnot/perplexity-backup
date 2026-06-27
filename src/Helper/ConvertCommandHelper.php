<?php
declare(strict_types=1);

namespace App\Helper;

class ConvertCommandHelper
{
    private const array KEEP_DOMAINS = [
        'ppl-ai-file-upload.s3.amazonaws.com',
        'ppl-ai-code-interpreter-files.s3.amazonaws.com',
        'user-gen-media-assets.s3.amazonaws.com',
    ];

    private const array GENERATED_IMAGE_ASSET_TYPES = ['GENERATED_IMAGE'];
    private const array CHART_ASSET_TYPES = ['CHART'];

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

        // Thread-level metadata from first entry
        $firstEntry = $entries[0];
        $title = $firstEntry['thread_title'] ?? '';
        $threadUuid = $this->getThreadUuid($firstEntry);
        $model = $firstEntry['display_model'] ?? '';
        $createdAt = $firstEntry['entry_created_datetime']
            ?? ($conversation['thread_metadata']['created_at'] ?? '')
            ?? ($conversation['thread_metadata']['createdat'] ?? '');

        // Collect data across all entries
        $allQuestions = [];
        $allAnswers = [];
        $allGoals = [];
        $allCitations = [];
        $allGeneratedImages = [];
        $allAttachments = [];
        $allWidgets = [];
        $allWorkflowQueries = [];
        $seenCitationUrls = [];
        $seenAttachmentKeys = [];
        $seenImagePaths = [];
        $seenWidgetSymbols = [];

        foreach ($entries as $entry) {
            // Question
            $query = $entry['query_str'] ?? '';
            if ($query !== '') {
                $allQuestions[] = $query;
            }

            // Answer — collect numbered ask_text_N_markdown blocks + ask_text_0_markdown + ask_text
            $entryAnswer = $this->getCanonicalAnswer($entry);
            if ($entryAnswer !== null) {
                $allAnswers[] = $entryAnswer;
            }

            $blocks = $entry['blocks'] ?? [];

            // Plan
            foreach ($blocks as $b) {
                if (($b['intended_usage'] ?? '') === 'plan') {
                    foreach (($b['plan_block']['goals'] ?? []) as $goal) {
                        $desc = $goal['description'] ?? '';
                        if ($desc !== '' && !in_array($desc, $allGoals, true)) {
                            $allGoals[] = $desc;
                        }
                    }
                }
            }

            // Citations (sources_answer_mode)
            foreach ($blocks as $b) {
                if (($b['intended_usage'] ?? '') !== 'sources_answer_mode') {
                    continue;
                }
                $rows = $b['sources_mode_block']['rows'] ?? [];
                // Sort by citation number if present
                usort($rows, fn($a, $b2) => ($a['citation'] ?? 9999) <=> ($b2['citation'] ?? 9999));
                foreach ($rows as $row) {
                    if (($row['status'] ?? '') !== 'SELECTED') {
                        continue;
                    }
                    $wr = $row['web_result'] ?? [];
                    $url = $wr['url'] ?? '';
                    if ($url !== '' && isset($seenCitationUrls[$url])) {
                        continue;
                    }
                    if ($url !== '') {
                        $seenCitationUrls[$url] = true;
                    }
                    // Find first non-empty snippet
                    $snippet = $wr['snippet'] ?? '';
                    $allCitations[] = [
                        'number' => $row['citation'] ?? null,
                        'name' => $wr['name'] ?? '',
                        'url' => $url,
                        'domain' => $wr['meta_data']['citation_domain_name'] ?? $this->extractDomain($url),
                        'snippet' => $snippet,
                        'images' => $wr['meta_data']['images'] ?? [],
                    ];
                }
            }

            // Workflow queries
            foreach ($blocks as $b) {
                if (($b['intended_usage'] ?? '') !== 'workflow_root') {
                    continue;
                }
                foreach (($b['workflow_block']['steps'] ?? []) as $step) {
                    foreach (($step['items'] ?? []) as $item) {
                        $type = $item['type'] ?? $item['item_type'] ?? '';
                        if ($type === 'WORKFLOW_ITEM_QUERIES') {
                            $q = $item['query'] ?? $item['content'] ?? '';
                            if ($q !== '' && !in_array($q, $allWorkflowQueries, true)) {
                                $allWorkflowQueries[] = $q;
                            }
                        }
                    }
                }
            }

            // Generated images (unified_assets + media_items)
            $entryImages = $this->collectGeneratedImages($entry);
            foreach ($entryImages as $img) {
                $path = $img['s3_path'] ?? '';
                if ($path !== '' && isset($seenImagePaths[$path])) {
                    continue;
                }
                if ($path !== '') {
                    $seenImagePaths[$path] = true;
                }
                $allGeneratedImages[] = $img;
            }

            // Attachments
            $entryAttachments = $this->collectAttachments($entry);
            foreach ($entryAttachments as $att) {
                $key = $att['s3_key'] ?? $this->s3KeyFromUrl($att['url'] ?? '');
                if ($key !== '' && isset($seenAttachmentKeys[$key])) {
                    continue;
                }
                if ($key !== '') {
                    $seenAttachmentKeys[$key] = true;
                }
                $allAttachments[] = $att;
            }

            // Widgets
            foreach ($blocks as $b) {
                if (($b['intended_usage'] ?? '') !== 'finance_widget') {
                    continue;
                }
                $fwBlock = $b['widget_block']['finance_widget_block'] ?? [];
                foreach (($fwBlock['data_json_v2'] ?? []) as $jsonStr) {
                    $data = json_decode($jsonStr, true);
                    if (!is_array($data)) {
                        continue;
                    }
                    $symbol = $data['symbol'] ?? '';
                    if ($symbol !== '' && isset($seenWidgetSymbols[$symbol])) {
                        continue;
                    }
                    if ($symbol !== '') {
                        $seenWidgetSymbols[$symbol] = true;
                    }
                    $allWidgets[] = [
                        'type' => 'finance',
                        'symbol' => $symbol,
                        'name' => $data['name'] ?? '',
                        'price' => $data['price'] ?? null,
                        'change' => $data['change'] ?? null,
                        'changePercent' => $data['changesPercentage'] ?? null,
                        'marketCap' => $data['marketCap'] ?? null,
                        'exchange' => $data['exchange'] ?? '',
                        'currency' => $data['currency'] ?? '',
                        'dayLow' => $data['dayLow'] ?? null,
                        'dayHigh' => $data['dayHigh'] ?? null,
                        'yearHigh' => $data['yearHigh'] ?? null,
                        'yearLow' => $data['yearLow'] ?? null,
                        'volume' => $data['volume'] ?? null,
                        'avgVolume' => $data['avgVolume'] ?? null,
                        'pe' => $data['pe'] ?? null,
                        'eps' => $data['eps'] ?? null,
                        'isEtf' => $data['isEtf'] ?? false,
                        'isCrypto' => $data['isCrypto'] ?? false,
                    ];
                }
            }
        }

        // Renumber citations sequentially if they don't have numbers
        $hasNumbers = false;
        foreach ($allCitations as $c) {
            if ($c['number'] !== null) {
                $hasNumbers = true;
                break;
            }
        }
        if (!$hasNumbers) {
            foreach ($allCitations as $i => &$c) {
                $c['number'] = $i + 1;
            }
            unset($c);
        }

        // Build media index entries
        $mediaIndex = $this->buildMediaIndex($allGeneratedImages, $allAttachments, $threadUuid, $createdAt);

        // Build markdown
        $md = $this->buildMarkdown(
            $title, $threadUuid, $model, $createdAt,
            $allQuestions, $allGoals, $allAnswers,
            $allCitations, $allGeneratedImages, $allAttachments,
            $allWidgets, $allWorkflowQueries, $mediaIndex,
        );

        // Build conversation meta
        $conversationMeta = [
            'thread_uuid' => $threadUuid,
            'title' => $title,
            'generated_images' => array_map(fn($img) => [
                's3_path' => $img['s3_path'] ?? '',
                'uuid' => $img['uuid'] ?? '',
                'name' => $img['name'] ?? '',
                'prompt' => $img['prompt'] ?? '',
                'model' => $img['model'] ?? '',
            ], $allGeneratedImages),
            'attachments' => array_map(fn($att) => [
                's3_key' => $att['s3_key'] ?? '',
                'name' => $att['name'] ?? '',
                'num_characters' => $att['num_characters'] ?? null,
            ], $allAttachments),
        ];

        return [
            'markdown' => $md,
            'mediaIndex' => $mediaIndex,
            'conversationMeta' => $conversationMeta,
        ];
    }

    // ─── Canonical answer ─────────────────────────────────────────

    private function getCanonicalAnswer(array $entry): ?string
    {
        $blocks = $entry['blocks'] ?? [];

        // Collect numbered ask_text_N_markdown blocks
        $numbered = [];
        $askText0 = null;
        $askText = null;

        foreach ($blocks as $b) {
            $usage = $b['intended_usage'] ?? '';
            if ($usage === 'ask_text_0_markdown') {
                $askText0 = $b['markdown_block']['answer'] ?? null;
            } elseif ($usage === 'ask_text') {
                $askText = $b['markdown_block']['answer'] ?? null;
            } elseif (preg_match('/^ask_text_(\d+)_markdown$/', $usage, $m)) {
                $numbered[(int) $m[1]] = $b['markdown_block']['answer'] ?? '';
            }
        }

        // If multiple numbered blocks exist, concatenate them (fragmented answer)
        if (count($numbered) > 1) {
            ksort($numbered);
            $parts = array_filter($numbered, fn($v) => $v !== '');
            if (!empty($parts)) {
                return implode("\n\n", $parts);
            }
        }

        // Single ask_text_0_markdown (complete answer)
        if ($askText0 !== null && $askText0 !== '') {
            return $askText0;
        }

        // Fallback: ask_text
        if ($askText !== null && $askText !== '') {
            return $askText;
        }

        // If ask_text_0_markdown exists but empty, still return null
        return null;
    }

    // ─── Thread UUID ──────────────────────────────────────────────

    private function getThreadUuid(array $entry): string
    {
        return $entry['backend_uuid'] ?? $entry['uuid'] ?? 'unknown';
    }

    // ─── Generated images ─────────────────────────────────────────

    /**
     * @return array<int, array{
     *     s3_path: string,
     *     uuid: string,
     *     name: string,
     *     prompt: string,
     *     model: string,
     *     thumbnail_url: string,
     *     signed_url: ?string,
     *     width: int|null,
     *     height: int|null,
     * }>
     */
    private function collectGeneratedImages(array $entry): array
    {
        $images = [];
        $blocks = $entry['blocks'] ?? [];

        // Primary: unified_assets
        foreach ($blocks as $b) {
            if (($b['intended_usage'] ?? '') !== 'unified_assets') {
                continue;
            }
            foreach (($b['unified_assets_block']['assets'] ?? []) as $asset) {
                $assetType = $asset['asset_type'] ?? '';
                if (!in_array($assetType, self::GENERATED_IMAGE_ASSET_TYPES, true)) {
                    continue;
                }
                $gen = $asset['generated_image'] ?? [];
                $thumbnailUrl = $gen['thumbnail_url'] ?? $gen['url'] ?? '';
                $signedUrl = $gen['url'] ?? null;
                $s3Path = $this->s3KeyFromUrl($thumbnailUrl);

                $images[] = [
                    's3_path' => $s3Path,
                    'uuid' => $asset['backend_uuid_slug'] ?? $asset['uuid'] ?? '',
                    'name' => $asset['name'] ?? '',
                    'prompt' => '',
                    'model' => '',
                    'thumbnail_url' => $thumbnailUrl,
                    'signed_url' => $signedUrl,
                    'width' => $gen['image_width'] ?? null,
                    'height' => $gen['image_height'] ?? null,
                ];
            }
        }

        // Supplement from media_items / answer_generated_image / assets_answer_mode for prompt & model
        foreach ($blocks as $b) {
            $usage = $b['intended_usage'] ?? '';
            $mediaBlock = null;

            if ($usage === 'media_items') {
                $mediaBlock = $b['media_block'] ?? null;
            } elseif ($usage === 'answer_generated_image') {
                $mediaBlock = $b['inline_entity_block']['media_block'] ?? null;
            } elseif ($usage === 'assets_answer_mode') {
                // assets_answer_mode doesn't have media items, skip
                continue;
            }

            if ($mediaBlock === null) {
                continue;
            }

            $allItems = array_merge(
                $mediaBlock['media_items'] ?? [],
                $mediaBlock['generated_media_items'] ?? [],
            );

            foreach ($allItems as $mi) {
                $meta = $mi['generated_media_metadata'] ?? [];
                if (empty($meta)) {
                    continue;
                }

                // Try to match with existing image by thumbnail/image URL path
                $miThumb = $mi['thumbnail'] ?? $mi['image'] ?? '';
                $miPath = $this->s3KeyFromUrl($miThumb);
                $matched = false;

                foreach ($images as &$img) {
                    if ($img['s3_path'] === $miPath || ($img['s3_path'] !== '' && $miPath !== '' && basename($img['s3_path']) === basename($miPath))) {
                        $img['prompt'] = $img['prompt'] !== '' ? $img['prompt'] : ($meta['prompt'] ?? '');
                        $img['model'] = $img['model'] !== '' ? $img['model'] : ($meta['model_str'] ?? '');
                        $img['name'] = $img['name'] !== '' ? $img['name'] : ($mi['name'] ?? '');
                        $matched = true;
                        break;
                    }
                }
                unset($img);

                // If no match found, add as new image
                if (!$matched && $miPath !== '') {
                    $images[] = [
                        's3_path' => $miPath,
                        'uuid' => '',
                        'name' => $mi['name'] ?? '',
                        'prompt' => $meta['prompt'] ?? '',
                        'model' => $meta['model_str'] ?? '',
                        'thumbnail_url' => $mi['thumbnail'] ?? $mi['image'] ?? '',
                        'signed_url' => $mi['image'] ?? $mi['thumbnail'] ?? null,
                        'width' => $mi['image_width'] ?? null,
                        'height' => $mi['image_height'] ?? null,
                    ];
                }
            }
        }

        return $images;
    }

    // ─── Attachments ──────────────────────────────────────────────

    /**
     * @return array<int, array{
     *     s3_key: string,
     *     name: string,
     *     url: string,
     *     snippet: string,
     *     num_characters: int|null,
     * }>
     */
    private function collectAttachments(array $entry): array
    {
        $attachments = [];
        $seen = [];

        foreach (($entry['blocks'] ?? []) as $b) {
            if (($b['intended_usage'] ?? '') !== 'web_results') {
                continue;
            }
            foreach (($b['web_result_block']['web_results'] ?? []) as $wr) {
                if (empty($wr['is_attachment'])) {
                    continue;
                }

                $url = $wr['url'] ?? '';
                $fm = $wr['file_metadata'] ?? [];
                $rawKey = $fm['raw_file_s3_key'] ?? null;
                $s3Key = $this->s3KeyFrom($url, $rawKey);

                if (isset($seen[$s3Key])) {
                    // Keep first non-empty snippet
                    if (($attachments[$seen[$s3Key]]['snippet'] ?? '') === '') {
                        $attachments[$seen[$s3Key]]['snippet'] = $wr['snippet'] ?? '';
                    }
                    continue;
                }

                $seen[$s3Key] = count($attachments);
                $attachments[] = [
                    's3_key' => $s3Key,
                    'name' => $wr['name'] ?? '',
                    'url' => $url,
                    'snippet' => $wr['snippet'] ?? '',
                    'num_characters' => $fm['num_characters'] ?? null,
                ];
            }
        }

        return array_values($attachments);
    }

    // ─── S3 path extraction ───────────────────────────────────────

    private function s3KeyFrom(string $url, ?string $rawFileS3Key = null): string
    {
        if ($rawFileS3Key !== null && $rawFileS3Key !== '') {
            return ltrim($rawFileS3Key, '/');
        }
        return $this->s3KeyFromUrl($url);
    }

    private function s3KeyFromUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parsed = parse_url($url);
        return ltrim($parsed['path'] ?? '', '/');
    }

    private function extractDomain(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        // Strip www.
        return preg_replace('/^www\./', '', $host);
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
                // Merge source_threads
                $existingThreads = $existing[$key]['source_threads'] ?? [];
                $newThreads = $entry['source_threads'] ?? [];
                foreach ($newThreads as $t) {
                    if (!in_array($t, $existingThreads, true)) {
                        $existingThreads[] = $t;
                    }
                }
                $existing[$key]['source_threads'] = $existingThreads;
                $existing[$key]['last_seen'] = $entry['last_seen'] ?? $existing[$key]['last_seen'] ?? '';
                // Update signed URL if we got a new one
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

    private function buildMarkdown(
        string $title,
        string $threadUuid,
        string $model,
        string $createdAt,
        array $questions,
        array $goals,
        array $answers,
        array $citations,
        array $images,
        array $attachments,
        array $widgets,
        array $workflowQueries,
        array $mediaIndex,
    ): string {
        $md = '';

        // Header
        $md .= "Title: {$title}\n";
        $md .= "Thread UUID: {$threadUuid}\n";
        $md .= "Model: {$model}\n";
        $md .= "Created: {$createdAt}\n";
        $md .= "\n";

        // Question
        $md .= "## Question\n";
        foreach ($questions as $q) {
            $md .= "{$q}\n\n";
        }

        // Plan
        if (!empty($goals)) {
            $md .= "## Plan\n";
            foreach ($goals as $g) {
                $md .= "- {$g}\n";
            }
            $md .= "\n";
        }

        // Answer
        if (!empty($answers)) {
            $md .= "## Answer\n";
            foreach ($answers as $answer) {
                $md .= "{$answer}\n\n";
            }
        }

        // Citations
        if (!empty($citations)) {
            $md .= "## Citations\n";
            foreach ($citations as $c) {
                $num = $c['number'] ?? '?';
                $name = $c['name'] ?? 'Untitled';
                $domain = $c['domain'] ?? '';
                $snippet = $c['snippet'] ?? '';
                $snippetShort = mb_strlen($snippet) > 240 ? mb_substr($snippet, 0, 240) . '...' : $snippet;

                $line = "[{$num}] **{$name}**";
                if ($domain !== '') {
                    $line .= " — {$domain}";
                }
                if ($snippetShort !== '') {
                    $line .= " — {$snippetShort}";
                }
                $md .= "{$line}\n";
            }
            $md .= "\n";
        }

        // Generated images
        if (!empty($images)) {
            $md .= "## Generated images\n";
            foreach ($images as $img) {
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
                    // Check if in media index with signed URL
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
        if (!empty($attachments)) {
            $md .= "## Attachments\n";
            foreach ($attachments as $att) {
                $name = $att['name'] ?? 'Untitled';
                $chars = $att['num_characters'] ?? null;
                $s3Key = $att['s3_key'] ?? '';
                $snippet = $att['snippet'] ?? '';
                $snippetShort = mb_strlen($snippet) > 240 ? mb_substr($snippet, 0, 240) . '...' : $snippet;

                $line = "- **{$name}**";
                if ($chars !== null) {
                    $line .= " ({$chars} chars)";
                }
                if ($s3Key !== '') {
                    $line .= " — local: ../../medias/{$s3Key}";
                }
                $md .= "{$line}\n";
                if ($snippetShort !== '') {
                    $md .= "  snippet: \"{$snippetShort}\"\n";
                }
            }
            $md .= "\n";
        }

        // Widgets
        if (!empty($widgets)) {
            $md .= "## Widgets\n";
            // Group by type
            $byType = [];
            foreach ($widgets as $w) {
                $byType[$w['type'] ?? 'unknown'][] = $w;
            }

            foreach ($byType as $type => $items) {
                if ($type === 'finance') {
                    $md .= "### Finance\n\n";
                    $md .= "| Symbol | Name | Price | Change | Change % | Market Cap | Exchange |\n";
                    $md .= "|--------|------|-------|--------|----------|------------|----------|\n";
                    foreach ($items as $item) {
                        $symbol = $item['symbol'] ?? '';
                        $name = $item['name'] ?? '';
                        $price = $item['price'] !== null ? number_format($item['price'], 2) : '-';
                        $change = $item['change'] !== null ? number_format($item['change'], 2) : '-';
                        $changePct = $item['changePercent'] !== null ? number_format($item['changePercent'], 2) . '%' : '-';
                        $mktCap = $item['marketCap'] !== null ? $this->formatLargeNumber($item['marketCap']) : '-';
                        $exchange = $item['exchange'] ?? '';
                        $md .= "| {$symbol} | {$name} | {$price} | {$change} | {$changePct} | {$mktCap} | {$exchange} |\n";
                    }
                    $md .= "\n";
                } else {
                    $md .= "### {$type}\n";
                    foreach ($items as $item) {
                        $md .= "- " . json_encode($item, JSON_UNESCAPED_UNICODE) . "\n";
                    }
                    $md .= "\n";
                }
            }
        }

        // Workflow
        if (!empty($workflowQueries)) {
            $md .= "## Workflow\n";
            $md .= "Queries executed:\n";
            foreach ($workflowQueries as $q) {
                $md .= "- {$q}\n";
            }
            $md .= "\n";
        }

        // Local media manifest reference
        $hasMedia = !empty($images) || !empty($attachments);
        if ($hasMedia) {
            $md .= "## Local media manifest\n";
            $md .= "See [medias/index.json](../../medias/index.json)\n";
        }

        return $md;
    }

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
