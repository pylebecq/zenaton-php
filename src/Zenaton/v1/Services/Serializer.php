<?php

namespace Zenaton\Services;

use SuperClosure\Serializer as ClosureSerializer;

/**
 * Serialize and deserialize data.
 *
 * This serializer is able to correctly deal with recursion in data. It will normalize data to an array, and
 * then output this array as JSON.
 *
 * @internal Should not be called by user code.
 */
final class Serializer
{
    /** @var string Prefix used in identifiers of objects in store */
    const ID_PREFIX = '@zenaton#';

    const KEY_OBJECT = 'o';
    const KEY_OBJECT_NAME = 'n';
    const KEY_OBJECT_PROPERTIES = 'p';
    const KEY_ARRAY = 'a';
    const KEY_CLOSURE = 'c';
    const KEY_DATA = 'd';
    const KEY_STORE = 's';

    /** @var ClosureSerializer */
    private $closure;
    /** @var Properties */
    private $properties;
    /** @var array Stores encoded version of objects */
    private $encoded;
    /** @var array Stores decoded version of objects */
    private $decoded;

    public function __construct()
    {
        $this->closure = new ClosureSerializer();
        $this->properties = new Properties();
    }

    /**
     * Encode data.
     *
     * @param mixed $data
     *
     * @return false|string
     */
    public function encode($data)
    {
        $this->encoded = [];
        $this->decoded = [];

        $value = [];
        if ($this->isBasicType($data)) {
            $value[self::KEY_DATA] = $data;
        } elseif ($this->isResource($data)) {
            $this->throwResourceException();
        } else {
            $value[self::KEY_OBJECT] = $this->encodeToStore($data);
        }
        // $this->encoded may have been updated by encodeToStore.
        // ksort is used because the store is filled in a non linear order, making json_encode to return a json object
        // instead of a json array. Using ksort fixes this behavior.
        ksort($this->encoded, SORT_NUMERIC);
        $value[self::KEY_STORE] = $this->encoded;

        return json_encode($value);
    }

    /**
     * Decode data that were previously encoded by the serializer.
     *
     * @param string $json
     *
     * @return mixed
     */
    public function decode($json)
    {
        $array = $this->jsonDecode($json);

        $this->decoded = [];
        $this->encoded = $array[self::KEY_STORE];

        if (array_key_exists(self::KEY_DATA, $array)) {
            return $array[self::KEY_DATA];
        }

        if (array_key_exists(self::KEY_OBJECT, $array)) {
            return $this->decodeFromStore($array[self::KEY_OBJECT]);
        }

        // Legacy array format. Deprecated since 0.3.0.
        if (array_key_exists(self::KEY_ARRAY, $array)) {
            return $this->decodeLegacyArray($array[self::KEY_ARRAY]);
        }

        // Legacy closure format. Deprecated since 0.3.0.
        if (array_key_exists(self::KEY_CLOSURE, $array)) {
            return $this->decodeFromStore($array[self::KEY_CLOSURE]);
        }

        throw new \UnexpectedValueException('Unknown key in: '.$json);
    }

    /**
     * Encode a value.
     *
     * Resources are not supported for encoding.
     * Basic types are return without change because they can be natively serialized to JSON. Complex types are
     * encoded in the store.
     *
     * @param mixed $data
     *
     * @return mixed The provided data if it's a basic type or a string containing the store id for complex types
     */
    private function encodeValue($data)
    {
        if ($this->isResource($data)) {
            $this->throwResourceException();
        }

        if ($this->isBasicType($data)) {
            return $data;
        }

        return $this->encodeToStore($data);
    }

    /**
     * Encode data to the store.
     *
     * If data is already found in the store, it is not stored again and its store id is returned immediately.
     *
     * @param mixed $data
     *
     * @return string The assigned store identifier
     */
    private function encodeToStore($data)
    {
        $index = array_search($data, $this->decoded, true);
        if ($index !== false) {
            return $this->getStoreId($index);
        }

        $index = count($this->decoded);
        $this->decoded[$index] = $data;

        if (is_object($data)) {
            if ($data instanceof \Closure) {
                $this->encoded[$index] = $this->encodeClosure($data);

                return $this->getStoreId($index);
            }

            $this->encoded[$index] = $this->encodeObject($data);

            return $this->getStoreId($index);
        }

        if (is_array($data)) {
            $this->encoded[$index] = $this->encodeArray($data);

            return $this->getStoreId($index);
        }

        throw new \UnexpectedValueException('Reached end of method Serializer::encodeToStore() without being able to encode the given value');
    }

