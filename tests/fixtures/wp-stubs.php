<?php

// Minimal stand-ins for the WordPress HTTP API, loaded only by WordPressTransportTest (separate process).

class WP_Error
{
    public function __construct(private string $code = '', private string $message = '')
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

/** Like WpOrg\Requests\Utility\CaseInsensitiveDictionary: iterable, with getAll(). */
class Astro96WpHeaders implements IteratorAggregate
{
    public function __construct(private array $data)
    {
    }

    public function getAll(): array
    {
        return $this->data;
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->data);
    }
}

function wp_remote_request(string $url, array $args = [])
{
    $GLOBALS['wp_calls'][] = [$url, $args];
    return array_shift($GLOBALS['wp_replies']);
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}
