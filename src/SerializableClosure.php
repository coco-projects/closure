<?php

    declare (strict_types = 1);

    namespace Coco\closure;

    use Closure;
    use SplObjectStorage;
    use ReflectionObject;

class SerializableClosure
{
    protected $closure;

    protected ?ReflectionClosure $reflector = null;

    protected $code;

    protected $reference;

    protected $scope;

    protected static $context;

    const ARRAY_RECURSIVE_KEY = 'array_recursive_key';

    public function __construct($closure)
    {
        $this->closure = $closure;

        if (static::$context !== null) {
            $this->scope = static::$context->scope;
            $this->scope->toserialize++;
        }
    }

    public function getClosure()
    {
        return $this->closure;
    }

    public function getReflector(): ReflectionClosure
    {
        if ($this->reflector === null) {
            $this->reflector = new ReflectionClosure($this->closure);
            $this->code      = null;
        }

        return $this->reflector;
    }

    public function __invoke()
    {
        return call_user_func_array($this->closure, func_get_args());
    }

    protected function transformUseVariables($data)
    {
        return $data;
    }

    protected function resolveUseVariables($data)
    {
        return $data;
    }

    public static function from($closure)
    {
        if (static::$context === null) {
            $instance = new static($closure);
        } elseif (isset(static::$context->scope[$closure])) {
            $instance = static::$context->scope[$closure];
        } else {
            $instance = new static($closure);

            static::$context->scope[$closure] = $instance;
        }

        return $instance;
    }

    public static function enterContext(): void
    {
        if (static::$context === null) {
            static::$context = new ClosureContext();
        }

        static::$context->locks++;
    }

    public static function exitContext(): void
    {
        if (static::$context !== null && !--static::$context->locks) {
            static::$context = null;
        }
    }

    public static function wrapClosures(&$data, SplObjectStorage $storage = null): void
    {
        if ($storage === null) {
            $storage = static::$context->scope;
        }

        if ($data instanceof Closure) {
            $data = static::from($data);
        } elseif (is_array($data)) {
            if (isset($data[self::ARRAY_RECURSIVE_KEY])) {
                return;
            }
            $data[self::ARRAY_RECURSIVE_KEY] = true;
            foreach ($data as $key => &$value) {
                if ($key === self::ARRAY_RECURSIVE_KEY) {
                    continue;
                }
                static::wrapClosures($value, $storage);
            }
            unset($value);
            unset($data[self::ARRAY_RECURSIVE_KEY]);
        } elseif ($data instanceof \stdClass) {
            if (isset($storage[$data])) {
                $data = $storage[$data];

                return;
            }
            $data = $storage[$data] = clone($data);
            foreach ($data as &$value) {
                static::wrapClosures($value, $storage);
            }
            unset($value);
        } elseif (is_object($data) && !$data instanceof static) {
            if (isset($storage[$data])) {
                $data = $storage[$data];

                return;
            }
            $instance   = $data;
            $reflection = new ReflectionObject($instance);
            if (!$reflection->isUserDefined()) {
                $storage[$instance] = $data;

                return;
            }
            $storage[$instance] = $data = $reflection->newInstanceWithoutConstructor();

            do {
                if (!$reflection->isUserDefined()) {
                    break;
                }
                foreach ($reflection->getProperties() as $property) {
                    if ($property->isStatic() || !$property->getDeclaringClass()->isUserDefined()) {
                        continue;
                    }
                    $property->setAccessible(true);
                    if (PHP_VERSION >= 7.4 && !$property->isInitialized($instance)) {
                        continue;
                    }
                    $value = $property->getValue($instance);
                    if (is_array($value) || is_object($value)) {
                        static::wrapClosures($value, $storage);
                    }
                    $property->setValue($data, $value);
                };
            } while ($reflection = $reflection->getParentClass());
        }
    }

    public static function unwrapClosures(&$data, SplObjectStorage $storage = null): void
    {
        if ($storage === null) {
            $storage = static::$context->scope;
        }

        if ($data instanceof static) {
            $data = $data->getClosure();
        } elseif (is_array($data)) {
            if (isset($data[self::ARRAY_RECURSIVE_KEY])) {
                return;
            }
            $data[self::ARRAY_RECURSIVE_KEY] = true;
            foreach ($data as $key => &$value) {
                if ($key === self::ARRAY_RECURSIVE_KEY) {
                    continue;
                }
                static::unwrapClosures($value, $storage);
            }
            unset($data[self::ARRAY_RECURSIVE_KEY]);
        } elseif ($data instanceof \stdClass) {
            if (isset($storage[$data])) {
                return;
            }
            $storage[$data] = true;
            foreach ($data as &$property) {
                static::unwrapClosures($property, $storage);
            }
        } elseif (is_object($data) && !($data instanceof Closure)) {
            if (isset($storage[$data])) {
                return;
            }
            $storage[$data] = true;
            $reflection     = new ReflectionObject($data);

            do {
                if (!$reflection->isUserDefined()) {
                    break;
                }
                foreach ($reflection->getProperties() as $property) {
                    if ($property->isStatic() || !$property->getDeclaringClass()->isUserDefined()) {
                        continue;
                    }
                    $property->setAccessible(true);
                    if (PHP_VERSION >= 7.4 && !$property->isInitialized($data)) {
                        continue;
                    }
                    $value = $property->getValue($data);
                    if (is_array($value) || is_object($value)) {
                        static::unwrapClosures($value, $storage);
                        $property->setValue($data, $value);
                    }
                };
            } while ($reflection = $reflection->getParentClass());
        }
    }

