<?php

namespace Chiron\Invoker\Exception;

class NotCallableException extends \Exception
{
    /**
     * @param mixed $value
     *
     * @return self
     */
    public static function fromInvalidCallable($value): self
    {
        if (is_object($value)) {
            $message = sprintf('Instance of %s is not a callable.', get_class($value));
        } elseif (is_array($value) && isset($value[0], $value[1])) {
            $class = is_object($value[0]) ? get_class($value[0]) : $value[0];
            $extra = method_exists($class, '__call') ? ' A __call() method exists but magic methods are not supported.' : '';
            $message = sprintf('%s::%s() is not a callable.%s', $class, $value[1], $extra);
        } else {
            $message = var_export($value, true) . ' is neither a callable nor a valid container entry.';
        }

        return new self($message);

        //return new self(self::getErrorMessage($value));
    }

    private static function getErrorMessage($value): string
    {
        if (\is_string($value)) {
            if (strpos($value, '::') !== false) {
                $value = explode('::', $value, 2);
            } elseif (substr_count($value, ':') === 1) {
                $value = explode(':', $value, 2);
            } else {
                return sprintf('Function "%s" does not exist.', $value);
            }
        }
        if (\is_object($value)) {
            $availableMethods = self::getClassMethodsWithoutMagicMethods($value);
            $alternativeMsg = $availableMethods ? sprintf(' or use one of the available methods: "%s"', implode('", "', $availableMethods)) : '';
            return sprintf('Controller class "%s" cannot be called without a method name. You need to implement "__invoke"%s.', \get_class($value), $alternativeMsg);
        }
        if (!\is_array($value)) {
            return sprintf('Invalid type for controller given, expected string, array or object, got "%s".', \gettype($value));
        }
        if (!isset($value[0]) || !isset($value[1]) || 2 !== \count($value)) {
            return 'Invalid array callable, expected [controller, method].';
        }
        list($controller, $method) = $value;
        if (\is_string($controller) && !class_exists($controller)) {
            return sprintf('Class "%s" does not exist.', $controller);
        }
        $className = \is_object($controller) ? \get_class($controller) : $controller;
        if (method_exists($controller, $method)) {
            return sprintf('Method "%s" on class "%s" should be public and non-abstract.', $method, $className);
        }

        $collection = self::getClassMethodsWithoutMagicMethods($controller);
        $alternatives = [];
        foreach ($collection as $item) {
            $lev = levenshtein($method, $item);
            if ($lev <= \strlen($method) / 3 || strpos($item, $method) !== false) {
                $alternatives[] = $item;
            }
        }
        asort($alternatives);

        $message = sprintf('Expected method "%s" on class "%s"', $method, $className);
        if (\count($alternatives) > 0) {
            $message .= sprintf(', did you mean "%s"?', implode('", "', $alternatives));
        } elseif ((\count($collection) > 0)) {
            $message .= sprintf('. Available methods: "%s".', implode('", "', $collection));
        }

        return $message;
    }

    private static function getClassMethodsWithoutMagicMethods($classOrObject): array
    {
        $methods = get_class_methods($classOrObject);
        return array_filter($methods, function (string $method) {
            return 0 !== strncmp($method, '__', 2);
        });
    }

}
