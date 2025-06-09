<?php

namespace McpSrv\Common\Response;

use JsonException;
use Psr\Log\LoggerInterface;

final class HttpResponseHandler implements ResponseHandlerInterface {
	public function __construct(
		private readonly ?LoggerInterface $logger = null
	) {}
	
	/**
	 * @param string|int $id
	 * @param object|array<mixed> $result
	 * @return never
	 * @throws JsonException
	 */
	public function reply(string|int $id, object|array $result): never {
		header('Content-Type: application/json');
		$errorContents = [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => $result
		];
		$jsonData = json_encode($errorContents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$this->logger?->info("Success", $errorContents);
		echo $jsonData;
		exit;
	}
	
	/**
	 * @param string|int $id
	 * @param string $message
	 * @param int $code
	 * @return never
	 * @throws JsonException
	 */
	public function replyError(string|int $id, string $message, int $code): never {
		header('Content-Type: application/json');
		http_response_code(500);
		$errorContents = [
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => ['code' => $code, 'message' => $message]
		];
		$jsonData = json_encode($errorContents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$this->logger?->error("Failure", [$errorContents]);
		echo $jsonData;
		exit;
	}
}
