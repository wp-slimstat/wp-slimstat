<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\BrowscapPHP\Command;

use function assert;
use function is_string;

use SlimStat\Dependencies\BrowscapPHP\BrowscapUpdater;
use SlimStat\Dependencies\BrowscapPHP\Exception\ErrorReadingFileException;
use SlimStat\Dependencies\BrowscapPHP\Exception\FileNameMissingException;
use SlimStat\Dependencies\BrowscapPHP\Exception\FileNotFoundException;
use SlimStat\Dependencies\BrowscapPHP\Helper\LoggerHelper;
use SlimStat\Dependencies\League\Flysystem\Filesystem;
use SlimStat\Dependencies\League\Flysystem\Local\LocalFilesystemAdapter;
use SlimStat\Dependencies\MatthiasMullie\Scrapbook\Adapters\Flysystem;
use SlimStat\Dependencies\MatthiasMullie\Scrapbook\Psr16\SimpleCache;
use SlimStat\Dependencies\Symfony\Component\Console\Command\Command;
use SlimStat\Dependencies\Symfony\Component\Console\Exception\InvalidArgumentException;
use SlimStat\Dependencies\Symfony\Component\Console\Exception\LogicException;
use SlimStat\Dependencies\Symfony\Component\Console\Input\InputArgument;
use SlimStat\Dependencies\Symfony\Component\Console\Input\InputInterface;
use SlimStat\Dependencies\Symfony\Component\Console\Input\InputOption;
use SlimStat\Dependencies\Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

use Throwable;

/**
 * Command to convert a downloaded Browscap ini file and write it to the cache
 *
 * @internal This extends Symfony API, and we do not want to expose upstream BC breaks, so we DO NOT promise BC on this
 */
class ConvertCommand extends Command
{
    public const FILENAME_MISSING = 6;

    public const FILE_NOT_FOUND = 7;

    public const ERROR_READING_FILE = 8;

    private ?string $defaultIniFile = null;

    private ?string $defaultCacheFolder = null;

    /**
     * @throws LogicException
     */
    public function __construct(string $defaultCacheFolder, string $defaultIniFile)
    {
        $this->defaultCacheFolder = $defaultCacheFolder;
        $this->defaultIniFile     = $defaultIniFile;

        parent::__construct();
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function configure(): void
    {
        $this
            ->setName('browscap:convert')
            ->setDescription('Converts an existing browscap.ini file to a cache.php file.')
            ->addArgument(
                'file',
                InputArgument::OPTIONAL,
                'Path to the browscap.ini file',
                $this->defaultIniFile
            )
            ->addOption(
                'cache',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Where the cache files are located',
                $this->defaultCacheFolder
            );
    }

    /**
     * @throws InvalidArgumentException
     * @throws \InvalidArgumentException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = LoggerHelper::createDefaultLogger($output);

        $cacheOption = $input->getOption('cache');
        assert(is_string($cacheOption));

        $adapter    = new LocalFilesystemAdapter($cacheOption);
        $filesystem = new Filesystem($adapter);
        $cache      = new SimpleCache(
            new Flysystem($filesystem)
        );

        $logger->info('initializing converting process');

        $browscap = new BrowscapUpdater($cache, $logger);

        $logger->info('started converting local file');

        $file = $input->getArgument('file');
        assert(is_string($file));
        if ('' === $file || '0' === $file) {
            $file = $this->defaultIniFile;
        }

        if (null === $file) {
            return self::FILENAME_MISSING;
        }

        $output->writeln(sprintf('converting file %s', $file));

        try {
            $browscap->convertFile($file);
        } catch (FileNameMissingException $e) {
            $logger->debug($e);

            return self::FILENAME_MISSING;
        } catch (FileNotFoundException $e) {
            $logger->debug($e);

            return self::FILE_NOT_FOUND;
        } catch (ErrorReadingFileException $e) {
            $logger->debug($e);

            return self::ERROR_READING_FILE;
        } catch (Throwable $e) {
            $logger->info($e);

            return CheckUpdateCommand::GENERIC_ERROR;
        }

        $logger->info('finished converting local file');

        return self::SUCCESS;
    }
}
