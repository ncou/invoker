<?php

declare(strict_types=1);

namespace Chiron\Invoker;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionObject;
use ReflectionClass;
use ReflectionFunction;
use Closure;
use RuntimeException;
use ReflectionFunctionAbstract;
use InvalidArgumentException;
use Throwable;

class Invoker implements InvokerInterface
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
     * Wrap the given closure such that its dependencies will be injected when executed.
     *
     * @param \Closure $callback
     * @param array    $parameters
     *
     * @return \Closure
     */
    // https://github.com/illuminate/container/blob/master/Container.php#L556
    // TODO : le paramétre $callback ne devrait pas plutot être du type callable au lieu de Closure ????? / voir même de type string car le call support les string avec le signe @
    public function wrap(Closure $closure, array $parameters = []): Closure
    {
        return function () use ($closure, $parameters) {
            return $this->call($closure, $parameters);
        };
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

        // TODO : ajouter une fonction pour "resolve()" le callback si c'est une string avec ":" ou si c'est un tableau et que le 1er élément est une chaine aller chercher dans le container si la classe existe !!!!
        $resolved = (new CallableResolver($this->container))->resolve($callback);

        if (!is_callable($resolved)) {
            throw new \InvalidArgumentException(sprintf(
                '%s is not resolvable',
                is_array($callback) || is_object($callback) ? json_encode($callback) : $callback
            ));
        }

        return $this->invoke($resolved, $params);
    }

    public function invoke(callable $callable, array $args = [])
    {
        $reflection = $this->reflectCallable($callable);
        $parameters = $this->resolveArguments($reflection, $args);

        return call_user_func_array($callable, $parameters);
    }

    private function reflectCallable(callable $callee): ReflectionFunctionAbstract
    {
        // closure, or function name,
        if ($callee instanceof Closure) {
            return new ReflectionFunction($callee);
        } elseif (is_string($callee) && strpos($callee, '::') === false) {
            return new ReflectionFunction($callee);
        }
        if (is_string($callee)) {
            $callee = explode('::', $callee);
        } elseif (is_object($callee)) {
            $callee = [$callee, '__invoke'];
        }
        if (is_object($callee[0])) {
            $reflection = new ReflectionObject($callee[0]);
            if ($reflection->hasMethod($callee[1])) {
                return $reflection->getMethod($callee[1]);
            }
            //magicMethod
            return $reflection->getMethod('__call');
        }
        $reflection = new ReflectionClass($callee[0]);
        if ($reflection->hasMethod($callee[1])) {
            return $reflection->getMethod($callee[1]);
        }
        //magicMethod
        return $reflection->getMethod('__callStatic');
    }


    final public function resolveArguments(ReflectionFunctionAbstract $reflection, array $parameters = []): array {
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            try {
                //Information we need to know about argument in order to resolve it's value
                $name = $parameter->getName();
                $class = $parameter->getClass();
            } catch (Throwable $e) {
                //Possibly invalid class definition or syntax error
                $location = $reflection->getName();
                if ($reflection instanceof \ReflectionMethod) {
                    $location = "{$reflection->getDeclaringClass()->getName()}->{$location}";
                }
                //Possibly invalid class definition or syntax error
                throw new RuntimeException(
                    "Unable to resolve `{$parameter->getName()}` in {$location}: " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }

            if (isset($parameters[$name]) && is_object($parameters[$name])) {
                //if ($parameters[$name] instanceof Autowire) {
                    //Supplied by user as late dependency
                //    $arguments[] = $parameters[$name]->resolve($this);
                //} else {
                    //Supplied by user as object
                    $arguments[] = $parameters[$name];
                //}
                continue;
            }
            //No declared type or scalar type or array
            if (empty($class)) {
                //Provided from outside
                if (array_key_exists($name, $parameters)) {
                    //Make sure it's properly typed
                    $this->assertType($parameter, $reflection, $parameters[$name]);
                    $arguments[] = $parameters[$name];
                    continue;
                }
                if ($parameter->isDefaultValueAvailable()) {
                    //Default value
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }
                //Unable to resolve scalar argument value
                throw new RuntimeException(sprintf(
                    'Unable to resolve a value for parameter (%s) in the function/method (%s)',
                    $name,
                    $reflection->getName()
                ));
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
     * @param \ReflectionFunctionAbstract $context
     * @param mixed                       $value
     *
     * @throws ArgumentException
     */
    private function assertType(
        \ReflectionParameter $parameter,
        \ReflectionFunctionAbstract $context,
        $value
    ) {
        if (is_null($value)) {
            if (!$parameter->isOptional() &&
                !($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === null)
            ) {
                throw new RuntimeException(sprintf(
                    'Unable to resolve a value for parameter (%s) in the function/method (%s)',
                    $parameter->getName(),
                    $context->getName()
                ));
            }
            return;
        }
        $type = $parameter->getType();
        if ($type === null) {
            return;
        }
        if ($type->getName() == 'array' && !is_array($value)) {
            throw new RuntimeException(sprintf(
                    'Unable to resolve a value for parameter (%s) in the function/method (%s)',
                    $parameter->getName(),
                    $context->getName()
                ));
        }
        if (($type->getName() == 'int' || $type->getName() == 'float') && !is_numeric($value)) {
            throw new RuntimeException(sprintf(
                    'Unable to resolve a value for parameter (%s) in the function/method (%s)',
                    $parameter->getName(),
                    $context->getName()
                ));
        }
        if ($type->getName() == 'bool' && !is_bool($value) && !is_numeric($value)) {
            throw new RuntimeException(sprintf(
                    'Unable to resolve a value for parameter (%s) in the function/method (%s)',
                    $parameter->getName(),
                    $context->getName()
                ));
        }
    }


}
