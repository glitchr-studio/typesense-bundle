<?php

declare(strict_types=1);

namespace Typesense\Bundle\DBAL;

use SensitiveParameter;

class Configuration
{
    protected ?string $secret;
    protected array $options;

    protected string $scheme;
    protected string $host;
    protected string $path;

    protected int $port;

    public function __toString(): string
    {
        return $this->getEndpoint();
    }

    public function __construct(#[SensitiveParameter] ?string $secret, array $params, array $options = [])
    {
        $this->scheme = $params['scheme'] ?? 'http';
        $this->host = $params['host'] ?? 'localhost';

        $this->port = $params['port'] ?? 8108;

        $this->path = trim((string) ($params['path'] ?? ''), '/');
        $this->path = $this->path ? ("/".$this->path) : '';

        $this->secret = $secret;
        $this->options = $options;
    }

    /**
     * @return string|null
     */
    public function getSecret()
    {
        return $this->secret;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return array
     */
    public function getNode()
    {
        return ['host' => $this->host, 'port' => $this->port, 'protocol' => $this->scheme];
    }

    /**
     * @param string $path
     * @return string
     */
    public function getEndpoint(string $path = '')
    {
        return sprintf('%s://%s:%d%s%s', $this->scheme, $this->host, $this->port, $this->path, $path);
    }
}
