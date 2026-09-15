<?php

namespace SlimStat\Exception;

use Exception;
use wp_slimstat as SlimStat;

class LogException extends Exception
{
    public function __construct($message, $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);

        SlimStat::log($this->generateLogMessage($message, $code), 'error');
    }

    private function generateLogMessage($message, $code)
    {
        return sprintf(
            /* translators: 1: exception code, 2: message, 3: source file, 4: line number. */
            __('Exception occurred: [Code %1$d] %2$s at %3$s:%4$d', 'wp-slimstat'),
            $code,
            $message,
            $this->getFile(),
            $this->getLine()
        );
    }
}
