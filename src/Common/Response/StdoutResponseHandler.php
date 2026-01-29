<?php

namespace McpSrv\Common\Response;

use Psr\Log\LoggerInterface;

class StdoutResponseHandler implements ResponseHandlerInterface {
	public function __construct(
		private readonly ?LoggerInterface $logger = null
	) {}

	public function reply(null|int|string $id, object|array $result): void {
		$contents = [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => $result
		];
		$jsonData = json_encode($contents, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$this->logger?->info("OUT", $contents);
		printf("%s\n", $jsonData);
	}

	public function replyRaw(object|array $result): void {
		$jsonData = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$this->logger?->info("OUT", (array) $result);
		printf("%s\n", $jsonData);
	}

	public function replyError(int|string $id, string $message, int $code, mixed $data = null): void {
		$errorContents = [
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => ['code' => $code, 'message' => $message]
		];
		if($data !== null) {
			$errorContents['error']['data'] = $data;
		}
		$jsonData = json_encode($errorContents, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$this->logger?->error("OUT", (array) $errorContents);
		printf("%s\n", $jsonData);
	}
}
