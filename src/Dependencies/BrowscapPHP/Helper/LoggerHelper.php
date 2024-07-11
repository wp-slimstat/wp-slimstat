<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\BrowscapPHP\Helper;

use Monolog\ErrorHandler;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use SlimStat\Dependencies\Psr\Log\LoggerInterface;
use SlimStat\Dependencies\Symfony\Component\Console\Logger\ConsoleLogger;
use SlimStat\Dependencies\Symfony\Component\Console\Output\OutputInterface;

use function assert;
use function is_callable;

final class LoggerHelper
{
    private function __construct()
    {
    }

    /**
     * creates a \Monolog\Logger instance
     *
     * @throws void
     */
    public static function createDefaultLogger(OutputInterface $output): LoggerInterface
    {
        $logger        = new Logger('browscap');
        $consoleLogger = new ConsoleLogger($output);
        $psrHandler    = new PsrHandler($consoleLogger);

        $logger->pushHandler($psrHandler);

        $memoryProcessor = new MemoryUsageProcessor(true);
        assert(is_callable($memoryProcessor));
        $logger->pushProcessor($memoryProcessor);

        $peakMemoryProcessor = new MemoryPeakUsageProcessor(true);
        assert(is_callable($peakMemoryProcessor));
        $logger->pushProcessor($peakMemoryProcessor);

        ErrorHandler::register($logger);

        return $logger;
    }
}