    public static function createClosure($args, $code)
    {
        ClosureStream::register();

        return include(ClosureStream::STREAM_PROTO . '://function(' . $args . '){' . $code . '};');
    }

    protected function mapPointers(&$data): void
    {
        $scope = $this->scope;

        if ($data instanceof static) {
            $data = &$data->closure;
        } elseif (is_array($data)) {
            if (isset($data[self::ARRAY_RECURSIVE_KEY])) {
                return;
            }

            $data[self::ARRAY_RECURSIVE_KEY] = true;

            foreach ($data as $key => &$value) {
                if ($key === self::ARRAY_RECURSIVE_KEY) {
                    continue;
                } elseif ($value instanceof static) {
                    $data[$key] = &$value->closure;
                } elseif ($value instanceof SelfReference && $value->hash === $this->code['self']) {
                    $data[$key] = &$this->closure;
                } else {
                    $this->mapPointers($value);
                }
            }

            unset($value);
            unset($data[self::ARRAY_RECURSIVE_KEY]);
        } elseif ($data instanceof \stdClass) {
            if (isset($scope[$data])) {
                return;
            }

            $scope[$data] = true;

            foreach ($data as $key => &$value) {
                if ($value instanceof SelfReference && $value->hash === $this->code['self']) {
                    $data->{$key} = &$this->closure;
                } elseif (is_array($value) || is_object($value)) {
                    $this->mapPointers($value);
                }
            }
            unset($value);
        } elseif (is_object($data) && !($data instanceof Closure)) {
            if (isset($scope[$data])) {
                return;
            }
            $scope[$data] = true;
            $reflection   = new ReflectionObject($data);
            do {
                if (!$reflection->isUserDefined()) {
                    break;
                }
                foreach ($reflection->getProperties() as $property) {
                    if ($property->isStatic() || !$property->getDeclaringClass()->isUserDefined()) {
                        continue;
                    }
                    $property->setAccessible(true);
                    if (PHP_VERSION >= 7.4 && !$property->isInitialized($data)) {
                        continue;
                    }
                    $item = $property->getValue($data);
                    if ($item instanceof SerializableClosure || ($item instanceof SelfReference && $item->hash === $this->code['self'])) {
                        $this->code['objects'][] = [
                            'instance' => $data,
                            'property' => $property,
                            'object'   => $item instanceof SelfReference ? $this : $item,
                        ];
                    } elseif (is_array($item) || is_object($item)) {
                        $this->mapPointers($item);
                        $property->setValue($data, $item);
                    }
                }
            } while ($reflection = $reflection->getParentClass());
        }
    }

    protected function mapByReference(&$data): void
    {
        if ($data instanceof Closure) {
            if ($data === $this->closure) {
                $data = new SelfReference($this->reference);

                return;
            }

            if (isset($this->scope[$data])) {
                $data = $this->scope[$data];

                return;
            }

            $instance = new static($data);

            if (static::$context !== null) {
                static::$context->scope->toserialize--;
            } else {
                $instance->scope = $this->scope;
            }

            $data = $this->scope[$data] = $instance;
        } elseif (is_array($data)) {
            if (isset($data[self::ARRAY_RECURSIVE_KEY])) {
                return;
            }
            $data[self::ARRAY_RECURSIVE_KEY] = true;
            foreach ($data as $key => &$value) {
                if ($key === self::ARRAY_RECURSIVE_KEY) {
                    continue;
                }
                $this->mapByReference($value);
            }
            unset($value);
            unset($data[self::ARRAY_RECURSIVE_KEY]);
        } elseif ($data instanceof \stdClass) {
            if (isset($this->scope[$data])) {
                $data = $this->scope[$data];

                return;
            }
            $instance               = $data;
            $this->scope[$instance] = $data = clone($data);

            foreach ($data as &$value) {
                $this->mapByReference($value);
            }
            unset($value);
        } elseif (is_object($data) && !$data instanceof SerializableClosure) {
            if (isset($this->scope[$data])) {
                $data = $this->scope[$data];

                return;
            }

            $instance   = $data;
            $reflection = new ReflectionObject($data);
            if (!$reflection->isUserDefined()) {
                $this->scope[$instance] = $data;

                return;
            }
            $this->scope[$instance] = $data = $reflection->newInstanceWithoutConstructor();

            do {
                if (!$reflection->isUserDefined()) {
                    break;
                }
                foreach ($reflection->getProperties() as $property) {
                    if ($property->isStatic() || !$property->getDeclaringClass()->isUserDefined()) {
                        continue;
                    }

                    $property->setAccessible(true);

                    if (PHP_VERSION >= 7.4 && !$property->isInitialized($instance)) {
                        continue;
                    }
                    $value = $property->getValue($instance);
                    if (is_array($value) || is_object($value)) {
                        $this->mapByReference($value);
                    }
                    $property->setValue($data, $value);
                }
            } while ($reflection = $reflection->getParentClass());
        }
    }

