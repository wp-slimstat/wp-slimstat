<?php

namespace SlimStat\Components;

class RemoteRequest
{
    private $url;
    private $method;
    private $params;
    private $args;
    private $response;
    private $responseCode;

    public function __construct($url, $method = 'GET', $params = [], $args = [])
    {
        $this->url = $url;
        $this->method = strtoupper($method);
        $this->params = $params;
        $this->args = $args;
    }

    public function execute($decode = true, $throw = true)
    {
        if ($this->method === 'GET' && !empty($this->params)) {
            $this->url .= '?' . http_build_query($this->params);
        }

        if ($this->method === 'POST' && !empty($this->params)) {
            $this->args['body'] = $this->params;
        }

        $this->response = \wp_remote_request($this->url, \array_merge([
            'method' => $this->method,
        ], $this->args));

        if (\is_wp_error($this->response)) {
            if ($throw) {
                throw new \Exception($this->response->get_error_message());
            }
            return false;
        }

        $this->responseCode = \wp_remote_retrieve_response_code($this->response);

        return true;
    }

    public function getResponseBody()
    {
        if (\is_wp_error($this->response)) {
            return null;
        }

        return \wp_remote_retrieve_body($this->response);
    }

    public function getResponseCode()
    {
        return $this->responseCode;
    }

    public function getResponse()
    {
        return $this->response;
    }
}