    /**
     * Encode an object.
     *
     * @param object $o
     *
     * @return array
     */
    private function encodeObject($o)
    {
        return [
            self::KEY_OBJECT_NAME => get_class($o),
            self::KEY_OBJECT_PROPERTIES => $this->encodeObjectProperties($this->properties->getPropertiesFromObject($o)),
        ];
    }

    /**
     * Encode object properties.
     *
     * @param array $array An array with property names as keys and their values
     *
     * @return array
     */
    private function encodeObjectProperties(array $array)
    {
        return array_map([$this, 'encodeValue'], $array);
    }

    /**
     * Encode a closure.
     *
     * @return array
     */
    private function encodeClosure(\Closure $c)
    {
        return [
            self::KEY_CLOSURE => $this->closure->serialize($c),
        ];
    }

    /**
     * Encode an array.
     *
     * @return array
     */
    private function encodeArray(array $a)
    {
        return [
            self::KEY_ARRAY => array_map([$this, 'encodeValue'], $a)
        ];
    }

    /**
     * Decode an object definition from the store.
     *
     * @param int $storeId The store identifier to decode
     *
     * @return mixed The decoded object. Can be an array, a closure, or any class instance.
     */
    private function decodeFromStore($storeId)
    {
        $index = $this->getStoreIndex($storeId);

        // return object if already known (avoid recursion)
        if (array_key_exists($index, $this->decoded)) {
            return $this->decoded[$index];
        }

        $encoded = $this->encoded[$index];

        // Legacy closure format. Deprecated since 0.3.0.
        if (is_string($encoded)) {
            return $this->decodeClosure($index, $encoded);
        }

        if (array_key_exists(self::KEY_CLOSURE, $encoded)) {
            return $this->decodeClosure($index, $encoded);
        }

        if (array_key_exists(self::KEY_OBJECT_NAME, $encoded)) {
            return $this->decodeObject($index, $encoded);
        }

        if (array_key_exists(self::KEY_ARRAY, $encoded)) {
            return $this->decodeArray($index, $encoded);
        }

        throw new \UnexpectedValueException('Reached end of method Serializer::decodeFromStore() without being able to decode the given value');
    }

    /**
     * Decode a value.
     *
     * If the value is a store identifier, it will be fetched from the store and decoded. If the value is a basic type,
     * it will be returned without any change.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function decodeValue($value)
    {
        if ($this->isStoreId($value)) {
            return $this->decodeFromStore($value);
        }

        if ($this->isBasicType($value)) {
            return $value;
        }

        throw new \UnexpectedValueException('Reached end of method Serializer::decodeValue() without being able to decode the given value ');
    }

    /**
     * Decode an object.
     *
     * @param int $index The index of the object in the store
     * @param array $encodedObject Encoded object definition
     *
     * @return object
     */
    private function decodeObject($index, array $encodedObject)
    {
        // return object if already known (avoid recursion)
        if (array_key_exists($index, $this->decoded)) {
            return $this->decoded[$index];
        }

        // new empty instance
        $o = $this->properties->getNewInstanceWithoutProperties($encodedObject[self::KEY_OBJECT_NAME]);

        // Special case of Carbon object
        // Carbon's definition of __set method forbid direct set of 'date' parameter
        // while DateTime is still able to set them despite not declaring them!
        // it's a very special and messy case due to internal DateTime implementation
        if ($o instanceof \Carbon\Carbon) {
            $properties = $this->decodeLegacyArray($encodedObject[self::KEY_OBJECT_PROPERTIES]);
            $o = $this->properties->getNewInstanceWithoutProperties('DateTime');
            $dt = $this->properties->setPropertiesToObject($o, $properties);
            // other possible implementation
            // $dt = 'O:8:"DateTime":3:{s:4:"date";s:' . strlen($properties['date']) . ':"' . $properties['date'] .
            //     '";s:13:"timezone_type";i:' . $properties['timezone_type'] .
            //     ';s:8:"timezone";s:'. strlen($properties['timezone']) . ':"' . $properties['timezone'] . '";}';
            // $dt = unserialize($dt);
            $o = \Carbon\Carbon::instance($dt);
            $this->decoded[$index] = $o;

            return $o;
        }

        // make sure this is in decoded array, before decoding properties, to avoid potential recursion
        $this->decoded[$index] = $o;

        // decode properties
        $properties = $this->decodeLegacyArray($encodedObject[self::KEY_OBJECT_PROPERTIES]);

        // fill instance with properties
        return $this->properties->setPropertiesToObject($o, $properties);
    }

