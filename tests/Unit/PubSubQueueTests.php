<?php

namespace Kainxspirits\PubSubQueue\Tests\Unit;

use Carbon\Carbon;
use Google\Cloud\Core\Exception\ServiceException;
use Google\Cloud\PubSub\Message;
use Google\Cloud\PubSub\PubSubClient;
use Google\Cloud\PubSub\Subscription;
use Google\Cloud\PubSub\Topic;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Kainxspirits\PubSubQueue\Jobs\PubSubJob;
use Kainxspirits\PubSubQueue\PubSubQueue;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[AllowMockObjectsWithoutExpectations]
class PubSubQueueTests extends TestCase
{
    /**
     * @var string
     */
    protected $expectedResult = 'message-id';

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&Topic
     */
    protected $topic;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&PubSubClient
     */
    protected $client;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&Subscription
     */
    protected $subscription;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&Message
     */
    protected $message;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&PubSubQueue
     */
    protected $queue;

    protected function setUp(): void
    {
        $this->expectedResult = 'message-id';

        $this->topic = $this->createMock(Topic::class);
        $this->client = $this->createMock(PubSubClient::class);
        $this->subscription = $this->createMock(Subscription::class);
        $this->message = $this->createPulledMessage();

        $this->queue = $this->createPartialQueue();
    }

    public function testImplementsQueueInterface(): void
    {
        $reflection = new ReflectionClass(PubSubQueue::class);
        $this->assertTrue($reflection->implementsInterface(QueueContract::class));
    }

    public function testPushNewJob(): void
    {
        $job = 'test';
        $data = ['foo' => 'bar'];

        $this->queue->setContainer(Container::getInstance());

        $this->queue->expects($this->once())
            ->method('pushRaw')
            ->willReturn($this->expectedResult)
            ->with($this->callback(function ($payload) use ($job, $data) {
                $decoded_payload = json_decode($payload, true);

                return $decoded_payload['data'] === $data && $decoded_payload['job'] === $job;
            }));

        $this->assertEquals($this->expectedResult, $this->queue->push('test', $data));
    }

    public function testPushRaw(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&PubSubQueue $queue */
        $queue = $this->getMockBuilder(PubSubQueue::class)
            ->setConstructorArgs([$this->client, 'default'])
            ->onlyMethods(['getTopic', 'subscribeToTopic'])
            ->getMock();

        $payload = json_encode(['id' => $this->expectedResult]);

        $this->topic->method('publish')
            ->willReturn($this->expectedResult)
            ->with($this->callback(function ($publish) use ($payload) {
                $decoded_payload = base64_decode($publish['data']);

                return $decoded_payload === $payload;
            }));

        $queue->method('getTopic')
            ->willReturn($this->topic);

        $queue->method('subscribeToTopic')
            ->willReturn($this->subscription);

        $this->assertEquals($this->expectedResult, $queue->pushRaw($payload));
    }

    public function testPushRawOptionsOnlyAcceptKeyValueStrings(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        /** @var \PHPUnit\Framework\MockObject\MockObject&PubSubQueue $queue */
        $queue = $this->getMockBuilder(PubSubQueue::class)
            ->setConstructorArgs([$this->client, 'default'])
            ->onlyMethods(['getTopic', 'subscribeToTopic'])
            ->getMock();

        $payload = json_encode(['id' => $this->expectedResult]);

        $queue->method('getTopic')
            ->willReturn($this->topic);

        $queue->method('subscribeToTopic')
            ->willReturn($this->subscription);

        $options = [
            'integer' => 42,
            'array' => [
                'foo' => 'bar',
            ],
            1 => 'wrong key',
            'object' => new \StdClass,
        ];

        $queue->pushRaw($payload, '', $options);
    }

