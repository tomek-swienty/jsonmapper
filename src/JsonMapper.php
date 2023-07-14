<?php
use Illuminate\Support\Str;
use TSW\JSONMapper\Exception\Exception;

/**
 * JsonMapper
 *
 * @author Tomasz Świenty
 * @version 0.1
 * @copyright Copyright (c) eDokumenty
 */
final class JsonMapper {


    public $classMap = array();
    public $undefinedPropertyHandler = NULL;


    protected $logger;
    protected $cache = [];


    public function setLogger($logger) {

        $this->logger = $logger;

    }


    public function map($jsonObject, $targetObject) {

        if (!is_object($jsonObject)) {
            throw new Exception('Parametr $jsonObject nie jest obiektem');
        }

        if (!is_object($targetObject)) {
            throw new Exception('Parametr $targetObject nie jest obiektem');
        }

        $targetObjectReflection = new ReflectionObject($targetObject);
        $targetObjectClassNameAsString = $targetObjectReflection->getName();
        $targetObjectNamespaceAsString = $targetObjectReflection->getNamespaceName();

        foreach ($jsonObject as $propertyName => $propertyValue) {
            $propertyName = self::sanitizeKeyName($propertyName);

            // keszowania w tej funkcji
            [$hasProperty, $accessor, $type, $isNullable] = $this->inspectProperty($targetObjectReflection, $propertyName);

            if ($hasProperty === FALSE) {
                if (!is_null($this->undefinedPropertyHandler)) {
                    call_user_func($this->undefinedPropertyHandler, $targetObject, $propertyName, $propertyValue);
                }

                continue;
            }

            if (is_null($accessor)) {
                continue;
            }

            if (is_null($propertyValue)) {
                if ($isNullable) {
                    self::setProperty($targetObject, $accessor, NULL);
                    continue;
                }

                throw new Exception($targetObjectClassNameAsString.'=>'.$propertyName.' - NULL property value NOT ALLOWED');
            }

            $type = self::getFullNamespace($type, $targetObjectNamespaceAsString);
            $type = $this->getMappedType($type, $propertyValue);

            if ($type === NULL || $type === 'mixed') {
                //no given type - simply set the json data
                self::setProperty($targetObject, $accessor, $propertyValue);

                continue;
            } else if ($this->isObjectOfSameType($type, $propertyValue)) {
                self::setProperty($targetObject, $accessor, $propertyValue);

                continue;
            } else if ($this->isSimpleType($type)) {
                if ($type === 'string' && is_object($propertyValue)) {
                    throw new JsonMapper_Exception(
                        'JSON property "' . $propertyName . '" in class "'
                        . $targetObjectClassNameAsString . '" is an object and'
                        . ' cannot be converted to a string'
                    );
                }

                settype($propertyValue, $type);
                self::setProperty($targetObject, $accessor, $propertyValue);

                continue;
            }

            //FIXME: check if type exists, give detailed error message if not
            if ($type === '') {
                throw new JsonMapper_Exception(
                    'Empty type at property "'
                    . $targetObjectClassNameAsString . '::$' . $propertyName . '"'
                );
            } else if (strpos($type, '|')) {
                throw new JsonMapper_Exception(
                    'Cannot decide which of the union types shall be used: '
                    . $type
                );
            }

            $array = NULL;
            $subtype = NULL;
            if (self::isArrayOfType($type)) {
                //array
                $array = array();
                $subtype = substr($type, 0, -2);
            } else if (substr($type, -1) == ']') {
                [$proptype, $subtype] = explode('[', substr($type, 0, -1));
                if ($proptype == 'array') {
                    $array = array();
                } else {
                    $array = $this->createInstance($proptype, false, $propertyValue);
                }
            } else {
                if (is_a($type, 'ArrayObject', true)) {
                    $array = $this->createInstance($type, false, $propertyValue);
                }
            }

            if ($array !== NULL) {
                if (!is_array($propertyValue) && $this->isFlatType(gettype($propertyValue))) {
                    throw new JsonMapper_Exception(
                        'JSON property "' . $propertyName . '" must be an array, '
                        . gettype($propertyValue) . ' given'
                    );
                }

                $subtype = self::getFullNamespace($subtype, $targetObjectNamespaceAsString);
                $child = $this->mapArray($propertyValue, $array, $subtype, $propertyName);
            } else if ($this->isFlatType(gettype($propertyValue))) {
                $child = $this->createInstance($type, true, $propertyValue);
            } else {
                $child = $this->createInstance($type, false, $propertyValue);
                $this->map($propertyValue, $child);
            }

            self::setProperty($targetObject, $accessor, $child);
        }

        return $targetObject;

    }



