<?php

declare(strict_types=1);

namespace Typesense\Bundle\ORM\Mapping;

use Http\Client\Exception as HttpClientException;
use Typesense\Bundle\DBAL\Connection;
use Typesense\Bundle\Exception\TypesenseException;
use Typesense\Client;
use Typesense\Exceptions\TypesenseClientError;

class TypesenseDocuments
{
    private ?Connection $connection;
    protected TypesenseMetadata $metadata;

    public function __construct(TypesenseMetadata $metadata, Connection $connection)
    {
        $this->metadata = $metadata;
        $this->connection = $connection;
    }

    /**
     * @return Client|null
     */
    public function connection()
    {
        return $this->connection->getClient();
    }

    public function delete(string|int $id): ?array
    {
        if (!$this->connection?->isConnected()) {
            throw new TypesenseException($this->connection->getStatus(), $this->connection->getStatusCode());
        }

        $documents = $this->connection?->getCollections()[$this->metadata->getCollectionName()]->documents;

        try {
            return $documents[$id]?->delete();
        } catch (TypesenseClientError|HttpClientException $e) {
            throw new TypesenseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function create(array $data, array $options): ?array
    {
        if (!$this->connection?->isConnected()) {
            throw new TypesenseException($this->connection->getStatus(), $this->connection->getStatusCode());
        }

        $collectionName = $this->metadata->getCollectionName();
        $collection = $this->connection?->getCollections()[$collectionName];
        $documents = $collection->documents;

        try {
            return $documents->create($data, $options);
        } catch (TypesenseClientError|HttpClientException $e) {
            throw new TypesenseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function upsert(array $data, array $options): ?array
    {
        if (!$this->connection?->isConnected()) {
            throw new TypesenseException($this->connection->getStatus(), $this->connection->getStatusCode());
        }

        $collectionName = $this->metadata->getCollectionName();
        $collection = $this->connection?->getCollections()[$collectionName];
        $documents = $collection->documents;

        try {
            return $documents->upsert($data, $options);
        } catch (TypesenseClientError|HttpClientException $e) {
            throw new TypesenseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function update(array $data, array $options): ?array
    {
        if (!$this->connection?->isConnected()) {
            throw new TypesenseException($this->connection->getStatus(), $this->connection->getStatusCode());
        }

        $collectionName = $this->metadata->getCollectionName();
        $collection = $this->connection?->getCollections()[$collectionName];
        $documents = $collection->documents;

        try {
            return $documents->update($data, $options);
        } catch (TypesenseClientError|HttpClientException $e) {
            throw new TypesenseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function search(array $searchParams): ?array
    {
        if (!$this->connection?->isConnected()) {
            throw new TypesenseException($this->connection->getStatus(), $this->connection->getStatusCode());
        }

        $collectionName = $this->metadata->getCollectionName();
        $collection = $this->connection?->getCollections()[$collectionName];
        $documents = $collection->documents;

        try {
            return $documents->search($searchParams);
        } catch (TypesenseClientError|HttpClientException $e) {
            throw new TypesenseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function import(array $data, string $action = 'create'): null|array|string
    {
        if (!$this->connection->isConnected()) {
            throw new TypesenseException($this->connection->getStatus(), $this->connection->getStatusCode());
        }

        if (empty($data)) {
            return [];
        }

        $collectionName = $this->metadata->getCollectionName();
        $collection = $this->connection?->getCollections()[$collectionName];
        $documents = $collection->documents;

        try {
            return $documents->import($data, ['action' => $action]);
        } catch (\JsonException|HttpClientException $e) {
            throw new TypesenseException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