    public function testLater(): void
    {
        $job = 'test';
        $delay = 60;
        $delay_timestamp = Carbon::now()->addSeconds($delay)->getTimestamp();

        $this->queue->setContainer(Container::getInstance());

        $this->queue->method('availableAt')
            ->willReturn($delay_timestamp);

        $this->queue->expects($this->once())
            ->method('pushRaw')
            ->willReturn($this->expectedResult)
            ->with(
                $this->isString(),
                $this->anything(),
                $this->callback(function ($options) use ($delay_timestamp) {
                    if (! is_array($options)) {
                        return false;
                    }

                    foreach ($options as $key => $option) {
                        if (! is_string($option) || ! is_string($key)) {
                            return false;
                        }
                    }

                    if (! isset($options['available_at']) || $options['available_at'] !== (string) $delay_timestamp) {
                        return false;
                    }

                    return true;
                })
            );

        $this->assertEquals($this->expectedResult, $this->queue->later($delay, $job, ['foo' => 'bar']));
    }

    public function testPopWhenJobsAvailable(): void
    {
        $this->subscription->expects($this->once())
            ->method('acknowledge');

        $this->subscription->method('pull')
            ->willReturn([$this->message]);

        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->topic->method('exists')
            ->willReturn(true);

        $this->queue->method('getTopic')
            ->willReturn($this->topic);

        $this->queue->setContainer($this->createMock(Container::class));

        $this->assertTrue($this->queue->pop('test') instanceof PubSubJob);
    }

    public function testPopWhenNoJobAvailable(): void
    {
        $this->subscription->expects($this->exactly(0))
            ->method('acknowledge');

        $this->subscription->method('pull')
            ->willReturn([]);

        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->topic->method('exists')
            ->willReturn(true);

        $this->queue->method('getTopic')
            ->willReturn($this->topic);

        $this->assertTrue(is_null($this->queue->pop('test')));
    }

    public function testPopWhenTopicDoesNotExist(): void
    {
        $this->queue->method('getTopic')
            ->willReturn($this->topic);

        $this->topic->method('exists')
            ->willReturn(false);

        $this->assertTrue(is_null($this->queue->pop('test')));
    }

    public function testPopWhenJobDelayed(): void
    {
        $delay = 60;
        $timestamp = Carbon::now()->addSeconds($delay)->getTimestamp();

        $this->message = $this->createPulledMessage();
        $this->message->method('attribute')
            ->willReturn($timestamp);

        $this->subscription->method('pull')
            ->willReturn([$this->message]);

        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->topic->method('exists')
            ->willReturn(true);

        $this->queue->method('getTopic')
            ->willReturn($this->topic);

        $this->queue->setContainer($this->createMock(Container::class));

        $this->assertTrue(is_null($this->queue->pop('test')));
    }

    public function testPopPullsWithSingleMessageOptionsByDefault(): void
    {
        $this->subscription->expects($this->once())
            ->method('pull')
            ->with($this->callback(function ($options) {
                return $options['maxMessages'] === 1
                    && $options['returnImmediately'] === true;
            }))
            ->willReturn([$this->message]);

        $this->subscription->expects($this->once())
            ->method('acknowledge');

        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->topic->method('exists')
            ->willReturn(true);

        $this->queue->method('getTopic')
            ->willReturn($this->topic);

        $this->queue->setContainer($this->createMock(Container::class));

        $this->assertTrue($this->queue->pop('test') instanceof PubSubJob);
    }

    public function testPopPullsBatchOnceAndHandsOutBufferedJobs(): void
    {
        $messages = [
            $this->createPulledMessage(),
            $this->createPulledMessage(),
            $this->createPulledMessage(),
        ];

        $queue = $this->createPartialQueue([
            $this->client, 'default', 'subscriber', true, true, '', true, 3, 60,
        ]);

        $this->subscription->expects($this->once())
            ->method('pull')
            ->with($this->callback(function ($options) {
                return $options['maxMessages'] === 3;
            }))
            ->willReturn($messages);

        $this->subscription->expects($this->exactly(3))
            ->method('acknowledge');

        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->topic->method('exists')
            ->willReturn(true);

        $queue->method('getTopic')
            ->willReturn($this->topic);

        $queue->setContainer($this->createMock(Container::class));

        $this->assertTrue($queue->pop('test') instanceof PubSubJob);
        $this->assertTrue($queue->pop('test') instanceof PubSubJob);
        $this->assertTrue($queue->pop('test') instanceof PubSubJob);
    }

