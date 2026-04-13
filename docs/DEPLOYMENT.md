# Live Sale Plugin — Digital Ocean Deployment Guide

## GitHub Repository Setup

### Suggested repo details (tell your developer):
- **Repository name:** `live-sale-plugin`
- **Description:** Real-time WooCommerce live sale product grid and chat powered by self-hosted Socket.io
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
4. **Deploy the Socket.io server** on the droplet:
   ```bash
   cd /var/www/html/wp-content/plugins/livesale/server
   cp .env.example .env
   # Edit .env — set LSG_SECRET and CORS_ORIGIN=https://yourdomain.com
   npm install
   pm2 start server.js --name livesale
   pm2 startup && pm2 save
   ```
5. **Add Socket.io config to wp-config.php**:
   ```php
   define( 'LSG_SOCKETIO_URL',    'https://yourdomain.com:3000' );
   define( 'LSG_SOCKETIO_SECRET', 'your_strong_shared_secret_here' );
   ```
6. **Activate the plugin** — WordPress Admin → Plugins → Live Sale
7. **Point your domain** — Add A record: `yourdomain.com → <droplet-ip>`
8. **Install SSL** (free HTTPS):
   ```bash
   sudo certbot --apache -d yourdomain.com
   ```

---

## Post-Deployment Checklist

- [ ] WordPress installed and admin account created
- [ ] WooCommerce installed and configured (currency set to KES)
- [ ] Plugin activated
- [ ] `LSG_SOCKETIO_URL` and `LSG_SOCKETIO_SECRET` added to wp-config.php
- [ ] Socket.io server running on droplet (pm2 status)
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

## Socket.io Server Secret (keep this secret — do not share publicly)

The shared secret is stored in `wp-config.php` as `LSG_SOCKETIO_SECRET` and in `server/.env` as `LSG_SECRET`.
Both values must match exactly. Use a long random string (32+ characters).
