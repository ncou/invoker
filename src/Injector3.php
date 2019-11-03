<?php
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

/**
 * Injector is able to analyze callable dependencies based on
 * type hinting and inject them from any PSR-11 compatible container.
 */
// CHIRON Container
class Injector3
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

    public function call(callable $callable, array $args = [])
    {
        $args = $this->resolveArguments($args);

        $reflection = $this->reflectCallable($callable);

        return call_user_func_array(
                $callable,
                $this->getParameters($reflection, $args)
            );
    }

    // TODO : ajouter la signature dans l'interface
    // TODO : regarder aussi ici : https://github.com/mrferos/di/blob/master/src/Definition/AbstractDefinition.php#L75
    // TODO : regarder ici pour utiliser le arobase @    https://github.com/slince/di/blob/master/DefinitionResolver.php#L210
    public function resolveArguments(array $arguments): array
    {
        foreach ($arguments as &$arg) {
            if (! is_string($arg)) {
                continue;
            }
            //if (! is_null($this->container) && $this->container->has($arg)) {
            if ($this->container->has($arg)) {
                $arg = $this->container->get($arg);
                continue;
            }
        }
        return $arguments;
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

    /**
     * @param \ReflectionFunctionAbstract $reflection
     * @param array                       $arguments
     *
     * @return array
     */
    // TODO : renommer en getMethodDependencies() ou plutot en reflectArguments(ReflectionFunctionAbstract $method, array $args = []) : array ou alors en resolveFunctionArguments()
    protected function getParameters(ReflectionFunctionAbstract $reflection, array $arguments = []): array
    {
        // TODO : améliorer ce bout de code ******************
        $parametersToReturn = static::getSeqArray($arguments); // utiliser plutot ce bout de code pour éviter d'initialiser un tableau lorsque les clés sont numeriques => https://github.com/illuminate/container/blob/master/BoundMethod.php#L119
        $reflectionParameters = array_slice($reflection->getParameters(), count($parametersToReturn));
        if (! count($reflectionParameters)) {
            return $parametersToReturn;
        }
        // TODO END ******************************************
        /* @var \ReflectionParameter $param */
        foreach ($reflectionParameters as $param) {
            /*
             * #1. search in arguments by parameter name
             * #1.1. search in arguments by class name
             * #2. if parameter has type hint
             * #2.1. search in container by class name
             * #3. if has default value, insert default value.
             * #4. exception
             */
            $paramName = $param->getName();
            try {
                if (array_key_exists($paramName, $arguments)) { // #1.
                    $parametersToReturn[] = $arguments[$paramName];
                    continue;
                }
                $paramClass = $param->getClass();
                if ($paramClass) { // #2.
                    $paramClassName = $paramClass->getName();
                    if (array_key_exists($paramClassName, $arguments)) {
                        $parametersToReturn[] = $arguments[$paramClassName];
                        continue;
                    } else { // #2.1.
                        try {
                            // TODO : on devrait pas créer une méthode make() qui soit un alias de get ? => https://github.com/illuminate/container/blob/master/Container.php#L616
                            // TODO : https://github.com/illuminate/container/blob/master/Container.php#L925
                            // TODO : ajouter des tests dans le cas ou la classe passée en parameter est optionnelle (cad avec une valeur par défaut), il faudrait aussi faire un test avec "?ClassObject" voir si on passe null par défaut ou si on léve une exception car la classe n'existe pas !!!! => https://github.com/illuminate/container/blob/master/Container.php#L935
                            $parametersToReturn[] = $this->container->get($paramClassName);
                            continue;
                        } catch (ContainerExceptionInterface $e) {
                        }
                    }
                }
                if ($param->isDefaultValueAvailable()) { // #3.
                    $parametersToReturn[] = $param->getDefaultValue();
                    continue;
                }
                throw new RuntimeException("Parameter '{$paramName}' cannot be resolved"); // #4.
            } catch (ReflectionException $e) {
                // ReflectionException is thrown when the class doesn't exist.
                throw new RuntimeException("Parameter '{$paramName}' cannot be resolved");
            }
        }
        return $parametersToReturn;
    }
    /**
     * @param array $array
     *
     * @return array
     */
    // TODO : essayer ce bout de code pour améliorer les choses : https://github.com/slince/di/blob/master/DefinitionResolver.php#L159
    protected static function getSeqArray(array $array): array
    {
        $arrayToReturn = [];
        foreach ($array as $key => $item) {
            if (is_int($key)) {
                $arrayToReturn[] = $item;
            }
        }
        return $arrayToReturn;
    }
}
