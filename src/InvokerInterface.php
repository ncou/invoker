<?php

declare(strict_types=1);

namespace Chiron\Invoker;

interface InvokerInterface
{
    public function call($callback, array $params = []);
}
