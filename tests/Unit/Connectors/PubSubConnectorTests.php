<?php

namespace Kainxspirits\PubSubQueue\Tests\Unit\Connectors;

use Illuminate\Queue\Connectors\ConnectorInterface;
use Kainxspirits\PubSubQueue\Connectors\PubSubConnector;
use Kainxspirits\PubSubQueue\PubSubQueue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

class PubSubConnectorTests extends TestCase
{
    public function testImplementsConnectorInterface(): void
    {
        putenv('SUPPRESS_GCLOUD_CREDS_WARNING=true');
        $reflection = new ReflectionClass(PubSubConnector::class);
        $this->assertTrue($reflection->implementsInterface(ConnectorInterface::class));
    }

    public function testConnectReturnsPubSubQueueInstance(): void
    {
        $connector = new PubSubConnector;
        $config = $this->createFakeConfig();
        $queue = $connector->connect($config);

        $this->assertTrue($queue instanceof PubSubQueue);
        $this->assertEquals($queue->getSubscriberName(), 'test-subscriber');
    }

    public function testQueuePrefixAdded(): void
    {
        $connector = new PubSubConnector();
        $config = $this->createFakeConfig() + ['queue_prefix' => 'prefix-'];
        $queue = $connector->connect($config);

        $this->assertEquals('prefix-my-queue', $queue->getQueue('my-queue'));
    }

    public function testNotQueuePrefixAddedMultipleTimes(): void
    {
        $connector = new PubSubConnector();
        $config = $this->createFakeConfig() + ['queue_prefix' => 'prefix-'];
        $queue = $connector->connect($config);

        $this->assertEquals('prefix-default', $queue->getQueue($queue->getQueue('default')));
    }

    public function testConnectUsesBackwardCompatiblePullDefaults(): void
    {
        $connector = new PubSubConnector();
        $queue = $connector->connect($this->createFakeConfig());

        $this->assertTrue($this->getProtectedProperty($queue, 'returnImmediately'));
        $this->assertSame(1, $this->getProtectedProperty($queue, 'pullMaxMessages'));
        $this->assertSame(60, $this->getProtectedProperty($queue, 'maxBufferAge'));
    }

    public function testConnectPassesPullConfigValues(): void
    {
        $connector = new PubSubConnector();
        $queue = $connector->connect($this->createFakeConfig() + [
            'return_immediately' => false,
            'pull_max_messages' => 25,
            'max_buffer_age' => 30,
        ]);

        $this->assertFalse($this->getProtectedProperty($queue, 'returnImmediately'));
        $this->assertSame(25, $this->getProtectedProperty($queue, 'pullMaxMessages'));
        $this->assertSame(30, $this->getProtectedProperty($queue, 'maxBufferAge'));
    }

    private function getProtectedProperty(object $object, string $property)
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }

    private function createFakeConfig(): array
    {
        return [
            'queue' => 'test',
            'project_id' => 'the-project-id',
            'subscriber' => 'test-subscriber',
            'retries' => 1,
            'request_timeout' => 60,
        ];
    }
}
