<?php

namespace McpSrv\Common;

/** Request-scoped HTTP metadata; never stored between MCP requests. */
final class HttpRequest {
	/** @var array<string, string> */
	private array $headers = [];

	/** @param array<string, string> $headers */
	public function __construct(array $headers) {
		foreach($headers as $name => $value) {
			$this->headers[strtolower($name)] = $value;
		}
	}

	public function header(string $name): ?string {
		return $this->headers[strtolower($name)] ?? null;
	}

	public static function fromGlobals(): self {
		// Preserve actual field names, including underscores in x-mcp-header names.
		/** @var array<string, string>|false $headers */
		$headers = function_exists('getallheaders') ? getallheaders() : false;
		if($headers === false) {
			$headers = [];
			foreach($_SERVER as $key => $value) {
				if(is_string($value) && str_starts_with($key, 'HTTP_')) {
					$headers[str_replace('_', '-', substr($key, 5))] = $value;
				}
			}
		}
		$request = new self($headers);
		foreach(['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length', 'REDIRECT_HTTP_AUTHORIZATION' => 'Authorization'] as $key => $name) {
			$value = $_SERVER[$key] ?? null;
			if($request->header($name) === null && is_string($value)) {
				$request->headers[strtolower($name)] = $value;
			}
		}
		return $request;
	}

	public function validate(string $method, object $params, ?object $toolSchema = null): void {
		$meta = $params->_meta ?? null;
		$version = is_object($meta) ? ($meta->{'io.modelcontextprotocol/protocolVersion'} ?? null) : null;
		if($this->header('MCP-Protocol-Version') !== null && !is_string($version)) {
			throw new MCPInvalidArgumentException('Required protocolVersion metadata is missing or invalid', -32602);
		}
		$this->matchHeader('MCP-Protocol-Version', $version);
		$this->matchHeader('Mcp-Method', $method);

		if(in_array($method, ['tools/call', 'prompts/get', 'resources/read'], true)) {
			$name = $method === 'resources/read' ? ($params->uri ?? null) : ($params->name ?? null);
			$this->matchHeader('Mcp-Name', $name, encoded: true);
		}

		if($toolSchema !== null) {
			$this->validateParameters($toolSchema, $params->arguments ?? null);
		}
	}

	private function matchHeader(string $name, mixed $expected, bool $encoded = false, bool $integer = false): void {
		$value = $this->header($name);
		if($value === null || preg_match('/[^\x20-\x7E\x09]/', $value) || trim($value) !== $value) {
			throw new MCPInvalidArgumentException("Missing or malformed $name header (MCP 2026-07-28 required)", -32020);
		}
		if($encoded && str_starts_with($value, '=?base64?') && str_ends_with($value, '?=')) {
			$decoded = base64_decode(substr($value, 9, -2), true);
			if($decoded === false || preg_match('//u', $decoded) !== 1) {
				throw new MCPInvalidArgumentException("Malformed $name encoding", -32020);
			}
			$value = $decoded;
		}
		$matches = is_string($expected) && ($value === $expected
			|| ($integer && preg_match('/^-?[0-9]+(?:\.0+)?$/D', $value) === 1 && (float) $value === (float) $expected));
		if(!$matches) {
			throw new MCPInvalidArgumentException("Header mismatch: $name does not match the request body", -32020);
		}
	}

	/** Walk only statically reachable object properties, as defined by x-mcp-header. */
	private function validateParameters(object $schema, mixed $arguments, int $depth = 0): void {
		if($depth > 64) {
			throw new MCPGeneralException('Tool header schema exceeds maximum depth', -32603);
		}
		$properties = $schema->properties ?? null;
		if(!is_object($properties) && !is_array($properties)) {
			return;
		}
		foreach((array) $properties as $name => $property) {
			if(!is_object($property) && !is_array($property)) {
				continue;
			}
			$property = (object) $property;
			$value = is_object($arguments) ? ($arguments->{$name} ?? null) : null;
			$headerName = $property->{'x-mcp-header'} ?? null;
			if(is_string($headerName)) {
				$headerName = 'Mcp-Param-' . $headerName;
				if($value === null) {
					if($this->header($headerName) !== null) {
						throw new MCPInvalidArgumentException("Unexpected $headerName header", -32020);
					}
				} else {
					$integer = ($property->type ?? null) === 'integer';
					if($integer && (!is_int($value) && !is_float($value)
						|| !is_finite((float) $value) || abs($value) > 9007199254740991 || floor((float) $value) !== (float) $value)) {
						throw new MCPInvalidArgumentException("Invalid safe integer for $headerName", -32020);
					}
					$expected = match(true) {
						is_string($value) => $value,
						is_int($value) => (string) $value,
						$integer && is_float($value) => sprintf('%.0f', $value),
						is_bool($value) => $value ? 'true' : 'false',
						default => null,
					};
					$this->matchHeader($headerName, $expected, encoded: true, integer: $integer);
				}
			}
			$this->validateParameters($property, $value, $depth + 1);
		}
	}
}
