<?php
namespace Chiron\Invoker;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionMethod;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionParameter;
use Closure;
use RuntimeException;

/**
 * Injector is able to analyze callable dependencies based on
 * type hinting and inject them from any PSR-11 compatible container.
 */
// PHPLEAGUE Container
class Injector2
{
    private $container;

    /**
     * Injector constructor.
     * @param $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Invoke a callback with resolving dependencies in parameters.
     *
     * This methods allows invoking a callback and let type hinted parameter names to be
     * resolved as objects of the Container. It additionally allow calling function using named parameters.
     *
     * For example, the following callback may be invoked using the Container to resolve the formatter dependency:
     *
     * ```php
     * $formatString = function($string, \yii\i18n\Formatter $formatter) {
     *    // ...
     * }
     * $container->invoke($formatString, ['string' => 'Hello World!']);
     * ```
     *
     * This will pass the string `'Hello World!'` as the first param, and a formatter instance created
     * by the DI container as the second param to the callable.
     *
     * @param callable $callback callable to be invoked.
     * @param array $params The array of parameters for the function.
     * This can be either a list of parameters, or an associative array representing named function parameters.
     * @return mixed the callback return value.
     * @throws MissingRequiredArgumentException  if required argument is missing.
     * @throws ContainerExceptionInterface if a dependency cannot be resolved or if a dependency cannot be fulfilled.
     * @throws \ReflectionException
     */
    public function invoke(callable $callback, array $params = [])
    {
        return $this->call($callback, $params);
    }

    /**
     * Invoke a callable via the container.
     *
     * @param callable $callable
     * @param array    $args
     *
     * @return mixed
     *
     * @throws ReflectionException
     */
    public function call(callable $callable, array $args = [])
    {
        if (is_string($callable) && strpos($callable, '::') !== false) {
            $callable = explode('::', $callable);
        }
        if (is_array($callable)) {
            if (is_string($callable[0])) {
                $callable[0] = $this->container->get($callable[0]);
            }
            $reflection = new ReflectionMethod($callable[0], $callable[1]);
            if ($reflection->isStatic()) {
                $callable[0] = null;
            }
            return $reflection->invokeArgs($callable[0], $this->reflectArguments($reflection, $args));
        }
        if (is_object($callable)) {
            $reflection = new ReflectionMethod($callable, '__invoke');
            return $reflection->invokeArgs($callable, $this->reflectArguments($reflection, $args));
        }
        $reflection = new ReflectionFunction(Closure::fromCallable($callable));
        return $reflection->invokeArgs($this->reflectArguments($reflection, $args));
    }

    /**
     * {@inheritdoc}
     */
    public function reflectArguments(ReflectionFunctionAbstract $method, array $args = []) : array
    {
        $arguments = array_map(function (ReflectionParameter $param) use ($method, $args) {
            $name  = $param->getName();
            $class = $param->getClass();
            if (array_key_exists($name, $args)) {
                return $args[$name];
            }
            if ($class !== null) {
                return $class->getName();
            }
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            throw new RuntimeException(sprintf(
                'Unable to resolve a value for parameter (%s) in the function/method (%s)',
                $name,
                $method->getName()
            ));
        }, $method->getParameters());

        return $this->resolveArguments($arguments);
    }

    /**
     * {@inheritdoc}
     */
    public function resolveArguments(array $arguments) : array
    {
        foreach ($arguments as &$arg) {
            if (! is_string($arg)) {
                 continue;
            }

            if ($this->container !== null && $this->container->has($arg)) {
                $arg = $this->container->get($arg);
                continue;
            }
        }

        return $arguments;
    }
}
