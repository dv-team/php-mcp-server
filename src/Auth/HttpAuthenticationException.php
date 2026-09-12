<?php

declare(strict_types=1);

namespace McpSrv\Auth;

use McpSrv\Common\MCPException;
use McpSrv\Common\MCPInvalidArgumentException;

/** An application-defined HTTP rejection, before processing any MCP message. */
final class HttpAuthenticationException extends MCPException {
	/** @param array<string, string> $headers E.g. an application-defined WWW-Authenticate challenge. */
	public function __construct(
		public readonly int $status = 401,
		public readonly array $headers = [],
		public readonly string $body = '',
	) {
		if(!in_array($status, [400, 401, 403], true)) {
			throw new MCPInvalidArgumentException('An authentication rejection requires HTTP 400, 401 or 403.');
		}
		parent::__construct('HTTP authentication rejected');
	}
}
