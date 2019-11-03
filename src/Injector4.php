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
// CHIRON Container - StrategyInvoker
class Injector4
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
     * Wrapper around the call_user_func_array function to execute the callable.
     *
     * @param callable $callback
     * @param array    $matched
     *
     * @return mixed
     */
    // TODO : renommer la méthode en invoke()
    public function call(callable $callback, array $matched)
    {
        $parameters = $this->bindParameters($callback, $matched);

        return call_user_func_array($callback, $parameters);
    }

    /**
     * Bind the matched parameters from the request with the callable parameters.
     *
     * @param callable $controller the callable to be executed
     * @param array    $matched    the parameters extracted from the uri
     *
     * @return array The
     */
    protected function bindParameters(callable $controller, array $matched): array
    {
        if (is_array($controller)) {
            $reflector = new \ReflectionMethod($controller[0], $controller[1]);
            $controllerName = sprintf('%s::%s()', get_class($controller[0]), $controller[1]);
        } elseif (is_object($controller) && ! $controller instanceof \Closure) {
            $reflector = (new \ReflectionObject($controller))->getMethod('__invoke');
            $controllerName = get_class($controller);
        } else {
            $controllerName = ($controller instanceof \Closure) ? get_class($controller) : $controller;
            $reflector = new \ReflectionFunction($controller);
        }

        $parameters = $reflector->getParameters();

        $bindParams = [];
        foreach ($parameters as $param) {
            // @notice \ReflectionType::getName() is not supported in PHP 7.0, that is why we use __toString()
            $paramType = $param->hasType() ? $param->getType()->__toString() : '';
            $paramClass = $param->getClass();

            if (array_key_exists($param->getName(), $matched)) {
                $bindParams[] = $this->transformToScalar($matched[$param->getName()], $paramType);
            } elseif ($paramClass && array_key_exists($paramClass->getName(), $matched)) {
                $bindParams[] = $matched[$paramClass->getName()];
            } elseif ($param->isDefaultValueAvailable()) {
                $bindParams[] = $param->getDefaultValue();
            //} elseif ($param->hasType() && $param->allowsNull()) {
            //    $result[] = null;
            } else {
                // can't find the value, or the default value for the parameter => throw an error
                throw new InvalidArgumentException(sprintf(
                    'Controller "%s" requires that you provide a value for the "$%s" argument (because there is no default value or because there is a non optional argument after this one).',
                    $controllerName,
                    $param->getName()
                ));
            }
        }

        return $bindParams;
    }

    /**
     * Transform parameter to scalar. We don't transform the string type.
     *
     * @param string $parameter the value of param
     * @param string $type      the tpe of param
     *
     * @return int|string|bool|float
     */
    private function transformToScalar(string $parameter, string $type)
    {
        switch ($type) {
            case 'int':
                $parameter = (int) $parameter;

                break;
            case 'bool':
                //TODO : utiliser plutot ce bout de code (il faudra surement faire un lowercase en plus !!!) :     \in_array(\trim($value), ['1', 'true'], true);
                $parameter = (bool) $parameter;

                break;
            case 'float':
                $parameter = (float) $parameter;

                break;
        }

        return $parameter;
    }
}
