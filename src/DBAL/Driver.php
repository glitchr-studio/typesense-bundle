<?php

declare(strict_types=1);

namespace Typesense\Bundle\DBAL;

use SensitiveParameter;
use Typesense\Bundle\Exception\TypesenseException;
use Typesense\Client;
use Typesense\Exceptions\ConfigError;

class Driver
{
    public const NODES = 'nodes';
    public const API_KEY = 'api_key';
    public const CONNECTION_TIMOUT_SECONDS = 'connection_timeout_seconds';

    public string $name;
    private ?Client $client = null;
    private ?Configuration $configuration = null;
    private ?ConfigError $configError = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function prepare(#[SensitiveParameter] array $params): Configuration
    {
        if (!$this->configuration) {

            // API Key extraction
            $secret = $params['secret'] ?? null;

            if (!$secret) {
                if (\PHP_SAPI === 'cli') {
                    throw new TypesenseException('Typesense API Key missing for "' . $this->name . '" connection');
                }

                return new Configuration(null, [], []);
            }

            // Parsing URL: return array
            $parsedUrl = parse_url($params['url'] ?? '');
            if ($parsedUrl) {
                $params = array_merge($params, array_filter($parsedUrl));
            }

            // Options
            $options = $params['options'] ?? [];
            $this->configuration = new Configuration($secret, $params, $options);
        }

        return $this->configuration;
    }

    public function connect(#[SensitiveParameter] array $params): ?Client
    {
        if (!$this->client) {
            $this->configuration = $this->prepare($params);

            $options = $this->configuration->getOptions();
            $options[self::NODES] = [$this->configuration->getNode()];
            $options[self::API_KEY] = $this->configuration->getSecret();

            $this->configError = null;
            try {
                $this->client = new Client($options);
            } catch (ConfigError $configError) {
                $this->configError = $configError;

                return null;
            }
        }

        return $this->client;
    }

    public function getConfigError(): ?ConfigError
    {
        return $this->configError;
    }
}
