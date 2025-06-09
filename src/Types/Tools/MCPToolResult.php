<?php

namespace McpSrv\Types\Tools;

use JsonSerializable;

/**
 * @phpstan-type TToolResult array{
 *     content: array{type: "text", text: string}[],
 *     structuredContent: object|array<array-key, mixed>,
 *     isError: bool
 * }
 */
class MCPToolResult implements JsonSerializable {
	/**
	 * @param object|array<array-key, null|scalar|object|array<array-key, mixed>> $content
	 * @param bool $isError
	 */
	public function __construct(
		public readonly object|array $content,
		public readonly bool $isError
	) {}

	/**
	 * @return TToolResult
	 */
	public function jsonSerialize(): array {
		return [
			'content' => [[
				'type' => 'text',
				'text' => json_encode($this->content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
			]],
			'structuredContent' => $this->content,
			'isError' => $this->isError
		];
	}
}