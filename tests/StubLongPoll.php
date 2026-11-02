<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Event\Update;
use DigitalStars\SimpleVK\Exception\SimpleVkException;
use DigitalStars\SimpleVK\LongPoll\LongPollClient;

/**
 * Подмена HTTP-цикла: отдаёт заранее заданные пачки апдейтов.
 */
final class StubLongPoll extends LongPollClient
{
    /** @var list<list<Update>> */
    public array $batches;

    public int $waitCalls = 0;

    public int $skipBacklogCalls = 0;

    public ?int $failOnWaitCall = null;

    public ?\Closure $onWait = null;

    /**
     * @param list<list<Update>> $batches
     */
    public function __construct(array $batches)
    {
        parent::__construct(
            ClientConfig::create(token: 'test', groupId: 1),
            new ApiClient(ClientConfig::create(token: 'test', groupId: 1)),
        );

        $this->batches = $batches;
    }

    public function wait(): array
    {
        ++$this->waitCalls;
        $this->onWait?->__invoke($this->waitCalls);

        if ($this->failOnWaitCall === $this->waitCalls) {
            throw new SimpleVkException(2, 'VK longpoll failed: 2');
        }

        return \array_shift($this->batches) ?? [];
    }

    public function skipBacklog(): void
    {
        ++$this->skipBacklogCalls;
    }
}
