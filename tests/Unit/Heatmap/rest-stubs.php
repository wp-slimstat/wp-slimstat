<?php
/** @license GPL-2.0-or-later */
// Just enough of the REST classes for a controller callback to run without WordPress.

if (!class_exists('WP_Error', false)) {
    class WP_Error
    {
        public $code;
        public $message;
        public $data;

        public function __construct($code = '', $message = '', $data = '')
        {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
    }
}

if (!class_exists('WP_REST_Request', false)) {
    class WP_REST_Request implements ArrayAccess
    {
        private array $params;

        public function __construct(array $params = [])
        {
            $this->params = $params;
        }

        public function offsetExists($offset): bool
        {
            return isset($this->params[$offset]);
        }

        #[\ReturnTypeWillChange]
        public function offsetGet($offset)
        {
            return $this->params[$offset] ?? null;
        }

        public function offsetSet($offset, $value): void
        {
            $this->params[$offset] = $value;
        }

        public function offsetUnset($offset): void
        {
            unset($this->params[$offset]);
        }
    }
}
