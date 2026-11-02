<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_kit;

use DigitalStars\SimpleVK\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\Dispatcher\BaseAction;
use DigitalStars\SimpleVK\Dispatcher\Context;

#[Trigger(command: '/reply')]
class KitReplyAction extends BaseAction
{
    public function handle(Context $ctx): void
    {
        $ctx
            ->replyMessage('ответ')
            ->kbd([[new KitButton()]])
            ->send();
    }
}
