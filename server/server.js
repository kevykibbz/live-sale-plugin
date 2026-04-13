'use strict';

/**
 * LiveSale Socket.io Server
 *
 * Runs on your Digital Ocean droplet (or any Node.js host).
 * WordPress PHP calls POST /emit to push events; browsers connect
 * via Socket.io WebSocket to receive them in real time.
 *
 * Setup:
 *   npm install
 *   cp .env.example .env   # then fill in the values
 *   node server.js         # or: pm2 start server.js --name livesale
 *
 * wp-config.php:
 *   define( 'LSG_SOCKETIO_URL',    'https://your-droplet-ip-or-domain:3000' );
 *   define( 'LSG_SOCKETIO_SECRET', 'same-secret-as-in-.env' );
 */

require('dotenv').config();

const express   = require('express');
const http      = require('http');
const { Server } = require('socket.io');

const PORT   = process.env.PORT   || 3000;
const SECRET = process.env.LSG_SECRET;
const DEBUG  = process.env.LSG_DEBUG === 'true' || process.env.LSG_DEBUG === '1';

// Support comma-separated list of allowed origins, e.g. "https://emgarden.co,http://localhost"
const ORIGIN = process.env.CORS_ORIGIN
    ? process.env.CORS_ORIGIN.split(',').map(o => o.trim())
    : '*';

const log = {
    info:  (...a) => console.log ('[LiveSale]', ...a),
    warn:  (...a) => console.warn ('[LiveSale]', ...a),
    error: (...a) => console.error('[LiveSale]', ...a),
    debug: (...a) => { if (DEBUG) console.log('[LiveSale:DEBUG]', ...a); },
};

if (!SECRET) {
    log.error('ERROR: LSG_SECRET env var is not set. Exiting.');
    process.exit(1);
}

const app    = express();
const server = http.createServer(app);

const io = new Server(server, {
    cors: {
        origin: ORIGIN,
        methods: ['GET', 'POST'],
    },
    // Allow long-polling fallback for users that can't use WebSockets
    transports: ['websocket', 'polling'],
});

app.use(express.json({ limit: '64kb' }));

// ----------------------------------------------------------------
// Health check
// ----------------------------------------------------------------
app.get('/health', (_req, res) => {
    log.debug('GET /health');
    res.json({ ok: true, ts: Date.now(), debug: DEBUG });
});

// ----------------------------------------------------------------
// POST /emit — called by WordPress PHP to broadcast an event
// Body: { channel: string, event: string, data: object, secret: string }
// ----------------------------------------------------------------
app.post('/emit', (req, res) => {
    const { channel, event, data, secret } = req.body || {};

    log.debug('POST /emit | channel:', channel, '| event:', event, '| data:', JSON.stringify(data));

    // Constant-time comparison to resist timing attacks
    if (!secret || secret.length !== SECRET.length || !timingSafeEqual(secret, SECRET)) {
        log.warn('POST /emit rejected — invalid secret');
        return res.status(403).json({ error: 'Forbidden' });
    }

    if (!channel || typeof channel !== 'string' ||
        !event   || typeof event   !== 'string') {
        log.warn('POST /emit rejected — missing channel or event');
        return res.status(400).json({ error: 'Missing channel or event' });
    }

    log.debug('Broadcasting event:', event, 'to channel:', channel);
    io.to(channel).emit(event, data || {});
    res.json({ ok: true });
});

// Simple constant-time string comparison (no crypto module needed here)
function timingSafeEqual(a, b) {
    if (a.length !== b.length) return false;
    let diff = 0;
    for (let i = 0; i < a.length; i++) {
        diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
    }
    return diff === 0;
}

// ----------------------------------------------------------------
// Socket.io connection
// ----------------------------------------------------------------
io.on('connection', (socket) => {
    const transport = socket.conn.transport.name;
    log.debug('Client connected | id:', socket.id, '| transport:', transport, '| ip:', socket.handshake.address);

    // Log transport upgrades (polling → websocket)
    socket.conn.on('upgrade', (newTransport) => {
        log.debug('Client upgraded | id:', socket.id, '|', transport, '→', newTransport.name);
    });

    // Client must join a named channel/room to receive events
    socket.on('join', (channel) => {
        if (typeof channel === 'string' && channel.length <= 100) {
            socket.join(channel);
            log.debug('Client joined channel | id:', socket.id, '| channel:', channel);
        }
    });

    socket.on('leave', (channel) => {
        if (typeof channel === 'string') {
            socket.leave(channel);
            log.debug('Client left channel | id:', socket.id, '| channel:', channel);
        }
    });

    socket.on('disconnect', (reason) => {
        log.debug('Client disconnected | id:', socket.id, '| reason:', reason);
    });
});

// ----------------------------------------------------------------
// Start
// ----------------------------------------------------------------
server.listen(PORT, () => {
    log.info(`Socket.io server listening on port ${PORT}`);
    log.info(`CORS origin: ${JSON.stringify(ORIGIN)}`);
    log.info(`Debug mode: ${DEBUG ? 'ON (LSG_DEBUG=true)' : 'OFF'}`);
});
