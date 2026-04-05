# Live Sale Plugin — Digital Ocean Deployment Guide

## GitHub Repository Setup

### Suggested repo details (tell your developer):
- **Repository name:** `live-sale-plugin`
- **Description:** Real-time WooCommerce live sale product grid and chat powered by Ably WebSockets
- **Visibility:** Private
- **No template** needed

### Files to push to GitHub:
Push ONLY the plugin folder contents (not the full WordPress install):
```
livesale/
├── livesale.php
├── livesale-grid.js
├── livesale-chat.js
├── README.md
└── .gitignore
```

### ⚠️ Do NOT push wp-config.php to GitHub — it contains your database password.

---

## Digital Ocean Setup

### What the client needs to do:

#### Step 1: Create a Digital Ocean account
- Go to https://digitalocean.com → Sign Up
- Add a payment method (credit card or PayPal)
- Recommended starting credit: $6–$12/month droplet

#### Step 2: Create a WordPress Droplet (1-Click App)
1. Click **Create → Droplets**
2. Choose **Marketplace** tab
3. Search for **"WordPress"** → select it
4. Choose the plan:
   - **Basic — Regular SSD**
   - **$12/month** (2GB RAM / 1 vCPU / 50GB SSD) — minimum recommended for WooCommerce
5. Choose a **datacenter region** closest to your customers (e.g. London, Frankfurt, Singapore)
6. Authentication: **SSH Key** (recommended) or Password
7. Click **Create Droplet**

#### Step 3: Give developer access
Option A — **SSH Key access** (recommended):
- Ask developer for their SSH public key (a .pub file)
- Add it under Droplet → Access → Add SSH Key

Option B — **DigitalOcean Team invite**:
- Go to Settings → Team → Invite a member
- Enter developer's email with **Member** role

#### Step 4: What developer needs from client
- Droplet IP address (shown on DO dashboard)
- SSH access OR root password
- Domain name pointed to the Droplet IP (DNS A record)

---

## What the Developer Will Do on the Server

1. **Complete WordPress setup** — visit `http://<droplet-ip>` to run WordPress installer
2. **Install WooCommerce** — Plugins → Add New → WooCommerce
3. **Upload the plugin** — SFTP the `livesale/` folder to `/var/www/html/wp-content/plugins/`
4. **Add Ably key to wp-config.php**:
   ```php
   define( 'LSG_ABLY_API_KEY', 'tez0cg.QzrezA:MptL-_B4Pzto4ezrgtCmXES3PSrqCWnvYdvJP7RL2U8' );
   ```
5. **Activate the plugin** — WordPress Admin → Plugins → Live Sale
6. **Point your domain** — Add A record: `yourdomain.com → <droplet-ip>`
7. **Install SSL** (free HTTPS):
   ```bash
   sudo certbot --apache -d yourdomain.com
   ```
8. **Install Ably PHP library** (for server-side push):
   ```bash
   cd /var/www/html/wp-content/plugins/livesale
   composer require ably/ably-php
   ```

---

## Post-Deployment Checklist

- [ ] WordPress installed and admin account created
- [ ] WooCommerce installed and configured (currency set to KES)
- [ ] Plugin activated
- [ ] `LSG_ABLY_API_KEY` added to wp-config.php
- [ ] "Live Sale" product category created
- [ ] Products assigned to "Live Sale" category
- [ ] `[live_sale_grid]` shortcode added to Live Sale page
- [ ] `[live_sale_chat]` shortcode added to Live Sale page (or same page)
- [ ] SSL certificate installed (HTTPS working)
- [ ] Domain pointing to Droplet IP
- [ ] Test: product claim works ✓
- [ ] Test: giveaway timer, roll, winner email ✓
- [ ] Test: winner sees "View Your Order" button ✓
- [ ] Test: chat send/receive works ✓

---

## Ably API Key (keep this secret — do not share publicly)

Key is stored in `wp-config.php` on the server as `LSG_ABLY_API_KEY`.
Dashboard: https://ably.com/accounts → your app → API Keys
