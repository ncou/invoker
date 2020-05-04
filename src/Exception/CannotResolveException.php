<?php

namespace Chiron\Invoker\Exception;

//https://github.com/spiral/core/blob/86ffeac422f2f368a890ccab71cf6a8b20668176/src/Exception/Container/ArgumentException.php

// TODO : renommer en CannotResolveParameterException ???? ou ParameterResolveException. ou renommer en ArgumentException ou ParameterException
class CannotResolveException extends InvocationException
{
    /**
     * @param string $parameter
     */
    public function __construct(\ReflectionParameter $parameter)
    {
        $function = $parameter->getDeclaringFunction();
        $location = $function->getName();

        if ($function instanceof \ReflectionMethod) {
            $location = $function->getDeclaringClass()->getName() . '::' . $location;
        }

        $this->line = $function->getStartLine();
        $this->file = $function->getFileName();
        $this->message = sprintf('Cannot resolve a value for parameter "$%s" in the callable "%s"', $parameter->getName(), $location);
    }
}
