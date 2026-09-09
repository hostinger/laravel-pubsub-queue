<?php

namespace Kainxspirits\PubSubQueue\Jobs;

use Google\Cloud\PubSub\Message;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Kainxspirits\PubSubQueue\PubSubQueue;

class PubSubJob extends Job implements JobContract
{
    /**
     * The PubSub queue.
     *
     * @var \Kainxspirits\PubSubQueue\PubSubQueue
     */
    protected $pubsub;

    /**
     * The job instance.
     *
     * @var array
     */
    protected $job;

    /**
     * Create a new job instance.
     *
     * @param \Illuminate\Container\Container $container
     * @param \Kainxspirits\PubSubQueue\PubSubQueue $sqs
     * @param \Google\Cloud\PubSub\Message $job
     * @param string       $connectionName
     * @param string       $queue
     */
    public function __construct(Container $container, PubSubQueue $pubsub, Message $job, $connectionName, $queue)
    {
        $this->pubsub = $pubsub;
        $this->job = $job;
        $this->queue = $queue;
        $this->container = $container;
        $this->connectionName = $connectionName;

        $this->decoded = $this->payload();
    }

    /**
     * Get the job identifier.
     *
     * @return string
     */
    public function getJobId()
    {
        return $this->decoded['id'] ?? null;
    }

    /**
     * Get the raw body of the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        return base64_decode($this->job->data());
    }

    /**
     * Get message attributes
     *
     * @return array
     */
    public function getAttributes()
    {
        return $this->job->attributes();
    }

    /**
     * Get payload as array
     *
     * @return array
     */
    public function getPayloadAsArray()
    {
        return json_decode($this->getRawBody(), true);
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * @return int
     */
    public function attempts()
    {
        return ((int) $this->job->attribute('attempts') ?? 0) + 1;
    }

    /**
     * Delete the job from the queue.
     *
     * When the queue is not acknowledging on pull, this is where the acknowledgement is sent.
     * Until then Pub/Sub still owns the message and will redeliver it after the ack deadline,
     * which is what makes a message survive a failed or crashed consumer.
     *
     * @return void
     */
    public function delete()
    {
        parent::delete();

        if (! $this->pubsub->acknowledgesOnPop()) {
            $this->pubsub->acknowledge($this->job, $this->queue);
        }
    }

    /**
     * Release the job back into the queue.
     *
     * @param  int   $delay
     * @return void
     */
    public function release($delay = 0)
    {
        parent::release($delay);

        $attempts = $this->attempts();
        $this->pubsub->republish(
            $this->job,
            $this->job->attribute('topic') ?: $this->queue,
            ['attempts' => (string) $attempts],
            $delay
        );

        // The republished copy supersedes this message, so acknowledge the original to stop
        // Pub/Sub redelivering it alongside the copy.
        if (! $this->pubsub->acknowledgesOnPop()) {
            $this->pubsub->acknowledge($this->job, $this->queue);
        }
    }
}
