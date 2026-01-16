<?php

namespace McpSrv\Types\Resources;

use JsonSerializable;

class MCPResource implements JsonSerializable {
	public function __construct(
		public string $contents,
		public string $mimeType,
		public ?string $uri = null,
	) {}

	public function jsonSerialize(): mixed {
		return [
			'uri' => $this->uri,
			'mimeType' => $this->mimeType,
			'text' => $this->contents,
		];
	}
}