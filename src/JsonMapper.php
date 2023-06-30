<?php

/**
 * JsonMapper
 *
 * @author Tomasz Świenty
 * @version 0.1
 * @copyright Copyright (c) eDokumenty
 */
final class JsonMapper {


    protected $logger;
    public function setLogger($logger) {

        $this->logger = $logger;

    }


    protected function log($level, $message, array $context = array()) {

        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }

    }


    public function map($json, $object) {

        if (!is_object($json)) {
            throw new InvalidArgumentException(
                'JsonMapper::map() requires first argument to be an object'
                . ', ' . gettype($json) . ' given.'
            );
        }

        if (!is_object($object)) {
            throw new InvalidArgumentException(
                'JsonMapper::map() requires second argument to be an object'
                . ', ' . gettype($object) . ' given.'
            );
        }

    }


    public function mapArray($json, $array, $class = null, $parent_key = '') {

    }

}