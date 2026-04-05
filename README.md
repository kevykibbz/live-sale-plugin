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

---

## Giveaway System

Each product can be turned into a giveaway by the admin. The full flow is:

1. **Admin marks a product as Giveaway** and sets a countdown duration
2. A **live countdown timer** displays on the product card for all visitors
3. When the timer hits zero, the server automatically **rolls a winner** from the claimed users list (WP cron)
4. The winner is broadcast to all connected browsers via Ably
5. **Winner's browser** shows:
   - Green banner: *"🏆 You won this giveaway!"*
   - Pulsing green button: *"🎁 View Your Order #X"* — links to WooCommerce orders page
   - Personal toast: *"🎉 You Won the giveaway!"*
6. **Everyone else** sees:
   - Yellow banner: *"🏆 Winner: [name]"*
   - Grey disabled button: *"Giveaway Ended"*
   - Toast: *"🏆 Giveaway winner: [name]"*
7. A **free WooCommerce order (KSh 0)** is automatically created for the winner:
   - Status: Processing
   - Payment method: Giveaway Win
   - Customer-visible order note explaining the prize
8. A **congratulations email** is sent to the winner with a link to their order
9. Available stock is decremented by 1 for the winner

### Giveaway Admin Controls

| Control | Location | Purpose |
|---------|----------|---------|
| Enable Giveaway | Products tab → Giveaway checkbox | Marks product as a giveaway |
| Set Timer | Products tab → Countdown duration | Sets how long until winner is rolled |
| View winner | Products tab → Giveaway Winner column | Shows winner username after roll |
| View order | WooCommerce → Orders | Free order at KSh 0 created automatically |

### Giveaway Technical Notes

- Winner selection is **server-side only** — cannot be manipulated from the browser
- Processing is **idempotent** — the `lsg_win_processed` meta flag prevents duplicate orders even if cron fires more than once
- Winner identity check is done **server-side per request** — each user's browser only knows if *they* won, not other users' status
