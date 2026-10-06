<?php

declare(strict_types=1);

namespace Typesense\Bundle\ORM\Mapping;

use Http\Client\Exception as HttpClientException;
use Typesense\Bundle\DBAL\Connection;
use Typesense\Bundle\Exception\TypesenseException;
use Typesense\Bundle\ORM\Query\Query;
use Typesense\Bundle\ORM\Query\Request;
use Typesense\Bundle\ORM\Transformer\Abstract\TransformerInterface;
use Typesense\Client;
use Typesense\Exceptions\ObjectAlreadyExists;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;

class TypesenseCollection
{
    protected $connection;

    protected $metadata;
    protected $documents;

    public function __construct(TypesenseMetadata $metadata, Connection $connection)
    {
        $this->metadata = $metadata;
        $this->connection = $connection;

        $this->documents = new TypesenseDocuments($metadata, $connection);
    }

    public function name(): string
    {
        return $this->metadata->getName();
    }

    public function metadata(): TypesenseMetadata
    {
        return $this->metadata;
    }

    public function transformer(): TransformerInterface
    {
        return $this->metadata->getTransformer();
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function client(): ?Client
    {
        return $this->connection->getClient();
    }

    public function documents(): TypesenseDocuments
    {
        return $this->documents;
    }

    /**
     * @param $entity
     * @return bool
     */
    public function supports($entity): bool
    {
        return is_a($entity, $this->metadata->getClass(), true);
    }

    /**
     * @param Request $query
     * @return array|null
     */
    public function search(Request $query)
    {
        if (!$this->connection->isConnected()) {
            throw new TypesenseException($this->connection->getStatus(), $this->connection->getStatusCode());
        }

        try {
            return $this->documents->search($query->getHeaders());
        } catch (TypesenseClientError|HttpClientException $e) {
            throw new TypesenseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param array $searchRequests
     * @param Request|null $commonSearchParams
     * @return array
     * @throws Exception
     */
    public function multiSearch(array $searchRequests, ?Request $commonSearchParams)
    {
        if (!$this->connection->isConnected()) {
            throw new TypesenseException($this->connection->getStatus(), $this->connection->getStatusCode());
        }

        $searches = [];
        foreach ($searchRequests as $sr) {
            if (!$sr instanceof Query) {
                throw new TypesenseException('searchRequests must be an array  of Request objects', 500);
            }
            if (!$sr->hasHeader('collection')) {
                throw new TypesenseException('Request must have the key : `collection` in order to perform multiSearch', 500);
            }
            $searches[] = $this->prefix($sr->getHeaders());
        }

        try {
            return $this->client()->getMultiSearch()->perform(
                ['searches' => $searches],
                $commonSearchParams ? $this->prefix($commonSearchParams->getHeaders()) : []
            );
        } catch (TypesenseClientError|HttpClientException $e) {
            throw new TypesenseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function list()
    {
        if (!$this->connection->isConnected()) {
            throw new TypesenseException($this->connection->getStatus(), $this->connection->getStatusCode());
        }

        try {
            return $this->client()->getCollections()->retrieve();
        } catch (TypesenseClientError|HttpClientException $e) {
            throw new TypesenseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function create()
    {
        if (!$this->connection->isConnected()) {
            throw new TypesenseException($this->connection->getStatus(), $this->connection->getStatusCode());
        }

        $configuration = $this->metadata->getConfiguration();
        $configuration['fields'] = array_values(array_map(fn($f) => $f->toArray(), $configuration['fields']));
        foreach ($configuration['fields'] as &$field) {
            $field['type'] = $this->metadata->getTransformer()->cast($field['type']);
        }

        try {

            $this->client()->getCollections()->create($configuration);

        } catch (ObjectAlreadyExists) {

        } catch (TypesenseClientError|HttpClientException $e) {

            throw new TypesenseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @return null
     */
    public function delete()
    {
        if (!$this->connection->isConnected()) {
            throw new TypesenseException($this->connection->getStatus(), $this->connection->getStatusCode());
        }

        try {
        
            return $this->client()->getCollections()[$this->metadata->getCollectionName()]?->delete();
        
        } catch (ObjectNotFound) {

        } catch (TypesenseClientError|HttpClientException $e) {
        
            throw new TypesenseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * A multiSearch names its collections itself, by their mapping key:
     * the configured `collection_prefix` is put in front here.
     */
    private function prefix(array $headers): array
    {
        if ('' !== $this->metadata->getPrefix() && isset($headers['collection'])) {
            $headers['collection'] = $this->metadata->getPrefix() . $headers['collection'];
        }

        return $headers;
    }
}
