<?php

declare(strict_types=1);

namespace Chiron\Invoker\Support;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Chiron\Invoker\Exception\CannotResolveException;
use Chiron\Invoker\Exception\InvocationException;
use Chiron\Invoker\Reflection\ReflectionCallable;
use Chiron\Invoker\Reflection\Reflection;
use Chiron\Invoker\Invoker;
use Chiron\Invoker\CallableResolver;
use ReflectionObject;
use ReflectionClass;
use ReflectionFunction;
use Closure;
use RuntimeException;
use ReflectionFunctionAbstract;
use InvalidArgumentException;
use Throwable;

class Invokable
{
    private $callback;

    /**
     * Invoker constructor.
     *
     * @param $callback callable|array|string
     */
    public function __construct($callback)
    {
        $this->callback = $callback;
    }

    public function __invoke(ContainerInterface $container)
    {
        $callable = (new CallableResolver($container))->resolve($this->callback);

        $invoker = new Invoker($container);

        return $invoker->invoke($callable);
    }

}
