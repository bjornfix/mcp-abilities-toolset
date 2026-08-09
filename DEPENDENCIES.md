# Runtime dependencies

- [WordPress 6.9+](https://wordpress.org/documentation/wordpress-version/version-6-9/) supplies the native Abilities API and the content, taxonomy, user, media, and permission Interfaces used by the plugin.
- [PHP 8.0+](https://www.php.net/releases/8.0/en.php) is the minimum PHP runtime for the plugin.
- [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) registers the 40 Toolset operations as typed WordPress abilities.
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) transports registered WordPress abilities to authenticated MCP clients.
- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/) exposes the registered public abilities through the controlled Devenia MCP surface.
- [Toolset](https://toolset.com/home/types-manage-post-types-taxonomy-and-custom-fields/) owns the custom post types, fields, taxonomies, relationships, Views, forms, and access data used by the matching operations.
