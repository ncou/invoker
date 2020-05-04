<?php

declare(strict_types=1);

namespace Chiron\Invoker;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Chiron\Invoker\Exception\CannotResolveException;
use Chiron\Invoker\Exception\InvocationException;
use Chiron\Invoker\Exception\NotCallableException;
use Chiron\Invoker\Reflection\ReflectionCallable;
use Chiron\Invoker\Reflection\ReflectionCallable2;
use Chiron\Invoker\Reflection\Reflection;
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

final class Invoker implements InvokerInterface
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
                    $this->resolveArguments($reflection, $params)
                );


            }
        }

        return $this->invoke($resolved, $params);
    }

    public function invoke(callable $callable, array $args = [])
    {
        $reflection = new ReflectionCallable($callable);
        $parameters = $this->resolveArguments($reflection, $args);

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
        $parameters = $this->resolveArguments($reflection, $args);

        //return call_user_func_array($callable, $parameters);
        return $reflection->invokeArgs($parameters);
    }

    //**********************




    public function resolveArguments(ReflectionFunctionAbstract $reflection, array $parameters = []): array {
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {

            try {


                //Information we need to know about argument in order to resolve it's value
                $name = $parameter->getName();
                $class = $parameter->getClass();



            } catch (\ReflectionException $e) {

                //throw new CannotResolveException($parameter);


                //Possibly invalid class definition or syntax error
                throw new InvocationException(sprintf('Invalid value for parameter %s', Reflection::toString($parameter)), $e->getCode());
                //throw new InvocationException("Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}", $e->getCode());
                //throw new InvocationException("Unresolvable dependency resolving [$parameter] in function " . $parameter->getDeclaringClass()->getName() . '::' . $parameter->getDeclaringFunction()->getName(), $e->getCode());
            }


            //die(var_dump($class));

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
                    //$arguments[] = $parameter->getDefaultValue();
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
        if ($value === null) {
            if (!$parameter->isOptional() &&
                !($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === null)
            ) {
                throw new CannotResolveException($parameter);
            }
            return;
        }

        // TODO : utiliser la méthode hasType()
        $type = $parameter->getType();

        if ($type === null) {
            return;
        }

        // TODO : on devrait aussi vérifier que la classe est identique, et vérifier aussi le type string pour que cette méthode soit plus générique. Vérifier ce qui se passe si on fait pas cette vérification c'est à dire appeller une fonction avec des paramétres qui n'ont pas le bon typehint !!!!
        $typeName = $type->getName();
        if ($typeName == 'array' && !is_array($value)) {
            throw new CannotResolveException($parameter);
        }
        if (($typeName == 'int' || $typeName == 'float') && !is_numeric($value)) {
            throw new CannotResolveException($parameter);
        }
        if ($typeName == 'bool' && !is_bool($value) && !is_numeric($value)) {
            throw new CannotResolveException($parameter);
        }
    }


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
