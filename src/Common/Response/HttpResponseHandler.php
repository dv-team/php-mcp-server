<?php

namespace McpSrv\Common\Response;

use Psr\Log\LoggerInterface;

/** Emits one JSON response using PHP's native HTTP output. */
class HttpResponseHandler implements ResponseHandlerInterface {
	public function __construct(private readonly ?LoggerInterface $logger = null) {}

	public function reply(int|string $id, object|array $result): void {
		$this->replyRaw(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
	}

	public function replyError(int|string $id, string $message, int $code, mixed $data = null): void {
		$error = ['code' => $code, 'message' => $message];
		if($data !== null) {
			$error['data'] = $data;
		}
		$this->replyRaw(['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
	}

	public function replyRaw(object|array $result): void {
		$contents = (array) $result;
		$json = json_encode($contents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$error = $contents['error'] ?? null;
		$code = is_array($error) ? ($error['code'] ?? null) : null;
		http_response_code($error === null ? 200 : match($code) {
			-32601 => 404,
			-32603 => 500,
			default => 400,
		});
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		$this->logger?->info('OUT', $contents);
		echo $json;
	}
}
