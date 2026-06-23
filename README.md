# MCP Abilities - Toolset

Toolset abilities for MCP. Manage custom post types, fields, and relationships created with Toolset.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-toolset)](https://github.com/bjornfix/mcp-abilities-toolset/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0
**Stable tag:** 1.0.4
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

Toolset abilities for MCP. Manage custom post types, fields, and relationships created with Toolset.

This plugin is part of the MCP abilities ecosystem. It gives an MCP-capable agent a focused, authenticated way to work with Toolset work inside WordPress through MCP.

**Example:** "Handle this WordPress maintenance task directly." - The agent can inspect the site, call the relevant ability, and return the result without making the human click through wp-admin for every step.

## The Real Workflow

In practice, the human should not have to memorize every ability name.

The normal pattern is:

1. install the base MCP stack
2. install only the add-ons the site actually needs
3. let the agent discover the available abilities
4. give the agent a clear task with boundaries
5. verify the result in WordPress

The human's job is mostly to describe the goal.
The agent's job is to figure out the mechanics.

## Why This Feels Different

Most WordPress automation still leaves the repetitive part to the human.

This plugin is different because the agent can act inside the site through a narrow, authenticated ability surface:

- inspect current site state before changing anything
- run the specific action needed for the task
- return structured results that are easy to verify
- keep the workflow inside WordPress instead of a separate checklist

That changes the experience from:

- `Here is what you should do in wp-admin`

to:

- `Tell the agent what needs doing, and let it carry out the work`

## Before vs After

### Before

- ask the AI what to do
- copy the answer into WordPress by hand
- click through wp-admin for the repetitive bits
- postpone maintenance because the task is tedious

### After

- tell the agent what needs doing
- let it inspect the relevant WordPress state
- let it run the targeted ability
- verify the result and move on

## Who It Is For

This is a good fit for:

- agencies managing WordPress sites with AI-assisted maintenance
- operators who want agents to do real WordPress work instead of producing instructions
- teams already using MCP Expose Abilities
- sites where this WordPress area is updated often enough to deserve automation

It is especially useful when the manual version is repetitive enough that important maintenance gets delayed.

## Documentation

Start with the main plugin page and base stack documentation:

- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
- [Getting Started](https://github.com/bjornfix/mcp-expose-abilities/wiki/Getting-Started)
- [Install Order and Dependencies](https://github.com/bjornfix/mcp-expose-abilities/wiki/Install-Order-and-Dependencies)

If you are using an AI agent, the simplest instruction is often just:

- `Read https://github.com/bjornfix/mcp-expose-abilities and figure out the stack before making changes.`

## Start Here

If you are new to the stack, use this order:

1. Install **Abilities API**.
2. Install **MCP Adapter**.
3. Install **MCP Expose Abilities**.
4. Install **MCP Abilities - Toolset**.
5. Confirm the new abilities appear in discovery.
6. Give the agent a clear task that uses this add-on.

If you skip base-stack verification and start with add-ons immediately, troubleshooting gets harder than it needs to be.

## Abilities (40)

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
| `toolset/audit-usage` | Audit pages, posts, and Toolset objects for active Toolset usage |
| `toolset/cleanup-stale-data` | Clean stale Toolset metadata, objects, and toolsetDSVersion attributes after migration |

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

### 1.0.4
- Update tested WordPress version metadata for Plugin Check.
- Align public release identity with the Basicus author/contributor rule.

### 1.0.3
- Added: `toolset/cleanup-stale-data` destructive cleanup ability for stale Toolset metadata, Toolset objects, and `toolsetDSVersion` content attributes after migration

### 1.0.2
- Added: `toolset/audit-usage` read-only ability to find posts/pages with Toolset blocks, shortcodes, fields, Views/templates, and Toolset configuration objects

### 1.0.1
- Fixed: Removed hard plugin header dependency on abilities-api to avoid slug-mismatch activation blocking
- Updated: Plugin URI to the bjornfix GitHub repository

### 1.0.0
- Initial release
- Post types: list, get fields, get taxonomies
- Posts: create, get, list, update, delete
- Issue tracker: create, list, update status

## Contributing

PRs welcome. Keep changes focused on the plugin's WordPress ability surface and preserve authenticated, explicit workflows.

## License

GPL-2.0+

## Author

[basicus](https://profiles.wordpress.org/basicus/)

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [GitHub Releases](https://github.com/bjornfix/mcp-abilities-toolset/releases)

## Star and Share

If this plugin saves you time or makes WordPress maintenance easier to verify, please:

- star the repo
- share it with people running WordPress sites
- point them to the main plugin page so they can see what the ecosystem can actually do

Why do it?

Because agent-friendly open WordPress tooling helps more of the boring but important work get done.
