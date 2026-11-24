<?php

namespace McpSrv\Types\Tools;

use JsonSerializable;
use McpSrv\Common\TextCleaner;

/**
 * @phpstan-type TToolResult array{
 *     content: array{type: "text", text: string}[],
 *     structuredContent: mixed,
 *     isError: bool
 * }
 */
class MCPToolResult implements JsonSerializable {
	/**
	 * @param object|array<array-key, mixed> $content
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
		$content = TextCleaner::values($this->content);
		return [
			'content' => [[
				'type' => 'text',
				'text' => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
			]],
			'structuredContent' => $content,
			'isError' => $this->isError
		];
	}
}
