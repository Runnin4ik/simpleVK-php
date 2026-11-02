<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_kit;

use DigitalStars\SimpleVK\Dispatcher\Attributes\AsButton;
use DigitalStars\SimpleVK\Dispatcher\BaseButton;
use DigitalStars\SimpleVK\Dispatcher\Context;

#[AsButton(label: 'Кнопка', color: 'green')]
class KitButton extends BaseButton
{
    public static ?array $lastArgs = null;

    public function handle(Context $ctx, ?string $extra = null): void
    {
        self::$lastArgs = ['extra' => $extra];
        $ctx->msg('pong')->send();
    }
}
