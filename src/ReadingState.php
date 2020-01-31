<?php

namespace SilverStripe\Versioned;

class ReadingState
{
    /**
     * Invoke a callback which may modify reading mode, but ensures this mode is restored
     * after completion, without modifying global state.
     *
     * The desired reading mode should be set by the callback directly
     *
     * @param callable $callback
     * @return mixed Result of $callback
     */
    public static function withVersionedMode(callable $callback)
    {
        $originalReadingMode = Backend::singleton()->getReadingMode();

        try {
            return $callback();
        } finally {
            Backend::singleton()->setReadingMode($originalReadingMode);
        }
    }
}
