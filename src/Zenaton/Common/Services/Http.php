<?php

namespace Zenaton\Common\Services;

use Httpful\Request as Httpful;
use Zenaton\Common\Services\Metrics;
use Httpful\Exception\ConnectionErrorException;
use stdClass;
use Exception;

class Http
{
    public function __construct()
    {
        $this->metrics = Metrics::getInstance();
    }

    public function post($url, $body)
    {
        $start = microtime(true);

        try {
            $response = Httpful::post($url)
                ->sendsJson()
                ->body($body)
                ->expectsJson()
                ->send();

            return $response->body;

        } catch (ConnectionErrorException $e) {

            $response = new stdClass();
            $response->error = "Connection Problem. Please Check that you've started a zenaton_worker or ensure that PORT 4001 is available.";

            return $response;
        }

        $this->metrics->addNetworkDuration(microtime(true) - $start);

    }

    public function get($url)
    {
        $start = microtime(true);

        $response = Httpful::get($url)
            ->expectsJson()
            ->send();

        $this->metrics->addNetworkDuration(microtime(true) - $start);

        return $response->body;
    }

    public function put($url, $body)
    {
        $start = microtime(true);

        $response = Httpful::put($url)
            ->sendsJson()
            ->body($body)
            ->expectsJson()
            ->send();

        $this->metrics->addNetworkDuration(microtime(true) - $start);

        return $response->body;
    }
}
