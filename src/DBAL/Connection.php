<?php

declare(strict_types=1);

namespace Typesense\Bundle\DBAL;

use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Document;
use Typesense\Documents;

/**
 * A named Typesense server: its parameters (secret, url or scheme/host/
 * port/path, client options) and the client made from them on first use.
 */
class Connection
{
    /** @var array<string, mixed>|object */
    protected array|object $parameters;

    protected string $name;

    protected Driver $driver;

    /**
     * @param array<string, mixed>|object $parameters the connection's parameters - secret, url, scheme,
     *                                                host, port, path, options - or, as before 2.0, a
     *                                                parameter bag (anything with all()) holding them
     *                                                under typesense.connections.<name>.*
     */
    public function __construct(string $name, #[\SensitiveParameter] array|object $parameters = [])
    {
        $this->name = $name;
        $this->parameters = $parameters;
        $this->driver = new Driver($name);
    }

    /**
     * @param $array
     * @param $divider_char
     * @return array|false|mixed
     */
    private function inflate($array, $divider_char = '.')
    {
        if (!is_array($array)) {
            return false;
        }

        $split = '/' . preg_quote($divider_char, '/') . '/';

        $ret = [];
        foreach ($array as $key => $val) {
            $parts = preg_split($split, $key, -1, PREG_SPLIT_NO_EMPTY);
            $leafpart = array_pop($parts);
            $parent = &$ret;
            foreach ($parts as $part) {
                if (!isset($parent[$part])) {
                    $parent[$part] = [];
                } elseif (!is_array($parent[$part])) {
                    $parent[$part] = [];
                }

                $parent = &$parent[$part];
            }

            if (empty($parent[$leafpart])) {
                $parent[$leafpart] = $val;
            }
        }

        return $ret;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getClient(): ?Client
    {
        return $this->getDriver()->connect($this->getParameters());
    }

    /**
     * @return array<string, mixed>
     */
    private function getParameters(): array
    {
        if (\is_array($this->parameters)) {
            return $this->parameters;
        }

        $params = array_filter($this->parameters->all(), fn ($k) => str_starts_with($k, 'typesense.connections.' . $this->name), ARRAY_FILTER_USE_KEY);

        return $this->inflate($params)['typesense']['connections'][$this->name] ?? [];
    }

    public function getDriver(): Driver
    {
        return $this->driver;
    }

    public function getStatus(): ?string
    {
        return $this->driver->getConfigError()?->getMessage() ?? null;
    }

    public function getStatusCode(): ?int
    {
        return $this->driver->getConfigError()?->getCode() ?? null;
    }

    public function isConnected(): bool
    {
        return null !== $this->getClient();
    }

    public function getCollections(): ?Collections
    {
        return $this->getClient()?->getCollections();
    }

    public function getCollection(string $name): Collection
    {
        return $this->getCollections()[$name];
    }

    public function getDocuments(string $name): Documents
    {
        return $this->getCollection($name)->getDocuments();
    }

    /**
     * @param string $name
     * @param $id
     * @return Document
     */
    public function getDocument(string $name, $id): Document
    {
        return $this->getCollection($name)->getDocuments()[$id];
    }

    public function getHealth(): bool
    {
        return $this->getClient()?->getHealth()?->retrieve()['ok'] ?? false;
    }

    public function getDebug(): ?array
    {
        return $this->getClient()?->getDebug()?->retrieve();
    }
}
