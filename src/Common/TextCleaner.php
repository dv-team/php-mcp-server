<?php

namespace McpSrv\Common;

use JsonException;
use stdClass;

final class TextCleaner {
	// Match meaningful uses first. SKIP/F leaves those sequences intact.
	private const FORMAT_MARKS = <<<'REGEX'
		~
		  (?:
		      (?<=\p{Emoji}) [\x{FE0E}\x{FE0F}]
		    # Supplementary variation selectors identify ideographic variants.
		    | (?<=\p{Han}) [\x{E0100}-\x{E01EF}]
		    | (?<=[\p{L}\p{N}\p{P}\p{S}\p{Mc}]) (?<!\p{Latin}) [\x{FE00}-\x{FE0D}]
		    | (?:(?<=[\p{Extended_Pictographic}\p{Emoji_Modifier}])|(?<=\p{Extended_Pictographic}\x{FE0F})) \x{200D} (?=\p{Extended_Pictographic})
		    # A subdivision flag ends with CANCEL TAG.
		    | (?<=\x{1F3F4}) [\x{E0030}-\x{E0039}\x{E0061}-\x{E007A}]{2,6} \x{E007F}
		    | (?<=[\p{L}\p{M}]) (?<!\p{Latin}) (?<!\p{Default_Ignorable_Code_Point}) [\x{200C}\x{200D}]
		    | [\x{200C}\x{200D}] (?=\p{L}) (?!\p{Latin})
		    | (?<=[\p{L}\p{N}\p{Sm}\p{Pe}]) [\x{2061}-\x{2064}] (?=[\p{L}\p{N}\p{Sm}\p{Ps}])
		    | (?<=[\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]) \x{200B}
		    | \x{034F} (?=\p{M}) (?!\p{Default_Ignorable_Code_Point})
		  ) (*SKIP)(*F)
		  | (?!\p{Bidi_Control}) (?=[\p{sc:Common}\p{sc:Inherited}\p{Cn}])
		    \p{Default_Ignorable_Code_Point}
		~ux
		REGEX;

	public static function text(string $text): string {
		// Keep HT, LF, CR and NEL as text layout, along with Unicode line/paragraph separators.
		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x84\x86-\x9F\x{00AD}\x{2060}\x{FEFF}]+/u', '', $text)
			?? throw new JsonException(preg_last_error_msg());
		// Directional controls can be needed even when separated from the RTL letters they affect.
		if(!preg_match('/(?=\p{L})[\p{bc:R}\p{bc:AL}]/u', $text)) {
			$text = preg_replace('/\p{Bidi_Control}+/u', '', $text) ?? throw new JsonException(preg_last_error_msg());
		}
		return preg_replace(self::FORMAT_MARKS, '', $text) ?? throw new JsonException(preg_last_error_msg());
	}

	/**
	 * Clean prose at JSON Schema nodes. Enum values, patterns, defaults and property names are contracts.
	 * @param object|array<array-key, mixed> $schema
	 * @return ($schema is array ? array<array-key, mixed> : object)
	 */
	public static function schema(array|object $schema, int $depth = 0): array|object {
		if($depth > 512) {
			throw new JsonException('Maximum JSON depth exceeded', JSON_ERROR_DEPTH);
		}
		if(is_object($schema) && $schema::class !== stdClass::class) {
			return $schema;
		}
		$fields = (array) $schema;
		foreach($fields as $key => $value) {
			if(in_array($key, ['title', 'description'], true) && is_string($value)) {
				$fields[$key] = self::text($value);
				continue;
			}
			if(!is_array($value) && !$value instanceof stdClass) {
				continue;
			}
			if(in_array($key, ['properties', 'patternProperties', 'dependentSchemas', '$defs', 'definitions',
				'oneOf', 'allOf', 'anyOf', 'prefixItems'], true)) {
				$children = is_array($value) ? $value : get_object_vars($value);
				foreach($children as $name => $child) {
					if(is_object($child) || is_array($child)) {
						$children[$name] = self::schema($child, $depth + 1);
					}
				}
				$fields[$key] = is_object($value) ? (object) $children : $children;
			} elseif(in_array($key, ['additionalProperties', 'items', 'contains', 'propertyNames', 'unevaluatedItems',
				'unevaluatedProperties', 'contentSchema', 'not', 'if', 'then', 'else'], true)) {
				$fields[$key] = self::schema($value, $depth + 1);
			}
		}
		return is_object($schema) ? (object) $fields : $fields;
	}

	/** Clean JSON values while preserving property names and the caller's objects. */
	public static function values(mixed $value, int $depth = 0): mixed {
		if($depth > 512) {
			throw new JsonException('Maximum JSON depth exceeded', JSON_ERROR_DEPTH);
		}
		if(is_string($value)) {
			return self::text($value);
		}
		if(is_array($value)) {
			$clean = [];
			foreach($value as $key => $item) {
				$clean[$key] = self::values($item, $depth + 1);
			}
			return $clean;
		}
		if(is_object($value) && $value::class === stdClass::class) {
			$object = new stdClass();
			foreach(get_object_vars($value) as $key => $item) {
				$object->{$key} = self::values($item, $depth + 1);
			}
			return $object;
		}
		if(is_object($value)) {
			// Let PHP resolve custom serializers, native objects and backed enums.
			$json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
			return self::values(json_decode($json, flags: JSON_THROW_ON_ERROR), $depth);
		}
		return $value;
	}
}
