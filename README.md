# MCP Abilities - Toolset

Toolset integration for WordPress via MCP.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-toolset)](https://github.com/bjornfix/mcp-abilities-toolset/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

**Tested up to:** 6.9
**Stable tag:** 1.0.1
**Requires PHP:** 8.0
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

This add-on plugin exposes Toolset capabilities through MCP (Model Context Protocol). Your AI assistant can manage post types, custom fields, taxonomies, terms, relationships, and Toolset-driven content workflows.

**Part of the [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/) ecosystem.**

## Requirements

- WordPress 6.9+
- PHP 8.0+
- [Abilities API](https://github.com/WordPress/abilities-api) plugin
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin
- [Toolset Types](https://wordpress.org/plugins/types/)

## Installation

1. Install the required plugins (Abilities API, MCP Adapter, Toolset Types)
2. Download the latest release from [Releases](https://github.com/bjornfix/mcp-abilities-toolset/releases)
3. Upload via WordPress Admin > Plugins > Add New > Upload Plugin
4. Activate the plugin

## Abilities (38)

| Ability | Description |
|---------|-------------|
| `toolset/list-post-types` | List Toolset post types |
| `toolset/get-post-type-fields` | Get fields for a post type |
| `toolset/get-post-type-taxonomies` | Get taxonomies for a post type |
| `toolset/create-post` | Create post with Toolset fields |
| `toolset/get-post` | Get post with Toolset field data |
| `toolset/list-posts` | List posts with filters |
| `toolset/update-post` | Update post and field values |
| `toolset/delete-post` | Delete a post |
| `toolset/get-user-fields` | Get user field definitions |
| `toolset/get-user` | Get user with Toolset fields |
| `toolset/list-users` | List users |
| `toolset/list-roles` | List WordPress roles |
| `toolset/get-role-capabilities` | Get role capabilities |
| `toolset/list-post-relationships` | List Toolset relationships |
| `toolset/list-forms` | List Toolset forms |
| `toolset/list-taxonomies` | List taxonomies |
| `toolset/list-terms` | List terms for taxonomy |
| `toolset/create-term` | Create taxonomy term |
| `toolset/get-taxonomy-ui` | Get taxonomy UI settings |
| `toolset/set-taxonomy-ui` | Update taxonomy UI settings |
| `toolset/list-views` | List Toolset views |
| `toolset/get-post-relationships` | Get relationships for a post |
| `toolset/get-users-by-role` | List users by role |
| `toolset/query-posts` | Query posts by filters |
| `toolset/create-post-type` | Create post type |
| `toolset/create-taxonomy` | Create taxonomy |
| `toolset/delete-post-type` | Delete post type |
| `toolset/delete-taxonomy` | Delete taxonomy |
| `toolset/list-field-groups` | List field groups |
| `toolset/update-field-group` | Update field group |
| `toolset/create-field-group` | Create field group |
| `toolset/create-relationship` | Create post relationship |
| `toolset/add-post-relationship` | Link posts in a relationship |
| `toolset/get-posts-by-relationship` | Get related posts |
| `toolset/check-access` | Check access rules |
| `toolset/get-user-capabilities` | Get user capabilities |
| `toolset/update-field` | Update field definition |
| `toolset/get-field` | Get field definition |

## Usage Examples

### List Toolset post types

```json
{
  "ability_name": "toolset/list-post-types",
  "parameters": {}
}
```

### Create a post with Toolset fields

```json
{
  "ability_name": "toolset/create-post",
  "parameters": {
    "post_type": "issue",
    "title": "Broken checkout button",
    "status": "publish",
    "fields": {
      "wpcf-priority": "high"
    }
  }
}
```

### Create relationship link

```json
{
  "ability_name": "toolset/add-post-relationship",
  "parameters": {
    "relationship_slug": "project-to-issue",
    "parent_id": 101,
    "child_id": 205
  }
}
```

## Changelog

### 1.0.1
- Fixed: Removed hard plugin header dependency on abilities-api to avoid slug-mismatch activation blocking
- Updated: Plugin URI to the bjornfix GitHub repository

### 1.0.0
- Initial release
- Post types: list, get fields, get taxonomies
- Posts: create, get, list, update, delete
- Issue tracker: create, list, update status

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/)
- [Core Plugin (MCP Expose Abilities)](https://github.com/bjornfix/mcp-expose-abilities)
- [All Add-on Plugins](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
