<?php

namespace McpSrv\Common\Response;

interface ResponseHandlerInterface {
	/**
	 * @param string|int $id
	 * @param object|mixed[] $result
	 * @return void
	 */
	public function reply(string|int $id, object|array $result): void;
	public function replyError(string|int $id, string $message, int $code): void;
}