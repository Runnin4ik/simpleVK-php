<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Service\Placeholders;
use DigitalStars\SimpleVK\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

final class PlaceholdersTest extends TestCase
{
    public function testResolvesUserAndGroupTags(): void
    {
        $transport = new FakeTransport([
            'users.get' => [['id' => 777, 'first_name' => 'Иван', 'last_name' => 'Петров']],
            'groups.getById' => ['groups' => [['id' => 456, 'name' => 'Пабы']]],
        ]);
        $api = new ApiClient(ClientConfig::create(token: 'test', groupId: 1), $transport);

        $text = Placeholders::resolve($api, 'Привет ~!full|777~ и ~full~ из ~club~', 777);

        self::assertSame('Привет @id777(Иван Петров) и Иван Петров из ~club~', $text);
    }

    public function testTextWithoutTagsIsReturnedAsIs(): void
    {
        $api = new ApiClient(ClientConfig::create(token: 'test', groupId: 1), new FakeTransport());

        self::assertSame('просто текст', Placeholders::resolve($api, 'просто текст'));
    }
}