    /**
     * Decode a closure.
     *
     * @param int $index The index of the closure in the store
     * @param array|string $encodedClosure Encoded closure definition
     *
     * @return \Closure
     */
    private function decodeClosure($index, $encodedClosure)
    {
        // return object if already known (avoid recursion)
        if (array_key_exists($index, $this->decoded)) {
            return $this->decoded[$index];
        }

        // Legacy closure format. Deprecated since 0.3.0.
        if (is_string($encodedClosure)) {
            $encodedClosure = [self::KEY_CLOSURE => $encodedClosure];
        }

        $closure = $this->closure->unserialize($encodedClosure[self::KEY_CLOSURE]);
        $this->decoded[$index] = $closure;

        return $closure;
    }

    /**
     * Decode an array.
     *
     * @param int $index The index of the array in the store
     * @param array $encodedArray Encoded array definition
     *
     * @return array
     */
    private function decodeArray($index, array $encodedArray)
    {
        $this->decoded[$index] = array_map([$this, 'decodeValue'], $encodedArray[self::KEY_ARRAY]);

        return $this->decoded[$index];
    }

    /**
     * Decode a legacy array.
     *
     * Also used to decode object properties. To be renamed to ::decodeObjectProperties() when legacy array syntax
     * is removed.
     *
     * @return array
     */
    private function decodeLegacyArray(array $array)
    {
        foreach ($array as $key => $value) {
            if ($this->isStoreId($value)) {
                $index = $this->getStoreIndex($value);
                $encoded = $this->encoded[$index];
                if (is_array($encoded) && array_key_exists(self::KEY_OBJECT_NAME, $encoded)) {
                    // object is defined by an array [n => ..., p => ...]
                    $array[$key] = $this->decodeObject($index, $encoded);
                } else {
                    // object is defined by an array [c => ...] or a string, then it's a closure
                    $array[$key] = $this->decodeClosure($index, $encoded);
                }
            } elseif (is_array($value)) {
                $array[$key] = $this->decodeLegacyArray($value);
            }
        }

        return $array;
    }

    /**
     * Return whether or not the given data is considered a basic type.
     *
     * Basic types are:
     * - null
     * - integer
     * - float/double
     * - string
     * - bool
     *
     * @param mixed $data
     *
     * @return bool
     */
    private function isBasicType($data)
    {
        if (null === $data) {
            return true;
        }

        if (is_resource($data)) {
            return false;
        }

        return is_scalar($data);
    }

    /**
     * Return whether or not the $possibleResource parameter is a resource or not.
     *
     * is_resource() is not used because it can return false for closed resources.
     *
     * @param mixed $possibleResource
     *
     * @return bool
     */
    private function isResource($possibleResource)
    {
        return @get_resource_type($possibleResource) !== null;
    }

    /**
     * Throw an exception explaining that we cannot serialize a resource.
     */
    private function throwResourceException()
    {
        throw new \UnexpectedValueException('You are trying to serialize an object containing a Resource (a turn around would be to use __sleep and __wakeup methods to remove and restore this Resource)');
    }

    /**
     * Return the store identifier to use for the object at the given index.
     *
     * @param int $index
     *
     * @return string
     */
    private function getStoreId($index)
    {
        return self::ID_PREFIX.$index;
    }

    /**
     * Returns whether the given string corresponds to a valid store identifier.
     *
     * To be considered valid, the identifier must be a string starting with the defined prefix and referencing
     * an index existing in the $this->encoded array.
     *
     * @param string $s
     *
     * @return bool
     */
    private function isStoreId($s)
    {
        $len = strlen(self::ID_PREFIX);

        return is_string($s)
            && strpos($s, self::ID_PREFIX) === 0
            && array_key_exists(substr($s, $len), $this->encoded);
    }

    /**
     * Return the store index corresponding to a given store identifier.
     *
     * @param string $storeId
     *
     * @return int
     */
    private function getStoreIndex($storeId)
    {
        return (int) substr($storeId, strlen(self::ID_PREFIX));
    }


    /**
     * Decode a JSON string, throwing exceptions when there is an error.
     *
     * @param string $json
     *
     * @return array
     */
    private function jsonDecode($json)
    {
        $result = json_decode($json, true);

        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                break;
            case JSON_ERROR_DEPTH:
                throw new \UnexpectedValueException('Maximum stack depth exceeded - '.$json);
            case JSON_ERROR_STATE_MISMATCH:
                throw new \UnexpectedValueException('Underflow or the modes mismatch - '.$json);
            case JSON_ERROR_CTRL_CHAR:
                throw new \UnexpectedValueException('Unexpected control character found - '.$json);
            case JSON_ERROR_SYNTAX:
                throw new \UnexpectedValueException('Syntax error, malformed JSON - '.$json);
            case JSON_ERROR_UTF8:
                throw new \UnexpectedValueException('Malformed UTF-8 characters, possibly incorrectly encoded - '.$json);
            default:
                throw new \UnexpectedValueException('Unknown error - '.$json);
        }

        return $result;
    }
}
