# wp-plugin

## PHP 7.1+ Compatibility

**Minimum PHP Version: 7.1** - The plugin MUST work on PHP 7.1+

✅ **Allowed:**

- Return types: `function getName(): string`
- Nullable types: `?int`, `?string`
- Void return: `function log(): void`
- Null coalescing: `$value ?? 'default'`

❌ **Forbidden (PHP 7.4+):**

- Typed properties: `private string $name;`
- Arrow functions: `fn($x) => $x * 2`
- Null coalescing assignment: `$data ??= []`
- Array spread: `[...$array1, ...$array2]`

**Class Naming:** Use `GeoGuru_` prefix (e.g., `GeoGuru_ConfigService`)
