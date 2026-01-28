<?php

namespace McpSrv\Common;

use Exception;
use Throwable;

abstract class MCPException extends Exception {
	private mixed $data;

	public function __construct(string $message = "", int $code = 0, mixed $data = null, ?Throwable $previous = null) {
		parent::__construct($message, $code, $previous);
		$this->data = $data;
	}

	public function getData(): mixed {
		return $this->data;
	}
}
