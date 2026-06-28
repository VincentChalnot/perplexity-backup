<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Centralizes all path construction for the backup directory layout.
 *
 * Layout:
 *   {dataDir}/conversations.json
 *   {dataDir}/conversations.md
 *   {dataDir}/conversations/{uuid}/conversation.json
 *   {dataDir}/conversations/{uuid}/conversation.md
 *   {dataDir}/conversations/{uuid}/conversation_meta.json
 *   {dataDir}/medias/index.json
 */
readonly class BackupPaths
{
    public function __construct(
        private string $conversationsPath,
    ) {
    }

    public function conversationsList(): string
    {
        return "{$this->conversationsPath}/conversations.json";
    }

    public function conversationsIndex(): string
    {
        return "{$this->conversationsPath}/conversations.md";
    }

    public function conversationsDir(): string
    {
        return "{$this->conversationsPath}/conversations";
    }

    public function threadDir(string $uuid): string
    {
        return "{$this->conversationsPath}/conversations/{$uuid}";
    }

    public function threadJson(string $uuid): string
    {
        return "{$this->conversationsPath}/conversations/{$uuid}/conversation.json";
    }

    public function threadMarkdown(string $uuid): string
    {
        return "{$this->conversationsPath}/conversations/{$uuid}/conversation.md";
    }

    public function threadMeta(string $uuid): string
    {
        return "{$this->conversationsPath}/conversations/{$uuid}/conversation_meta.json";
    }

    public function mediaIndex(): string
    {
        return "{$this->conversationsPath}/medias/index.json";
    }

    public function mediaFile(string $relativePath): string
    {
        return "{$this->conversationsPath}/medias/{$relativePath}";
    }

    public function dataDir(): string
    {
        return $this->conversationsPath;
    }
}
