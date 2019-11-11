<?php

namespace Chiron\Invoker\Reflection;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionObject;
use Reflector;
use RuntimeException;

class ReflectionCallable extends ReflectionFunctionAbstract implements Reflector
{
    const TYPE_FUNCTION = 1;
    const TYPE_STATIC_METHOD = 2;
    const TYPE_INSTANCE_METHOD = 3;
    const TYPE_INVOKER = 4;
    const TYPE_CLOSURE = 5;

    /** @var callable */
    private $callee;

    /** @var ReflectionFunctionAbstract */
    private $reflection;

    /** @var int */
    private $reflectionType;

    /** @var boolean */
    private $magicMethod = false;

    /**
     * @param callable $callee
     */
    public function __construct(callable $callee)
    {
        $this->callee = $callee;
        $this->reflection = $this->getFunctionAbstractReflection($callee);
    }

    /**
     * @return string
     */
    public function getCallableName()
    {
        if (is_string($this->callee)) {
            return $this->callee;
        } elseif (is_array($this->callee)) {
            if (is_string($this->callee[0])) {
                return $this->callee[0] . '::' . ($this->isMagicMethod() ? '__callStatic' : $this->callee[1]);
            } else {
                return get_class($this->callee[0]) . '::' . ($this->isMagicMethod() ? '__call' : $this->callee[1]);
            }
        } elseif ($this->callee instanceof Closure) {
            return Closure::class;
        } elseif (is_object($this->callee)) {
            return get_class($this->callee) . '::__invoke';
        }

        // fallback..
        return $this->reflection->getShortName();
    }

    /**
     * @return boolean
     */
    public function isMagicMethod()
    {
        return $this->magicMethod;
    }

    /**
     * @param callable $callee
     * @return ReflectionFunctionAbstract
     */
    protected function getFunctionAbstractReflection(callable $callee)
    {
        // closure, or function name,
        if ($callee instanceof Closure) {
            $this->reflectionType = static::TYPE_CLOSURE;
            return new ReflectionFunction($callee);
        } elseif (is_string($callee) && strpos($callee, '::') === false) {
            $this->reflectionType = static::TYPE_FUNCTION;
            return new ReflectionFunction($callee);
        }
        if (is_string($callee)) {
            $callee = explode('::', $callee);
        } elseif (is_object($callee)) {
            $this->reflectionType = static::TYPE_INVOKER;
            $callee = [$callee, '__invoke'];
        }
        if (is_object($callee[0])) {
            if (!isset($this->reflectionType)) {
                $this->reflectionType = static::TYPE_INSTANCE_METHOD;
            }
            $reflection = new ReflectionObject($callee[0]);
            if ($reflection->hasMethod($callee[1])) {
                return $reflection->getMethod($callee[1]);
            }
            $this->magicMethod = true;
            return $reflection->getMethod('__call');
        }

        $this->reflectionType = static::TYPE_STATIC_METHOD;
        $reflection = new ReflectionClass($callee[0]);
        if ($reflection->hasMethod($callee[1])) {
            return $reflection->getMethod($callee[1]);
        }
        $this->magicMethod = true;
        return $reflection->getMethod('__callStatic');
    }

    /**
     * @return int
     */
    public function getReflectionType()
    {
        return $this->reflectionType;
    }

    /**
     * @return \ReflectionFunctionAbstract
     */
    public function getRawReflection()
    {
        return $this->reflection;
    }

    /**
     * @param ...mixed $params
     * @return mixed
     */
    public function __invoke()
    {
        return call_user_func_array($this->callee, func_get_args());
    }

    /**
     * {@inheritdoc}
     */
    public function getReturnType()
    {
        return $this->reflection->getReturnType();
    }

    /**
     * {@inheritdoc}
     */
    public function isGenerator()
    {
        return $this->reflection->isGenerator();
    }

    /**
     * {@inheritdoc}
     */
    public function isVariadic()
    {
        return $this->reflection->isVariadic();
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        return $this->reflection->__toString();
    }

    /**
     * {@inheritdoc}
     */
    public static function export()
    {
        throw new RuntimeException('not implemented method.');
    }

    /**
     * {@inheritdoc}
     */
    public function inNamespace()
    {
        return $this->reflection->inNamespace();
    }

    /**
     * {@inheritdoc}
     */
    public function isClosure()
    {
        return $this->reflection->isClosure();
    }

    /**
     * {@inheritdoc}
     */
    public function isDeprecated()
    {
        return $this->reflection->isDeprecated();
    }

    /**
     * {@inheritdoc}
     */
    public function isInternal()
    {
        return $this->reflection->isInternal();
    }

    /**
     * {@inheritdoc}
     */
    public function isUserDefined()
    {
        return $this->reflection->isUserDefined();
    }

    /**
     * {@inheritdoc}
     */
    public function getClosureThis()
    {
        return $this->reflection->getClosureThis();
    }

    /**
     * {@inheritdoc}
     */
    public function getClosureScopeClass()
    {
        return $this->reflection->getClosureScopeClass();
    }

    /**
     * {@inheritdoc}
     */
    public function getDocComment()
    {
        return $this->reflection->getDocComment();
    }

    /**
     * {@inheritdoc}
     */
    public function getEndLine()
    {
        return $this->reflection->getEndLine();
    }

    /**
     * {@inheritdoc}
     */
    public function getExtension()
    {
        return $this->reflection->getExtension();
    }

    /**
     * {@inheritdoc}
     */
    public function getExtensionName()
    {
        return $this->reflection->getExtensionName();
    }

    /**
     * {@inheritdoc}
     */
    public function getFileName()
    {
        return $this->reflection->getFileName();
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        if ($this->magicMethod) {
            return $this->callee[1];
        }
        return $this->reflection->getName();
    }

    /**
     * {@inheritdoc}
     */
    public function getNamespaceName()
    {
        return $this->reflection->getNamespaceName();
    }

    /**
     * {@inheritdoc}
     */
    public function getNumberOfParameters()
    {
        if ($this->magicMethod) {
            return 0;
        }
        return $this->reflection->getNumberOfParameters();
    }

    /**
     * {@inheritdoc}
     */
    public function getNumberOfRequiredParameters()
    {
        if ($this->magicMethod) {
            return 0;
        }
        return $this->reflection->getNumberOfRequiredParameters();
    }

    /**
     * {@inheritdoc}
     */
    public function getParameters()
    {
        if ($this->magicMethod) {
            return [];
        }
        return $this->reflection->getParameters();
    }

    /**
     * {@inheritdoc}
     */
    public function getShortName()
    {
        if ($this->magicMethod) {
            return $this->callee[1];
        }
        return $this->reflection->getShortName();
    }

    /**
     * {@inheritdoc}
     */
    public function getStartLine()
    {
        return $this->reflection->getStartLine();
    }

    /**
     * {@inheritdoc}
     */
    public function getStaticVariables()
    {
        return $this->reflection->getStaticVariables();
    }

    /**
     * {@inheritdoc}
     */
    public function returnsReference()
    {
        return $this->reflection->returnsReference();
    }
}
