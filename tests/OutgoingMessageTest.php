<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Message\OutgoingMessage;
use DigitalStars\SimpleVK\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

final class OutgoingMessageTest extends TestCase
{
    public function testSendBroadcastsToEveryPeerInList(): void
    {
        $transport = new FakeTransport([
            'messages.send' => [['message_id' => 1]],
        ]);
        $api = new ApiClient(ClientConfig::create(token: 'test', groupId: 1), $transport);

        $message = new OutgoingMessage($api);
        $ids = $message->text('привет')->send([11, 22]);

        self::assertSame([1, 1], $ids);
        self::assertCount(2, $transport->calls);
        self::assertSame(11, $transport->calls[0]['params']['peer_id']);
        self::assertSame(22, $transport->calls[1]['params']['peer_id']);
    }

    public function testAttachmentSkipsNullValues(): void
    {
        $transport = new FakeTransport(['messages.send' => [['message_id' => 1]]]);
        $api = new ApiClient(ClientConfig::create(token: 'test', groupId: 1), $transport);

        new OutgoingMessage($api)
            ->text('текст')
            ->attachment(null, 'photo1_2')
            ->send(5);

        self::assertSame('photo1_2', $transport->calls[0]['params']['attachment']);
    }
}
