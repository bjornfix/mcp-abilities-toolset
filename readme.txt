=== MCP Abilities - Toolset ===
Contributors: devenia
Tags: mcp, toolset, types, custom-fields
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 1.0.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Toolset integration for WordPress via MCP. Manage custom post types, fields, and relationships.

== Description ==

This add-on plugin exposes Toolset functionality through MCP (Model Context Protocol). Your AI assistant can manage custom post types, custom fields, taxonomies, and posts with Toolset fields directly via the Abilities API.

Part of the MCP Expose Abilities ecosystem.

== Features ==

- List post types and their custom fields
- Create, read, update, delete posts with custom fields
- Manage taxonomy terms
- Issue tracker specific abilities for managing issues

== Installation ==

1. Install the required plugins (Abilities API, MCP Adapter)
2. Configure Toolset (Types, Views) as needed
3. Download and install this plugin
4. Activate the plugin

== Changelog ==

= 1.0.1 =
* Fixed: Removed hard plugin header dependency on abilities-api to avoid slug-mismatch activation blocking
* Updated: Plugin URI to the bjornfix GitHub repository

= 1.0.0 =
* Initial release
* Post types: list, get fields, get taxonomies
* Posts: create, get, list, update, delete
* Issue tracker: create, list, update status
