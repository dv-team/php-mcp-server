<?php

namespace McpSrv\Common;

final class Request {
	/** @param array<string, mixed> $request */
	public static function encode(array $request): string {
		$params = $request['params'] ?? (object) [];
		assert($params instanceof \stdClass);
		$params->_meta = (object) [
			'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
			'io.modelcontextprotocol/clientCapabilities' => (object) [],
		];
		$request['params'] = $params;
		$request['jsonrpc'] = '2.0';
		return json_encode($request, JSON_THROW_ON_ERROR);
	}
}
