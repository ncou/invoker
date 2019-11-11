<?php

namespace Chiron\Invoker\Exception;

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
