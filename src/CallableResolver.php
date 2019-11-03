<?php

namespace Chiron\Invoker;

use Chiron\Invoker\Exception\NotCallableException;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final class CallableResolver
{
    /**
     * @var ContainerInterface
     */
    private $container;
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Resolve the given callable into a real PHP callable.
     *
     * @param callable|string|array $callable
     * @return callable Real PHP callable.
     *
     * @throws NotCallableException
     */
    public function resolve($callable): callable
    {
        // The callable is a string in the service:method notation.
        if (is_string($callable) && substr_count($callable, ':') === 1) {
            $callable = explode(':', $callable, 2);
        }

        // The callable is a string in the class::method notation.
        if (is_string($callable) && strpos($callable, '::') !== false) {
            $callable = explode('::', $callable, 2);
        }

        $resolved = $this->resolveFromContainer($callable);

        if (! is_callable($resolved)) {
            throw NotCallableException::fromInvalidCallable($callable);
        }

        return $resolved;
    }

    /**
     * @param callable|string|array $callable
     * @return mixed
     *
     * @throws NotCallableException
     */
    private function resolveFromContainer($callable)
    {
        // Shortcut for a very common use case
        if ($callable instanceof \Closure) {
            return $callable;
        }

        // We cannot just check is_callable but have to use reflection because a non-static method
        // can still be called statically in PHP but we don't want that. This is deprecated in PHP 7 !!!
        // and an E_DEPRECATED warning is throwed when the callable is invoked. So we could simplify this with PHP 8.
        $isStaticCallToNonStaticMethod = false;
        // If it's already a callable there is nothing to do
        if (is_callable($callable)) {
            $isStaticCallToNonStaticMethod = $this->isStaticCallToNonStaticMethod($callable);
            if (! $isStaticCallToNonStaticMethod) {
                return $callable;
            }
        }

        // The callable is a container entry name
        if (is_string($callable)) {
            try {
                return $this->container->get($callable);
            } catch (NotFoundExceptionInterface $e) {
                if ($this->container->has($callable)) {
                    throw $e;
                }
                throw NotCallableException::fromInvalidCallable($callable);
            }
        }

        // The callable is an array whose first item is a container entry name
        // e.g. ['some-container-entry', 'methodToCall']
        if (is_array($callable) && is_string($callable[0])) {
            try {
                // Replace the container entry name by the actual object
                $callable[0] = $this->container->get($callable[0]);
                return $callable;
            } catch (NotFoundExceptionInterface $e) {
                if ($this->container->has($callable[0])) {
                    throw $e;
                }
                if ($isStaticCallToNonStaticMethod) {
                    throw new NotCallableException(sprintf(
                        'Cannot call %s::%s() because %s() is not a static method and "%s" is not a container entry',
                        $callable[0],
                        $callable[1],
                        $callable[1],
                        $callable[0]
                    ));
                }
                throw new NotCallableException(sprintf(
                    'Cannot call %s on %s because it is not a class nor a valid container entry',
                    $callable[1],
                    $callable[0]
                ));
            }
        }

        // Unrecognized stuff
        return $callable;

    }

    /**
     * Check if the callable represents a static call to a non-static method.
     *
     * @param mixed $callable
     *
     * @return bool
     */
    private function isStaticCallToNonStaticMethod($callable): bool
    {
        if (is_array($callable) && is_string($callable[0])) {
            list($class, $method) = $callable;
            $reflection = new \ReflectionMethod($class, $method);

            return ! $reflection->isStatic();
        }

        return false;
    }
}