    /**
     * Map an array
     *
     * @param array  $json       JSON array structure from json_decode()
     * @param mixed  $array      Array or ArrayObject that gets filled with
     *                           data from $json
     * @param string $class      Class name for children objects.
     *                           All children will get mapped onto this type.
     *                           Supports class names and simple types
     *                           like "string" and NULLability "string|NULL".
     *                           Pass "NULL" to not convert any values
     * @param string $parent_key Defines the key this array belongs to
     *                           in order to aid debugging.
     *
     * @return mixed Mapped $array is returned
     */
    public function mapArray($json, $array, $class = NULL, $parent_key = '') {

        $originalClass = $class;
        foreach ($json as $key => $jvalue) {
            $class = $this->getMappedType($originalClass, $jvalue);
            if ($class === NULL) {
                $array[$key] = $jvalue;
            } else if (self::isArrayOfType($class)) {
                $array[$key] = $this->mapArray(
                    $jvalue,
                    array(),
                    substr($class, 0, -2)
                );
            } else if ($this->isFlatType(gettype($jvalue))) {
                //use constructor parameter if we have a class
                // but only a flat type (i.e. string, int)
                if ($jvalue === NULL) {
                    $array[$key] = NULL;
                } else {
                    if ($this->isSimpleType($class)) {
                        settype($jvalue, $class);
                        $array[$key] = $jvalue;
                    } else {
                        $array[$key] = $this->createInstance(
                            $class, true, $jvalue
                        );
                    }
                }
            } else if ($this->isFlatType($class)) {
                throw new JsonMapper_Exception(
                    'JSON property "' . ($parent_key ? $parent_key : '?') . '"'
                    . ' is an array of type "' . $class . '"'
                    . ' but contained a value of type'
                    . ' "' . gettype($jvalue) . '"'
                );
            } else if (is_a($class, 'ArrayObject', true)) {
                $array[$key] = $this->mapArray(
                    $jvalue,
                    $this->createInstance($class)
                );
            } else {
                $array[$key] = $this->map(
                    $jvalue, $this->createInstance($class, false, $jvalue)
                );
            }
        }

        return $array;

    }


