# Live Sale – Real-Time WooCommerce Product Grid & Chat

A WordPress/WooCommerce plugin that adds a live product grid with real-time claiming, waitlists, giveaways, and a live chat — powered by Ably WebSockets.

## Features

- Real-time product grid via Ably (claim, waitlist, giveaway, timers)
- Live chat with admin moderation (delete messages, clear all)
- Giveaway system: countdown timer, automatic winner roll, winner email, free WooCommerce order creation
- Skeleton loading, toast notifications
- Admin panel: manage products, prices, stock, pinning, giveaway controls

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+
- An [Ably](https://ably.com) account (free tier works for development)
- Optional: Ably PHP library for server-side publishing (`composer require ably/ably-php`)

## Installation

1. Upload the `livesale/` folder to `wp-content/plugins/`
2. Add your Ably API key to `wp-config.php` (see Configuration)
3. Activate the plugin in WordPress → Plugins
4. Add the shortcodes to any page:
   - `[live_sale_grid]` — product grid
   - `[live_sale_chat]` — live chat

## Configuration

Add to `wp-config.php` **before** the `That's all, stop editing!` line:

```php
define( 'LSG_ABLY_API_KEY', 'your_ably_api_key_here' );
```

Get your API key from: https://ably.com → Apps → API Keys

## Ably PHP Library (optional but recommended)

Install via Composer in the plugin directory:

```bash
cd wp-content/plugins/livesale
composer require ably/ably-php
```

Without it, real-time server-side publishing is disabled but the frontend still polls via AJAX.

## Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[live_sale_grid]` | The live product grid |
| `[live_sale_chat]` | The live chat panel |

## Live Sale Category

Create a WooCommerce product category named **"Live Sale"** and assign products to it. The plugin auto-detects this category by name.

## File Structure

```
livesale/
├── livesale.php          # Main plugin file (all PHP logic)
├── livesale-grid.js      # Frontend JS for the product grid
├── livesale-chat.js      # Frontend JS for the chat
└── README.md
```
