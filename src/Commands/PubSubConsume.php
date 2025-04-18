<?php

namespace Kainxspirits\PubSubQueue\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class PubSubConsume extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pubsub:consume
                            {sub-name : The name of the sub to consume}
                            {--sleep=3 : Number of seconds to sleep when no job is available}
                            {--connection=pubsub : The maximum number of seconds the worker should run}
                            {--max-time=0 : The maximum number of seconds the worker should run}
                            {--memory=128 : The memory limit in megabytes}
                            {--json : Print the output in JSON format}';

    /**
     * @var string
     */
    protected $description = 'Start processing messages on the specified subscription';

    /**
     * @return void
     */
    public function handle(): void
    {
        $this->setSubscriptionToConsume($this->getSubscriptionName());

        Artisan::call('queue:work', $this->getOptions(), $this->output);
    }

    /**
     * @return array
     */
    protected function getOptions(): array
    {
        return [
            'connection' => $this->getConnectionOption(),
            '--sleep' => $this->getSleepOption(),
            '--max-time' => $this->getMaxTimeOption(),
            '--memory' => $this->getMemoryOption(),
            '--json' => $this->getJsonOption(),
        ];
    }

    /**
     * @param string $subscriptionName
     *
     * @return void
     */
    private function setSubscriptionToConsume(string $subscriptionName): void
    {
        config(['queue.connections.pubsub.subscriber' => $subscriptionName]);
    }

    /**
     * @return string
     */
    private function getSubscriptionName(): string
    {
        return $this->argument('sub-name');
    }

    /**
     * @return string
     */
    private function getSleepOption(): string
    {
        return $this->option('sleep');
    }

    /**
     * @return string
     */
    private function getConnectionOption(): string
    {
        return $this->option('connection');
    }

    /**
     * @return string
     */
    private function getMaxTimeOption(): string
    {
        return $this->option('max-time');
    }

    /**
     * @return string
     */
    private function getMemoryOption(): string
    {
        return $this->option('memory');
    }

    /**
     * @return bool
     */
    private function getJsonOption(): bool
    {
        return (bool)$this->option('json');
    }
}
