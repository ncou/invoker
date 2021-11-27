<?php

declare(strict_types=1);

namespace Chiron\Invoker;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Chiron\Invoker\Exception\CannotResolveException;
use Chiron\Invoker\Exception\InvocationException;
use Chiron\Invoker\Exception\NotCallableException;
use Chiron\Reflection\ReflectionCallable;
use Chiron\Reflection\ReflectionCallable2;
use Chiron\Reflection\Reflection;
use Chiron\Reflection\Resolver;
use ReflectionObject;
use ReflectionClass;
use ReflectionFunction;
use ReflectionParameter;
use Closure;
use RuntimeException;
use ReflectionFunctionAbstract;
use InvalidArgumentException;
use Throwable;

use ReflectionMethod;
use ReflectionException;


//https://github.com/rdlowrey/auryn/blob/master/lib/Injector.php#L237
//https://github.com/yiisoft/injector/blob/master/src/Injector.php

final class Invoker implements InvokerInterface
{
    /** ContainerInterface */
    private $container;

    /** Resolver */
    private $resolver;

    /**
     * Invoker constructor.
     *
     * @param $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->resolver = new Resolver($container);
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

    public function call2($callback, array $params = [])
    {
        $resolver = new CallableResolver($this->container);
        try {
            $resolved = $resolver->resolve($callback);
        } catch (NotCallableException $e) {
            // check if the method we try to call is private or protected.
            $resolved = $resolver->resolveFromContainer($callback);
            if (! is_callable($resolved) && is_array($resolved) && method_exists($resolved[0], $resolved[1])) {

                $reflection = new ReflectionMethod($resolved[0], $resolved[1]);
                $reflection->setAccessible(true);

                //Invoking factory method with resolved arguments
                return $reflection->invokeArgs(
                    $resolved[0],
                    $this->resolver->resolveArguments($reflection, $params)
                );


            }
        }

        return $this->invoke($resolved, $params);
    }

    public function invoke(callable $callable, array $args = [])
    {

        // TODO : virer la classe ReflectionCallable et utiliser directement le code :
        //$callable = Closure::fromCallable($callable);
        //$reflection = new \ReflectionFunction($callable);

        $reflection = new ReflectionCallable($callable);
        $parameters = $this->resolver->resolveArguments($reflection, $args);

        //https://github.com/yiisoft/injector/blob/3bd38d4ebc70f39050e4ae056ac10c40c4975cb1/src/Injector.php#L65
        return call_user_func_array($callable, $parameters);
        //return $reflection->invoke($parameters);
    }

    //***********************


    public function call3($callback, array $params = [])
    {
        $resolved = (new CallableResolver($this->container))->resolveFromContainer($callback);

        return $this->invoke2($resolved, $params);
    }

    public function invoke2($callable, array $args = [])
    {
        $reflection = new ReflectionCallable2($callable);
        $parameters = $this->resolver->resolveArguments($reflection, $args);

        //return call_user_func_array($callable, $parameters);
        return $reflection->invokeArgs($parameters);
    }

    //**********************







/*
// TODO : utiliser ce bout de code et lever une exception si ce n'est pas un callable valide (throw InjectionException::fromInvalidCallable(xxx);)
//https://github.com/rdlowrey/auryn/blob/master/lib/InjectionException.php
//https://github.com/rdlowrey/auryn/blob/master/lib/Injector.php#L237
    private function isExecutable($exe)
    {
        if (is_callable($exe)) {
            return true;
        }
        if (is_string($exe) && method_exists($exe, '__invoke')) {
            return true;
        }
        if (is_array($exe) && isset($exe[0], $exe[1]) && method_exists($exe[0], $exe[1])) {
            return true;
        }

        return false;
    }
*/

    /**
     * @param string $type
     * @return bool
     */
    /*
    //https://github.com/zendframework/zend-di/blob/615fc00b55602d20506f228e939ac70792645e9b/src/Resolver/DependencyResolver.php#L208
    private function isCallableType(string $type): bool
    {
        if ($this->config->isAlias($type)) {
            $type = $this->config->getClassForAlias($type);
        }

        if (! class_exists($type) && ! interface_exists($type)) {
            return false;
        }

        $reflection = new ReflectionClass($type);

        return $reflection->hasMethod('__invoke')
            && $reflection->getMethod('__invoke')->isPublic();
    }*/


}
