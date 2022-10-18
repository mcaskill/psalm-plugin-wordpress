# WordPress plugin for Psalm

A fork of [humanmade/psalm-plugin-wordpress] that integrates upcoming pull requests.

Please use [humanmade/psalm-plugin-wordpress] instead.

This [Psalm] plugin provides all WordPress (and WP CLI) stubs, so your WordPress
based project or plugin will have type information for calls to WordPress APIs.

- Stubs for all of WordPress Core
- Stubs for WP CLI
- Types for `apply_filters` return values.
- Types for `add_filter` / `add_action`

## Installation

You can install the package via Composer:

```shell
composer require mcaskill/psalm-plugin-wordpress
```

[humanmade]:                        https://hmn.md/
[humanmade/psalm-plugin-wordpress]: https://github.com/humanmade/psalm-plugin-wordpress
[Psalm]:                            https://psalm.dev/
