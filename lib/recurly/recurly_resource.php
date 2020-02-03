<?php

namespace Recurly;

abstract class RecurlyResource
{
    use RecurlyTraits;

    private $_response;

    /**
     * Getter for the Recurly HTTP Response
     * 
     * @return \Recurly\Response The Recurly HTTP Response
     */
    public function getResponse(): \Recurly\Response
    {
        return $this->_response;
    }

    /**
     * Setter for the Recurly HTTP Response
     * 
     * @param \Recurly\Response $response The Recurly HTTP Response
     * 
     * @return void
     */
    protected function setResponse(\Recurly\Response $response): void
    {
        $this->_response = $response;
    }

    /**
     * Guard against setting invalid properties
     *
     * @param string $key   The parameter name that is being set
     * @param $value The parameter value that is being set
     * 
     * @return             void
     * @codeCoverageIgnore
     */
    public function __set(string $key, $value): void
    {
        $klass = get_class($this);
        // TODO: This should only happen in strict mode?
        trigger_error("Cannot set {$key} on {$klass}", E_USER_ERROR);
    }

    /**
     * Converts a JSON response object into a \Recurly\RecurlyResource.
     * 
     * @param object            $json     PHP Object containing the decoded JSON
     * @param \Recurly\Response $response (optional) The Recurly HTTP Response
     * 
     * @return \Recurly\RecurlyResource An instance of a Recurly Resource
     */
    public static function fromJson(object $json, \Recurly\Response $response): \Recurly\RecurlyResource // phpcs:ignore Generic.Files.LineLength.TooLong
    {
        if (isset($json->error)) {
            throw \Recurly\RecurlyError::fromJson($json, $response);
        } else {
            $klass_name = static::resourceClass($json->object, "\\Recurly\\Resources\\");
        }
        $klass = $klass_name::cast($json);

        if ($response) {
            $klass->setResponse($response);
        }
        return $klass;
    }

    /**
     * Recursively converts a response object into a \Recurly\RecurlyResource.
     * 
     * @param object $data PHP Object containing the decoded JSON
     * 
     * @return \Recurly\RecurlyResource An instance of a Recurly Resource
     */
    public static function cast(object $data): \Recurly\RecurlyResource // phpcs:ignore Generic.Files.LineLength.TooLong
    {
        $klass = new static();
        foreach ($data as $key => $value) {
            if ($key == 'object' || empty($value)) {
                continue;
            }

            $setter = static::titleize($key, 'set');
            if (method_exists(static::class, $setter)) {
                if (is_array($value)) {
                    $klass->$setter(
                        array_map(
                            function ($item) use ($setter) {
                                if (property_exists($item, 'object')) {
                                    $item_class = static::resourceClass($item->object, "\\Recurly\\Resources\\");
                                } else {
                                    $item_class = static::hintArrayType($setter);
                                    if (substr($item_class, 0, 8) != "\\Recurly") {
                                        return $item;
                                    }
                                }
                                return $item_class::cast($item);
                            }, $value
                        )
                    );
                } elseif (is_object($value)) {
                    $setter_class = static::setterParamClass($setter);
                    $param = new $setter_class();
                    $klass->$setter($param::cast($value));
                } else {
                    $klass->$setter($value);
                }
            } elseif (\Recurly\STRICT_MODE) {
                $klass_name = static::class;
                trigger_error("$klass_name encountered json attribute $key but it's unknown to it's schema", E_USER_ERROR);
            }
        }
        return $klass;
    }

    /**
     * Uses the Reflection API to determine what \Recurly\RecurlyResource is the
     * expected parameter for the given method.
     * 
     * @param string $method The name of the setter method to find the type hint for
     * 
     * @return string The \Recurly\RecurlyResource that the setter method expects
     */
    protected static function setterParamClass(string $method)
    {
        $class = new \ReflectionClass(get_called_class());
        $method = $class->getMethod($method);
        $parameters = $method->getParameters();
        return $parameters[0]->getType()->getName();
    }

    /**
     * Converts a binary response into a Recurly BinaryFile
     * 
     * @param string            $data     The binary file data
     * @param \Recurly\Response $response (optional) The Recurly HTTP Response
     * 
     * @return \Recurly\Resources\BinaryFile An instance of a Recurly BinaryFile
     */
    public static function fromBinary(string $data, \Recurly\Response $response): \Recurly\Resources\BinaryFile // phpcs:ignore Generic.Files.LineLength.TooLong
    {
        $klass = new \Recurly\Resources\BinaryFile();
        $klass->setData($data);
        $klass->setResponse($response);
        return $klass;
    }

    /**
     * Override of the magic method __debugInfo that will only return the relevant
     * attributes of the \Recurly\RecurlyResource
     * 
     * @return             array
     * @codeCoverageIgnore
     */
    public function __debugInfo(): array
    {
        $class = new \ReflectionClass(get_called_class());
        return array_reduce(
            $class->getProperties(),
            function ($carry, $property) {
                $private = $property->isPrivate();
                if ($private) {
                    $property->setAccessible(true);
                }
                $display_name = $private ? substr($property->name, 1) : $property->name; // phpcs:ignore Generic.Files.LineLength.TooLong
                $carry[$display_name] = $property->getValue($this);
                if ($private) {
                    $property->setAccessible(false);
                }
                return $carry;
            },
            []
        );
    }
}
