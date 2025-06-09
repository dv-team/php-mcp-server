<?php

namespace McpSrv\Common;

use McpSrv\Common\Response\ResponseHandlerInterface;

class CapturingResponseHandler implements ResponseHandlerInterface {
	/** @var array{id: int|string, result: object|array<array-key, mixed>}|null */
	public ?array $reply = null;
	
	/** @var array{id: int|string, message: string, code: int}|null */
	public ?array $error = null;

	public function reply(int|string $id, object|array $result): void {
		$this->reply = ['id' => $id, 'result' => $result];
	}

	public function replyError(int|string $id, string $message, int $code): void {
		$this->error = ['id' => $id, 'message' => $message, 'code' => $code];
	}
}