    public function serialize(): string
    {
        if ($this->scope === null) {
            $this->scope = new ClosureScope();
            $this->scope->toserialize++;
        }

        $this->scope->serializations++;

        $scope     = $object = null;
        $reflector = $this->getReflector();

        if ($reflector->isBindingRequired()) {
            $object = $reflector->getClosureThis();
            static::wrapClosures($object, $this->scope);
            if ($scope = $reflector->getClosureScopeClass()) {
                $scope = $scope->name;
            }
        } else {
            if ($scope = $reflector->getClosureScopeClass()) {
                $scope = $scope->name;
            }
        }

        $this->reference = spl_object_hash($this->closure);

        $this->scope[$this->closure] = $this;

        $use  = $this->transformUseVariables($reflector->getUseVariables());
        $code = $reflector->getCode();
        $this->mapByReference($use);

        $ret = \serialize([
            'use'      => $use,
            'function' => $code,
            'scope'    => $scope,
            'this'     => $object,
            'self'     => $this->reference,
        ]);

        if (!--$this->scope->serializations && !--$this->scope->toserialize) {
            $this->scope = null;
        }

        return $ret;
    }

    public function unserialize($data): void
    {
        ClosureStream::register();

        if ($data[0] === '@') {
            if ($data[1] !== '{') {
                $separator = strpos($data, '.');
                if ($separator === false) {
                    throw new SecurityException('Invalid signed closure');
                }
                $hash    = substr($data, 1, $separator - 1);
                $closure = substr($data, $separator + 1);

                $data = [
                    'hash'    => $hash,
                    'closure' => $closure,
                ];

                unset($hash, $closure);
            } else {
                $data = json_decode(substr($data, 1), true);
            }

            if (!is_array($data) || !isset($data['closure']) || !isset($data['hash'])) {
                throw new SecurityException('Invalid signed closure');
            }

            $data = $data['closure'];
        }

        $this->code = \unserialize($data);

        unset($data);

        $this->code['objects'] = [];

        if ($this->code['use']) {
            $this->scope       = new ClosureScope();
            $this->code['use'] = $this->resolveUseVariables($this->code['use']);
            $this->mapPointers($this->code['use']);
            extract($this->code['use'], EXTR_OVERWRITE | EXTR_REFS);
            $this->scope = null;
        }

        $this->closure = include(ClosureStream::STREAM_PROTO . '://' . $this->code['function']);

        if ($this->code['this'] === $this) {
            $this->code['this'] = null;
        }

        $this->closure = $this->closure->bindTo($this->code['this'], $this->code['scope']);

        if (!empty($this->code['objects'])) {
            foreach ($this->code['objects'] as $item) {
                $item['property']->setValue($item['instance'], $item['object']->getClosure());
            }
        }

        $this->code = $this->code['function'];
    }

    public function __serialize(): array
    {
        if ($this->scope === null) {
            $this->scope = new ClosureScope();
            $this->scope->toserialize++;
        }

        $this->scope->serializations++;

        $scope = $object = null;

        $reflector = $this->getReflector();

        if ($reflector->isBindingRequired()) {
            $object = $reflector->getClosureThis();
            static::wrapClosures($object, $this->scope);
            if ($scope = $reflector->getClosureScopeClass()) {
                $scope = $scope->name;
            }
        } else {
            if ($scope = $reflector->getClosureScopeClass()) {
                $scope = $scope->name;
            }
        }

        $this->reference = spl_object_hash($this->closure);

        $this->scope[$this->closure] = $this;

        $use  = $this->transformUseVariables($reflector->getUseVariables());
        $code = $reflector->getCode();
        $this->mapByReference($use);

        return [
            'use'      => $use,
            'function' => $code,
            'scope'    => $scope,
            'this'     => $object,
            'self'     => $this->reference,
        ];
    }

    public function __unserialize(array $data): void
    {
        ClosureStream::register();

        $this->code = $data;

        $this->code['objects'] = [];

        if ($this->code['use']) {
            $this->scope       = new ClosureScope();
            $this->code['use'] = $this->resolveUseVariables($this->code['use']);
            $this->mapPointers($this->code['use']);
            extract($this->code['use'], EXTR_OVERWRITE | EXTR_REFS);
            $this->scope = null;
        }

        $this->closure = include(ClosureStream::STREAM_PROTO . '://' . $this->code['function']);

        if ($this->code['this'] === $this) {
            $this->code['this'] = null;
        }

        $this->closure = $this->closure->bindTo($this->code['this'], $this->code['scope']);

        if (!empty($this->code['objects'])) {
            foreach ($this->code['objects'] as $item) {
                $item['property']->setValue($item['instance'], $item['object']->getClosure());
            }
        }

        $this->code = $this->code['function'];
    }
}