    public function testPopSkipsDelayedMessagesInBatchAndServesDueOnes(): void
    {
        $future_timestamp = Carbon::now()->addSeconds(60)->getTimestamp();

        $delayed_message = $this->createPulledMessage();
        $delayed_message->method('attribute')
            ->willReturnMap([
                ['available_at', $future_timestamp],
                ['topic', null],
            ]);

        $due_message = $this->createPulledMessage();

        $queue = $this->createPartialQueue([
            $this->client, 'default', 'subscriber', true, true, '', true, 2, 60,
        ]);

        $this->subscription->method('pull')
            ->willReturn([$delayed_message, $due_message]);

        // Only the due message must be acknowledged; the delayed one is left
        // unacked so PubSub redelivers it after the ack deadline.
        $this->subscription->expects($this->once())
            ->method('acknowledge')
            ->with($this->identicalTo($due_message));

        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->topic->method('exists')
            ->willReturn(true);

        $queue->method('getTopic')
            ->willReturn($this->topic);

        $queue->setContainer($this->createMock(Container::class));

        $this->assertTrue($queue->pop('test') instanceof PubSubJob);
    }

    public function testPopDropsStaleBufferedMessagesWithoutAck(): void
    {
        $queue = $this->createPartialQueue([
            $this->client, 'default', 'subscriber', true, true, '', true, 2, 60,
        ]);

        $buffer = new ReflectionProperty(PubSubQueue::class, 'messageBuffer');
        $buffer->setValue($queue, [
            'test' => [
                ['message' => $this->message, 'pulled_at' => time() - 120],
            ],
        ]);

        $this->subscription->expects($this->never())
            ->method('acknowledge');

        $this->subscription->expects($this->never())
            ->method('pull');

        $this->assertTrue(is_null($queue->pop('test')));
    }

    public function testPopReturnsNullWhenPullTimesOut(): void
    {
        $this->subscription->method('pull')
            ->willThrowException(new ServiceException(
                'cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received'
            ));

        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->topic->method('exists')
            ->willReturn(true);

        $this->queue->method('getTopic')
            ->willReturn($this->topic);

        $this->assertTrue(is_null($this->queue->pop('test')));
    }

    public function testPopRethrowsNonTimeoutServiceExceptions(): void
    {
        $this->expectException(ServiceException::class);

        $this->subscription->method('pull')
            ->willThrowException(new ServiceException('The caller does not have permission', 403));

        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->topic->method('exists')
            ->willReturn(true);

        $this->queue->method('getTopic')
            ->willReturn($this->topic);

        $this->queue->pop('test');
    }

    public function testBulk(): void
    {
        $jobs = ['test'];
        $data = ['foo' => 'bar'];

        $this->queue->setContainer(Container::getInstance());

        $this->topic->expects($this->once())
            ->method('publishBatch')
            ->willReturn($this->expectedResult)
            ->with($this->callback(function ($payloads) use ($jobs, $data) {
                $decoded_payload = json_decode(base64_decode($payloads[0]['data']), true);

                return $decoded_payload['job'] === $jobs[0] && $decoded_payload['data'] === $data;
            }));

        $this->queue->method('getTopic')
            ->willReturn($this->topic);

        $this->queue->method('subscribeToTopic')
            ->willReturn($this->subscription);

        $this->assertEquals($this->expectedResult, $this->queue->bulk($jobs, $data));
    }

    public function testAcknowledge(): void
    {
        $this->subscription->expects($this->once())
            ->method('acknowledge');

        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->queue->method('getTopic')
            ->willReturn($this->topic);

        $this->queue->acknowledge($this->message);
    }

