<?php

declare(strict_types=1);

namespace Chiron\Invoker;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Chiron\Invoker\Exception\CannotResolveException;
use Chiron\Invoker\Exception\InvocationException;
use Chiron\Invoker\Reflection\ReflectionCallable;
use Chiron\Invoker\Reflection\Reflection;
use ReflectionObject;
use ReflectionClass;
use ReflectionFunction;
use Closure;
use RuntimeException;
use ReflectionFunctionAbstract;
use InvalidArgumentException;
use Throwable;

// TODO : passer la classe en "final"
class Invoker implements InvokerInterface
{
    private $container;

    /**
     * Invoker constructor.
     *
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
    //$callback => callable|array|string
    public function call($callback, array $params = [])
    {
        $resolved = (new CallableResolver($this->container))->resolve($callback);

        return $this->invoke($resolved, $params);
    }

    public function invoke(callable $callable, array $args = [])
    {
        $reflection = new ReflectionCallable($callable);
        $parameters = $this->resolveArguments($reflection, $args);

        return call_user_func_array($callable, $parameters);
        //return $reflection->invoke($parameters);
    }

    public function resolveArguments(ReflectionFunctionAbstract $reflection, array $parameters = []): array {
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            try {
                //Information we need to know about argument in order to resolve it's value
                $name = $parameter->getName();
                $class = $parameter->getClass();
            } catch (\ReflectionException $e) {
                //Possibly invalid class definition or syntax error
                throw new InvocationException(sprintf('Invalid value for parameter "$%s"', Reflection::toString($parameter)), $e->getCode(), $e);
            }

            if (isset($parameters[$name]) && is_object($parameters[$name])) {
                //Supplied by user as object
                $arguments[] = $parameters[$name];
                continue;
            }
            //No declared type or scalar type or array
            if (empty($class)) {
                //Provided from outside
                if (array_key_exists($name, $parameters)) {
                    //Make sure it's properly typed
                    $this->assertType($parameter, $parameters[$name]);
                    $arguments[] = $parameters[$name];
                    continue;
                }
                if ($parameter->isDefaultValueAvailable()) {
                    //Default value
                    $arguments[] = $parameter->getDefaultValue();
                    $arguments[] = Reflection::getParameterDefaultValue($parameter);
                    continue;
                }
                //Unable to resolve scalar argument value
                throw new CannotResolveException($parameter);
            }

            try {
                //Requesting for contextual dependency
                $arguments[] = $this->container->get($class->getName());
                continue;
            } catch (ContainerExceptionInterface $e) {
                if ($parameter->isOptional()) {
                    //This is optional dependency, skip
                    $arguments[] = null;
                    continue;
                }
                throw $e;
            }
        }

        return $arguments;
    }

    /**
     * Assert that given value are matched parameter type.
     *
     * @param \ReflectionParameter        $parameter
     * @param mixed                       $value
     *
     * @throws CannotResolveException
     */
    private function assertType(ReflectionParameter $parameter, $value): void
    {
        if (is_null($value)) {
            if (!$parameter->isOptional() &&
                !($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === null)
            ) {
                throw new CannotResolveException($parameter);
            }
            return;
        }

        $type = $parameter->getType();
        if ($type === null) {
            return;
        }
        if ($type->getName() == 'array' && !is_array($value)) {
            throw new CannotResolveException($parameter);
        }
        if (($type->getName() == 'int' || $type->getName() == 'float') && !is_numeric($value)) {
            throw new CannotResolveException($parameter);
        }
        if ($type->getName() == 'bool' && !is_bool($value) && !is_numeric($value)) {
            throw new CannotResolveException($parameter);
        }
    }


}