    protected function log($level, $message, array $context = []) {

        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }

    }


    protected static function getFullNamespace(&$type, $targetObjectNamespaceAsString) {

        if (is_null($type) OR ($targetObjectNamespaceAsString === '')) {
            return $type;
        }

        $type = self::removeNullableType($type);
        if (($type === NULL) OR ($type === '') OR ($type[0] === '\\')) {
            return $type;
        }

        [$first] = explode('[', $type, 2);

        if ($first === 'mixed' || $this->isSimpleType($first)) {
            return $type;
        }

        return '\\' . $targetObjectNamespaceAsString . '\\' . $type;

    }


    /**
     * Try to find out if a property exists in a given class.
     * Checks property first, falls back to setter method.
     *
     * @param ReflectionClass $rc   Reflection class to check
     * @param string          $name Property name
     *
     * @return array First value: if the property exists
     *               Second value: the accessor to use (
     *                 ReflectionMethod or ReflectionProperty, or NULL)
     *               Third value: type of the property
     *               Fourth value: if the property is NULLable
     */
    protected function inspectProperty(ReflectionObject $targetObjectReflection, $propertyName) {

        $targetObjectClassNameAsString = $targetObjectReflection->getName();

        if (isset($this->cache[$targetObjectClassNameAsString][$propertyName])) {
            return $this->cache[$targetObjectClassNameAsString][$propertyName];
        }


        $targetObjectReflection->getName();

        //try setter method first
        $setter = 'set' . self::getCamelCaseName($propertyName);

        if ($rc->hasMethod($setter)) {
            $rmeth = $rc->getMethod($setter);
            if ($rmeth->isPublic()) {
                $isNullable = false;
                $rparams = $rmeth->getParameters();
                if (count($rparams) > 0) {
                    $isNullable = $rparams[0]->allowsNull();
                    $ptype      = $rparams[0]->getType();
                    if ($ptype !== NULL) {
                        $typeName = $this->stringifyReflectionType($ptype);
                        //allow overriding an "array" type hint
                        // with a more specific class in the docblock
                        if ($typeName !== 'array') {
                            return array(
                                true, $rmeth,
                                $typeName,
                                $isNullable,
                            );
                        }
                    }
                }

                $docblock    = $rmeth->getDocComment();
                $annotations = static::parseAnnotations($docblock);

                if (!isset($annotations['param'][0])) {
                    return array(true, $rmeth, NULL, $isNullable);
                }
                [$type] = explode(' ', trim($annotations['param'][0]));
                return array(true, $rmeth, $type, self::isTypeNullable($type));
            }
        }

        //now try to set the property directly
        //we have to look it up in the class hierarchy
        $class = $rc;
        $rprop = NULL;
        do {
            if ($class->hasProperty($propertyName)) {
                $rprop = $class->getProperty($propertyName);
            }
        } while ($rprop === NULL && $class = $class->getParentClass());

        if ($rprop === NULL) {
            //case-insensitive property matching
            foreach ($rc->getProperties() as $p) {
                if ((strcasecmp($p->name, $propertyName) === 0)) {
                    $rprop = $p;
                    break;
                }
            }
        }
        if ($rprop !== NULL) {
            if ($rprop->isPublic()) {
                $docblock    = $rprop->getDocComment();
                $annotations = static::parseAnnotations($docblock);

                if (!isset($annotations['var'][0])) {
                    // If there is no annotations (higher priority) inspect
                    // if there's a scalar type being defined
                    if (PHP_VERSION_ID >= 70400 && $rprop->hasType()) {
                        $rPropType = $rprop->getType();
                        $propTypeName = $this->stringifyReflectionType($rPropType);
                        if ($this->isSimpleType($propTypeName)) {
                            return array(
                                true,
                                $rprop,
                                $propTypeName,
                                $rPropType->allowsNull()
                            );
                        }

                        return array(
                            true,
                            $rprop,
                            '\\' . ltrim($propTypeName, '\\'),
                            $rPropType->allowsNull()
                        );
                    }

                    return array(true, $rprop, NULL, false);
                }

                //support "@var type description"
                [$type] = explode(' ', $annotations['var'][0]);

                return array(true, $rprop, $type, self::isTypeNullable($type));
            } else {
                //no setter, private property
                return array(true, NULL, NULL, false);
            }
        }

        //no setter, no property
        return array(false, NULL, NULL, false);
    }


    protected static function getCamelCaseName($name) {

        return \Illuminate\Support\Str::camel($name);

    }


    protected static function sanitizeKeyName($name) {

        if (strpos($name, '-') !== false) {
            $name = self::getCamelCaseName($name);
        }

        return $name;

    }


    protected static function setProperty($targetObject, \Reflector $accessor, $propertyValue) {

        if (method_exists($accessor, 'setAccessible')) {
            $accessor->setAccessible(TRUE);
        }

        if ($accessor instanceof ReflectionProperty) {
            $accessor->setValue($targetObject, $propertyValue);
        } elseif ($accessor instanceof ReflectionMethod) {
            $accessor->invoke($targetObject, $propertyValue);
        }

    }


    /**
     * Create a new object of the given type.
     *
     * This method exists to be overwritten in child classes,
     * so you can do dependency injection or so.
     *
     * @param string  $class        Class name to instantiate
     * @param boolean $useParameter Pass $parameter to the constructor or not
     * @param mixed   $jvalue       Constructor parameter (the json value)
     *
     * @return object Freshly created object
     */
    protected function createInstance(
        $class, $useParameter = false, $jvalue = NULL
    ) {
        if ($useParameter) {
            return new $class($jvalue);
        } else {
            $reflectClass = new ReflectionClass($class);
            $constructor  = $reflectClass->getConstructor();
            if (NULL === $constructor
                || $constructor->getNumberOfRequiredParameters() > 0
            ) {
                return $reflectClass->newInstanceWithoutConstructor();
            }
            return $reflectClass->newInstance();
        }
    }

    /**
     * Get the mapped class/type name for this class.
     * Returns the incoming classname if not mapped.
     *
     * @param string $type   Type name to map
     * @param mixed  $jvalue Constructor parameter (the json value)
     *
     * @return string The mapped type/class name
     */
    protected function getMappedType($type, $jvalue = NULL) {

        if (isset($this->classMap[$type])) {
            $target = $this->classMap[$type];
        } else if (is_string($type) && $type !== '' && $type[0] == '\\' && isset($this->classMap[substr($type, 1)])) {
            $target = $this->classMap[substr($type, 1)];
        } else {
            $target = NULL;
        }

        if ($target) {
            if (is_callable($target)) {
                $type = $target($type, $jvalue);
            } else {
                $type = $target;
            }
        }

        return $type;

    }

    /**
     * Checks if the given type is a "simple type"
     *
     * @param string $type type name from gettype()
     *
     * @return boolean True if it is a simple PHP type
     *
     * @see isFlatType()
     */
    protected function isSimpleType($type)
    {
        return $type == 'string'
            || $type == 'boolean' || $type == 'bool'
            || $type == 'integer' || $type == 'int'
            || $type == 'double' || $type == 'float'
            || $type == 'array' || $type == 'object';
    }

    /**
     * Checks if the object is of this type or has this type as one of its parents
     *
     * @param string $type  class name of type being required
     * @param mixed  $value Some PHP value to be tested
     *
     * @return boolean True if $object has type of $type
     */
    protected function isObjectOfSameType($type, $value)
    {
        if (false === is_object($value)) {
            return false;
        }

        return is_a($value, $type);
    }

    /**
     * Checks if the given type is a type that is not nested
     * (simple type except array and object)
     *
     * @param string $type type name from gettype()
     *
     * @return boolean True if it is a non-nested PHP type
     *
     * @see isSimpleType()
     */
    protected function isFlatType($type)
    {
        return $type == 'NULL'
            || $type == 'string'
            || $type == 'boolean' || $type == 'bool'
            || $type == 'integer' || $type == 'int'
            || $type == 'double' || $type == 'float';
    }


    protected static function isArrayOfType($type) {

        return (preg_match('/\[\]$/', $type) === 1);

    }


    protected static function removeNullableType($type) {

        return preg_replace('/\|+/', '|', trim(str_ireplace('null', '', $type), '|'));

    }


    protected static function isTypeNullable($type) {

        return strlen(self::removeNullableType($type)) != strlen($type);

    }


    /**
     * Get a string representation of the reflection type.
     * Required because named, union and intersection types need to be handled.
     *
     * @param ReflectionType $type Native PHP type
     *
     * @return string "foo|bar"
     */
    protected function stringifyReflectionType(ReflectionType $type)
    {
        if ($type instanceof ReflectionNamedType) {
            return ($type->isBuiltin() ? '' : '\\') . $type->getName();
        }

        return implode(
            '|',
            array_map(
                function (ReflectionNamedType $type) {
                    return ($type->isBuiltin() ? '' : '\\') . $type->getName();
                },
                $type->getTypes()
            )
        );
    }

    /**
     * Copied from PHPUnit 3.7.29, Util/Test.php
     *
     * @param string $docblock Full method docblock
     *
     * @return array Array of arrays.
     *               Key is the "@"-name like "param",
     *               each value is an array of the rest of the @-lines
     */
    protected static function parseAnnotations($docblock)
    {
        $annotations = array();
        // Strip away the docblock header and footer
        // to ease parsing of one line annotations
        $docblock = substr($docblock, 3, -2);

        $re = '/@(?P<name>[A-Za-z_-]+)(?:[ \t]+(?P<value>.*?))?[ \t]*\r?$/m';
        if (preg_match_all($re, $docblock, $matches)) {
            $numMatches = count($matches[0]);

            for ($i = 0; $i < $numMatches; ++$i) {
                $annotations[$matches['name'][$i]][] = $matches['value'][$i];
            }
        }

        return $annotations;
    }

}