    public function testRepublish(): void
    {
        $options = ['foo' => 'bar'];
        $delay = 60;
        $delay_timestamp = Carbon::now()->addSeconds($delay)->getTimestamp();

        $this->queue->method('getTopic')
            ->willReturn($this->topic);

        $this->queue->method('availableAt')
            ->willReturn($delay_timestamp);

        $this->topic->expects($this->once())
            ->method('publish')
            ->willReturn($this->expectedResult)
            ->with(
                $this->callback(function ($message) use ($options, $delay_timestamp) {
                    if (! isset($message['attributes']) || ! is_array($message['attributes'])) {
                        return false;
                    }

                    foreach ($message['attributes'] as $key => $attribute) {
                        if (! is_string($attribute) || ! is_string($key)) {
                            return false;
                        }
                    }

                    if (! isset($message['attributes']['available_at']) || $message['attributes']['available_at'] !== (string) $delay_timestamp) {
                        return false;
                    }

                    if (! isset($message['attributes']['foo']) || $message['attributes']['foo'] != $options['foo']) {
                        return false;
                    }

                    return true;
                })
            );

        $this->queue->republish($this->message, 'test', $options, $delay);
    }

    public function testRepublishOptionsOnlyAcceptString(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $delay = 60;
        $delay_timestamp = Carbon::now()->addSeconds($delay)->getTimestamp();

        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->queue->method('getTopic')
            ->willReturn($this->topic);

        $this->queue->method('availableAt')
            ->willReturn($delay_timestamp);

        $this->topic->method('publish')
            ->willReturn($this->expectedResult);

        $options = [
            'integer' => 42,
            'array' => [
                'foo' => 'bar',
            ],
            1 => 'wrong key',
            'object' => new \StdClass,
        ];

        $this->queue->republish($this->message, 'test', $options, $delay);
    }

    public function testGetTopic(): void
    {
        $this->topic->method('exists')
            ->willReturn(true);

        $this->client->method('topic')
            ->willReturn($this->topic);

        $queue = $this->createRealQueue();

        $this->assertTrue($queue->getTopic('test') instanceof Topic);
    }

    public function testCreateTopicAndReturnIt(): void
    {
        $this->topic->method('exists')
            ->willReturn(false);

        $this->topic->expects($this->once())
            ->method('create')
            ->willReturn(true);

        $this->client->method('topic')
            ->willReturn($this->topic);

        $queue = $this->createRealQueue();

        $this->assertTrue($queue->getTopic('test', true) instanceof Topic);
    }

    public function testSubscribtionIsCreated(): void
    {
        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->topic->method('subscribe')
            ->willReturn($this->subscription);

        $this->subscription->method('exists')
            ->willReturn(false);

        $queue = $this->createRealQueue();

        $this->assertTrue($queue->subscribeToTopic($this->topic) instanceof Subscription);
    }

    public function testSubscriptionIsRetrieved(): void
    {
        $this->topic->method('subscription')
            ->willReturn($this->subscription);

        $this->subscription->method('exists')
            ->willReturn(true);

        $queue = $this->createRealQueue();

        $this->assertTrue($queue->subscribeToTopic($this->topic) instanceof Subscription);
    }

    public function testGetSubscriberName(): void
    {
        $queue = $this->createRealQueue([$this->client, 'default', 'test-subscriber']);

        $this->assertTrue(is_string($queue->getSubscriberName()));
        $this->assertEquals($queue->getSubscriberName(), 'test-subscriber');
    }

    public function testGetPubSub(): void
    {
        $this->assertTrue($this->queue->getPubSub() instanceof PubSubClient);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&Message
     */
    private function createPulledMessage()
    {
        $message = $this->createMock(Message::class);

        $message->method('data')
            ->willReturn(base64_encode(json_encode(['id' => $this->expectedResult])));

        return $message;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&PubSubQueue
     */
    private function createPartialQueue(?array $constructorArgs = null)
    {
        return $this->getMockBuilder(PubSubQueue::class)
            ->setConstructorArgs($constructorArgs ?? [$this->client, 'default'])
            ->onlyMethods([
                'pushRaw',
                'getTopic',
                'availableAt',
                'subscribeToTopic',
            ])->getMock();
    }

    private function createRealQueue(?array $constructorArgs = null): PubSubQueue
    {
        return new PubSubQueue(...($constructorArgs ?? [$this->client, 'default']));
    }
}
