<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\SchemaGenerator\Command;

use ApiPlatform\SchemaGenerator\CardinalitiesExtractor;
use ApiPlatform\SchemaGenerator\GoodRelationsBridge;
use ApiPlatform\SchemaGenerator\PhpTypeConverter;
use ApiPlatform\SchemaGenerator\Printer;
use ApiPlatform\SchemaGenerator\TypesGenerator;
use ApiPlatform\SchemaGenerator\TypesGeneratorConfiguration;
use Doctrine\Inflector\InflectorFactory;
use EasyRdf\Graph as RdfGraph;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Parser;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

/**
 * Generate entities command.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class GenerateCommand extends Command
{
    private const DEFAULT_CONFIG_FILE = 'schema.yaml';

    private ?string $namespacePrefix = null;
    private ?string $defaultOutput = null;

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->readComposer();

        $this
            ->setName('generate')
            ->setDescription('Generate the PHP code')
            ->addArgument('output', $this->defaultOutput ? InputArgument::OPTIONAL : InputArgument::REQUIRED, 'The output directory', $this->defaultOutput)
            ->addArgument('config', InputArgument::OPTIONAL, 'The config file to use (default to "schema.yaml" in the current directory, will generate all types if no config file exists)');
    }

    private function readComposer(): void
    {
        if (file_exists('composer.json') && is_file('composer.json') && is_readable('composer.json')) {
            if (false === ($composerContent = file_get_contents('composer.json'))) {
                throw new \RuntimeException('Cannot read composer.json content.');
            }
            $composer = json_decode($composerContent, true, 512, \JSON_THROW_ON_ERROR);
            foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $output) {
                if ('' === $prefix) {
                    continue;
                }

                $this->namespacePrefix = $prefix;
                $this->defaultOutput = $output;

                break;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $defaultOutput = $this->defaultOutput ? realpath($this->defaultOutput) : null;
        $outputDir = $input->getArgument('output');
        $configArgument = $input->getArgument('config');

        if ($dir = realpath($outputDir)) {
            if (!is_dir($dir)) {
                if (!$defaultOutput) {
                    throw new \InvalidArgumentException(sprintf('The file "%s" is not a directory.', $dir));
                }

                $dir = $defaultOutput;
                $configArgument = $outputDir;
            }

            if (!is_writable($dir)) {
                throw new \InvalidArgumentException(sprintf('The "%s" directory is not writable.', $dir));
            }

            $outputDir = $dir;
        } else {
            (new Filesystem())->mkdir($outputDir);
            $outputDir = realpath($outputDir);
        }

        if ($configArgument) {
            if (!file_exists($configArgument)) {
                throw new \InvalidArgumentException(sprintf('The file "%s" doesn\'t exist.', $configArgument));
            }

            if (!is_file($configArgument)) {
                throw new \InvalidArgumentException(sprintf('"%s" isn\'t a file.', $configArgument));
            }

            if (!is_readable($configArgument)) {
                throw new \InvalidArgumentException(sprintf('The file "%s" isn\'t readable.', $configArgument));
            }

            if (false === ($configContent = file_get_contents($configArgument))) {
                throw new \RuntimeException(sprintf('Cannot read "%s" content.', $configArgument));
            }

            $parser = new Parser();
            $config = $parser->parse($configContent);
            unset($parser);
        } elseif (is_readable(self::DEFAULT_CONFIG_FILE)) {
            if (false === ($defaultConfigContent = file_get_contents(self::DEFAULT_CONFIG_FILE))) {
                throw new \RuntimeException(sprintf('Cannot read "%s" content.', self::DEFAULT_CONFIG_FILE));
            }

            $parser = new Parser();
            $config = $parser->parse($defaultConfigContent);
            unset($parser);
        } else {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Your project has no config file. The entire vocabulary will be imported.'.\PHP_EOL.'Continue? [yN]', false);

            if (!$helper->ask($input, $output, $question)) {
                return 0;
            }

            $config = [];
        }

        $processor = new Processor();
        $configuration = new TypesGeneratorConfiguration($dir === $defaultOutput ? $this->namespacePrefix : null);
        /** @var Configuration */
        $processedConfiguration = $processor->processConfiguration($configuration, [$config]);
        $processedConfiguration['output'] = $outputDir;
        if (!$processedConfiguration['output']) {
            throw new \RuntimeException('The specified output is invalid');
        }

        $graphs = [];
        foreach ($processedConfiguration['vocabularies'] as $vocab) {
            $graph = new RdfGraph();
            if (0 === strpos($vocab['uri'], 'http://') || 0 === strpos($vocab['uri'], 'https://')) {
                $graph->load($vocab['uri'], $vocab['format']);
            } else {
                $graph->parseFile($vocab['uri'], $vocab['format']);
            }

            $graphs[] = $graph;
        }

        $relations = [];
        foreach ($processedConfiguration['relations'] as $relation) {
            $relations[] = new \SimpleXMLElement($relation, 0, true);
        }

        $goodRelationsBridge = new GoodRelationsBridge($relations);
        $cardinalitiesExtractor = new CardinalitiesExtractor($graphs, $goodRelationsBridge);

        $templatePaths = $processedConfiguration['generatorTemplates'];
        $templatePaths[] = __DIR__.'/../../templates/';

        $inflector = InflectorFactory::create()->build();

        $loader = new FilesystemLoader($templatePaths);
        $twig = new Environment($loader, ['autoescape' => false, 'debug' => $processedConfiguration['debug']]);

        if ($processedConfiguration['debug']) {
            $twig->addExtension(new DebugExtension());
        }

        $logger = new ConsoleLogger($output);

        $entitiesGenerator = new TypesGenerator(
            $inflector,
            $twig,
            $logger,
            $graphs,
            new PhpTypeConverter(),
            $cardinalitiesExtractor,
            $goodRelationsBridge,
            new Printer()
        );

        $entitiesGenerator->generate($processedConfiguration);

        return 0;
    }
}
