<?php

namespace McpSrv\Common\Response;

use Psr\Log\LoggerInterface;

class StdoutResponseHandler implements ResponseHandlerInterface {
	public function __construct(
		private readonly ?LoggerInterface $logger = null
	) {}
	
	public function reply(int|string $id, object|array $result): void {
		$errorContents = [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => $result
		];
		$jsonData = json_encode($errorContents, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$this->logger?->info("Success", $errorContents);
		printf("%s\n", $jsonData);
	}
	
	public function replyError(int|string $id, string $message, int $code): void {
		$errorContents = [
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => ['code' => $code, 'message' => $message]
		];
		$jsonData = json_encode($errorContents, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$this->logger?->error("Failure", [$errorContents]);
		printf("%s\n", $jsonData);
	}
}
