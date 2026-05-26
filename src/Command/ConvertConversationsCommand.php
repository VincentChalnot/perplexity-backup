<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:convert-conversations', description: 'Convert json conversation files to markdown')]
class ConvertConversationsCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $kernelProjectDir,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = "{$this->kernelProjectDir}/var/data/conversations";
        $finder = new Finder();
        $finder->in($path)->sortByName()->name('*.json')->files();
        foreach ($finder as $i) {
            $array = json_decode($i->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $text = $this->convertConversation($array);
            file_put_contents("{$path}/{$i->getBasename('.json')}.md", $text);
        }

        return Command::SUCCESS;
    }

    private function convertConversation(array $conversation): string
    {
        $text = '';
        foreach ($conversation['entries'] as $entry) {
            $text .= "## User:\n{$entry['query_str']}\n\n";
            foreach ($entry['blocks'] as $block) {
                if ($block['intended_usage'] !== 'ask_text') {
                    continue;
                }
                $text .= "## {$entry['display_model']}:\n{$block['markdown_block']['answer']}\n\n";
            }
        }

        return $text;
    }
}
