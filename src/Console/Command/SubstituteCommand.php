<?php

namespace Tempa\Console\Command;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tempa\Console\Helper\ArgumentParser;
use Tempa\Core\Exception\SubstituteException;
use Tempa\Core\Options;
use Tempa\Core\Processor;
use Tempa\Instrument\FileSystem\FileIterator;

class SubstituteCommand extends AbstractCommand
{

    protected function configure()
    {
        parent::configure();

        $this->addArgument('map', InputOption::VALUE_OPTIONAL);

        $this->setName('file:substitute')
             ->setDescription('Parse source directory for templates files replacing placeholders')
             ->setHelp(
                 <<<EOT
Iterate a given directory searching for template files.
This will replace found placeholders with a set up value.
EOT
             );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $config = $input->getOption('config');
        $configPath = stream_resolve_include_path($config);

        $map = ArgumentParser::parseMapping($input->getArgument('map') ?: []);

        $rootDirectory = $input->getArgument('dir');
        $rootPath = stream_resolve_include_path($rootDirectory);

        // If config is empty, try reading it from current execution path
        if ($config === null) {
            $configPath = $configPath . DIRECTORY_SEPARATOR . 'tempa.json';
        }

        if (!is_readable($configPath)) {
            throw new \InvalidArgumentException("Config not readable: {$configPath}");
        }

        $io->title("Processing template files in: {$rootPath}");

        $config = file_get_contents($configPath);
        $options = new Options(json_decode($config, true));
        $iterator = new FileIterator($rootPath, $options->fileExtensions);

        /** @var \SplFileInfo[]|\CallbackFilterIterator $result */
        $result = $iterator->iterate();
        $processor = new Processor($options);

        $io->progressStart(count(iterator_to_array($result)));
        /** @var \SubstituteException[] $fileErrors */
        $fileErrors = [];

        foreach ($result as $file) {

            if (!isset($fileErrors[$file->getPathname()])) {
                $fileErrors[$file->getPathname()] = [];
            }

            try {
                $processor->substitute(new \SplFileObject($file->getPathname()), $map);
            } catch (SubstituteException $e) {
                $fileErrors[$file->getPathname()][] = $e;
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        foreach ($fileErrors as $file => $errors) {

            if (empty($errors)) {
                continue;
            }

            $io->section($file);

            foreach ($errors as $error) {
                $io->note($error->getMessage());
            }
        }
    }
}
