<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\ConvertCommandHelper;
use App\Processor\AnswerProcessor;
use App\Processor\AttachmentProcessor;
use App\Processor\CitationProcessor;
use App\Processor\EntryMetadataProcessor;
use App\Processor\FinanceWidgetProcessor;
use App\Processor\GeneratedImageProcessor;
use App\Processor\PlanProcessor;
use App\Processor\QuestionProcessor;
use App\Processor\WorkflowProcessor;
use PHPUnit\Framework\TestCase;

class ConvertCommandHelperTest extends TestCase
{
    private ConvertCommandHelper $helper;

    protected function setUp(): void
    {
        $processors = [
            new EntryMetadataProcessor(),
            new QuestionProcessor(),
            new AnswerProcessor(),
            new CitationProcessor(),
            new GeneratedImageProcessor(),
            new AttachmentProcessor(),
            new PlanProcessor(),
            new FinanceWidgetProcessor(),
            new WorkflowProcessor(),
        ];
        $this->helper = new ConvertCommandHelper($processors);
    }

    public function testConvertSimpleConversation(): void
    {
        $conversation = $this->fixture('simple-conversation.json');

        $result = $this->helper->convertConversation($conversation);

        $this->assertArrayHasKey('markdown', $result);
        $this->assertArrayHasKey('mediaIndex', $result);
        $this->assertArrayHasKey('conversationMeta', $result);

        $md = $result['markdown'];

        // Frontmatter present
        $this->assertStringContainsString('---', $md);
        $this->assertStringContainsString('thread_uuid:', $md);
        $this->assertStringContainsString('model:', $md);

        // Question rendered
        $this->assertStringContainsString('What is PHP?', $md);

        // Answer rendered
        $this->assertStringContainsString('PHP is a general-purpose scripting language', $md);

        // Metadata
        $this->assertSame('test-thread-001', $result['conversationMeta']['thread_uuid']);
        $this->assertSame('What is PHP?', $result['conversationMeta']['title']);
    }

    public function testConvertConversationWithSources(): void
    {
        $conversation = $this->fixture('conversation-with-sources.json');

        $result = $this->helper->convertConversation($conversation);
        $md = $result['markdown'];

        // Source types in frontmatter
        $this->assertStringContainsString('source_types:', $md);
        $this->assertStringContainsString('web', $md);
        $this->assertStringContainsString('scholar', $md);
    }

    public function testConvertEmptyEntries(): void
    {
        $conversation = [
            'uuid' => 'empty-thread',
            'title' => '',
            'created_at' => '2025-01-01T00:00:00Z',
            'entries' => [],
        ];

        $result = $this->helper->convertConversation($conversation);

        $this->assertSame('', $result['markdown']);
        $this->assertSame([], $result['mediaIndex']);
    }

    public function testMergeMediaIndex(): void
    {
        $existing = [
            'img/1.png' => [
                'local_path' => 'medias/img/1.png',
                'source_threads' => ['thread-a'],
                'last_seen' => '2025-01-01',
                'url_signed' => null,
                'expires_at' => null,
                'size_bytes' => null,
                'sha256' => null,
                'type' => 'generated_image',
                'name' => 'test.png',
                'first_seen' => '2025-01-01',
            ],
        ];
        $new = [
            'img/1.png' => [
                'local_path' => 'medias/img/1.png',
                'source_threads' => ['thread-b'],
                'last_seen' => '2025-06-01',
                'url_signed' => null,
                'expires_at' => null,
                'size_bytes' => null,
                'sha256' => null,
                'type' => 'generated_image',
                'name' => 'test.png',
                'first_seen' => '2025-06-01',
            ],
            'img/2.png' => [
                'local_path' => 'medias/img/2.png',
                'source_threads' => ['thread-b'],
                'last_seen' => '2025-06-01',
                'url_signed' => null,
                'expires_at' => null,
                'size_bytes' => null,
                'sha256' => null,
                'type' => 'generated_image',
                'name' => 'test2.png',
                'first_seen' => '2025-06-01',
            ],
        ];

        $merged = $this->helper->mergeMediaIndex($existing, $new);

        // Existing entry merged with new thread
        $this->assertContains('thread-a', $merged['img/1.png']['source_threads']);
        $this->assertContains('thread-b', $merged['img/1.png']['source_threads']);
        $this->assertSame('2025-06-01', $merged['img/1.png']['last_seen']);

        // New entry added
        $this->assertArrayHasKey('img/2.png', $merged);
    }

    private function fixture(string $name): array
    {
        $path = __DIR__ . '/../fixtures/' . $name;
        $this->assertFileExists($path, "Fixture not found: {$name}");

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
