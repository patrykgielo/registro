<?php

if (! function_exists('clean')) {
    /**
     * Clean HTML content using HTMLPurifier to prevent XSS attacks.
     *
     * @param  string  $dirty  The HTML content to clean
     * @param  string|null  $config  Optional purifier configuration name
     * @return string The cleaned HTML content
     */
    function clean(string $dirty, ?string $config = null): string
    {
        return app('purifier')->clean($dirty, $config);
    }
}
