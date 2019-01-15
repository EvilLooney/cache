<?php

namespace Illuminate\Cache;

use RuntimeException;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\InteractsWithTime;
use Aws\DynamoDb\Exception\DynamoDbException;

class DynamoDbStore implements Store
{
    use InteractsWithTime;

    /**
     * The DynamoDB client instance.
     *
     * @var \Aws\DynamoDb\DynamoDbClient
     */
    protected $dynamo;

    /**
     * The table name.
     *
     * @var string
     */
    protected $table;

    /**
     * The name of the attribute that should hold the key.
     *
     * @var  string
     */
    protected $keyAttribute;

    /**
     * The name of the attribute that should hold the value.
     *
     * @var  string
     */
    protected $valueAttribute;

    /**
     * The name of the attribute that should hold the expiration timestamp.
     *
     * @var  string
     */
    protected $expirationAttribute;

    /**
     * Create a new store instance.
     *
     * @param  \Aws\DynamoDb\DynamoDbClient  $dynamo
     * @param  string  $table
     * @param  string  $keyAttribute
     * @param  string  $valueAttribute
     * @param  string  $expirationAttribute
     * @return void
     */
    public function __construct(DynamoDbClient $dynamo,
                                $table,
                                $keyAttribute = 'key',
                                $valueAttribute = 'value',
                                $expirationAttribute = 'expires_at')
    {
        $this->table = $table;
        $this->dynamo = $dynamo;
        $this->keyAttribute = $keyAttribute;
        $this->valueAttribute = $valueAttribute;
        $this->expirationAttribute = $expirationAttribute;
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function get($key)
    {
        $response = $this->dynamo->getItem([
            'TableName' => $this->table,
            'ConsistentRead' => false,
            'Key' => [
                $this->keyAttribute => [
                    'S' => $key,
                ],
            ],
        ]);

        if (! isset($response['Item'])) {
            return;
        }

        if ($this->isExpired($response['Item'])) {
            return;
        }

        if (isset($response['Item'][$this->valueAttribute])) {
            return $this->unserialize(
                $response['Item'][$this->valueAttribute]['S'] ??
                $response['Item'][$this->valueAttribute]['N'] ??
                null
            );
        }
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * Items not found in the cache will have a null value.
     *
     * @param  array  $keys
     * @return array
     */
    public function many(array $keys)
    {
        $response = $this->dynamo->batchGetItem([
            'RequestItems' => [
                $this->table => [
                    'ConsistentRead' => false,
                    'Keys' => collect($keys)->map(function ($key) {
                        return [
                            $this->keyAttribute => [
                                'S' => $key,
                            ],
                        ];
                    })->all(),
                ],
            ],
        ]);

        $now = Carbon::now();

        return array_merge(collect(array_flip($keys))->map(function () {
            return null;
        })->all(), collect($response['Responses'][$this->table])->mapWithKeys(function ($response) use ($now) {
            if ($this->isExpired($response, $now)) {
                $value = null;
            } else {
                $value = $this->unserialize(
                    $response[$this->valueAttribute]['S'] ??
                    $response[$this->valueAttribute]['N'] ??
                    null
                );
            }

            return [$response[$this->keyAttribute]['S'] => $value];
        })->all());
    }

    /**
     * Determine if the given item is expired.
     *
     * @param  arary  $item
     * @param  \DateTimeInterface|null  $expiration
     * @return bool
     */
    protected function isExpired(array $item, $expiration = null)
    {
        $expiration = $expiration ?: Carbon::now();

        return isset($item[$this->expirationAttribute]) &&
               $expiration->getTimestamp() >= $item[$this->expirationAttribute]['N'];
    }

    /**
     * Store an item in the cache for a given number of minutes.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @param  float|int  $minutes
     * @return void
     */
    public function put($key, $value, $minutes)
    {
        $this->dynamo->putItem([
            'TableName' => $this->table,
            'Item' => [
                $this->keyAttribute => [
                    'S' => $key,
                ],
                $this->valueAttribute => [
                    $this->type($value) => $this->serialize($value),
                ],
                $this->expirationAttribute => [
                    'N' => (string) $this->toTimestamp($minutes),
                ],
            ],
        ]);
    }

    /**
     * Store multiple items in the cache for a given number of minutes.
     *
     * @param  array  $values
     * @param  float|int  $minutes
     * @return void
     */
    public function putMany(array $values, $minutes)
    {
        $expiration = $this->toTimestamp($minutes);

        $this->dynamo->batchWriteItem([
            'RequestItems' => [
                $this->table => collect($values)->map(function ($value, $key) use ($expiration) {
                    return [
                        'PutRequest' => [
                            'Item' => [
                                $this->keyAttribute => [
                                    'S' => $key,
                                ],
                                $this->valueAttribute => [
                                    $this->type($value) => $this->serialize($value),
                                ],
                                $this->expirationAttribute => [
                                    'N' => (string) $expiration,
                                ],
                            ],
                        ],
                    ];
                })->values()->all(),
            ],
        ]);
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @param  float|int  $minutes
     * @return bool
     */
    public function add($key, $value, $minutes)
    {
        try {
            $response = $this->dynamo->putItem([
                'TableName' => $this->table,
                'Item' => [
                    $this->keyAttribute => [
                        'S' => $key,
                    ],
                    $this->valueAttribute => [
                        $this->type($value) => $this->serialize($value),
                    ],
                    $this->expirationAttribute => [
                        'N' => (string) $this->toTimestamp($minutes),
                    ],
                ],
                'ConditionExpression' => 'attribute_not_exists(#key) OR #expires_at < :now',
                'ExpressionAttributeNames' => [
                    '#key' => $this->keyAttribute,
                    '#expires_at' => $this->expirationAttribute,
                ],
                'ExpressionAttributeValues' => [
                    ':now' => [
                        'N' => (string) Carbon::now()->getTimestamp(),
                    ],
                ],
            ]);

            return true;
        } catch (DynamoDbException $e) {
            if (Str::contains($e->getMessage(), 'ConditionalCheckFailed')) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        try {
            $response = $this->dynamo->updateItem([
                'TableName' => $this->table,
                'Key' => [
                    $this->keyAttribute => [
                        'S' => $key,
                    ],
                ],
                'ConditionExpression' => 'attribute_exists(#key) AND #expires_at > :now',
                'UpdateExpression' => 'SET #value = #value + :amount',
                'ExpressionAttributeNames' => [
                    '#key' => $this->keyAttribute,
                    '#value' => $this->valueAttribute,
                    '#expires_at' => $this->expirationAttribute,
                ],
                'ExpressionAttributeValues' => [
                    ':now' => [
                        'N' => (string) Carbon::now()->getTimestamp(),
                    ],
                    ':amount' => [
                        'N' => (string) $value,
                    ],
                ],
                'ReturnValues' => 'UPDATED_NEW',
            ]);

            return (int) $response['Attributes'][$this->valueAttribute]['N'];
        } catch (DynamoDbException $e) {
            if (Str::contains($e->getMessage(), 'ConditionalCheckFailed')) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        try {
            $response = $this->dynamo->updateItem([
                'TableName' => $this->table,
                'Key' => [
                    $this->keyAttribute => [
                        'S' => $key,
                    ],
                ],
                'ConditionExpression' => 'attribute_exists(#key) AND #expires_at > :now',
                'UpdateExpression' => 'SET #value = #value - :amount',
                'ExpressionAttributeNames' => [
                    '#key' => $this->keyAttribute,
                    '#value' => $this->valueAttribute,
                    '#expires_at' => $this->expirationAttribute,
                ],
                'ExpressionAttributeValues' => [
                    ':now' => [
                        'N' => (string) Carbon::now()->getTimestamp(),
                    ],
                    ':amount' => [
                        'N' => (string) $value,
                    ],
                ],
                'ReturnValues' => 'UPDATED_NEW',
            ]);

            return (int) $response['Attributes'][$this->valueAttribute]['N'];
        } catch (DynamoDbException $e) {
            if (Str::contains($e->getMessage(), 'ConditionalCheckFailed')) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function forever($key, $value)
    {
        $this->put($key, $value, now()->addYears(5));
    }

    /**
     * Get a lock instance.
     *
     * @param  string  $name
     * @param  int  $seconds
     * @param  string|null  $owner
     * @return \Illuminate\Contracts\Cache\Lock
     */
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new DynamoDbLock($this, $name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     *
     * @param  string  $name
     * @param  string  $owner
     * @return \Illuminate\Contracts\Cache\Lock
     */
    public function restoreLock($name, $owner)
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function forget($key)
    {
        $this->dynamo->deleteItem([
            'TableName' => $this->table,
            'Key' => [
                $this->keyAttribute => [
                    'S' => $key,
                ],
            ],
        ]);

        return true;
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush()
    {
        throw new RuntimeException('DynamoDb does not support flushing an entire table. Please create a new table.');
    }

    /**
     * Get the UNIX timestamp for the given number of minutes.
     *
     * @param  int  $minutes
     * @return int
     */
    protected function toTimestamp($minutes)
    {
        return $minutes > 0
                    ? $this->availableAt($minutes * 60)
                    : Carbon::now()->getTimestamp();
    }

    /**
     * Serialize the value.
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected function serialize($value)
    {
        return is_numeric($value) ? (string) $value : serialize($value);
    }

    /**
     * Unserialize the value.
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected function unserialize($value)
    {
        if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return unserialize($value);
    }

    /**
     * Get the DynamoDB type for the given value.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function type($value)
    {
        return is_numeric($value) ? 'N' : 'S';
    }

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        return '';
    }
}
