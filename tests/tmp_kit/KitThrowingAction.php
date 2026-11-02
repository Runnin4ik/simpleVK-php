<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_kit;

use DigitalStars\SimpleVK\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\Dispatcher\BaseAction;
use DigitalStars\SimpleVK\Dispatcher\Context;
use RuntimeException;

#[Trigger(command: '/boom')]
class KitThrowingAction extends BaseAction
{
    public function handle(Context $ctx): void
    {
        throw new RuntimeException('взрыв');
    }
}
